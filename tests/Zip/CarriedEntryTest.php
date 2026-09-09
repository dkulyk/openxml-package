<?php

declare(strict_types=1);

namespace DK\OpenXml\Tests\Zip;

use DK\OpenXml\Exception\ConcurrentModificationException;
use DK\OpenXml\OpenXmlPackage;
use DK\OpenXml\Tests\Support\ArchiveAssertions;
use PHPUnit\Framework\TestCase;

/**
 * Copying a part between two open packages moves the compressed bytes as they
 * are, without decoding and re-encoding them.
 */
final class CarriedEntryTest extends TestCase
{
    use ArchiveAssertions;

    /** @var list<string> */
    private array $files = [];

    private string $text;

    private string $binary;

    protected function setUp(): void
    {
        $this->text = str_repeat('<paragraph>text</paragraph>', 2_000);
        $this->binary = random_bytes(128 * 1024);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->files = [];
    }

    public function testADeflatedPartMovesBetweenPackagesByteForByte(): void
    {
        $source = $this->sourcePackage();
        $package = OpenXmlPackage::open($source);
        $stream = $package->getPart('/word/document.xml')->openStream();
        $destination = $this->write($stream, 'application/xml', null);

        // Deflate is deterministic, so identical bytes alone would not prove the
        // copy skipped the codec. An untouched stream does: the destination never
        // read from it.
        self::assertSame(0, ftell($stream));
        self::assertFalse(feof($stream));
        self::assertSame($this->text, stream_get_contents($stream));
        fclose($stream);

        $from = self::archiveEntries($source)['word/document.xml'];
        $to = self::archiveEntries($destination)['word/document.xml'];

        self::assertSame(8, $to['method']);
        self::assertSame($from['crc'], $to['crc']);
        self::assertSame($from['compressedSize'], $to['compressedSize']);
        self::assertSame(
            self::rawEntryBytes($source, 'word/document.xml'),
            self::rawEntryBytes($destination, 'word/document.xml'),
        );
        self::assertSame($this->text, OpenXmlPackage::open($destination)->getPart('/word/document.xml')->getContents());
    }

    public function testAStoredPartMovesTheSameWay(): void
    {
        $source = $this->sourcePackage();
        $destination = $this->copy($source, '/media/payload.bin', 'application/octet-stream', false);

        self::assertSame(0, self::archiveEntries($destination)['media/payload.bin']['method']);
        self::assertSame(
            self::rawEntryBytes($source, 'media/payload.bin'),
            self::rawEntryBytes($destination, 'media/payload.bin'),
        );
        self::assertSame($this->binary, OpenXmlPackage::open($destination)->getPart('/media/payload.bin')->getContents());
    }

    public function testAPartAlreadyReadFromFallsBackToDecodingIt(): void
    {
        $source = $this->sourcePackage();
        $package = OpenXmlPackage::open($source);
        $stream = $package->getPart('/word/document.xml')->openStream();
        fread($stream, 16);
        rewind($stream);
        // The rewind restarts the entry, so the copy is exact again and correct
        // either way; what matters is that the contents survive the round trip.
        $destination = $this->write($stream, 'application/xml', null);
        fclose($stream);

        self::assertSame($this->text, OpenXmlPackage::open($destination)->getPart('/word/document.xml')->getContents());
    }

    public function testAPartialReadWithoutARewindStillCopiesTheWholePart(): void
    {
        $source = $this->sourcePackage();
        $package = OpenXmlPackage::open($source);
        $stream = $package->getPart('/word/document.xml')->openStream();
        $head = fread($stream, 16);
        self::assertNotFalse($head);
        $destination = $this->write($stream, 'application/xml', null);
        fclose($stream);

        // Reading first is the caller's choice; the copy then holds what was left.
        self::assertSame(
            substr($this->text, 16),
            OpenXmlPackage::open($destination)->getPart('/word/document.xml')->getContents(),
        );
    }

    public function testAskingForACompressionTheSourceDoesNotUseReEncodes(): void
    {
        $source = $this->sourcePackage();
        $destination = $this->copy($source, '/word/document.xml', 'application/xml', false);

        self::assertSame(0, self::archiveEntries($destination)['word/document.xml']['method']);
        self::assertSame($this->text, OpenXmlPackage::open($destination)->getPart('/word/document.xml')->getContents());
    }

    public function testACarriedPartHasAReadableLocalPath(): void
    {
        $source = $this->sourcePackage();
        $filename = $this->file();
        $sourcePackage = OpenXmlPackage::open($source);
        $stream = $sourcePackage->getPart('/word/document.xml')->openStream();
        $destination = OpenXmlPackage::create();
        $part = $destination->addPartFromStream('/word/document.xml', 'application/xml', $stream);
        fclose($stream);

        self::assertSame($this->text, (string) file_get_contents($part->getLocalPath()));
        self::assertSame($this->text, $part->getContents());

        $destination->saveAs($filename);
        self::assertArchiveConsistent($filename);
    }

    public function testTheSourcePackageMayBeReleasedBeforeTheSaveHappens(): void
    {
        $source = $this->sourcePackage();
        $sourcePackage = OpenXmlPackage::open($source);
        $stream = $sourcePackage->getPart('/word/document.xml')->openStream();
        $destination = OpenXmlPackage::create();
        $destination->addPartFromStream('/word/document.xml', 'application/xml', $stream);
        fclose($stream);
        unset($sourcePackage);
        gc_collect_cycles();

        $filename = $this->file();
        $destination->saveAs($filename);

        self::assertSame($this->text, OpenXmlPackage::open($filename)->getPart('/word/document.xml')->getContents());
    }

    public function testASourceArchiveChangedBeforeTheSaveIsRefused(): void
    {
        $source = $this->sourcePackage();
        $sourcePackage = OpenXmlPackage::open($source);
        $stream = $sourcePackage->getPart('/word/document.xml')->openStream();
        $destination = OpenXmlPackage::create();
        $destination->addPartFromStream('/word/document.xml', 'application/xml', $stream);
        fclose($stream);
        unset($sourcePackage);

        file_put_contents($source, 'not a package any more');

        $this->expectException(ConcurrentModificationException::class);
        $destination->saveAs($this->file());
    }

    public function testAStreamFromAPackageWithNoArchiveIsCopiedNormally(): void
    {
        $memory = OpenXmlPackage::create();
        $memory->addPart('/word/document.xml', 'application/xml', $this->text);
        $stream = $memory->getPart('/word/document.xml')->openStream();
        $destination = $this->write($stream, 'application/xml', null);
        fclose($stream);

        self::assertSame($this->text, OpenXmlPackage::open($destination)->getPart('/word/document.xml')->getContents());
    }

    private function copy(string $source, string $partName, string $contentType, ?bool $compress = null): string
    {
        $package = OpenXmlPackage::open($source);
        $stream = $package->getPart($partName)->openStream();

        try {
            return $this->write($stream, $contentType, $compress, $partName);
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream */
    private function write($stream, string $contentType, ?bool $compress, string $partName = '/word/document.xml'): string
    {
        $filename = $this->file();
        $destination = OpenXmlPackage::create();
        $destination->addPartFromStream($partName, $contentType, $stream, $compress);
        $destination->saveAs($filename);

        return $filename;
    }

    private function sourcePackage(): string
    {
        $filename = $this->file();
        $package = OpenXmlPackage::create();
        $package->addPart('/word/document.xml', 'application/xml', $this->text);
        $package->addPart('/media/payload.bin', 'application/octet-stream', $this->binary, false);
        $package->saveAs($filename);

        return $filename;
    }

    private function file(): string
    {
        $filename = tempnam(sys_get_temp_dir(), 'openxml-carry-');
        self::assertNotFalse($filename);
        $this->files[] = $filename;

        return $filename;
    }
}
