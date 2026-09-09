<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal\Zip;

use DK\OpenXml\Exception\OpenXmlException;

/**
 * Writes one archive to a seekable stream.
 *
 * Entries carried from another archive keep their compressed bytes, method, CRC
 * and timestamp exactly. New entries are deflated or stored as asked, with sizes
 * patched back into the local header once they are known, so no entry ever needs
 * a trailing data descriptor.
 *
 * @internal
 */
final class ZipWriter
{
    private const LOCAL_SIGNATURE = "PK\x03\x04";
    private const RECORD_SIGNATURE = "PK\x01\x02";
    private const EOCD_SIGNATURE = "PK\x05\x06";
    private const ZIP64_EOCD_SIGNATURE = "PK\x06\x06";
    private const ZIP64_LOCATOR_SIGNATURE = "PK\x06\x07";
    private const ZIP64_EXTRA_ID = 0x0001;
    private const SENTINEL_16 = 0xFFFF;
    private const SENTINEL_32 = 0xFFFFFFFF;
    private const VERSION = 20;
    private const VERSION_ZIP64 = 45;
    private const VERSION_MADE_BY = 20;
    /** Names are always written as UTF-8, which flag bit 11 declares. */
    private const FLAG_UTF8 = 0x0800;
    /** Sizes follow the data instead of preceding it, which flag bit 3 declares. */
    private const FLAG_DATA_DESCRIPTOR = 0x0008;
    private const DESCRIPTOR_SIGNATURE = "PK\x07\x08";
    private const METHOD_STORE = 0;
    private const METHOD_DEFLATE = 8;
    private const LEVEL = 6;
    private const CHUNK = 65_536;

    /** @var list<array{name: string, method: int, flags: int, crc: int, compressedSize: int, uncompressedSize: int, dosTime: int, dosDate: int, offset: int}> */
    private array $written = [];

    /**
     * Counted rather than asked of the stream: a pipe has no position to tell,
     * and every byte of the archive goes through this writer anyway. It starts
     * where the stream already is, so a destination that already holds bytes
     * still gets offsets a reader can follow.
     *
     * @var int<0, max>
     */
    private int $position;

    /**
     * A stream that cannot seek back to a local header takes the sizes it could
     * not know in advance in a trailing descriptor instead.
     */
    private readonly bool $seekable;

    /** @param resource $handle */
    public function __construct(private $handle)
    {
        $this->seekable = stream_get_meta_data($handle)['seekable'];
        $position = ftell($handle);
        $this->position = $position === false ? 0 : max(0, $position);
    }

    /**
     * Copies entries that sit side by side in the source archive in one pass,
     * local headers included. Their names, flags and extra fields travel exactly
     * as they are, so only the directory has to be rebuilt.
     *
     * @param non-empty-list<Entry> $entries
     */
    public function addRawRun(array $entries, ZipReader $reader): void
    {
        $first = $entries[0];
        $last = $entries[count($entries) - 1];
        $start = $first->localHeaderOffset;
        $end = $reader->dataOffset($last) + $last->compressedSize;
        $shift = $this->position() - $start;

        foreach ($entries as $entry) {
            $this->assertRepresentable($entry->name, $entry->uncompressedSize, $entry->compressedSize);
        }
        $reader->copyRange($start, $end - $start, $this->handle, sprintf('entry "%s"', $first->name));
        $this->advanceBy($end - $start);

        foreach ($entries as $entry) {
            $this->record(
                $entry->name,
                $entry->method,
                $entry->crc,
                $entry->compressedSize,
                $entry->uncompressedSize,
                $entry->dosTime,
                $entry->dosDate,
                $entry->localHeaderOffset + $shift,
                $entry->flags,
            );
        }
    }

    /** Copies an entry from another archive without decoding it. */
    public function addRaw(string $name, Entry $entry, ZipReader $reader): void
    {
        $offset = $this->position();
        $this->assertRepresentable($name, $entry->uncompressedSize, $entry->compressedSize);
        $this->writeLocalHeader(
            $name,
            $entry->method,
            $entry->crc,
            $entry->compressedSize,
            $entry->uncompressedSize,
            $entry->dosTime,
            $entry->dosDate,
        );
        $reader->copyRawTo($entry, $this->handle);
        $this->advanceBy($entry->compressedSize);
        $this->record(
            $name,
            $entry->method,
            $entry->crc,
            $entry->compressedSize,
            $entry->uncompressedSize,
            $entry->dosTime,
            $entry->dosDate,
            $offset,
        );
    }

    public function addString(string $name, string $contents, bool $compress): void
    {
        $payload = $contents;
        $method = self::METHOD_STORE;
        if ($compress) {
            $deflated = gzdeflate($contents, self::LEVEL);
            if ($deflated === false) {
                throw new OpenXmlException(sprintf('Unable to compress ZIP entry "%s".', $name));
            }
            $payload = $deflated;
            $method = self::METHOD_DEFLATE;
        }

        $offset = $this->position();
        $this->assertRepresentable($name, strlen($contents), strlen($payload));
        [$dosTime, $dosDate] = Dos::stamp();
        $checksum = new Crc32();
        $checksum->update($contents);
        $crc = $checksum->value();
        $this->writeLocalHeader($name, $method, $crc, strlen($payload), strlen($contents), $dosTime, $dosDate);
        $this->write($payload, $name);
        $this->record($name, $method, $crc, strlen($payload), strlen($contents), $dosTime, $dosDate, $offset);
    }

    /**
     * @param resource $stream
     */
    public function addStream(string $name, $stream, bool $compress): void
    {
        $offset = $this->position();
        $method = $compress ? self::METHOD_DEFLATE : self::METHOD_STORE;
        [$dosTime, $dosDate] = Dos::stamp();
        // Sizes are unknown until the stream ends, so the header is either patched
        // below or, on a stream that cannot seek, followed by a descriptor.
        $flags = self::FLAG_UTF8 | ($this->seekable ? 0 : self::FLAG_DATA_DESCRIPTOR);
        $this->writeLocalHeader($name, $method, 0, 0, 0, $dosTime, $dosDate, $flags);

        $checksum = new Crc32();
        $deflate = null;
        if ($compress) {
            $deflate = deflate_init(ZLIB_ENCODING_RAW, ['level' => self::LEVEL]);
            if ($deflate === false) {
                throw new OpenXmlException(sprintf('Unable to compress ZIP entry "%s".', $name));
            }
        }
        $uncompressedSize = 0;
        $compressedSize = 0;

        while (!feof($stream)) {
            $chunk = fread($stream, self::CHUNK);
            if ($chunk === false) {
                throw new OpenXmlException(sprintf('Unable to read contents for ZIP entry "%s".', $name));
            }
            if ($chunk === '') {
                break;
            }
            $uncompressedSize += strlen($chunk);
            $checksum->update($chunk);
            $compressedSize += $this->write(
                $deflate === null ? $chunk : self::deflated($deflate, $chunk, ZLIB_NO_FLUSH, $name),
                $name,
            );
        }
        if ($deflate !== null) {
            $compressedSize += $this->write(self::deflated($deflate, '', ZLIB_FINISH, $name), $name);
        }

        $crc = $checksum->value();
        // Both sizes are known to fit, so the descriptor never needs its ZIP64 form.
        $this->assertRepresentable($name, $uncompressedSize, $compressedSize);
        if ($this->seekable) {
            $this->patchLocalHeader($offset, $crc, $compressedSize, $uncompressedSize, $name);
        } else {
            $this->write(
                self::DESCRIPTOR_SIGNATURE . pack('VVV', $crc, $compressedSize, $uncompressedSize),
                $name,
            );
        }
        $this->record($name, $method, $crc, $compressedSize, $uncompressedSize, $dosTime, $dosDate, $offset, $flags);
    }

    public function finish(): void
    {
        $directoryOffset = $this->position();
        $directory = '';
        foreach ($this->written as $entry) {
            $zip64 = $entry['offset'] >= self::SENTINEL_32;
            $extra = $zip64 ? pack('vv', self::ZIP64_EXTRA_ID, 8) . pack('P', $entry['offset']) : '';
            $directory .= self::RECORD_SIGNATURE . pack(
                'vvvvvvVVVvvvvvVV',
                self::VERSION_MADE_BY,
                $zip64 ? self::VERSION_ZIP64 : self::VERSION,
                $entry['flags'],
                $entry['method'],
                $entry['dosTime'],
                $entry['dosDate'],
                $entry['crc'],
                $entry['compressedSize'],
                $entry['uncompressedSize'],
                strlen($entry['name']),
                strlen($extra),
                0,
                0,
                0,
                0,
                $zip64 ? self::SENTINEL_32 : $entry['offset'],
            ) . $entry['name'] . $extra;
        }
        $this->write($directory, 'central directory');

        $count = count($this->written);
        $directorySize = strlen($directory);
        // 0xFFFF in the record is how a reader is told to look for ZIP64, so an
        // archive that really holds that many entries must carry one.
        $zip64 = $count >= self::SENTINEL_16
            || $directoryOffset >= self::SENTINEL_32
            || $directorySize >= self::SENTINEL_32;

        if ($zip64) {
            $recordOffset = $this->position();
            $this->write(self::ZIP64_EOCD_SIGNATURE . pack('P', 44) . pack(
                'vvVV',
                self::VERSION_MADE_BY,
                self::VERSION_ZIP64,
                0,
                0,
            ) . pack('PPPP', $count, $count, $directorySize, $directoryOffset), 'central directory');
            $this->write(
                self::ZIP64_LOCATOR_SIGNATURE . pack('V', 0) . pack('P', $recordOffset) . pack('V', 1),
                'central directory',
            );
        }

        $this->write(self::EOCD_SIGNATURE . pack(
            'vvvvVVv',
            0,
            0,
            $zip64 ? self::SENTINEL_16 : $count,
            $zip64 ? self::SENTINEL_16 : $count,
            $zip64 ? self::SENTINEL_32 : $directorySize,
            $zip64 ? self::SENTINEL_32 : $directoryOffset,
            0,
        ), 'central directory');

        // Anything the destination already held past this point would sit after
        // the central directory, where a reader reports a damaged archive.
        $stat = $this->seekable ? @fstat($this->handle) : false;
        if (is_array($stat) && $stat['size'] > $this->position && !@ftruncate($this->handle, $this->position)) {
            throw new OpenXmlException('Unable to cut the destination back to the end of the package.');
        }
    }

    private static function deflated(\DeflateContext $context, string $chunk, int $flush, string $name): string
    {
        $deflated = deflate_add($context, $chunk, $flush);
        if ($deflated === false) {
            throw new OpenXmlException(sprintf('Unable to compress ZIP entry "%s".', $name));
        }

        return $deflated;
    }

    private function writeLocalHeader(
        string $name,
        int $method,
        int $crc,
        int $compressedSize,
        int $uncompressedSize,
        int $dosTime,
        int $dosDate,
        int $flags = self::FLAG_UTF8,
    ): void {
        $this->write(self::LOCAL_SIGNATURE . pack(
            'vvvvvVVVvv',
            self::VERSION,
            $flags,
            $method,
            $dosTime,
            $dosDate,
            $crc,
            $compressedSize,
            $uncompressedSize,
            strlen($name),
            0,
        ) . $name, $name);
    }

    private function patchLocalHeader(
        int $offset,
        int $crc,
        int $compressedSize,
        int $uncompressedSize,
        string $name,
    ): void {
        $end = $this->position();
        // The CRC and both sizes sit 14 bytes into the local header. A stream that
        // reported itself seekable and then refuses to seek is the one way this
        // fails; a custom wrapper that cannot seek should say so in its metadata.
        if (fseek($this->handle, $offset + 14) !== 0) {
            throw new OpenXmlException(sprintf(
                'Unable to seek back to the header of ZIP entry "%s" in a stream that reported itself seekable.',
                $name,
            ));
        }
        $this->write(pack('VVV', $crc, $compressedSize, $uncompressedSize), $name);
        if (fseek($this->handle, $end) !== 0) {
            throw new OpenXmlException(sprintf('Unable to finish ZIP entry "%s".', $name));
        }
        // This header was overwritten, not appended to the archive.
        $this->position = $end;
    }

    private function record(
        string $name,
        int $method,
        int $crc,
        int $compressedSize,
        int $uncompressedSize,
        int $dosTime,
        int $dosDate,
        int $offset,
        int $flags = self::FLAG_UTF8,
    ): void {
        $this->written[] = [
            'name' => $name,
            'method' => $method,
            'flags' => $flags,
            'crc' => $crc,
            'compressedSize' => $compressedSize,
            'uncompressedSize' => $uncompressedSize,
            'dosTime' => $dosTime,
            'dosDate' => $dosDate,
            'offset' => $offset,
        ];
    }

    private function assertRepresentable(string $name, int $uncompressedSize, int $compressedSize): void
    {
        if ($uncompressedSize >= self::SENTINEL_32 || $compressedSize >= self::SENTINEL_32) {
            throw new OpenXmlException(sprintf(
                'ZIP entry "%s" is 4 GiB or larger, which this writer does not produce.',
                $name,
            ));
        }
    }

    /**
     * A pipe or socket accepts as much as fits and reports how much that was, so
     * the remainder is offered again until it is taken. A write that reports no
     * progress at all ends the archive with an exception rather than a warning.
     */
    private function write(string $bytes, string $subject): int
    {
        $length = strlen($bytes);
        $offset = 0;
        while ($offset < $length) {
            $written = @fwrite($this->handle, $offset === 0 ? $bytes : substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new OpenXmlException(sprintf('Unable to write ZIP entry "%s".', $subject));
            }
            $offset += $written;
        }
        $this->position += $length;

        return $length;
    }

    /**
     * Account for bytes the reader copied into the handle itself, which never
     * passed through write(). A ZIP length is never negative; the archive would
     * already be unreadable if one were.
     */
    private function advanceBy(int $length): void
    {
        $this->position += max(0, $length);
    }

    /** @return int<0, max> */
    private function position(): int
    {
        return $this->position;
    }
}
