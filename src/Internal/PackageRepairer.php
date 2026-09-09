<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal;

use DK\OpenXml\Exception\OpenXmlException;
use DK\OpenXml\Internal\Container\ContainerInterface;
use DK\OpenXml\Packaging\ContentTypes;
use DK\OpenXml\Packaging\PartName;
use DK\OpenXml\Packaging\Relationships;
use DK\OpenXml\Repair\PackageRepairOptions;
use DK\OpenXml\Repair\RepairAction;
use DK\OpenXml\Repair\RepairReport;

/** @internal */
final class PackageRepairer
{
    /**
     * @param \Closure(?string): ?Relationships $getRelationships
     * @param \Closure(): void                  $markChanged
     */
    public function __construct(
        private ContainerInterface $container,
        private ContentTypes $contentTypes,
        private \Closure $getRelationships,
        private \Closure $markChanged,
    ) {}

    public function run(PackageRepairOptions $options, bool $apply): RepairReport
    {
        $actions = [];
        $partNames = $this->inspectEntries($options, $apply, $actions);
        $this->inspectContentTypeOverrides($options, $apply, $actions);
        $this->inspectRelationships($partNames, $options, $apply, $actions);

        return new RepairReport($actions);
    }

    /**
     * @param list<RepairAction> $actions
     *
     * @return list<string>
     */
    private function inspectEntries(PackageRepairOptions $options, bool $apply, array &$actions): array
    {
        $partNames = [];

        foreach ($this->container->entries() as $entryName) {
            if ($entryName === '[Content_Types].xml') {
                continue;
            }

            $partName = '/' . $entryName;
            if (!PartName::isRelationshipsPart($partName)) {
                $partNames[] = $partName;

                continue;
            }

            $sourcePartName = PartName::relationshipSourceName($partName);
            if ($this->isSelectedOrphan($sourcePartName, $options)) {
                $actions[] = new RepairAction(
                    RepairAction::REMOVE_ORPHAN_RELATIONSHIP_PART,
                    $partName,
                    sprintf('Remove relationship part "%s" for missing source "%s".', $partName, $sourcePartName),
                );
                if ($apply) {
                    $this->container->remove($entryName);
                    $this->contentTypes->removeOverride($partName);
                    ($this->markChanged)();
                }

                continue;
            }

            if (
                $options->correctRelationshipContentTypes
                && $this->contentTypes->getForPart($partName) !== Relationships::CONTENT_TYPE
            ) {
                $actions[] = new RepairAction(
                    RepairAction::CORRECT_RELATIONSHIP_CONTENT_TYPE,
                    $partName,
                    sprintf('Register the OPC relationship content type for "%s".', $partName),
                );
                if ($apply) {
                    $this->contentTypes->removeOverride($partName);
                    $this->contentTypes->setDefault('rels', Relationships::CONTENT_TYPE);
                    ($this->markChanged)();
                }
            }
        }

        return $partNames;
    }

    private function isSelectedOrphan(?string $sourcePartName, PackageRepairOptions $options): bool
    {
        return $options->removeOrphanRelationshipParts
            && $sourcePartName !== null
            && !$this->hasPart($sourcePartName);
    }

    /** @param list<RepairAction> $actions */
    private function inspectContentTypeOverrides(
        PackageRepairOptions $options,
        bool $apply,
        array &$actions,
    ): void {
        if (!$options->removeStaleContentTypeOverrides) {
            return;
        }

        foreach ($this->contentTypes->getOverrides() as $partName => $_contentType) {
            if ($this->hasPart($partName)) {
                continue;
            }

            $actions[] = new RepairAction(
                RepairAction::REMOVE_STALE_CONTENT_TYPE_OVERRIDE,
                $partName,
                sprintf('Remove content type override for missing part "%s".', $partName),
            );
            if ($apply) {
                $this->contentTypes->removeOverride($partName);
                ($this->markChanged)();
            }
        }
    }

    /**
     * @param list<string>       $partNames
     * @param list<RepairAction> $actions
     */
    private function inspectRelationships(
        array $partNames,
        PackageRepairOptions $options,
        bool $apply,
        array &$actions,
    ): void {
        foreach ([null, ...$partNames] as $sourcePartName) {
            try {
                $relationships = ($this->getRelationships)($sourcePartName);
                if ($relationships === null) {
                    continue;
                }
            } catch (OpenXmlException) {
                continue;
            }

            $remove = [];
            foreach ($relationships as $relationship) {
                if ($relationship->isExternal()) {
                    continue;
                }

                try {
                    $targetPartName = (string) $relationship->getTargetPartName();
                } catch (OpenXmlException) {
                    if ($options->removeInvalidRelationships) {
                        $remove[] = $relationship->getId();
                        $actions[] = new RepairAction(
                            RepairAction::REMOVE_INVALID_RELATIONSHIP,
                            $relationship->getId(),
                            sprintf(
                                'Remove relationship "%s" from %s with invalid target "%s".',
                                $relationship->getId(),
                                $sourcePartName ?? 'the package',
                                $relationship->getTarget(),
                            ),
                        );
                    }

                    continue;
                }

                if ($options->removeDanglingRelationships && !$this->hasPart($targetPartName)) {
                    $remove[] = $relationship->getId();
                    $actions[] = new RepairAction(
                        RepairAction::REMOVE_DANGLING_RELATIONSHIP,
                        $relationship->getId(),
                        sprintf(
                            'Remove relationship "%s" from %s to missing part "%s".',
                            $relationship->getId(),
                            $sourcePartName ?? 'the package',
                            $targetPartName,
                        ),
                    );
                }
            }

            if ($apply) {
                foreach ($remove as $id) {
                    $relationships->remove($id);
                }
            }
        }
    }

    private function hasPart(string $name): bool
    {
        foreach ($this->container->entries() as $entryName) {
            if ($entryName !== '[Content_Types].xml' && PartName::equivalent('/' . $entryName, $name)) {
                return true;
            }
        }

        return false;
    }
}
