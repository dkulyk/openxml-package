<?php

declare(strict_types=1);

namespace DK\OpenXml;

use DK\OpenXml\Exception\EncryptedPackageException;
use DK\OpenXml\Exception\OpenXmlException;
use DK\OpenXml\Exception\PackageValidationException;
use DK\OpenXml\Exception\PartInUseException;
use DK\OpenXml\Exception\PartNotFoundException;
use DK\OpenXml\Exception\UnsupportedFileFormatException;
use DK\OpenXml\Internal\AtomicFileWriter;
use DK\OpenXml\Internal\Container\ContainerInterface;
use DK\OpenXml\Internal\Container\SourceArchiveRegistry;
use DK\OpenXml\Internal\Container\ZipContainer;
use DK\OpenXml\Internal\MaterializationPool;
use DK\OpenXml\Internal\PackageRepairer;
use DK\OpenXml\Internal\PartNameIndex;
use DK\OpenXml\Internal\SignatureInspector;
use DK\OpenXml\Internal\SourceFileState;
use DK\OpenXml\Internal\Zip\CentralDirectory;
use DK\OpenXml\Packaging\ContentCompression;
use DK\OpenXml\Packaging\ContentTypes;
use DK\OpenXml\Packaging\PackageInterface;
use DK\OpenXml\Packaging\Part;
use DK\OpenXml\Packaging\PartInterface;
use DK\OpenXml\Packaging\PartName;
use DK\OpenXml\Packaging\PartRemovalResult;
use DK\OpenXml\Packaging\RelationshipInterface;
use DK\OpenXml\Packaging\RelationshipReference;
use DK\OpenXml\Packaging\Relationships;
use DK\OpenXml\Packaging\RelationshipType;
use DK\OpenXml\Repair\PackageRepairOptions;
use DK\OpenXml\Repair\RepairReport;
use DK\OpenXml\Security\PackageLimits;
use DK\OpenXml\Signature\SignatureContentType;
use DK\OpenXml\Signature\SignatureInspection;
use DK\OpenXml\Signature\SignatureRemovalResult;
use DK\OpenXml\Signature\SignatureStatus;

final class OpenXmlPackage implements PackageInterface
{
    private PartNameIndex $partNames;

    /** @var array<string, Relationships> Source part name ('' for the package) => live collection. */
    private array $relationships = [];

    private MaterializationPool $materializations;

    private int $contentRevision = 0;

    /** A saved package reopens its container on first access. */
    private bool $reopenPending = false;

    private function __construct(
        private ?ContainerInterface $container,
        private ContentTypes $contentTypes,
        private ?string $sourceFilename = null,
        private ?SourceFileState $sourceState = null,
        private bool $changed = false,
        private PackageLimits $limits = new PackageLimits(),
    ) {
        $this->partNames = new PartNameIndex();
        $this->materializations = new MaterializationPool();
        $this->rebuildPartNameIndex();
    }

    public static function create(?PackageLimits $limits = null): self
    {
        $limits ??= new PackageLimits();
        $contentTypes = new ContentTypes();
        $contentTypes->setDefault('rels', Relationships::CONTENT_TYPE);
        $contentTypes->setDefault('xml', 'application/xml');
        // Staged first so it becomes the first ZIP entry, as OPC expects for streaming readers.
        $container = new ZipContainer($limits);
        $container->write('[Content_Types].xml', $contentTypes->toXml());

        return new self($container, $contentTypes, limits: $limits);
    }

    /**
     * @param null|list<string>|string $expecting Content type, or types, the main document part must have.
     */
    public static function open(
        string $filename,
        ?PackageLimits $limits = null,
        string|array|null $expecting = null,
    ): self {
        $limits ??= new PackageLimits();
        $sourceFilename = self::resolveExistingFilename($filename);
        // The container check below is authoritative for OPC, so the archive is opened once.
        $format = OfficeFileDetector::detectContainer($sourceFilename);

        if ($format === OfficeFileFormat::EncryptedOpcPackage) {
            throw new EncryptedPackageException(
                'This is an encrypted Office Open XML package. Open it through the encryption API before reading it as OPC.',
            );
        }

        if ($format === OfficeFileFormat::CompoundFile) {
            throw new UnsupportedFileFormatException(
                'This is a valid CFBF/OLE file, but it is not an encrypted Office Open XML package.',
            );
        }

        if ($format === OfficeFileFormat::Unknown) {
            throw new UnsupportedFileFormatException(
                'The file is neither a recognized OPC ZIP package nor a supported Office compound file.',
            );
        }

        $sourceState = SourceFileState::capture($sourceFilename);
        $container = ZipContainer::open($sourceFilename, $limits, $sourceState);

        if (!$container->has('[Content_Types].xml')) {
            throw new UnsupportedFileFormatException(
                'This is a ZIP archive without [Content_Types].xml, so it is not an OPC package.',
            );
        }

        $contentTypes = ContentTypes::fromXml(
            $container->read('[Content_Types].xml'),
            $limits->maximumXmlBytes,
        );

        // Checked before the passes over every entry below, so a package of the wrong
        // kind is rejected without validating its names or indexing them.
        if ($expecting !== null) {
            self::assertMainDocumentType($container, $contentTypes, $limits, (array) $expecting);
        }

        self::assertPartNameIntegrity($container);
        $sourceState->assertUnchanged();

        return new self(
            $container,
            $contentTypes,
            $sourceFilename,
            $sourceState,
            limits: $limits,
        );
    }

    /**
     * Opens a package, applies an edit, and atomically saves the result.
     *
     * @param callable(self): void $edit
     */
    public static function edit(
        string $filename,
        callable $edit,
        ?PackageLimits $limits = null,
    ): void {
        $package = self::open($filename, $limits);
        $edit($package);
        $package->save();
    }

    public function hasPart(string $name): bool
    {
        try {
            $name = PartName::normalize($name);
        } catch (OpenXmlException) {
            return false;
        }

        return $this->findPartName($name) !== null;
    }

    public function getPart(string $name): PartInterface
    {
        $requestedName = PartName::normalize($name);
        $name = $this->findPartName($requestedName);
        if ($name === null) {
            throw new PartNotFoundException(sprintf('Package part does not exist: %s', $requestedName));
        }

        $contentType = $this->contentTypes->getForPart($name);
        if ($contentType === null) {
            throw new OpenXmlException(sprintf('No content type is registered for part "%s".', $name));
        }

        return new Part($this, $name, $contentType);
    }

    /** The content type currently registered for a part, or null when none covers it. */
    public function getPartContentType(string $name): ?string
    {
        $name = $this->findPartName(PartName::normalize($name));

        return $name === null ? null : $this->contentTypes->getForPart($name);
    }

    public function getParts(): \Traversable
    {
        foreach ($this->container()->entries() as $entryName) {
            if ($entryName === '[Content_Types].xml') {
                continue;
            }

            $partName = '/' . $entryName;
            if (!PartName::isRelationshipsPart($partName)) {
                // An entry the content types do not cover is not a part. validate()
                // reports it; stopping here would leave such a package unrepairable,
                // since fixing it means adding the content type it lacks.
                $contentType = $this->contentTypes->getForPart($partName);
                if ($contentType !== null) {
                    yield new Part($this, $partName, $contentType);
                }
            }
        }
    }

    public function addPart(string $name, string $contentType, string $contents, ?bool $compress = null): PartInterface
    {
        $name = PartName::normalize($name);
        if (PartName::isRelationshipsPart($name)) {
            throw new OpenXmlException('Relationship parts are managed through the relationship API.');
        }
        $this->assertPartNameAvailable($name, true);

        $this->container()->write(PartName::entry($name), $contents, $compress ?? ContentCompression::compresses($contentType));
        $this->registerContentType($name, $contentType);
        $this->partNames->add($name);
        ++$this->contentRevision;
        $this->changed = true;

        return $this->getPart($name);
    }

    public function addPartFromStream(string $name, string $contentType, $stream, ?bool $compress = null): PartInterface
    {
        $name = PartName::normalize($name);
        if (PartName::isRelationshipsPart($name)) {
            throw new OpenXmlException('Relationship parts are managed through the relationship API.');
        }
        $this->assertPartNameAvailable($name, true);

        $this->container()->writeStream(PartName::entry($name), $stream, $compress ?? ContentCompression::compresses($contentType));
        $this->registerContentType($name, $contentType);
        $this->partNames->add($name);
        ++$this->contentRevision;
        $this->changed = true;

        return $this->getPart($name);
    }

    public function addPartFromPath(string $name, string $contentType, string $path, ?bool $compress = null): PartInterface
    {
        $name = PartName::normalize($name);
        if (PartName::isRelationshipsPart($name)) {
            throw new OpenXmlException('Relationship parts are managed through the relationship API.');
        }
        $this->assertPartNameAvailable($name, true);

        $this->container()->writePath(
            PartName::entry($name),
            $path,
            $compress ?? ContentCompression::compresses($contentType),
        );
        $this->registerContentType($name, $contentType);
        $this->partNames->add($name);
        ++$this->contentRevision;
        $this->changed = true;

        return $this->getPart($name);
    }

    public function readPart(string $name): string
    {
        $requestedName = PartName::normalize($name);
        $name = $this->findPartName($requestedName);
        if ($name === null) {
            throw new PartNotFoundException(sprintf('Package part does not exist: %s', $requestedName));
        }

        return $this->container()->read(PartName::entry($name));
    }

    /** @return resource */
    public function openPartStream(string $name)
    {
        $requestedName = PartName::normalize($name);
        $name = $this->findPartName($requestedName);
        if ($name === null) {
            throw new PartNotFoundException(sprintf('Package part does not exist: %s', $requestedName));
        }

        return $this->container()->openStream(PartName::entry($name));
    }

    public function getPartReadablePath(string $name): string
    {
        $name = $this->existingPartName($name);

        return $this->container()->getReadablePath(PartName::entry($name))
            ?? $this->getPartLocalPath($name);
    }

    public function getPartLocalPath(string $name): string
    {
        $name = $this->existingPartName($name);
        $key = $this->contentRevision . "\0" . strtolower($name);

        return $this->materializations->materialize(
            $key,
            PartName::entry($name),
            fn() => $this->container()->openStream(PartName::entry($name)),
        );
    }

    public function writePart(string $name, string $contents, ?bool $compress = null): void
    {
        $name = $this->existingWritablePartName($name);

        $this->container()->write(PartName::entry($name), $contents, $compress ?? $this->partCompresses($name));
        ++$this->contentRevision;
        $this->changed = true;
    }

    /** @param resource $stream */
    public function writePartFromStream(string $name, $stream, ?bool $compress = null): void
    {
        $name = $this->existingWritablePartName($name);

        $this->container()->writeStream(PartName::entry($name), $stream, $compress ?? $this->partCompresses($name));
        ++$this->contentRevision;
        $this->changed = true;
    }

    public function writePartFromPath(string $name, string $path, ?bool $compress = null): void
    {
        $name = $this->existingWritablePartName($name);

        $this->container()->writePath(PartName::entry($name), $path, $compress ?? $this->partCompresses($name));
        ++$this->contentRevision;
        $this->changed = true;
    }

    public function setDefaultContentType(string $extension, string $contentType): void
    {
        $this->contentTypes->setDefault($extension, $contentType);
        $this->changed = true;
    }

    public function removePart(string $name): void
    {
        $this->removeParts([$name]);
    }

    /**
     * Remove unreferenced parts with one relationship scan. All names and inbound
     * references are checked before removal; duplicate names are removed once.
     *
     * @param list<string> $names
     */
    public function removeParts(array $names): void
    {
        $referencesByPart = $this->collectInboundRelationships($names);
        $batch = [];
        foreach (array_keys($referencesByPart) as $name) {
            $batch[strtolower($name)] = true;
        }

        foreach ($referencesByPart as $name => $references) {
            // A reference from a part the same batch removes is not what blocks
            // removal: that part's relationship part goes with it, so nothing is
            // left pointing here. Rejecting it would make the batch stricter than
            // the loop of single removals it replaces, where deleting the source
            // first succeeds. Ignoring it also makes the batch order-independent,
            // which that loop is not.
            $blocking = array_values(array_filter(
                $references,
                static fn(RelationshipReference $reference): bool => $reference->sourcePartName === null
                    || !isset($batch[strtolower($reference->sourcePartName)]),
            ));
            if ($blocking !== []) {
                throw new PartInUseException($name, $blocking);
            }
        }

        foreach ($referencesByPart as $name => $_references) {
            $this->removePartContents($name);
        }
    }

    public function getInboundRelationships(string $partName): array
    {
        return array_values($this->collectInboundRelationships([$partName]))[0];
    }

    public function removePartAndRelationships(string $name): PartRemovalResult
    {
        return $this->removePartsAndRelationships([$name])[0];
    }

    /**
     * Remove parts and their inbound relationships with one relationship scan.
     * Results follow input order, with equivalent names included only once.
     *
     * @param list<string> $names
     *
     * @return list<PartRemovalResult>
     */
    public function removePartsAndRelationships(array $names): array
    {
        $referencesByPart = $this->collectInboundRelationships($names);
        // Remove references before any source part: a later target may be
        // referenced by an earlier part in the same batch (including cycles).
        foreach ($referencesByPart as $references) {
            foreach ($references as $reference) {
                $this->getRelationships($reference->sourcePartName)->remove(
                    $reference->relationship->getId(),
                );
            }
        }

        $results = [];
        foreach ($referencesByPart as $name => $references) {
            $this->removePartContents($name);
            $results[] = new PartRemovalResult($name, $references);
        }

        return $results;
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, list<RelationshipReference>>
     */
    private function collectInboundRelationships(array $names): array
    {
        $selected = [];
        $references = [];
        foreach ($names as $name) {
            $requestedName = PartName::normalize($name);
            $name = $this->findPartName($requestedName);
            if ($name === null || PartName::isRelationshipsPart($name)) {
                throw new PartNotFoundException(sprintf('Package part does not exist: %s', $requestedName));
            }
            $selected[strtolower($name)] = $name;
            $references[$name] = [];
        }
        if ($selected === []) {
            return [];
        }

        foreach ([null, ...$this->relationshipSourceNames()] as $sourcePartName) {
            foreach ($this->existingRelationships($sourcePartName) ?? [] as $relationship) {
                if ($relationship->isExternal()) {
                    continue;
                }
                $target = PartName::normalize((string) $relationship->getTargetPartName());
                $name = $selected[strtolower($target)] ?? null;
                if ($name !== null) {
                    $references[$name][] = new RelationshipReference($sourcePartName, $relationship);
                }
            }
        }

        return $references;
    }

    private function removePartContents(string $name): void
    {
        $relationshipPartName = $this->relationshipsPartName($name);

        $this->container()->remove(PartName::entry($name));
        $this->container()->remove(PartName::entry($relationshipPartName));
        $this->contentTypes->removeOverride($name);
        $this->partNames->remove($name);
        $this->partNames->remove($relationshipPartName);
        unset($this->relationships[strtolower($name)]);
        ++$this->contentRevision;
        $this->changed = true;
    }

    public function movePart(string $source, string $destination): PartInterface
    {
        $requestedSource = PartName::normalize($source);
        $source = $this->findPartName($requestedSource);
        $destination = PartName::normalize($destination);
        if (($source !== null && PartName::isRelationshipsPart($source)) || PartName::isRelationshipsPart($destination)) {
            throw new OpenXmlException('Relationship parts are managed through the relationship API.');
        }
        if ($source === null) {
            throw new PartNotFoundException(sprintf('Package part does not exist: %s', $requestedSource));
        }
        if (PartName::equivalent($source, $destination)) {
            return $this->getPart($source);
        }
        if ($this->hasPart($destination)) {
            throw new OpenXmlException(sprintf('Package part already exists: %s', $destination));
        }
        $this->assertPartNameAvailable($destination, false, $source);

        $contentType = $this->getPart($source)->getContentType();
        $relationshipChanges = $this->relationshipChangesForMove($source, $destination);
        $sourceRelationshipPart = $this->relationshipsPartName($source);
        $destinationRelationshipPart = $this->relationshipsPartName($destination);
        if (
            $this->container()->has(PartName::entry($sourceRelationshipPart))
            && $this->container()->has(PartName::entry($destinationRelationshipPart))
        ) {
            throw new OpenXmlException(sprintf(
                'Destination relationship part already exists: %s',
                $destinationRelationshipPart,
            ));
        }

        $this->container()->move(PartName::entry($source), PartName::entry($destination));
        if ($this->container()->has(PartName::entry($sourceRelationshipPart))) {
            $this->container()->move(
                PartName::entry($sourceRelationshipPart),
                PartName::entry($destinationRelationshipPart),
            );
            $this->partNames->remove($sourceRelationshipPart);
            $this->partNames->add($destinationRelationshipPart);
        }

        $this->contentTypes->removeOverride($source);
        $this->registerContentType($destination, $contentType);
        $this->partNames->remove($source);
        $this->partNames->add($destination);
        unset($this->relationships[strtolower($source)], $this->relationships[strtolower($destination)]);

        foreach ($relationshipChanges as [$relationshipSource, $id, $target]) {
            $this->getRelationships($relationshipSource)->retarget($id, $target);
        }

        ++$this->contentRevision;
        $this->changed = true;

        return $this->getPart($destination);
    }

    public function getRelationships(?string $sourcePartName = null): Relationships
    {
        if ($sourcePartName !== null) {
            $requestedSourcePartName = PartName::normalize($sourcePartName);
            if (PartName::isRelationshipsPart($requestedSourcePartName)) {
                throw new PartNotFoundException(sprintf(
                    'Relationship parts cannot own relationships: %s',
                    $requestedSourcePartName,
                ));
            }
            // The source part may be added after its relationships; validate()
            // reports relationship parts whose source never arrives.
            $sourcePartName = $this->findPartName($requestedSourcePartName) ?? $requestedSourcePartName;
        }

        // One live collection per source: separate handles would otherwise
        // overwrite each other's changes when they persist.
        $cacheKey = strtolower($sourcePartName ?? '');
        if (isset($this->relationships[$cacheKey])) {
            return $this->relationships[$cacheKey];
        }

        $relationshipEntryName = PartName::entry($this->relationshipsPartName($sourcePartName));
        // The collection refers back weakly, so holding it here forms no cycle and
        // the package's ZIP archive is still released when the package is.
        $package = \WeakReference::create($this);
        $onChange = static function (Relationships $relationships) use ($package, $sourcePartName): void {
            $package->get()?->writeRelationships($relationships, $sourcePartName);
        };

        $relationships = $this->container()->has($relationshipEntryName)
            ? Relationships::fromXml(
                $this->container()->read($relationshipEntryName),
                $this,
                $sourcePartName,
                $onChange,
                $this->limits->maximumXmlBytes,
            )
            : new Relationships($this, $sourcePartName, $onChange);

        // Kept for the package's lifetime: every whole-package operation walks all
        // relationships, and re-parsing each .rels on each walk dominated them.
        return $this->relationships[$cacheKey] = $relationships;
    }

    /** Internal walks must not create a live collection for a source with no relationships. */
    private function existingRelationships(?string $sourcePartName): ?Relationships
    {
        $cached = $this->relationships[strtolower($sourcePartName ?? '')] ?? null;
        if ($cached !== null) {
            return $cached;
        }
        $entryName = PartName::entry($this->relationshipsPartName($sourcePartName));
        if (!$this->container()->has($entryName)) {
            return null;
        }

        return $this->getRelationships($sourcePartName);
    }

    public function addRelationship(
        string $type,
        string $target,
        bool $external = false,
        ?string $id = null,
        ?string $sourcePartName = null,
    ): RelationshipInterface {
        if (!$external) {
            PartName::resolveTarget($sourcePartName, $target);
        }

        return $this->getRelationships($sourcePartName)->create($type, $target, $external, $id);
    }

    public function removeRelationship(string $id, ?string $sourcePartName = null): void
    {
        $this->getRelationships($sourcePartName)->remove($id);
    }

    public function getMainDocumentPart(): ?PartInterface
    {
        $partName = self::mainDocumentPartName($this->getRelationships());

        return $partName !== null && $this->hasPart($partName) ? $this->getPart($partName) : null;
    }

    /**
     * Exact entry lookup first, so the common case stays a hash hit; the scan behind it
     * only runs for a target whose case differs from the stored entry, or for one that
     * is not in the archive at all and is about to be rejected anyway.
     */
    private static function containsPart(ContainerInterface $container, string $partName): bool
    {
        $entryName = PartName::entry($partName);
        if ($container->has($entryName)) {
            return true;
        }

        // Compared by ASCII case, the OPC equivalence rule, rather than by normalizing
        // entry names: they are only validated after this check runs.
        $comparisonKey = strtolower($entryName);
        foreach ($container->entries() as $candidate) {
            if (strtolower($candidate) === $comparisonKey) {
                return true;
            }
        }

        return false;
    }

    private static function mainDocumentPartName(Relationships $relationships): ?string
    {
        $relationship = $relationships->firstByType(RelationshipType::OFFICE_DOCUMENT);

        return $relationship === null || $relationship->isExternal()
            ? null
            : $relationship->getTargetPartName();
    }

    /**
     * Reads the package relationships straight from the container: the check runs before
     * the package exists, so that a rejected file never pays for its part-name index.
     *
     * @param list<string> $expected
     */
    private static function assertMainDocumentType(
        ContainerInterface $container,
        ContentTypes $contentTypes,
        PackageLimits $limits,
        array $expected,
    ): void {
        $entryName = PartName::entry(PartName::relationshipsName());
        $partName = $container->has($entryName)
            ? self::mainDocumentPartName(Relationships::fromXml(
                $container->read($entryName),
                maximumXmlBytes: $limits->maximumXmlBytes,
            ))
            : null;

        if ($partName === null) {
            throw new UnsupportedFileFormatException(sprintf(
                'The package declares no main document part; expected one of: %s.',
                implode(', ', $expected),
            ));
        }
        if (!self::containsPart($container, $partName)) {
            throw new UnsupportedFileFormatException(sprintf(
                'The main document part "%s" is missing from the package; expected one of: %s.',
                $partName,
                implode(', ', $expected),
            ));
        }

        $contentType = $contentTypes->getForPart($partName);
        if ($contentType === null) {
            throw new UnsupportedFileFormatException(sprintf(
                'The main document part "%s" has no declared content type; expected one of: %s.',
                $partName,
                implode(', ', $expected),
            ));
        }

        foreach ($expected as $expectedContentType) {
            if (strcasecmp($contentType, $expectedContentType) === 0) {
                return;
            }
        }

        throw new UnsupportedFileFormatException(sprintf(
            'The main document part "%s" is "%s"; expected one of: %s.',
            $partName,
            $contentType,
            implode(', ', $expected),
        ));
    }

    public function inspectSignatures(): SignatureInspection
    {
        return (new SignatureInspector(
            $this->container(),
            $this->contentTypes,
            $this->limits->maximumXmlBytes,
        ))->inspect();
    }

    public function removeSignatures(): SignatureRemovalResult
    {
        $sourcePartNames = [];
        $partsToRemove = [];
        foreach ($this->partNames as $partName) {
            if (PartName::isRelationshipsPart($partName)) {
                continue;
            }

            $sourcePartNames[] = $partName;
            if ($this->isSignaturePart($partName)) {
                $partsToRemove[strtolower($partName)] = $partName;
            }
        }

        /** @var array<string, Relationships> $relationshipsBySource */
        $relationshipsBySource = [];
        foreach ([null, ...$sourcePartNames] as $sourcePartName) {
            $relationships = $this->existingRelationships($sourcePartName);
            if ($relationships === null) {
                continue;
            }
            $key = $sourcePartName ?? '';
            $relationshipsBySource[$key] = $relationships;

            foreach ($relationshipsBySource[$key] as $relationship) {
                if (!in_array($relationship->getType(), self::signatureRelationshipTypes(), true)) {
                    continue;
                }

                if (!$relationship->isExternal()) {
                    $targetPartName = $relationship->getTargetPartName();
                    if ($targetPartName !== null) {
                        $storedPartName = $this->findPartName($targetPartName);
                        if ($storedPartName !== null && !PartName::isRelationshipsPart($storedPartName)) {
                            $partsToRemove[strtolower($storedPartName)] = $storedPartName;
                        }
                    }
                }
            }
        }

        /** @var array<string, array{?string, string}> $relationshipsToRemove */
        $relationshipsToRemove = [];
        $removedRelationships = 0;
        foreach ($relationshipsBySource as $sourceKey => $relationships) {
            $sourcePartName = $sourceKey === '' ? null : $sourceKey;
            foreach ($relationships as $relationship) {
                if ($sourcePartName !== null && isset($partsToRemove[strtolower($sourcePartName)])) {
                    ++$removedRelationships;

                    continue;
                }

                $remove = in_array($relationship->getType(), self::signatureRelationshipTypes(), true);
                if (!$remove && !$relationship->isExternal()) {
                    $targetPartName = $relationship->getTargetPartName();
                    $remove = $targetPartName !== null && isset($partsToRemove[strtolower($targetPartName)]);
                }

                if ($remove) {
                    ++$removedRelationships;
                    $relationshipsToRemove[$sourceKey . "\0" . $relationship->getId()] = [
                        $sourcePartName,
                        $relationship->getId(),
                    ];
                }
            }
        }

        foreach ($relationshipsToRemove as [$sourcePartName, $relationshipId]) {
            $this->getRelationships($sourcePartName)->remove($relationshipId);
        }

        $removedPartNames = array_values($partsToRemove);
        sort($removedPartNames);
        foreach ($removedPartNames as $partName) {
            $this->removePartContents($partName);
        }

        return new SignatureRemovalResult($removedPartNames, $removedRelationships);
    }

    /** Whether any signature part or origin relationship exists; without them inspection reports an unsigned package. */
    private function hasSignatureMaterial(): bool
    {
        foreach ($this->partNames as $partName) {
            if ($this->isSignaturePart($partName)) {
                return true;
            }
        }

        try {
            $relationships = $this->existingRelationships(null);

            return $relationships !== null
                && $relationships->getByType(RelationshipType::DIGITAL_SIGNATURE_ORIGIN) !== [];
        } catch (OpenXmlException) {
            return true;
        }
    }

    private function isSignaturePart(string $partName): bool
    {
        return str_starts_with(strtolower($partName), '/_xmlsignatures/')
            || in_array(
                $this->contentTypes->getForPart($partName),
                [SignatureContentType::ORIGIN, SignatureContentType::XML_SIGNATURE, SignatureContentType::CERTIFICATE],
                true,
            );
    }

    /** @return list<string> */
    private static function signatureRelationshipTypes(): array
    {
        return [
            RelationshipType::DIGITAL_SIGNATURE_ORIGIN,
            RelationshipType::DIGITAL_SIGNATURE,
            RelationshipType::DIGITAL_SIGNATURE_CERTIFICATE,
        ];
    }

    public function validate(): array
    {
        $issues = [];

        if ($this->hasSignatureMaterial()) {
            $signatureInspection = $this->inspectSignatures();
            if ($signatureInspection->status !== SignatureStatus::Unsigned) {
                $issues[] = 'Digitally signed packages cannot be saved because signature preservation is not supported.';
            }
            foreach ($signatureInspection->getIssues() as $signatureIssue) {
                $issues[] = 'Digital signature: ' . $signatureIssue;
            }
        }

        $partNames = $this->collectPartNames($issues);

        foreach ([null, ...$partNames] as $sourcePartName) {
            try {
                $relationships = $this->existingRelationships($sourcePartName);
                if ($relationships === null) {
                    continue;
                }
            } catch (OpenXmlException $exception) {
                $sourceDescription = $sourcePartName ?? 'the package';
                $issues[] = sprintf(
                    'Relationship part for %s is invalid: %s',
                    $sourceDescription,
                    $exception->getMessage(),
                );

                continue;
            }

            foreach ($relationships as $relationship) {
                if ($relationship->isExternal()) {
                    continue;
                }

                try {
                    $targetPartName = (string) $relationship->getTargetPartName();
                } catch (OpenXmlException $exception) {
                    $sourceDescription = $sourcePartName ?? 'the package';
                    $issues[] = sprintf(
                        'Relationship "%s" from %s has an invalid internal target "%s": %s',
                        $relationship->getId(),
                        $sourceDescription,
                        $relationship->getTarget(),
                        $exception->getMessage(),
                    );

                    continue;
                }

                if (!$this->hasPart($targetPartName)) {
                    $sourceDescription = $sourcePartName ?? 'the package';
                    $issues[] = sprintf(
                        'Relationship "%s" from %s targets missing part "%s".',
                        $relationship->getId(),
                        $sourceDescription,
                        $targetPartName,
                    );
                }
            }
        }

        return $issues;
    }

    public function analyzeRepairs(PackageRepairOptions $options): RepairReport
    {
        return $this->packageRepairer()->run($options, false);
    }

    public function applyRepairs(PackageRepairOptions $options): RepairReport
    {
        return $this->packageRepairer()->run($options, true);
    }

    private function packageRepairer(): PackageRepairer
    {
        return new PackageRepairer(
            $this->container(),
            $this->contentTypes,
            fn(?string $sourcePartName): ?Relationships => $this->existingRelationships($sourcePartName),
            function (): void {
                $this->rebuildPartNameIndex();
                $this->changed = true;
            },
        );
    }

    public function hasChanges(): bool
    {
        return $this->changed;
    }

    public function discardChanges(): void
    {
        $freshPackage = $this->sourceFilename === null
            ? self::create($this->limits)
            : self::open($this->sourceFilename, $this->limits);

        $this->container = $freshPackage->container;
        $this->reopenPending = false;
        $this->contentTypes = $freshPackage->contentTypes;
        $this->sourceFilename = $freshPackage->sourceFilename;
        $this->sourceState = $freshPackage->sourceState;
        $this->partNames = $freshPackage->partNames;
        $this->relationships = [];
        ++$this->contentRevision;
        $this->changed = false;
    }

    public function save(): void
    {
        if ($this->sourceFilename === null) {
            throw new OpenXmlException('A newly created package must first be saved with saveAs().');
        }

        if (!$this->changed) {
            return;
        }

        $this->persist($this->sourceFilename, $this->sourceState);
    }

    public function saveAs(string $filename): void
    {
        $resolvedDestination = realpath($filename);
        $overwritesSource = $resolvedDestination !== false
            && $resolvedDestination === $this->sourceFilename;

        $this->persist($filename, $overwritesSource ? $this->sourceState : null);
    }

    /**
     * @param list<string> $issues
     *
     * @return list<string>
     */
    private function collectPartNames(array &$issues): array
    {
        $partNames = [];

        foreach ($this->container()->entries() as $entryName) {
            if ($entryName === '[Content_Types].xml') {
                continue;
            }

            $partName = '/' . $entryName;
            if (PartName::isRelationshipsPart($partName)) {
                if ($this->contentTypes->getForPart($partName) !== Relationships::CONTENT_TYPE) {
                    $issues[] = sprintf(
                        'Relationship part "%s" does not use content type "%s".',
                        $partName,
                        Relationships::CONTENT_TYPE,
                    );
                }

                $sourcePartName = PartName::relationshipSourceName($partName);
                if ($sourcePartName !== null && !$this->hasPart($sourcePartName)) {
                    $issues[] = sprintf(
                        'Relationship part "%s" belongs to missing source part "%s".',
                        $partName,
                        $sourcePartName,
                    );
                }

                continue;
            }

            $partNames[] = $partName;

            if ($this->contentTypes->getForPart($partName) === null) {
                $issues[] = sprintf('Part "%s" has no registered content type.', $partName);
            }
        }

        foreach ($this->contentTypes->getOverrides() as $partName => $contentType) {
            if (!$this->hasPart($partName)) {
                $issues[] = sprintf(
                    'Content type override for "%s" has no matching part.',
                    $partName,
                );
            }
        }

        return $partNames;
    }

    private function writeRelationships(
        Relationships $relationships,
        ?string $sourcePartName,
    ): void {
        $relationshipPartName = $this->relationshipsPartName($sourcePartName);
        $relationshipEntryName = PartName::entry($relationshipPartName);

        if (count($relationships) === 0) {
            $this->container()->remove($relationshipEntryName);
            $this->partNames->remove($relationshipPartName);
            $this->changed = true;

            return;
        }

        $this->contentTypes->setDefault('rels', Relationships::CONTENT_TYPE);
        // Serialized once on read or save instead of after every change.
        $this->container()->writeLazy($relationshipEntryName, static fn(): string => $relationships->toXml());
        $this->partNames->add($relationshipPartName);
        $this->changed = true;
    }

    /**
     * Names that can own relationships, taken from the part-name index rather than
     * from getParts(): a package-wide walk must not depend on every entry having a
     * content type.
     *
     * @return list<string>
     */
    private function relationshipSourceNames(): array
    {
        $names = [];
        foreach ($this->partNames as $partName) {
            if (!PartName::isRelationshipsPart($partName)) {
                $names[] = $partName;
            }
        }

        return $names;
    }

    /**
     * @return list<array{?string, string, string}>
     */
    private function relationshipChangesForMove(string $source, string $destination): array
    {
        $changes = [];
        foreach ([null, ...$this->relationshipSourceNames()] as $relationshipSource) {
            foreach ($this->existingRelationships($relationshipSource) ?? [] as $relationship) {
                if ($relationship->isExternal()) {
                    continue;
                }

                $targetPartName = (string) $relationship->getTargetPartName();
                $newRelationshipSource = $relationshipSource === $source
                    ? $destination
                    : $relationshipSource;

                if (PartName::equivalent($targetPartName, $source)) {
                    $newTarget = str_starts_with($relationship->getTarget(), '/')
                        ? $destination
                        : PartName::relativeTarget($newRelationshipSource, $destination);
                } elseif ($relationshipSource === $source && !str_starts_with($relationship->getTarget(), '/')) {
                    $newTarget = PartName::relativeTarget($destination, $targetPartName);
                } else {
                    continue;
                }

                $changes[] = [$newRelationshipSource, $relationship->getId(), $newTarget];
            }
        }

        return $changes;
    }

    private function persist(string $filename, ?SourceFileState $expectedSource): void
    {
        $existingDestination = realpath($filename);
        if ($existingDestination !== false) {
            SourceArchiveRegistry::assertCanReplace($existingDestination);
        }

        $issues = $this->validate();
        if ($issues !== []) {
            throw new PackageValidationException($issues);
        }

        $this->container()->write('[Content_Types].xml', $this->contentTypes->toXml());

        $beforeReplace = static function () use ($filename, $expectedSource): void {
            $expectedSource?->assertUnchanged();

            $existingDestination = realpath($filename);
            if ($existingDestination !== false) {
                SourceArchiveRegistry::prepareForReplacement($existingDestination);
            }
        };

        AtomicFileWriter::replace(
            $filename,
            function (string $temporaryFilename): void {
                $this->container()->saveAs($temporaryFilename);
                $this->verifyWrittenPackage($temporaryFilename);
            },
            $beforeReplace,
        );

        $this->sourceFilename = self::resolveExistingFilename($filename);
        $this->sourceState = SourceFileState::capture($this->sourceFilename);
        // Reopened on first access, so a package that is saved and released never reads its output back.
        $this->container = null;
        $this->reopenPending = true;
        ++$this->contentRevision;
        $this->changed = false;
    }

    private function verifyWrittenPackage(string $filename): void
    {
        // Entries were validated when staged; only the archive structure and content
        // types are read back. Opening the archive parses its whole directory, and
        // the package writer puts content types first, so both are checked here.
        $archive = new \ZipArchive();
        if ($archive->open($filename, \ZipArchive::RDONLY) !== true) {
            throw new OpenXmlException(sprintf('Written package "%s" cannot be opened.', $filename));
        }

        try {
            $first = $archive->getNameIndex(0);
            $contentTypesXml = $archive->getFromName(CentralDirectory::CONTENT_TYPES);
        } finally {
            $archive->close();
        }
        if ($first !== CentralDirectory::CONTENT_TYPES || $contentTypesXml === false) {
            throw new OpenXmlException(sprintf('Written package "%s" has no [Content_Types].xml.', $filename));
        }
        ContentTypes::fromXml($contentTypesXml, $this->limits->maximumXmlBytes);
    }

    private function container(): ContainerInterface
    {
        if ($this->reopenPending) {
            $this->sourceState?->assertUnchanged();
            $this->container = ZipContainer::open((string) $this->sourceFilename, $this->limits, $this->sourceState);
            $this->reopenPending = false;
            $this->rebuildPartNameIndex();
        }

        return $this->container ?? throw new OpenXmlException('The package container is unavailable.');
    }

    private function assertPartNameAvailable(
        string $partName,
        bool $allowExactMatch,
        ?string $excludedPartName = null,
    ): void {
        $this->partNames->assertAvailable($partName, $allowExactMatch, $excludedPartName);
    }

    private static function assertPartNameIntegrity(ContainerInterface $container): void
    {
        /** @var array<string, string> $partNamesByKey */
        $partNamesByKey = [];
        foreach ($container->entries() as $entryName) {
            if ($entryName === '[Content_Types].xml') {
                continue;
            }

            $partName = PartName::normalize('/' . $entryName);
            $comparisonKey = strtolower($partName);
            if (isset($partNamesByKey[$comparisonKey])) {
                throw new OpenXmlException(sprintf(
                    'OPC part name "%s" conflicts with part "%s".',
                    $partName,
                    $partNamesByKey[$comparisonKey],
                ));
            }
            $partNamesByKey[$comparisonKey] = $partName;
        }

        foreach ($partNamesByKey as $comparisonKey => $partName) {
            $prefix = $comparisonKey;
            while (($separator = strrpos($prefix, '/')) !== false && $separator > 0) {
                $prefix = substr($prefix, 0, $separator);
                if (isset($partNamesByKey[$prefix])) {
                    throw new OpenXmlException(sprintf(
                        'OPC part name "%s" is derivable from part "%s".',
                        $partName,
                        $partNamesByKey[$prefix],
                    ));
                }
            }
        }
    }

    /** Whether the part currently registered under this name is worth deflating. */
    private function partCompresses(string $partName): bool
    {
        return ContentCompression::compresses($this->contentTypes->getForPart($partName));
    }

    private function registerContentType(string $name, string $contentType): void
    {
        $this->contentTypes->removeOverride($name);
        if (strcasecmp((string) $this->contentTypes->getForPart($name), $contentType) !== 0) {
            $this->contentTypes->setOverride($name, $contentType);
        }
    }

    /** Stored name of an existing relationship part, otherwise the name derived from its source. */
    private function relationshipsPartName(?string $sourcePartName): string
    {
        $derived = PartName::relationshipsName($sourcePartName);

        return $this->findPartName($derived) ?? $derived;
    }

    private function findPartName(string $name): ?string
    {
        return $this->partNames->find($name);
    }

    private function existingPartName(string $name): string
    {
        $requestedName = PartName::normalize($name);
        $name = $this->findPartName($requestedName);
        if ($name === null) {
            throw new PartNotFoundException(sprintf('Package part does not exist: %s', $requestedName));
        }

        return $name;
    }

    private function existingWritablePartName(string $name): string
    {
        $name = $this->existingPartName($name);
        if (PartName::isRelationshipsPart($name)) {
            throw new OpenXmlException('Relationship parts are managed through the relationship API.');
        }

        return $name;
    }

    private function rebuildPartNameIndex(): void
    {
        $this->partNames = new PartNameIndex();
        $this->relationships = [];
        foreach ($this->container()->entries() as $entryName) {
            if ($entryName === '[Content_Types].xml') {
                continue;
            }

            $partName = '/' . $entryName;
            $this->partNames->add($partName);
        }
    }

    private static function resolveExistingFilename(string $filename): string
    {
        $resolvedFilename = realpath($filename);
        if ($resolvedFilename === false || !is_file($resolvedFilename)) {
            throw new OpenXmlException(sprintf('Package "%s" does not exist.', $filename));
        }

        return $resolvedFilename;
    }
}
