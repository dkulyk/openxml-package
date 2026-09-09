<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal\Zip;

/**
 * Where the central directory is and how many records it holds.
 *
 * @internal
 */
final class Eocd
{
    public function __construct(
        public readonly int $entryCount,
        /** Absolute position of the first record, with `shift` already applied. */
        public readonly int $directoryOffset,
        public readonly int $directorySize,
        /** Bytes prepended to the archive, added to every recorded offset. */
        public readonly int $shift,
        public readonly int $fileSize,
    ) {}
}
