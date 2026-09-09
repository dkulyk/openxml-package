<?php

declare(strict_types=1);

namespace DK\OpenXml\Tests\Zip;

use DK\OpenXml\Internal\Zip\ZipWriter;
use DK\OpenXml\OpenXmlPackage;
use DK\OpenXml\Tests\Support\ArchiveAssertions;
use DK\OpenXml\Tests\Support\ZipFixture;
use PHPUnit\Framework\TestCase;

final class ZipWriterTest extends TestCase
{
    use ArchiveAssertions;

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

    public function testUnchangedEntriesKeepTheirCompressedBytes(): void
    {
        $first = $this->file();
        $package = OpenXmlPackage::create();
        $package->addPart('/word/document.xml', 'application/xml', str_repeat('<p>text</p>', 500));
        $package->addPart('/media/image.png', 'image/png', random_bytes(4_096));
        $package->addPart('/word/styles.xml', 'application/xml', str_repeat('<style/>', 300));
        $package->saveAs($first);
        unset($package);
        self::assertArchiveConsistent($first);

        $before = self::archiveEntries($first);
        $carried = self::rawEntryBytes($first, 'word/styles.xml');
        $storedImage = self::rawEntryBytes($first, 'media/image.png');

        $second = $this->file();
        $package = OpenXmlPackage::open($first);
        $package->writePart('/word/document.xml', '<p>replaced</p>');
        $package->saveAs($second);
        unset($package);
        self::assertArchiveConsistent($second);

        $after = self::archiveEntries($second);
        self::assertSame($before['word/styles.xml'], $after['word/styles.xml']);
        self::assertSame($before['media/image.png'], $after['media/image.png']);
        self::assertSame($carried, self::rawEntryBytes($second, 'word/styles.xml'));
        self::assertSame($storedImage, self::rawEntryBytes($second, 'media/image.png'));
        self::assertNotSame($before['word/document.xml'], $after['word/document.xml']);
        // The image is a stored content type, so it was never deflated either time.
        self::assertSame(0, $after['media/image.png']['method']);
        self::assertSame(8, $after['word/styles.xml']['method']);
    }

    public function testContentTypesComeFirstEvenWhenTheSourcePutThemLast(): void
    {
        $source = $this->file();
        (new ZipFixture())
            ->add('word/document.xml', '<document/>')
            ->add('[Content_Types].xml', self::contentTypesXml())
            ->writeTo($source);
        self::assertSame(
            ['word/document.xml', '[Content_Types].xml'],
            self::archiveOrder($source),
        );

        $destination = $this->file();
        $package = OpenXmlPackage::open($source);
        $package->addPart('/word/styles.xml', 'application/xml', '<styles/>');
        $package->saveAs($destination);
        unset($package);

        self::assertArchiveConsistent($destination);
        self::assertSame('[Content_Types].xml', self::archiveOrder($destination)[0]);
    }

    public function testOneSaveCarriesMovesRemovalsReplacementsAndAdditions(): void
    {
        $first = $this->file();
        $package = OpenXmlPackage::create();
        $package->addPart('/word/document.xml', 'application/xml', '<document/>');
        $package->addPart('/word/styles.xml', 'application/xml', str_repeat('<style/>', 200));
        $package->addPart('/word/dropped.xml', 'application/xml', '<dropped/>');
        $package->addPart('/media/photo.png', 'image/png', random_bytes(2_048));
        $package->saveAs($first);
        unset($package);

        $carried = self::rawEntryBytes($first, 'word/styles.xml');

        $second = $this->file();
        $package = OpenXmlPackage::open($first);
        $package->removePart('/word/dropped.xml');
        $package->movePart('/media/photo.png', '/media/renamed.png');
        $package->writePart('/word/document.xml', '<document>changed</document>');
        $package->addPart('/word/added.xml', 'application/xml', '<added/>');
        $package->saveAs($second);
        unset($package);

        self::assertArchiveConsistent($second);
        $entries = self::archiveEntries($second);
        self::assertArrayNotHasKey('word/dropped.xml', $entries);
        self::assertArrayNotHasKey('media/photo.png', $entries);
        self::assertArrayHasKey('media/renamed.png', $entries);
        self::assertArrayHasKey('word/added.xml', $entries);
        self::assertSame($carried, self::rawEntryBytes($second, 'word/styles.xml'));
        self::assertSame(0, $entries['media/renamed.png']['method']);

        $reopened = OpenXmlPackage::open($second);
        self::assertSame('<document>changed</document>', $reopened->getPart('/word/document.xml')->getContents());
        self::assertSame([], $reopened->validate());
    }

    public function testRemovedPartsDoNotTravelWithTheEntriesAroundThem(): void
    {
        $first = $this->file();
        $package = OpenXmlPackage::create();
        for ($index = 0; $index < 10; ++$index) {
            // A stored content type keeps the marker readable in the archive itself.
            $package->addPart('/media/part-' . $index . '.png', 'image/png', 'marker-' . $index . '-marker');
        }
        $package->saveAs($first);
        unset($package);

        $second = $this->file();
        $package = OpenXmlPackage::open($first);
        for ($index = 1; $index < 10; $index += 2) {
            $package->removePart('/media/part-' . $index . '.png');
        }
        $package->saveAs($second);
        unset($package);

        self::assertArchiveConsistent($second);
        $written = file_get_contents($second);
        self::assertNotFalse($written);
        $reopened = OpenXmlPackage::open($second);
        for ($index = 0; $index < 10; ++$index) {
            $marker = 'marker-' . $index . '-marker';
            $name = '/media/part-' . $index . '.png';
            if ($index % 2 === 0) {
                self::assertSame($marker, $reopened->getPart($name)->getContents());

                continue;
            }
            self::assertFalse($reopened->hasPart($name));
            self::assertStringNotContainsString($marker, $written);
        }
    }

    public function testEntriesDeferringTheirSizesAreRewrittenWithRealHeaders(): void
    {
        $source = $this->file();
        (new ZipFixture())
            ->add('[Content_Types].xml', self::contentTypesXml())
            // Flag bit 3 defers the sizes and CRC to a descriptor after the data.
            ->add('word/document.xml', '<document/>', flags: 0x0008)
            ->writeTo($source);

        $destination = $this->file();
        $package = OpenXmlPackage::open($source);
        $package->addPart('/word/styles.xml', 'application/xml', '<styles/>');
        $package->saveAs($destination);
        unset($package);

        self::assertArchiveConsistent($destination);
        $written = file_get_contents($destination);
        self::assertNotFalse($written);
        $offset = strpos($written, "PK\x03\x04");
        self::assertNotFalse($offset);
        foreach (self::localHeaders($written) as $header) {
            self::assertSame(0, $header['flags'] & 0x0008, 'No entry should defer its sizes.');
            self::assertNotSame(0, $header['uncompressedSize']);
        }
    }

    public function testStreamedEntriesLargerThanOneChunkGetPatchedHeaders(): void
    {
        $body = random_bytes(2_000) . str_repeat('repeatable ', 30_000);
        $stream = fopen('php://temp', 'r+b');
        self::assertNotFalse($stream);
        fwrite($stream, $body);
        rewind($stream);

        $filename = $this->file();
        $package = OpenXmlPackage::create();
        $package->addPartFromStream('/word/large.bin', 'application/octet-stream', $stream);
        $package->saveAs($filename);
        unset($package);
        fclose($stream);

        self::assertArchiveConsistent($filename);
        $entries = self::archiveEntries($filename);
        self::assertSame(strlen($body), $entries['word/large.bin']['size']);
        self::assertSame(crc32($body), $entries['word/large.bin']['crc']);
        self::assertLessThan(strlen($body), $entries['word/large.bin']['compressedSize']);
        self::assertSame($body, OpenXmlPackage::open($filename)->getPart('/word/large.bin')->getContents());
    }

    public function testMoreEntriesThanTheClassicDirectoryHoldsUseZip64(): void
    {
        $filename = $this->file();
        $handle = fopen($filename, 'w+b');
        self::assertNotFalse($handle);
        $writer = new ZipWriter($handle);
        $count = 0xFFFF + 3;
        for ($index = 0; $index < $count; ++$index) {
            $writer->addString('part-' . $index . '.xml', '<p/>', false);
        }
        $writer->finish();
        fclose($handle);

        self::assertArchiveConsistent($filename);
        $archive = new \ZipArchive();
        self::assertTrue($archive->open($filename, \ZipArchive::RDONLY) === true);
        self::assertSame($count, $archive->numFiles);
        $archive->close();
    }

    /** @return list<array{flags: int, uncompressedSize: int}> */
    private static function localHeaders(string $archive): array
    {
        $headers = [];
        $offset = 0;
        while (($offset = strpos($archive, "PK\x03\x04", $offset)) !== false) {
            $header = unpack('vflags/x2/x4/x4/VcompressedSize/VuncompressedSize', $archive, $offset + 6);
            self::assertNotFalse($header);
            self::assertIsInt($header['flags']);
            self::assertIsInt($header['uncompressedSize']);
            $headers[] = ['flags' => $header['flags'], 'uncompressedSize' => $header['uncompressedSize']];
            ++$offset;
        }
        self::assertNotSame([], $headers);

        return $headers;
    }

    private static function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Default Extension="png" ContentType="image/png"/>'
            . '</Types>';
    }

    private function file(): string
    {
        $filename = tempnam(sys_get_temp_dir(), 'openxml-writer-');
        self::assertNotFalse($filename);
        $this->files[] = $filename;

        return $filename;
    }
}
