<?php

declare(strict_types=1);

namespace DK\OpenXml\Tests\Zip;

use DK\OpenXml\Exception\OpenXmlException;
use DK\OpenXml\Exception\PackageLimitException;
use DK\OpenXml\Internal\Zip\CentralDirectory;
use DK\OpenXml\Internal\Zip\Entry;
use DK\OpenXml\Security\PackageLimits;
use DK\OpenXml\Tests\Support\ZipFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CentralDirectoryTest extends TestCase
{
    public function testDirectoryMatchesWhatZipArchiveReports(): void
    {
        $filename = tempnam(sys_get_temp_dir(), 'openxml-zip-');
        self::assertNotFalse($filename);

        try {
            $archive = new \ZipArchive();
            self::assertTrue($archive->open($filename, \ZipArchive::OVERWRITE));
            $archive->addFromString('[Content_Types].xml', '<Types/>');
            $archive->addFromString('word/document.xml', str_repeat('<p/>', 2_000));
            $archive->addFromString('media/image.png', random_bytes(4_096));
            $archive->setCompressionName('media/image.png', \ZipArchive::CM_STORE);
            $archive->close();

            $expected = [];
            self::assertTrue($archive->open($filename, \ZipArchive::RDONLY));
            for ($index = 0; $index < $archive->numFiles; ++$index) {
                $stat = $archive->statIndex($index);
                self::assertNotFalse($stat);
                $expected[$stat['name']] = [$stat['size'], $stat['comp_size'], $stat['crc']];
            }
            $archive->close();

            $contents = file_get_contents($filename);
            self::assertNotFalse($contents);
            $actual = [];
            foreach (self::scan($contents) as $entry) {
                $actual[$entry->name] = [$entry->uncompressedSize, $entry->compressedSize, $entry->crc];
                // The recorded offset really is where that entry's local header sits.
                self::assertSame("PK\x03\x04", substr($contents, $entry->localHeaderOffset, 4));
            }

            self::assertSame($expected, $actual);
        } finally {
            unlink($filename);
        }
    }

    public function testCommentHoldingTheEndSignatureIsNotMistakenForIt(): void
    {
        $archive = (new ZipFixture())
            ->add('[Content_Types].xml')
            ->withComment("PK\x05\x06 trailing words")
            ->build();

        self::assertSame(['[Content_Types].xml'], self::names($archive));
    }

    public function testDataPrependedToTheArchiveShiftsEveryOffset(): void
    {
        $plain = (new ZipFixture())->add('word/document.xml')->build();
        $shifted = (new ZipFixture())->add('word/document.xml')->withPrefix(str_repeat("\x7f", 129))->build();

        $entries = self::scan($shifted);
        self::assertCount(1, $entries);
        self::assertSame(129, $entries[0]->localHeaderOffset - self::scan($plain)[0]->localHeaderOffset);
        self::assertSame("PK\x03\x04", substr($shifted, $entries[0]->localHeaderOffset, 4));
    }

    public function testZip64RecordsCarryTheCountsAndTheEntrySizes(): void
    {
        $archive = (new ZipFixture())
            ->add('[Content_Types].xml', '<Types/>', zip64: true)
            ->add('word/document.xml', str_repeat('<p/>', 100), zip64: true)
            ->withZip64()
            ->build();

        $entries = self::scan($archive);
        self::assertCount(2, $entries);
        self::assertSame(8, $entries[0]->uncompressedSize);
        self::assertSame(400, $entries[1]->uncompressedSize);
        self::assertSame("PK\x03\x04", substr($archive, $entries[1]->localHeaderOffset, 4));
    }

    public function testSizesComeFromTheDirectoryWhenTheLocalHeaderDefersThem(): void
    {
        // Flag bit 3 leaves the local header's sizes and CRC zeroed.
        $archive = (new ZipFixture())->add('word/document.xml', 'body', flags: 0x0008)->build();

        $entries = self::scan($archive);
        self::assertSame(4, $entries[0]->uncompressedSize);
        self::assertSame(crc32('body'), $entries[0]->crc);
        self::assertSame(0, self::uint32($archive, $entries[0]->localHeaderOffset + 18));
    }

    public function testLocalExtraFieldsDoNotDisturbTheDirectory(): void
    {
        $archive = (new ZipFixture())
            ->add('word/document.xml', 'body', localExtra: pack('vv', 0x5455, 5) . "\x03\x01\x02\x03\x04")
            ->build();

        self::assertSame(['word/document.xml'], self::names($archive));
    }

    public function testDirectoryEntriesAreSkipped(): void
    {
        $archive = (new ZipFixture())->add('word/')->add('word/document.xml')->build();

        self::assertSame(['word/document.xml'], self::names($archive));
    }

    public function testUnsupportedCompressionMethodsAreCarried(): void
    {
        $archive = (new ZipFixture())->add('word/document.xml', 'body', method: 12)->build();

        self::assertSame(12, self::scan($archive)[0]->method);
    }

    /** @return iterable<string, array{ZipFixture, string}> */
    public static function refusedArchives(): iterable
    {
        yield 'encrypted flag' => [
            (new ZipFixture())->add('word/document.xml', 'body', flags: 0x0001),
            'is encrypted',
        ];
        yield 'aes method' => [
            (new ZipFixture())->add('word/document.xml', 'body', method: 99),
            'is encrypted',
        ];
        yield 'absolute name' => [
            (new ZipFixture())->add('/etc/passwd'),
            'Unsafe ZIP entry name',
        ];
        yield 'traversing name' => [
            (new ZipFixture())->add('word/../../outside.xml'),
            'Unsafe ZIP entry name',
        ];
        yield 'more records than the directory holds' => [
            (new ZipFixture())->add('word/document.xml')->withDeclaredEntryCount(9),
            'The ZIP archive is corrupt',
        ];
    }

    #[DataProvider('refusedArchives')]
    public function testRefusedArchives(ZipFixture $fixture, string $message): void
    {
        $this->expectException(OpenXmlException::class);
        $this->expectExceptionMessage($message);

        self::scan($fixture->build());
    }

    public function testTruncationAtAnyOffsetIsReportedNotWarnedAbout(): void
    {
        $archive = (new ZipFixture())
            ->add('[Content_Types].xml')
            ->add('word/document.xml', str_repeat('<p/>', 40))
            ->build();

        for ($length = strlen($archive) - 1; $length > 0; --$length) {
            try {
                self::scan(substr($archive, 0, $length));
                self::fail(sprintf('Expected a truncation at %d bytes to be refused.', $length));
            } catch (OpenXmlException) {
                // Every failure must arrive as an exception, never as a warning or a notice.
            }
        }
    }

    public function testAnEntryPointingOutsideTheFileIsRefused(): void
    {
        $archive = (new ZipFixture())->add('word/document.xml')->build();
        $position = strpos($archive, "PK\x01\x02");
        self::assertNotFalse($position);
        $broken = substr_replace($archive, pack('V', 0x7FFF_FFFF), $position + 42, 4);

        $this->expectException(OpenXmlException::class);
        $this->expectExceptionMessage('points outside the file');

        self::scan($broken);
    }

    public function testLimitsAreAppliedFromDirectoryValuesAlone(): void
    {
        $archive = (new ZipFixture())->add('word/document.xml', str_repeat('a', 4_096))->build();

        try {
            self::scan($archive, new PackageLimits(maximumPartBytes: 1_024));
            self::fail('Expected the declared size to be refused.');
        } catch (PackageLimitException $exception) {
            self::assertStringContainsString('expands to 4096 bytes', $exception->getMessage());
        }

        try {
            self::scan($archive, new PackageLimits(maximumCompressionRatio: 2.0));
            self::fail('Expected the declared ratio to be refused.');
        } catch (PackageLimitException $exception) {
            self::assertStringContainsString('suspicious compression ratio', $exception->getMessage());
        }

        $pair = (new ZipFixture())->add('a.xml')->add('b.xml')->build();

        $this->expectException(PackageLimitException::class);
        $this->expectExceptionMessage('Package contains 2 entries; the configured maximum is 1');
        self::scan($pair, new PackageLimits(maximumEntries: 1));
    }

    public function testTheScanStopsWhereTheCallerStops(): void
    {
        $archive = ZipFixture::breakRecord(
            (new ZipFixture())
                ->add('[Content_Types].xml')
                ->add('word/document.xml')
                ->add('media/image.png')
                ->build(),
            2,
        );

        $handle = self::handle($archive);

        try {
            $limits = new PackageLimits();
            $eocd = CentralDirectory::locate($handle, strlen($archive), $limits);
            $first = null;
            foreach (CentralDirectory::scan($handle, $eocd, $limits) as $entry) {
                $first = $entry->name;

                break;
            }
            self::assertSame('[Content_Types].xml', $first);

            $this->expectException(OpenXmlException::class);
            $this->expectExceptionMessage('bad signature');
            iterator_to_array(CentralDirectory::scan($handle, $eocd, $limits), false);
        } finally {
            fclose($handle);
        }
    }

    /** @return list<Entry> */
    private static function scan(string $archive, ?PackageLimits $limits = null): array
    {
        $limits ??= new PackageLimits();
        $handle = self::handle($archive);

        try {
            $eocd = CentralDirectory::locate($handle, strlen($archive), $limits);

            return iterator_to_array(CentralDirectory::scan($handle, $eocd, $limits), false);
        } finally {
            fclose($handle);
        }
    }

    /** @return list<string> */
    private static function names(string $archive): array
    {
        return array_map(static fn(Entry $entry): string => $entry->name, self::scan($archive));
    }

    /** @return resource */
    private static function handle(string $archive)
    {
        $handle = fopen('php://temp', 'r+b');
        self::assertNotFalse($handle);
        fwrite($handle, $archive);
        rewind($handle);

        return $handle;
    }

    private static function uint32(string $data, int $position): int
    {
        $value = unpack('V1value', $data, $position);
        self::assertNotFalse($value);
        self::assertIsInt($value['value']);

        return $value['value'];
    }
}
