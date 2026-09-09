<?php

declare(strict_types=1);

namespace DK\OpenXml\Tests\Zip;

use DK\OpenXml\Exception\OpenXmlException;
use DK\OpenXml\Exception\PackageLimitException;
use DK\OpenXml\Internal\Zip\ZipReader;
use DK\OpenXml\OpenXmlPackage;
use DK\OpenXml\Tests\Support\ArchiveAssertions;
use DK\OpenXml\Tests\Support\ZipFixture;
use PHPUnit\Framework\TestCase;

final class EntryReadingTest extends TestCase
{
    use ArchiveAssertions;

    private const RECORD_SIGNATURE = "PK\x01\x02";
    private const CRC_OFFSET = 16;
    private const COMPRESSED_SIZE_OFFSET = 20;
    private const UNCOMPRESSED_SIZE_OFFSET = 24;

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->files = [];
    }

    public function testStoredAndDeflatedEntriesReadBackExactly(): void
    {
        $body = str_repeat('contents ', 5_000);
        $filename = $this->write((new ZipFixture())
            ->add('deflated.xml', $body)
            ->add('stored.bin', $body, method: 0)
            ->add('empty-deflated.xml', '')
            ->add('empty-stored.bin', '', method: 0));

        $reader = new ZipReader($filename);

        try {
            $read = [];
            foreach ($reader->entries() as $entry) {
                $read[$entry->name] = $reader->read($entry, $entry->name);
            }
        } finally {
            $reader->close();
        }

        self::assertSame($body, $read['deflated.xml']);
        self::assertSame($body, $read['stored.bin']);
        self::assertSame('', $read['empty-deflated.xml']);
        self::assertSame('', $read['empty-stored.bin']);
    }

    public function testAChecksumThatDoesNotMatchIsRefusedOnEveryReadingPath(): void
    {
        $filename = $this->corruptedPackage(self::CRC_OFFSET, pack('V', 0x0BADBEEF));
        $package = OpenXmlPackage::open($filename);

        try {
            $package->getPart('/word/document.xml')->getContents();
            self::fail('Expected a checksum mismatch to be refused.');
        } catch (OpenXmlException $exception) {
            self::assertStringContainsString('checksum does not match', $exception->getMessage());
        }

        $stream = $package->getPart('/word/document.xml')->openStream();

        try {
            stream_get_contents($stream);
            self::fail('Expected a checksum mismatch to be refused while streaming.');
        } catch (OpenXmlException $exception) {
            self::assertStringContainsString('checksum does not match', $exception->getMessage());
        } finally {
            fclose($stream);
        }

        $this->expectException(OpenXmlException::class);
        $this->expectExceptionMessage('checksum does not match');
        $package->getPart('/word/document.xml')->getLocalPath();
    }

    public function testCompressedDataCutShortByItsDirectoryIsRefused(): void
    {
        $filename = $this->corruptedPackage(self::COMPRESSED_SIZE_OFFSET, pack('V', 6));

        $this->expectException(OpenXmlException::class);
        $this->expectExceptionMessage('shorter than its directory declares');
        OpenXmlPackage::open($filename)->getPart('/word/document.xml')->getContents();
    }

    public function testACompressedStreamThatNeverEndsIsRefused(): void
    {
        $body = str_repeat('<paragraph/>', 200);
        // Half a deflate stream still decodes, so the shortfall only shows up
        // in the decoder's own state, not in the number of bytes produced.
        $compressed = gzdeflate($body);
        self::assertNotFalse($compressed);
        $half = intdiv(strlen($compressed), 2);
        $context = inflate_init(ZLIB_ENCODING_RAW);
        self::assertNotFalse($context);
        $decoded = inflate_add($context, substr($compressed, 0, $half));
        self::assertNotFalse($decoded);

        $archive = self::patch($this->packageBytes(), 'word/document.xml', self::COMPRESSED_SIZE_OFFSET, pack('V', $half));
        $filename = $this->write(self::patch($archive, 'word/document.xml', self::UNCOMPRESSED_SIZE_OFFSET, pack('V', strlen($decoded))));

        $this->expectException(OpenXmlException::class);
        $this->expectExceptionMessage('compressed data ends early');
        OpenXmlPackage::open($filename)->getPart('/word/document.xml')->getContents();
    }

    public function testDataBeyondTheCompressedStreamIsRefused(): void
    {
        $archive = $this->packageBytes();
        $actual = self::field($archive, 'word/document.xml', self::COMPRESSED_SIZE_OFFSET);
        $filename = $this->write(self::patch($archive, 'word/document.xml', self::COMPRESSED_SIZE_OFFSET, pack('V', $actual + 6)));

        $this->expectException(OpenXmlException::class);
        $this->expectExceptionMessage('past the end of its compressed stream');
        OpenXmlPackage::open($filename)->getPart('/word/document.xml')->getContents();
    }

    public function testAnEntryThatExpandsPastItsDeclaredSizeIsStoppedWhileItIsRead(): void
    {
        $filename = $this->corruptedPackage(self::UNCOMPRESSED_SIZE_OFFSET, pack('V', 32));

        $this->expectException(PackageLimitException::class);
        $this->expectExceptionMessage('expands beyond the 32 bytes its ZIP directory declares');
        OpenXmlPackage::open($filename)->getPart('/word/document.xml')->getContents();
    }

    public function testAnUnsupportedMethodIsCarriedButNotRead(): void
    {
        $source = $this->write((new ZipFixture())
            ->add('[Content_Types].xml', self::contentTypesXml())
            // Method 12 is bzip2; the payload here is not, and never gets decoded.
            ->add('word/document.xml', 'body', method: 12));

        $package = OpenXmlPackage::open($source);
        $destination = $this->file();
        $package->addPart('/word/styles.xml', 'application/xml', '<styles/>');
        $package->saveAs($destination);
        unset($package);

        self::assertArchiveConsistent($destination);
        self::assertSame(12, self::archiveEntries($destination)['word/document.xml']['method']);

        $this->expectException(OpenXmlException::class);
        $this->expectExceptionMessage('unsupported compression method 12');
        OpenXmlPackage::open($destination)->getPart('/word/document.xml')->getContents();
    }

    public function testAPartStreamRewindsButDoesNotSeekElsewhere(): void
    {
        $body = str_repeat('line of text ', 10_000);
        $filename = $this->file();
        $package = OpenXmlPackage::create();
        $package->addPart('/word/document.xml', 'application/xml', $body);
        $package->saveAs($filename);
        unset($package);

        $package = OpenXmlPackage::open($filename);
        $stream = $package->getPart('/word/document.xml')->openStream();

        try {
            self::assertSame(substr($body, 0, 100), fread($stream, 100));
            self::assertSame(100, ftell($stream));
            self::assertTrue(rewind($stream));
            self::assertSame($body, stream_get_contents($stream));
            self::assertTrue(feof($stream));
            self::assertSame(-1, fseek($stream, 5, SEEK_SET));
        } finally {
            fclose($stream);
        }
    }

    private function corruptedPackage(int $fieldOffset, string $value): string
    {
        return $this->write(self::patch($this->packageBytes(), 'word/document.xml', $fieldOffset, $value));
    }

    private function packageBytes(): string
    {
        return (new ZipFixture())
            ->add('[Content_Types].xml', self::contentTypesXml())
            ->add('word/document.xml', str_repeat('<paragraph/>', 200))
            ->build();
    }

    private static function patch(string $archive, string $entryName, int $fieldOffset, string $value): string
    {
        return substr_replace($archive, $value, self::recordOffset($archive, $entryName) + $fieldOffset, strlen($value));
    }

    private static function field(string $archive, string $entryName, int $fieldOffset): int
    {
        $value = unpack('V1value', $archive, self::recordOffset($archive, $entryName) + $fieldOffset);
        self::assertNotFalse($value);
        self::assertIsInt($value['value']);

        return $value['value'];
    }

    private static function recordOffset(string $archive, string $entryName): int
    {
        $offset = 0;
        while (($offset = strpos($archive, self::RECORD_SIGNATURE, $offset)) !== false) {
            $nameLength = unpack('v1length', $archive, $offset + 28);
            self::assertNotFalse($nameLength);
            self::assertIsInt($nameLength['length']);
            if (substr($archive, $offset + 46, $nameLength['length']) === $entryName) {
                return $offset;
            }
            ++$offset;
        }

        self::fail(sprintf('The fixture has no record for "%s".', $entryName));
    }

    private static function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '</Types>';
    }

    private function write(ZipFixture|string $archive): string
    {
        $filename = $this->file();
        file_put_contents($filename, $archive instanceof ZipFixture ? $archive->build() : $archive);

        return $filename;
    }

    private function file(): string
    {
        $filename = tempnam(sys_get_temp_dir(), 'openxml-reading-');
        self::assertNotFalse($filename);
        $this->files[] = $filename;

        return $filename;
    }
}
