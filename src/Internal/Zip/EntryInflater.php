<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal\Zip;

use DK\OpenXml\Exception\OpenXmlException;
use DK\OpenXml\Exception\PackageLimitException;

/**
 * Decodes one entry, a chunk at a time.
 *
 * The size the central directory declares is enforced while the entry is being
 * decoded, not after, so a directory that understates an entry costs a reader one
 * buffer rather than the whole expansion. The CRC and both lengths are checked
 * once the entry ends.
 *
 * @internal
 */
final class EntryInflater
{
    private const CHUNK = 65_536;
    private const METHOD_STORE = 0;
    private const METHOD_DEFLATE = 8;

    private ?\InflateContext $inflate = null;

    private \HashContext $checksum;

    private int $consumed = 0;

    private int $produced = 0;

    private bool $finished = false;

    private int $status = ZLIB_OK;

    private int $readLength = 0;

    public function __construct(
        private readonly ZipReader $reader,
        private readonly Entry $entry,
        private readonly string $reportedName,
    ) {
        if ($entry->method !== self::METHOD_STORE && $entry->method !== self::METHOD_DEFLATE) {
            throw new OpenXmlException(sprintf(
                'ZIP entry "%s" uses unsupported compression method %d.',
                $reportedName,
                $entry->method,
            ));
        }
        $this->checksum = hash_init('crc32b');
        if ($entry->method === self::METHOD_DEFLATE) {
            $inflate = inflate_init(ZLIB_ENCODING_RAW);
            if ($inflate === false) {
                throw new OpenXmlException(sprintf('Unable to decompress ZIP entry "%s".', $reportedName));
            }
            $this->inflate = $inflate;
        }
    }

    public function finished(): bool
    {
        return $this->finished;
    }

    public function size(): int
    {
        return $this->entry->uncompressedSize;
    }

    /** The next slice of decoded bytes, empty once the entry has been read out. */
    public function next(): string
    {
        if ($this->finished) {
            return '';
        }

        $decoded = '';
        $remaining = $this->entry->compressedSize - $this->consumed;
        if ($remaining > 0) {
            $length = min(self::CHUNK, $remaining);
            $raw = $this->reader->readRaw($this->entry, $this->consumed, $length);
            $this->consumed += $length;
            $decoded = $this->inflate === null ? $raw : $this->inflated($this->inflate, $raw, ZLIB_NO_FLUSH);
        }

        if ($this->consumed >= $this->entry->compressedSize) {
            if ($this->inflate !== null) {
                // A stream that already ended must not be finished again: zlib
                // reports a buffer error for it and forgets how much it read.
                if (inflate_get_status($this->inflate) !== ZLIB_STREAM_END) {
                    $decoded .= $this->inflated($this->inflate, '', ZLIB_FINISH);
                }
                $this->status = inflate_get_status($this->inflate);
                $this->readLength = inflate_get_read_len($this->inflate);
            }
            $this->finished = true;
        }

        $this->accept($decoded);
        if ($this->finished) {
            $this->verify();
        }

        return $decoded;
    }

    private function accept(string $decoded): void
    {
        if ($decoded === '') {
            return;
        }
        $this->produced += strlen($decoded);
        if ($this->produced > $this->entry->uncompressedSize) {
            throw new PackageLimitException(sprintf(
                'Part "%s" expands beyond the %d bytes its ZIP directory declares.',
                $this->reportedName,
                $this->entry->uncompressedSize,
            ));
        }
        hash_update($this->checksum, $decoded);
    }

    private function verify(): void
    {
        if ($this->produced !== $this->entry->uncompressedSize) {
            throw $this->corrupt('it is shorter than its directory declares');
        }
        if ($this->inflate !== null) {
            if ($this->status !== ZLIB_STREAM_END) {
                throw $this->corrupt('its compressed data ends early');
            }
            if ($this->readLength !== $this->consumed) {
                throw $this->corrupt('it carries data past the end of its compressed stream');
            }
        }
        $crc = Binary::integers(unpack('N1crc', hash_final($this->checksum, true)))['crc'];
        if ($crc !== $this->entry->crc) {
            throw $this->corrupt('its checksum does not match its directory');
        }
    }

    private function inflated(\InflateContext $context, string $raw, int $flush): string
    {
        $decoded = @inflate_add($context, $raw, $flush);
        if ($decoded === false) {
            throw $this->corrupt('its compressed data cannot be decoded');
        }

        return $decoded;
    }

    private function corrupt(string $reason): OpenXmlException
    {
        return new OpenXmlException(sprintf('ZIP entry "%s" is corrupt: %s.', $this->reportedName, $reason));
    }
}
