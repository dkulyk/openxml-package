<?php

declare(strict_types=1);

namespace DK\OpenXml\Tests\Zip;

use DK\OpenXml\Internal\Zip\Crc32;
use PHPUnit\Framework\TestCase;

final class Crc32Test extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function payloads(): iterable
    {
        yield 'empty' => [''];
        yield 'one byte' => ['a'];
        yield 'short' => ['<document/>'];
        yield 'one byte short of a chunk' => [str_repeat('x', 65_535)];
        yield 'exactly a chunk' => [str_repeat('x', 65_536)];
        yield 'one byte past a chunk' => [str_repeat('x', 65_537)];
        yield 'several chunks and a remainder' => [str_repeat('payload', 40_000)];
        yield 'binary' => ["\x00\xff\x80\x7f\x00\x00\xff"];
    }

    /**
     * @dataProvider payloads
     */
    public function testItAgreesWithPhpsOwnChecksum(string $payload): void
    {
        $checksum = new Crc32();
        $checksum->update($payload);

        self::assertSame(crc32($payload), $checksum->value());
    }

    /**
     * @dataProvider payloads
     */
    public function testTheAnswerDoesNotDependOnHowThePayloadIsSplit(string $payload): void
    {
        $checksum = new Crc32();
        foreach (str_split($payload === '' ? ' ' : $payload, 7) as $piece) {
            if ($payload !== '') {
                $checksum->update($piece);
            }
        }

        self::assertSame(crc32($payload), $checksum->value());
    }

    public function testTheValueCanBeAskedForMoreThanOnce(): void
    {
        $checksum = new Crc32();
        $checksum->update('contents');

        self::assertSame(crc32('contents'), $checksum->value());
        self::assertSame(crc32('contents'), $checksum->value());
    }

    public function testEmptyUpdatesChangeNothing(): void
    {
        $checksum = new Crc32();
        $checksum->update('');
        $checksum->update('contents');
        $checksum->update('');

        self::assertSame(crc32('contents'), $checksum->value());
    }

    public function testALargePayloadIsNotHeldTwice(): void
    {
        $payload = random_bytes(8 * 1024 * 1024);
        $before = memory_get_peak_usage(true);

        $checksum = new Crc32();
        $checksum->update($payload);
        $value = $checksum->value();

        self::assertSame(crc32($payload), $value);
        self::assertSame($before, memory_get_peak_usage(true));
    }
}
