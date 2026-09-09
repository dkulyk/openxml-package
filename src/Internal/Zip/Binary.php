<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal\Zip;

use DK\OpenXml\Exception\OpenXmlException;

/**
 * Little-endian field decoding, shared by the directory reader and the writer.
 *
 * @internal
 */
final class Binary
{
    private function __construct() {}

    /**
     * @param resource $handle
     */
    public static function read($handle, int $length, int $offset): string
    {
        if ($length === 0) {
            return '';
        }
        $data = stream_get_contents($handle, $length, $offset);
        if ($data === false || strlen($data) !== $length) {
            throw self::corrupt('the file ends before a record it declares');
        }

        return $data;
    }

    public static function uint16(string $data, int $position): int
    {
        return self::integers(unpack('v1value', $data, $position))['value'];
    }

    public static function uint32(string $data, int $position): int
    {
        return self::integers(unpack('V1value', $data, $position))['value'];
    }

    public static function uint64(string $data, int $position): int
    {
        $value = self::integers(unpack('P1value', $data, $position))['value'];
        if ($value < 0) {
            throw new OpenXmlException('A ZIP64 value exceeds the largest integer this PHP build represents.');
        }

        return $value;
    }

    /**
     * `unpack()` is typed as returning anything; every format this package uses
     * yields integers, and a short input makes it fail outright.
     *
     * @param array<array-key, mixed>|false $record
     *
     * @return array<array-key, int>
     */
    public static function integers(array|false $record): array
    {
        if ($record === false) {
            throw self::corrupt('a record is truncated');
        }
        $values = array_filter($record, is_int(...));
        if (count($values) !== count($record)) {
            throw self::corrupt('a record is truncated');
        }

        return $values;
    }

    public static function corrupt(string $reason): OpenXmlException
    {
        return new OpenXmlException(sprintf('The ZIP archive is corrupt: %s.', $reason));
    }
}
