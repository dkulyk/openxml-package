<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal\Container;

use DK\OpenXml\Exception\OpenXmlException;
use DK\OpenXml\Exception\PackageLimitException;
use DK\OpenXml\Internal\SourceFileState;
use DK\OpenXml\Internal\StreamOwner;
use DK\OpenXml\Security\PackageLimits;

/** @internal */
final class ZipContainer implements ContainerInterface
{
    /** @var array<string, int> Uncompressed entry sizes. */
    private array $entries = [];

    /** Running totals over live entries, so limit checks do not rescan the map on every write. */
    private int $liveBytes = 0;

    private int $liveEntryCount = 0;

    /** @var array<string, string|StagedContents|\Closure(): string> */
    private array $staged = [];

    /** @var array<string, true> */
    private array $removed = [];

    /** @var array<string, string> Destination entry names mapped to their source ZIP entry. */
    private array $moved = [];

    /** @var array<string, true> Staged entries written without deflate. */
    private array $stored = [];

    private ?\ZipArchive $sourceArchive = null;

    private int $openSourceStreams = 0;

    public function __construct(
        private PackageLimits $limits = new PackageLimits(),
        private ?string $sourceFilename = null,
        private ?SourceFileState $sourceState = null,
    ) {}

    public function __destruct()
    {
        if ($this->sourceFilename !== null) {
            SourceArchiveRegistry::unregister($this->sourceFilename, $this);
        }
        $this->closeSourceArchive();
    }

    public static function open(
        string $filename,
        ?PackageLimits $limits = null,
        ?SourceFileState $sourceState = null,
    ): self {
        $limits ??= new PackageLimits();
        $resolvedFilename = realpath($filename);
        if ($resolvedFilename !== false) {
            $filename = $resolvedFilename;
        }
        $sourceState ??= SourceFileState::capture($filename);
        $archive = new \ZipArchive();
        if ($archive->open($filename) !== true) {
            throw new OpenXmlException(sprintf('Unable to open package "%s".', $filename));
        }

        try {
            if ($archive->numFiles > $limits->maximumEntries) {
                throw new PackageLimitException(sprintf(
                    'Package contains %d entries; the configured maximum is %d.',
                    $archive->numFiles,
                    $limits->maximumEntries,
                ));
            }

            $container = new self($limits, $filename);
            $totalBytes = 0;
            for ($index = 0; $index < $archive->numFiles; ++$index) {
                $entry = $archive->statIndex($index);
                if ($entry === false) {
                    throw new OpenXmlException(sprintf('Unable to inspect ZIP entry at index %d.', $index));
                }
                $name = $entry['name'];
                if (str_ends_with($name, '/')) {
                    continue;
                }

                self::assertSafeEntryName($name);
                if (isset($container->entries[$name])) {
                    throw new OpenXmlException(sprintf('Duplicate ZIP entry "%s".', $name));
                }
                self::assertEntryWithinLimits($name, $entry['size'], $entry['comp_size'], $limits);
                $totalBytes += $entry['size'];
                if ($totalBytes > $limits->maximumPackageBytes) {
                    throw new PackageLimitException(sprintf(
                        'Package expands beyond the configured maximum of %d bytes.',
                        $limits->maximumPackageBytes,
                    ));
                }
                $container->setEntry($name, $entry['size']);
            }

            $sourceState->assertUnchanged();
            $container->sourceState = $sourceState;
            $container->sourceArchive = $archive;
            SourceArchiveRegistry::register($filename, $container);

            return $container;
        } catch (\Throwable $exception) {
            $archive->close();

            throw $exception;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->entries[$name]) && !isset($this->removed[$name]);
    }

    public function read(string $name): string
    {
        if (!$this->has($name)) {
            throw new OpenXmlException(sprintf('ZIP entry "%s" does not exist.', $name));
        }
        if (!isset($this->staged[$name])) {
            return $this->readSourceEntry($name);
        }

        // Staged contents are this container's own, so they are read where they
        // already are rather than copied into a stream first.
        $staged = $this->resolveStaged($name);
        if (is_string($staged)) {
            return $staged;
        }

        return $staged->read($name);
    }

    /**
     * Read an unchanged entry straight out of the source archive: libzip does the same
     * work getStream() would, without a stream wrapper and its owner object. The length
     * bounds a directory that understates the entry, as the stream filter does.
     */
    private function readSourceEntry(string $name): string
    {
        if ($this->sourceFilename === null) {
            throw new OpenXmlException(sprintf('ZIP entry "%s" has no content source.', $name));
        }
        $this->assertSourceUnchanged();

        $declaredBytes = $this->entries[$name];
        $contents = $this->sourceArchive()->getFromName($this->moved[$name] ?? $name, $declaredBytes + 1);
        if ($contents === false) {
            throw new OpenXmlException(sprintf('Unable to read ZIP entry "%s".', $name));
        }
        if (strlen($contents) > $declaredBytes) {
            throw DeclaredSizeFilter::exceeded($name, $declaredBytes);
        }

        return $contents;
    }

    public function openStream(string $name)
    {
        $stream = $this->entryStream($name);
        if (!isset($this->staged[$name])) {
            // Staged content is this container's own, and its recorded size is what was
            // written; only what the source archive declares is worth distrusting.
            DeclaredSizeFilter::attach($stream, $name, $this->entries[$name]);
        }

        return $stream;
    }

    /** @return resource */
    private function entryStream(string $name)
    {
        if (!$this->has($name)) {
            throw new OpenXmlException(sprintf('ZIP entry "%s" does not exist.', $name));
        }
        if (isset($this->staged[$name])) {
            return $this->openStagedStream($this->resolveStaged($name), $name);
        }
        $sourceFilename = $this->sourceFilename;
        if ($sourceFilename === null) {
            throw new OpenXmlException(sprintf('ZIP entry "%s" has no content source.', $name));
        }
        $this->assertSourceUnchanged();

        $archive = $this->sourceArchive();
        $sourceEntryName = $this->moved[$name] ?? $name;
        $stream = $archive->getStream($sourceEntryName);
        if ($stream === false) {
            throw new OpenXmlException(sprintf('Unable to open ZIP entry "%s".', $name));
        }

        ++$this->openSourceStreams;
        $owner = new StreamOwner(function (): void {
            --$this->openSourceStreams;
        });
        if (!stream_context_set_option($stream, 'dk-openxml', 'container-owner', $owner)) {
            fclose($stream);

            throw new OpenXmlException(sprintf('Unable to bind ZIP entry stream "%s" to its container.', $name));
        }

        return $stream;
    }

    public function hasOpenSourceStreams(): bool
    {
        return $this->openSourceStreams > 0;
    }

    public function releaseSourceArchive(): void
    {
        $this->closeSourceArchive();
    }

    /**
     * A `zip://` URI the consumer opens itself, so the declared-size bound the
     * container applies to its own reads does not reach it; getLocalPath() is the
     * bounded alternative.
     */
    public function getReadablePath(string $name): ?string
    {
        if (!$this->has($name)) {
            throw new OpenXmlException(sprintf('ZIP entry "%s" does not exist.', $name));
        }
        if (isset($this->staged[$name])) {
            $staged = $this->resolveStaged($name);

            // Only a part staged from a caller's own file has a path that outlives
            // the staging: a temporary file is released as soon as the part is
            // replaced, and callers are promised a path that stays valid for the
            // life of the package. Those are materialized instead.
            return $staged instanceof StagedPath ? $staged->path($name) : null;
        }
        $sourceFilename = $this->sourceFilename;
        if ($sourceFilename === null) {
            return null;
        }
        if (!in_array('zip', stream_get_wrappers(), true) || str_contains($sourceFilename, '#')) {
            return null;
        }

        $this->assertSourceUnchanged();
        $sourceFilename = str_replace('\\', '/', $sourceFilename);
        $sourceEntryName = $this->moved[$name] ?? $name;

        return 'zip://' . $sourceFilename . '#' . $sourceEntryName;
    }

    public function entries(): iterable
    {
        foreach ($this->entries as $name => $_size) {
            if (!isset($this->removed[$name])) {
                yield $name;
            }
        }
    }

    public function write(string $name, string $contents, bool $compress = true): void
    {
        self::assertSafeEntryName($name);
        $this->assertWriteWithinLimits($name, strlen($contents));
        $this->staged[$name] = $contents;
        $this->setEntry($name, strlen($contents));
        $this->setCompression($name, $compress);
        unset($this->moved[$name]);
    }

    public function writeLazy(string $name, \Closure $contents): void
    {
        self::assertSafeEntryName($name);
        // Size is unknown until produced; entry-count limits apply now, byte limits in resolveStaged().
        if (!$this->has($name) && $this->liveEntryCount >= $this->limits->maximumEntries) {
            throw new PackageLimitException(sprintf(
                'Package exceeds the configured maximum of %d entries.',
                $this->limits->maximumEntries,
            ));
        }
        $this->staged[$name] = $contents;
        $this->setEntry($name, 0);
        $this->setCompression($name, true);
        unset($this->moved[$name]);
    }

    public function writeStream(string $name, $stream, bool $compress = true): void
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new \InvalidArgumentException('Part contents must be a readable stream resource.');
        }
        $metadata = stream_get_meta_data($stream);
        if (!str_contains($metadata['mode'], 'r') && !str_contains($metadata['mode'], '+')) {
            throw new \InvalidArgumentException('Part contents stream is not readable.');
        }
        self::assertSafeEntryName($name);
        $staged = tmpfile();
        if ($staged === false) {
            throw new OpenXmlException('Unable to create temporary storage for streamed part contents.');
        }

        try {
            $bytes = 0;
            while (!feof($stream)) {
                $chunk = fread($stream, 65_536);
                if ($chunk === false) {
                    throw new OpenXmlException(sprintf('Unable to read streamed contents for part "%s".', $name));
                }
                if ($chunk === '') {
                    break;
                }
                $bytes += strlen($chunk);
                if ($bytes > $this->limits->maximumPartBytes) {
                    throw new PackageLimitException(sprintf(
                        'Part "%s" exceeds the configured maximum of %d bytes.',
                        $name,
                        $this->limits->maximumPartBytes,
                    ));
                }
                self::writeToResource($staged, $chunk, $name);
            }

            $this->assertWriteWithinLimits($name, $bytes);
            rewind($staged);
            $this->staged[$name] = new StagedFile($staged);
            $this->setEntry($name, $bytes);
            $this->setCompression($name, $compress);
            unset($this->moved[$name]);
        } catch (\Throwable $exception) {
            fclose($staged);

            throw $exception;
        }
    }

    public function writePath(string $name, string $path, bool $compress = true): void
    {
        self::assertSafeEntryName($name);
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
            throw new OpenXmlException(sprintf('Local file "%s" is not readable.', $path));
        }
        $bytes = filesize($resolved);
        if ($bytes === false) {
            throw new OpenXmlException(sprintf('Unable to size local file "%s".', $path));
        }
        if ($bytes > $this->limits->maximumPartBytes) {
            throw new PackageLimitException(sprintf(
                'Part "%s" exceeds the configured maximum of %d bytes.',
                $name,
                $this->limits->maximumPartBytes,
            ));
        }
        $this->assertWriteWithinLimits($name, $bytes);

        // Staged where it already is: the file is read when the package reads or
        // saves the part, so nothing is copied now. The caller owns that file until
        // the package is saved, and its identity and timestamps are checked on every
        // read, so a file replaced behind the package's back is refused rather than
        // silently packaged.
        $this->staged[$name] = new StagedPath($resolved);
        $this->setEntry($name, $bytes);
        $this->setCompression($name, $compress);
        unset($this->moved[$name]);
    }

    public function remove(string $name): void
    {
        unset($this->staged[$name], $this->stored[$name], $this->moved[$name]);
        if ($this->has($name)) {
            $this->liveBytes -= $this->entries[$name];
            --$this->liveEntryCount;
        }
        if (isset($this->entries[$name])) {
            $this->removed[$name] = true;
        }
    }

    public function move(string $source, string $destination): void
    {
        self::assertSafeEntryName($source);
        self::assertSafeEntryName($destination);
        if (!$this->has($source)) {
            throw new OpenXmlException(sprintf('ZIP entry "%s" does not exist.', $source));
        }
        if ($this->has($destination)) {
            throw new OpenXmlException(sprintf('ZIP entry "%s" already exists.', $destination));
        }

        $this->entries[$destination] = $this->entries[$source];
        unset($this->entries[$source]);
        $this->removed[$source] = true;
        unset($this->removed[$destination]);

        if (isset($this->stored[$source])) {
            $this->stored[$destination] = true;
        }
        unset($this->stored[$source]);

        if (array_key_exists($source, $this->staged)) {
            $this->staged[$destination] = $this->staged[$source];
            unset($this->staged[$source]);

            return;
        }

        $this->moved[$destination] = $this->moved[$source] ?? $source;
        unset($this->moved[$source]);
    }

    public function saveAs(string $filename): void
    {
        $this->assertSourceUnchanged();
        $copySource = $this->sourceFilename !== null && is_file($this->sourceFilename);
        if ($copySource && !copy($this->sourceFilename, $filename)) {
            throw new OpenXmlException(sprintf('Unable to copy source package to "%s".', $filename));
        }

        $archive = new \ZipArchive();
        $flags = $copySource ? 0 : \ZipArchive::CREATE | \ZipArchive::OVERWRITE;
        if ($archive->open($filename, $flags) !== true) {
            throw new OpenXmlException(sprintf('Unable to create package "%s".', $filename));
        }

        try {
            foreach ($this->moved as $destination => $source) {
                // move() only accepts a destination that is absent or removed,
                // so an entry still under that name in the source archive is stale.
                if ($archive->locateName($destination) !== false && !$archive->deleteName($destination)) {
                    throw new OpenXmlException(sprintf('Unable to remove ZIP entry "%s".', $destination));
                }
                if ($archive->locateName($source) === false || !$archive->renameName($source, $destination)) {
                    throw new OpenXmlException(sprintf(
                        'Unable to move ZIP entry "%s" to "%s".',
                        $source,
                        $destination,
                    ));
                }
            }
            foreach ($this->removed as $entryName => $_removed) {
                if ($archive->locateName($entryName) !== false && !$archive->deleteName($entryName)) {
                    throw new OpenXmlException(sprintf('Unable to remove ZIP entry "%s".', $entryName));
                }
            }
            foreach (array_keys($this->staged) as $entryName) {
                $contents = $this->resolveStaged($entryName);
                $written = is_string($contents)
                    ? $archive->addFromString($entryName, $contents)
                    : $archive->addFile($contents->path($entryName), $entryName);
                if (!$written) {
                    throw new OpenXmlException(sprintf('Unable to write ZIP entry "%s".', $entryName));
                }
                if (isset($this->stored[$entryName]) && !$archive->setCompressionName($entryName, \ZipArchive::CM_STORE)) {
                    throw new OpenXmlException(sprintf('Unable to store ZIP entry "%s" uncompressed.', $entryName));
                }
            }
        } catch (\Throwable $exception) {
            $archive->close();

            throw $exception;
        }
        if (!$archive->close()) {
            throw new OpenXmlException(sprintf('Unable to finalize package "%s".', $filename));
        }
    }

    private function sourceArchive(): \ZipArchive
    {
        if ($this->sourceArchive !== null) {
            return $this->sourceArchive;
        }
        if ($this->sourceFilename === null) {
            throw new OpenXmlException('Package has no source ZIP archive.');
        }

        $archive = new \ZipArchive();
        if ($archive->open($this->sourceFilename) !== true) {
            throw new OpenXmlException(sprintf('Unable to reopen package "%s".', $this->sourceFilename));
        }

        return $this->sourceArchive = $archive;
    }

    private function closeSourceArchive(): void
    {
        if ($this->sourceArchive === null) {
            return;
        }

        $archive = $this->sourceArchive;
        $this->sourceArchive = null;
        $archive->close();
    }

    private function assertWriteWithinLimits(string $name, int $contentsBytes): void
    {
        if ($contentsBytes > $this->limits->maximumPartBytes) {
            throw new PackageLimitException(sprintf(
                'Part "%s" exceeds the configured maximum of %d bytes.',
                $name,
                $this->limits->maximumPartBytes,
            ));
        }
        $existingBytes = $this->has($name) ? $this->entries[$name] : 0;
        if ($this->liveBytes - $existingBytes + $contentsBytes > $this->limits->maximumPackageBytes) {
            throw new PackageLimitException(sprintf(
                'Package exceeds the configured maximum of %d bytes.',
                $this->limits->maximumPackageBytes,
            ));
        }
        if (!$this->has($name) && $this->liveEntryCount >= $this->limits->maximumEntries) {
            throw new PackageLimitException(sprintf(
                'Package exceeds the configured maximum of %d entries.',
                $this->limits->maximumEntries,
            ));
        }
    }

    private function setCompression(string $name, bool $compress): void
    {
        if ($compress) {
            unset($this->stored[$name]);
        } else {
            $this->stored[$name] = true;
        }
    }

    /** Record a live entry's size, replacing a previous live size or reviving a removed name. */
    private function setEntry(string $name, int $size): void
    {
        if ($this->has($name)) {
            $this->liveBytes -= $this->entries[$name];
        } else {
            ++$this->liveEntryCount;
        }
        $this->entries[$name] = $size;
        $this->liveBytes += $size;
        unset($this->removed[$name]);
    }

    /**
     * @param string|StagedContents $contents
     *
     * @return resource
     */
    private function openStagedStream(string|StagedContents $contents, string $name)
    {
        if (is_string($contents)) {
            $stream = self::temporaryStream();
            self::writeToResource($stream, $contents, $name);
            rewind($stream);

            return $stream;
        }

        return $contents->openStream($name);
    }

    /** @return resource */
    private static function temporaryStream()
    {
        $stream = fopen('php://temp/maxmemory:1048576', 'w+b');
        if ($stream === false) {
            throw new OpenXmlException('Unable to create a temporary part stream.');
        }

        return $stream;
    }

    /**
     * Produce lazily staged contents once and keep the result.
     *
     * @return string|StagedContents
     */
    private function resolveStaged(string $name): string|StagedContents
    {
        $contents = $this->staged[$name];
        if (!$contents instanceof \Closure) {
            return $contents;
        }

        $produced = $contents();
        $this->assertWriteWithinLimits($name, strlen($produced));
        $this->staged[$name] = $produced;
        $this->setEntry($name, strlen($produced));

        return $produced;
    }

    /** @param resource $stream */
    private static function writeToResource($stream, string $contents, string $name): void
    {
        $offset = 0;
        while ($offset < strlen($contents)) {
            $written = fwrite($stream, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new OpenXmlException(sprintf('Unable to stage streamed contents for part "%s".', $name));
            }
            $offset += $written;
        }
    }

    private static function assertSafeEntryName(string $name): void
    {
        $segments = explode('/', $name);
        if (
            $name === ''
            || str_starts_with($name, '/')
            || str_contains($name, '\\')
            || str_contains($name, "\0")
            || in_array('', $segments, true)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
        ) {
            throw new OpenXmlException(sprintf('Unsafe ZIP entry name "%s".', $name));
        }
    }

    private static function assertEntryWithinLimits(
        string $entryName,
        int $uncompressedBytes,
        int $compressedBytes,
        PackageLimits $limits,
    ): void {
        if ($uncompressedBytes > $limits->maximumPartBytes) {
            throw new PackageLimitException(sprintf(
                'Part "%s" expands to %d bytes; the configured maximum is %d.',
                $entryName,
                $uncompressedBytes,
                $limits->maximumPartBytes,
            ));
        }
        $compressionRatio = $compressedBytes === 0
            ? ($uncompressedBytes === 0 ? 1.0 : INF)
            : $uncompressedBytes / $compressedBytes;
        if ($compressionRatio > $limits->maximumCompressionRatio) {
            throw new PackageLimitException(sprintf(
                'Part "%s" has a suspicious compression ratio of %.2f.',
                $entryName,
                $compressionRatio,
            ));
        }
    }

    private function assertSourceUnchanged(): void
    {
        if ($this->sourceState === null) {
            return;
        }
        $this->sourceState->assertUnchanged();
    }
}
