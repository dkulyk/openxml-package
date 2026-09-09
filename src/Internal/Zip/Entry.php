<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal\Zip;

/**
 * One central directory record.
 *
 * Sizes and CRC come from the central directory, never from the local header,
 * which carries zeros whenever the data descriptor flag is set.
 *
 * @internal
 */
final class Entry
{
    public function __construct(
        public readonly string $name,
        public readonly int $method,
        public readonly int $flags,
        public readonly int $crc,
        public readonly int $compressedSize,
        public readonly int $uncompressedSize,
        public readonly int $dosTime,
        public readonly int $dosDate,
        /** Absolute position in the file, with any prepended data accounted for. */
        public readonly int $localHeaderOffset,
    ) {}
}
