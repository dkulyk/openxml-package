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
 * buffer rather than the whole expansion.
 *
 * A deflated entry is handed to zlib as a gzip stream. gzip is the same raw
 * deflate data between a fixed ten-byte header and a trailer holding the CRC-32
 * and the length of the contents, which is exactly what the ZIP directory already
 * told us, so zlib checks both itself while it decodes and the decoded bytes never
 * make a second pass for a checksum. A stored entry has no deflate stream to wrap
 * and is checksummed directly.
 *
 * @internal
 */
final class EntryInflater
{
    public const CHUNK = 65_536;
    private const METHOD_STORE = 0;
    private const METHOD_DEFLATE = 8;

    /** Deflate, no flags, no timestamp, unknown operating system. */
    private const GZIP_HEADER = "\x1f\x8b\x08\x00\x00\x00\x00\x00\x00\xff";
    private const GZIP_HEADER_LENGTH = 10;

    private ?\InflateContext $inflate = null;

    private ?Crc32 $checksum = null;

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
        if ($entry->method !== self::METHOD_DEFLATE) {
            $this->checksum = new Crc32();

            return;
        }
        $inflate = inflate_init(ZLIB_ENCODING_GZIP);
        if ($inflate === false) {
            throw new OpenXmlException(sprintf('Unable to decompress ZIP entry "%s".', $reportedName));
        }
        $this->inflate = $inflate;
        $this->inflated($inflate, self::GZIP_HEADER, ZLIB_NO_FLUSH);
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

        $this->accept($decoded);
        if ($this->consumed >= $this->entry->compressedSize) {
            if ($this->inflate !== null) {
                $this->closeGzip($this->inflate);
            }
            $this->finished = true;
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
        $this->checksum?->update($decoded);
    }

    /**
     * Hand zlib the trailer the ZIP directory describes and let it judge the entry.
     *
     * The trailer goes in a call of its own, after every compressed byte has been
     * accepted, so a rejection here can only be the check itself failing.
     */
    private function closeGzip(\InflateContext $context): void
    {
        $this->readLength = inflate_get_read_len($context) - self::GZIP_HEADER_LENGTH;
        if ($this->readLength !== $this->consumed) {
            throw $this->corrupt('it carries data past the end of its compressed stream');
        }
        if ($this->produced !== $this->entry->uncompressedSize) {
            // zlib would reject the trailer for this too, but the directory told us
            // the length and a reader deserves to hear which of the two is wrong.
            throw $this->corrupt('it is shorter than its directory declares');
        }

        $trailer = pack('V', $this->entry->crc) . pack('V', $this->entry->uncompressedSize & 0xFFFFFFFF);
        if (@inflate_add($context, $trailer, ZLIB_NO_FLUSH) === false) {
            throw $this->corrupt('its checksum does not match its directory');
        }
        $this->status = inflate_get_status($context);
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

            return;
        }
        if ($this->checksum?->value() !== $this->entry->crc) {
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
