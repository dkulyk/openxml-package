<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal\Zip;

use DK\OpenXml\Exception\OpenXmlException;
use DK\OpenXml\Exception\PackageLimitException;
use DK\OpenXml\Security\PackageLimits;

/**
 * Reads a ZIP central directory without ext-zip.
 *
 * Nothing the archive declares is trusted before it is checked against the file
 * size, and the directory is walked lazily so callers that want one entry do not
 * pay for the rest.
 *
 * @internal
 */
final class CentralDirectory
{
    public const CONTENT_TYPES = '[Content_Types].xml';

    private const EOCD_SIGNATURE = "PK\x05\x06";
    private const EOCD_LENGTH = 22;
    private const ZIP64_LOCATOR_SIGNATURE = "PK\x06\x07";
    private const ZIP64_LOCATOR_LENGTH = 20;
    private const ZIP64_EOCD_SIGNATURE = "PK\x06\x06";
    private const ZIP64_EOCD_LENGTH = 56;
    private const MAXIMUM_COMMENT = 0xFFFF;
    private const RECORD_SIGNATURE = 0x02014B50;
    private const RECORD_LENGTH = 46;
    private const LOCAL_HEADER_LENGTH = 30;
    private const SENTINEL_16 = 0xFFFF;
    private const SENTINEL_32 = 0xFFFFFFFF;
    private const ZIP64_EXTRA_ID = 0x0001;
    private const FLAG_ENCRYPTED = 0x0001;
    private const METHOD_AES = 99;

    /** Wider than the largest possible record: 46 + 3 * 0xFFFF. */
    private const WINDOW = 262_144;

    private function __construct() {}

    /**
     * @param resource $handle
     */
    public static function locate($handle, int $fileSize, PackageLimits $limits): Eocd
    {
        if ($fileSize < self::EOCD_LENGTH) {
            throw self::corrupt('the file is too short to hold a directory');
        }

        $tailLength = min($fileSize, self::EOCD_LENGTH + self::MAXIMUM_COMMENT);
        $tail = Binary::read($handle, $tailLength, $fileSize - $tailLength);

        $position = null;
        for ($candidate = $tailLength - self::EOCD_LENGTH; $candidate >= 0; --$candidate) {
            if (substr($tail, $candidate, 4) !== self::EOCD_SIGNATURE) {
                continue;
            }
            // A comment that runs to the last byte tells the record apart from
            // the same four bytes appearing inside a comment.
            if ($candidate + self::EOCD_LENGTH + Binary::uint16($tail, $candidate + 20) === $tailLength) {
                $position = $candidate;

                break;
            }
        }
        if ($position === null) {
            throw self::corrupt('no end of central directory record was found');
        }

        $recordOffset = $fileSize - $tailLength + $position;
        $disk = Binary::uint16($tail, $position + 4);
        $directoryDisk = Binary::uint16($tail, $position + 6);
        $entryCount = Binary::uint16($tail, $position + 10);
        $directorySize = Binary::uint32($tail, $position + 12);
        $directoryOffset = Binary::uint32($tail, $position + 16);
        $directoryEnd = $recordOffset;

        if (
            $disk === self::SENTINEL_16
            || $directoryDisk === self::SENTINEL_16
            || $entryCount === self::SENTINEL_16
            || $directorySize === self::SENTINEL_32
            || $directoryOffset === self::SENTINEL_32
            || self::hasZip64Locator($handle, $recordOffset)
        ) {
            [$disk, $directoryDisk, $entryCount, $directorySize, $directoryOffset, $directoryEnd]
                = self::readZip64($handle, $recordOffset, $fileSize);
        }

        if ($disk !== 0 || $directoryDisk !== 0) {
            throw new OpenXmlException('Split ZIP archives are not supported.');
        }
        if ($entryCount > $limits->maximumEntries) {
            throw new PackageLimitException(sprintf(
                'Package contains %d entries; the configured maximum is %d.',
                $entryCount,
                $limits->maximumEntries,
            ));
        }
        if ($directorySize > $directoryEnd || $entryCount > intdiv($directorySize, self::RECORD_LENGTH)) {
            throw self::corrupt('the directory size does not match the number of entries');
        }

        // Self-extractors and signed installers prepend data, which shifts every
        // recorded offset by the same amount.
        $shift = $directoryEnd - $directorySize - $directoryOffset;
        if ($shift < 0) {
            throw self::corrupt('the directory starts after its own end');
        }

        return new Eocd($entryCount, $directoryOffset + $shift, $directorySize, $shift, $fileSize);
    }

    /**
     * @param resource $handle
     *
     * @return \Generator<int, Entry>
     */
    public static function scan($handle, Eocd $eocd, PackageLimits $limits): \Generator
    {
        $buffer = '';
        $position = 0;
        $cursor = $eocd->directoryOffset;
        $remaining = $eocd->directorySize;

        for ($index = 0; $index < $eocd->entryCount; ++$index) {
            if (strlen($buffer) - $position < self::WINDOW && $remaining > 0) {
                $buffer = substr($buffer, $position);
                $position = 0;
                $wanted = min(self::WINDOW, $remaining);
                // Absolute offsets: a caller may move the handle while this
                // generator is suspended between entries.
                $buffer .= Binary::read($handle, $wanted, $cursor);
                $cursor += $wanted;
                $remaining -= $wanted;
            }

            $available = strlen($buffer) - $position;
            if ($available < self::RECORD_LENGTH) {
                throw self::corrupt('the directory ends inside a record');
            }
            $record = Binary::integers(unpack(
                'Vsignature/x4/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed'
                    . '/vnameLength/vextraLength/vcommentLength/x8/Voffset',
                $buffer,
                $position,
            ));
            if ($record['signature'] !== self::RECORD_SIGNATURE) {
                throw self::corrupt('a record has a bad signature');
            }
            $variableLength = $record['nameLength'] + $record['extraLength'] + $record['commentLength'];
            if ($available < self::RECORD_LENGTH + $variableLength) {
                throw self::corrupt('the directory ends inside a record');
            }
            $name = substr($buffer, $position + self::RECORD_LENGTH, $record['nameLength']);
            $extra = substr(
                $buffer,
                $position + self::RECORD_LENGTH + $record['nameLength'],
                $record['extraLength'],
            );
            $position += self::RECORD_LENGTH + $variableLength;

            if (str_ends_with($name, '/')) {
                continue;
            }

            [$uncompressedSize, $compressedSize, $localHeaderOffset] = self::resolveZip64(
                $extra,
                $record['uncompressed'],
                $record['compressed'],
                $record['offset'],
            );

            EntryName::assertSafe($name);
            if (($record['flags'] & self::FLAG_ENCRYPTED) !== 0 || $record['method'] === self::METHOD_AES) {
                throw new OpenXmlException(sprintf('ZIP entry "%s" is encrypted.', $name));
            }
            self::assertWithinLimits($name, $uncompressedSize, $compressedSize, $limits);

            $localHeaderOffset += $eocd->shift;
            if (
                $localHeaderOffset < 0
                || $localHeaderOffset + self::LOCAL_HEADER_LENGTH + $compressedSize > $eocd->fileSize
            ) {
                throw self::corrupt(sprintf('entry "%s" points outside the file', $name));
            }

            yield new Entry(
                $name,
                $record['method'],
                $record['flags'],
                $record['crc'],
                $compressedSize,
                $uncompressedSize,
                $record['time'],
                $record['date'],
                $localHeaderOffset,
            );
        }
    }

    public static function assertWithinLimits(
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

    /**
     * @return array{int, int, int} Uncompressed size, compressed size, local header offset.
     */
    private static function resolveZip64(
        string $extra,
        int $uncompressedSize,
        int $compressedSize,
        int $localHeaderOffset,
    ): array {
        if (
            $uncompressedSize !== self::SENTINEL_32
            && $compressedSize !== self::SENTINEL_32
            && $localHeaderOffset !== self::SENTINEL_32
        ) {
            return [$uncompressedSize, $compressedSize, $localHeaderOffset];
        }

        $length = strlen($extra);
        $position = 0;
        while ($position + 4 <= $length) {
            $identifier = Binary::uint16($extra, $position);
            $size = Binary::uint16($extra, $position + 2);
            $position += 4;
            if ($position + $size > $length) {
                break;
            }
            if ($identifier !== self::ZIP64_EXTRA_ID) {
                $position += $size;

                continue;
            }

            // Each field is present only when its 32-bit counterpart is a sentinel.
            $end = $position + $size;
            if ($uncompressedSize === self::SENTINEL_32) {
                $uncompressedSize = Binary::uint64($extra, self::zip64Field($position, $end));
                $position += 8;
            }
            if ($compressedSize === self::SENTINEL_32) {
                $compressedSize = Binary::uint64($extra, self::zip64Field($position, $end));
                $position += 8;
            }
            if ($localHeaderOffset === self::SENTINEL_32) {
                $localHeaderOffset = Binary::uint64($extra, self::zip64Field($position, $end));
                $position += 8;
            }

            return [$uncompressedSize, $compressedSize, $localHeaderOffset];
        }

        throw self::corrupt('a ZIP64 entry has no ZIP64 extra field');
    }

    private static function zip64Field(int $position, int $end): int
    {
        if ($position + 8 > $end) {
            throw self::corrupt('a ZIP64 extra field is too short');
        }

        return $position;
    }

    /**
     * @param resource $handle
     */
    private static function hasZip64Locator($handle, int $recordOffset): bool
    {
        if ($recordOffset < self::ZIP64_LOCATOR_LENGTH) {
            return false;
        }

        return Binary::read($handle, 4, $recordOffset - self::ZIP64_LOCATOR_LENGTH)
            === self::ZIP64_LOCATOR_SIGNATURE;
    }

    /**
     * @param resource $handle
     *
     * @return array{int, int, int, int, int, int} Disk, directory disk, entries, size, offset, directory end.
     */
    private static function readZip64($handle, int $recordOffset, int $fileSize): array
    {
        if ($recordOffset < self::ZIP64_LOCATOR_LENGTH) {
            throw self::corrupt('a ZIP64 archive has no locator');
        }
        $locator = Binary::read($handle, self::ZIP64_LOCATOR_LENGTH, $recordOffset - self::ZIP64_LOCATOR_LENGTH);
        if (substr($locator, 0, 4) !== self::ZIP64_LOCATOR_SIGNATURE) {
            throw self::corrupt('a ZIP64 archive has no locator');
        }

        $zip64Offset = Binary::uint64($locator, 8);
        $record = null;
        if ($zip64Offset + self::ZIP64_EOCD_LENGTH <= $fileSize) {
            $candidate = Binary::read($handle, self::ZIP64_EOCD_LENGTH, $zip64Offset);
            if (substr($candidate, 0, 4) === self::ZIP64_EOCD_SIGNATURE) {
                $record = $candidate;
            }
        }
        if ($record === null) {
            // Prepended data shifts the recorded offset; the record always sits
            // immediately before its own locator.
            $zip64Offset = $recordOffset - self::ZIP64_LOCATOR_LENGTH - self::ZIP64_EOCD_LENGTH;
            if ($zip64Offset < 0) {
                throw self::corrupt('the ZIP64 end of central directory record is missing');
            }
            $record = Binary::read($handle, self::ZIP64_EOCD_LENGTH, $zip64Offset);
            if (substr($record, 0, 4) !== self::ZIP64_EOCD_SIGNATURE) {
                throw self::corrupt('the ZIP64 end of central directory record is missing');
            }
        }

        return [
            Binary::uint32($record, 16),
            Binary::uint32($record, 20),
            Binary::uint64($record, 32),
            Binary::uint64($record, 40),
            Binary::uint64($record, 48),
            $zip64Offset,
        ];
    }

    private static function corrupt(string $reason): OpenXmlException
    {
        return Binary::corrupt($reason);
    }
}
