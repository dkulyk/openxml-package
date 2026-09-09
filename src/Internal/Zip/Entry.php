<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal\Zip;

use DK\OpenXml\Exception\OpenXmlException;

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

    /**
     * Refuses a name that would escape the package it is read into or written
     * from: an absolute path, a traversal, a backslash a host may read as a
     * separator, or an empty segment.
     */
    public static function assertSafeName(string $name): void
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
}
