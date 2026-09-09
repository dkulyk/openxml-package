<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal\Zip;

use DK\OpenXml\Exception\OpenXmlException;

/**
 * The CRC-32 a ZIP entry carries, accumulated a chunk at a time.
 *
 * PHP computes this checksum a byte at a time, through `crc32()` and through
 * `hash('crc32b')` alike, at around 550 MiB/s. zlib computes the same checksum
 * with whatever the platform accelerates, and a gzip stream's trailer holds
 * exactly the CRC-32 of everything fed into it, so an uncompressed gzip stream is
 * the cheapest way to reach that implementation from PHP. Its output is thrown
 * away; only the last eight bytes matter. On a 16 MiB part this is 2 ms rather
 * than 30, and on a part of a few hundred bytes it costs a fraction of a
 * microsecond more than hashing does.
 *
 * @internal
 */
final class Crc32
{
    private const CHUNK = 65_536;

    private \DeflateContext $context;

    /** The last eight bytes seen, which after the final block are the gzip trailer. */
    private string $trailer = '';

    private bool $finished = false;

    public function __construct()
    {
        // The smallest window zlib offers: nothing is compressed, so the memory a
        // larger one buys is spent for no reason.
        $context = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 0, 'memory' => 1]);
        if ($context === false) {
            throw new OpenXmlException('Unable to start a ZIP checksum.');
        }
        $this->context = $context;
    }

    public function update(string $bytes): void
    {
        $length = strlen($bytes);
        if ($length <= self::CHUNK) {
            if ($length > 0) {
                $this->add($bytes, ZLIB_NO_FLUSH);
            }

            return;
        }

        // zlib hands back a stored copy of whatever it is given, so a whole part
        // passed in one call would be held twice for no reason.
        for ($offset = 0; $offset < $length; $offset += self::CHUNK) {
            $this->add(substr($bytes, $offset, self::CHUNK), ZLIB_NO_FLUSH);
        }
    }

    /** The checksum of everything passed to update(), as the ZIP directory stores it. */
    public function value(): int
    {
        if (!$this->finished) {
            $this->add('', ZLIB_FINISH);
            $this->finished = true;
        }

        return Binary::integers(unpack('V1crc', $this->trailer, 0))['crc'];
    }

    private function add(string $bytes, int $flush): void
    {
        $produced = deflate_add($this->context, $bytes, $flush);
        if ($produced === false) {
            throw new OpenXmlException('Unable to compute a ZIP checksum.');
        }
        $this->trailer = strlen($produced) >= 8
            ? substr($produced, -8)
            : substr($this->trailer . $produced, -8);
    }
}
