<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal\Zip;

use DK\OpenXml\Exception\OpenXmlException;

/**
 * Reads an archive's raw bytes. Sizes and CRC always come from the central
 * directory; the local header is consulted only for where an entry's data
 * begins, because its own name and extra fields may differ in length.
 *
 * @internal
 */
final class ZipReader
{
    private const LOCAL_SIGNATURE = "PK\x03\x04";
    private const LOCAL_HEADER_LENGTH = 30;
    private const CHUNK = 65_536;

    /** @var resource|null */
    private $handle;

    /** @var array<string, int> */
    private array $dataOffsets = [];

    public function __construct(private readonly string $filename) {}

    public function __destruct()
    {
        $this->close();
    }

    /** Where an entry's compressed bytes start, read from its local header. */
    public function dataOffset(Entry $entry): int
    {
        if (isset($this->dataOffsets[$entry->name])) {
            return $this->dataOffsets[$entry->name];
        }

        $header = Binary::read($this->handle(), self::LOCAL_HEADER_LENGTH, $entry->localHeaderOffset);
        if (substr($header, 0, 4) !== self::LOCAL_SIGNATURE) {
            throw Binary::corrupt(sprintf('entry "%s" has no local header', $entry->name));
        }
        $nameLength = Binary::uint16($header, 26);
        $extraLength = Binary::uint16($header, 28);
        $offset = $entry->localHeaderOffset + self::LOCAL_HEADER_LENGTH + $nameLength + $extraLength;

        return $this->dataOffsets[$entry->name] = $offset;
    }

    /**
     * Copies an entry's compressed bytes as they stand, with no decoding.
     *
     * @param resource $destination
     */
    public function copyRawTo(Entry $entry, $destination): void
    {
        $this->copyRange($this->dataOffset($entry), $entry->compressedSize, $destination, $entry->name);
    }

    /**
     * Copies a span of the archive verbatim: entries that sit side by side move
     * in one pass instead of one pass each.
     *
     * @param resource $destination
     */
    public function copyRange(int $offset, int $length, $destination, string $subject): void
    {
        $handle = $this->handle();
        if (fseek($handle, $offset) !== 0) {
            throw Binary::corrupt(sprintf('%s starts past the end of the file', $subject));
        }

        $remaining = $length;
        while ($remaining > 0) {
            $copied = stream_copy_to_stream($handle, $destination, min(self::CHUNK, $remaining));
            if ($copied === false || $copied === 0) {
                throw Binary::corrupt(sprintf('%s is shorter than the directory declares', $subject));
            }
            $remaining -= $copied;
        }
    }

    public function close(): void
    {
        if ($this->handle === null) {
            return;
        }

        $handle = $this->handle;
        $this->handle = null;
        fclose($handle);
    }

    /** @return resource */
    private function handle()
    {
        if ($this->handle !== null) {
            return $this->handle;
        }

        $handle = fopen($this->filename, 'rb');
        if ($handle === false) {
            throw new OpenXmlException(sprintf('Unable to reopen package "%s".', $this->filename));
        }

        return $this->handle = $handle;
    }
}
