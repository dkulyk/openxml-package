<?php

declare(strict_types=1);

namespace DK\OpenXml\Tests\Support;

/**
 * Builds ZIP archives byte by byte, including shapes no PHP writer produces:
 * archive comments, prepended data, data descriptors and ZIP64 records.
 */
final class ZipFixture
{
    private const LOCAL_SIGNATURE = "PK\x03\x04";
    private const RECORD_SIGNATURE = "PK\x01\x02";
    private const EOCD_SIGNATURE = "PK\x05\x06";
    private const ZIP64_EOCD_SIGNATURE = "PK\x06\x06";
    private const ZIP64_LOCATOR_SIGNATURE = "PK\x06\x07";
    private const SENTINEL_32 = 0xFFFFFFFF;
    private const DOS_TIME = 0x6000;
    private const DOS_DATE = 0x2101;

    /** @var list<array{name: string, data: string, method: int, flags: int, localExtra: string, zip64: bool}> */
    private array $entries = [];

    private string $comment = '';

    private string $prefix = '';

    private bool $zip64 = false;

    private ?int $declaredEntryCount = null;

    public function add(
        string $name,
        string $data = 'contents',
        int $method = 8,
        int $flags = 0,
        string $localExtra = '',
        bool $zip64 = false,
    ): self {
        $this->entries[] = [
            'name' => $name,
            'data' => $data,
            'method' => $method,
            'flags' => $flags,
            'localExtra' => $localExtra,
            'zip64' => $zip64,
        ];

        return $this;
    }

    public function withComment(string $comment): self
    {
        $this->comment = $comment;

        return $this;
    }

    /** Bytes written in front of the archive, leaving every recorded offset short by their length. */
    public function withPrefix(string $prefix): self
    {
        $this->prefix = $prefix;

        return $this;
    }

    public function withZip64(): self
    {
        $this->zip64 = true;

        return $this;
    }

    public function withDeclaredEntryCount(int $count): self
    {
        $this->declaredEntryCount = $count;

        return $this;
    }

    public function build(): string
    {
        $body = '';
        $directory = '';
        foreach ($this->entries as $entry) {
            $payload = $entry['data'];
            if ($entry['method'] === 8) {
                $deflated = gzdeflate($entry['data'], 6);
                if ($deflated === false) {
                    throw new \RuntimeException('Unable to deflate fixture contents.');
                }
                $payload = $deflated;
            }
            $crc = crc32($entry['data']);
            $compressedSize = strlen($payload);
            $uncompressedSize = strlen($entry['data']);
            $offset = strlen($body);
            $hasDescriptor = ($entry['flags'] & 0x0008) !== 0;

            $body .= self::LOCAL_SIGNATURE . pack(
                'vvvvvVVVvv',
                20,
                $entry['flags'],
                $entry['method'],
                self::DOS_TIME,
                self::DOS_DATE,
                $hasDescriptor ? 0 : $crc,
                $hasDescriptor ? 0 : $compressedSize,
                $hasDescriptor ? 0 : $uncompressedSize,
                strlen($entry['name']),
                strlen($entry['localExtra']),
            ) . $entry['name'] . $entry['localExtra'] . $payload;
            if ($hasDescriptor) {
                $body .= "PK\x07\x08" . pack('VVV', $crc, $compressedSize, $uncompressedSize);
            }

            $extra = '';
            if ($entry['zip64']) {
                $extra = pack('vv', 0x0001, 24) . pack('PPP', $uncompressedSize, $compressedSize, $offset);
                $compressedSize = self::SENTINEL_32;
                $uncompressedSize = self::SENTINEL_32;
                $offset = self::SENTINEL_32;
            }

            $directory .= self::RECORD_SIGNATURE . pack(
                'vvvvvvVVVvvvvvVV',
                20,
                20,
                $entry['flags'],
                $entry['method'],
                self::DOS_TIME,
                self::DOS_DATE,
                $crc,
                $compressedSize,
                $uncompressedSize,
                strlen($entry['name']),
                strlen($extra),
                0,
                0,
                0,
                0,
                $offset,
            ) . $entry['name'] . $extra;
        }

        $directoryOffset = strlen($body);
        $count = $this->declaredEntryCount ?? count($this->entries);
        $archive = $body . $directory;

        if ($this->zip64) {
            $recordOffset = strlen($archive);
            $archive .= self::ZIP64_EOCD_SIGNATURE . pack('P', 44) . pack('vvVV', 45, 45, 0, 0)
                . pack('PPPP', $count, $count, strlen($directory), $directoryOffset);
            $archive .= self::ZIP64_LOCATOR_SIGNATURE . pack('V', 0) . pack('P', $recordOffset) . pack('V', 1);
            $archive .= self::EOCD_SIGNATURE . pack(
                'vvvvVVv',
                0,
                0,
                0xFFFF,
                0xFFFF,
                self::SENTINEL_32,
                self::SENTINEL_32,
                strlen($this->comment),
            ) . $this->comment;

            return $this->prefix . $archive;
        }

        $archive .= self::EOCD_SIGNATURE . pack(
            'vvvvVVv',
            0,
            0,
            $count,
            $count,
            strlen($directory),
            $directoryOffset,
            strlen($this->comment),
        ) . $this->comment;

        return $this->prefix . $archive;
    }

    public function writeTo(string $filename): string
    {
        file_put_contents($filename, $this->build());

        return $filename;
    }

    /** Replaces the signature of one central directory record, leaving the rest intact. */
    public static function breakRecord(string $archive, int $index): string
    {
        $position = -1;
        for ($found = 0; $found <= $index; ++$found) {
            $position = strpos($archive, self::RECORD_SIGNATURE, $position + 1);
            if ($position === false) {
                throw new \RuntimeException('The fixture has fewer records than requested.');
            }
        }

        return substr_replace($archive, "PK\x01\x03", $position, 4);
    }
}
