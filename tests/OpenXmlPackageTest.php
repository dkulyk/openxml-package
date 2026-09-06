<?php

declare(strict_types=1);

namespace DK\OpenXml\Tests;

use DK\OpenXml\Exception\ConcurrentModificationException;
use DK\OpenXml\Exception\OpenXmlException;
use DK\OpenXml\Exception\PackageLimitException;
use DK\OpenXml\Exception\PackageValidationException;
use DK\OpenXml\Exception\PartInUseException;
use DK\OpenXml\Exception\PartNotFoundException;
use DK\OpenXml\Exception\UnsupportedFileFormatException;
use DK\OpenXml\OpenXmlPackage;
use DK\OpenXml\Packaging\ContentCompression;
use DK\OpenXml\Packaging\PartInterface;
use DK\OpenXml\Packaging\RelationshipType;
use DK\OpenXml\Security\PackageLimits;
use PHPUnit\Framework\TestCase;

final class OpenXmlPackageTest extends TestCase
{
    private const PRESENTATION_CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml';

    private const DOCUMENT_CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml';

    private string $filename;

    protected function setUp(): void
    {
        $filename = tempnam(sys_get_temp_dir(), 'openxml-');
        self::assertNotFalse($filename);

        $this->filename = $filename;
    }

    protected function tearDown(): void
    {
        if (is_file($this->filename)) {
            unlink($this->filename);
        }
    }

    public function testPackageAndPartRelationshipsSurviveRoundTrip(): void
    {
        $package = OpenXmlPackage::create();
        $document = $package->addPart('/word/document.xml', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml', '<document/>');
        $package->addPart('/word/styles.xml', 'application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml', '<styles/>');
        $package->addRelationship(RelationshipType::OFFICE_DOCUMENT, 'word/document.xml');
        $document->addRelationship('urn:styles', 'styles.xml');
        $document->addRelationship(RelationshipType::HYPERLINK, 'https://example.com', true);
        self::assertSame([], $package->validate());
        $package->saveAs($this->filename);

        $reopened = OpenXmlPackage::open($this->filename);
        $officeDocument = $reopened->getRelationships()->firstByType(RelationshipType::OFFICE_DOCUMENT);
        self::assertNotNull($officeDocument);
        self::assertSame('/word/document.xml', $officeDocument->getTargetPartName());

        $documentPart = $officeDocument->getTargetPart();
        self::assertNotNull($documentPart);
        self::assertSame('<document/>', $documentPart->getContents());

        $styles = $documentPart->getRelationships()->firstByType('urn:styles');
        self::assertNotNull($styles);
        self::assertSame('/word/styles.xml', $styles->getTargetPartName());

        $stylesPart = $styles->getTargetPart();
        self::assertNotNull($stylesPart);
        self::assertSame('<styles/>', $stylesPart->getContents());
        self::assertCount(2, iterator_to_array($reopened->getParts()));
    }

    public function testPartContentsCanBeUpdated(): void
    {
        $package = OpenXmlPackage::create();
        $part = $package->addPart('/doc.xml', 'application/xml', 'old');
        $part->setContents('new');
        self::assertSame('new', $package->getPart('/doc.xml')->getContents());
    }

    public function testPartLookupAndRelationshipsUseAsciiCaseInsensitiveEquivalence(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/Word/Document.XML', 'application/xml', '<document/>');
        $package->addRelationship('urn:document', 'word/document.xml');

        self::assertTrue($package->hasPart('/word/document.xml'));
        self::assertSame('/Word/Document.XML', $package->getPart('/WORD/DOCUMENT.xml')->getName());
        self::assertSame('<document/>', $package->getPart('/word/document.xml')->getContents());
        self::assertSame('/word/document.xml', $package->getRelationships()->get('rId1')->getTargetPartName());
        self::assertNotNull($package->getRelationships()->get('rId1')->getTargetPart());
        self::assertSame([], $package->validate());
    }

    public function testEquivalentOrDerivedPartNamesCannotBeAdded(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/custom/data.xml', 'application/xml', 'data');

        foreach (['/CUSTOM/DATA.XML', '/custom', '/custom/data.xml/child'] as $conflictingName) {
            try {
                $package->addPart($conflictingName, 'application/xml', 'conflict');
                self::fail(sprintf('Part name "%s" was expected to conflict.', $conflictingName));
            } catch (OpenXmlException $exception) {
                self::assertStringContainsString('conflicts', $exception->getMessage());
            }
        }

        self::assertCount(1, iterator_to_array($package->getParts()));
    }

    public function testRelationshipPartsRemainIndexedForPackageValidation(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/word/document.xml', 'application/xml', '<document/>');
        $package->addRelationship('urn:document', 'word/document.xml');
        $package->addRelationship('urn:image', 'media/image.png', sourcePartName: '/word/document.xml');
        $package->addPart('/word/media/image.png', 'image/png', 'image');

        self::assertTrue($package->hasPart('/_RELS/.RELS'));
        self::assertTrue($package->hasPart('/WORD/_RELS/DOCUMENT.XML.RELS'));
        self::assertSame('/_rels/.rels', $package->getPart('/_RELS/.RELS')->getName());
        self::assertSame(
            $package->readPart('/word/_rels/document.xml.rels'),
            $package->getPart('/WORD/_RELS/DOCUMENT.XML.RELS')->getContents(),
        );
        self::assertSame([], $package->validate());
    }

    public function testHasPartReturnsFalseForPackageMetadataAndInvalidNames(): void
    {
        $package = OpenXmlPackage::create();

        self::assertFalse($package->hasPart('[Content_Types].xml'));
        self::assertFalse($package->hasPart('/invalid/'));
        self::assertFalse($package->hasPart(''));
    }

    public function testRelationshipPartContentsCannotBeWrittenDirectly(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/document.xml', 'application/xml', '<document/>');
        $package->addRelationship('urn:document', 'document.xml');

        $this->expectException(OpenXmlException::class);
        $this->expectExceptionMessage('Relationship parts are managed through the relationship API.');

        $package->getPart('/_rels/.rels')->setContents('<Relationships/>');
    }

    public function testRelationshipPartContentsCannotBeWrittenFromStream(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/document.xml', 'application/xml', '<document/>');
        $package->addRelationship('urn:document', 'document.xml');
        $source = fopen('php://temp', 'w+b');
        self::assertIsResource($source);

        try {
            $this->expectException(OpenXmlException::class);
            $this->expectExceptionMessage('Relationship parts are managed through the relationship API.');

            $package->getPart('/_rels/.rels')->setContentsFromStream($source);
        } finally {
            fclose($source);
        }
    }

    public function testRelationshipPartContentsCannotBeWrittenFromPath(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/document.xml', 'application/xml', '<document/>');
        $package->addRelationship('urn:document', 'document.xml');

        $this->expectException(OpenXmlException::class);
        $this->expectExceptionMessage('Relationship parts are managed through the relationship API.');

        $package->getPart('/_rels/.rels')->setContentsFromPath($this->filename);
    }

    public function testPartCanBeAddedAndReadAsAStream(): void
    {
        $source = tmpfile();
        self::assertIsResource($source);
        fwrite($source, 'ignored-prefix');
        fwrite($source, "\x00binary-media\xFF");
        fseek($source, strlen('ignored-prefix'));

        $package = OpenXmlPackage::create();
        $part = $package->addPartFromStream('/media/image.bin', 'application/octet-stream', $source);
        fclose($source);
        $package->saveAs($this->filename);

        $stream = OpenXmlPackage::open($this->filename)->getPart('/media/image.bin')->openStream();

        try {
            self::assertSame("\x00binary-media\xFF", stream_get_contents($stream));
        } finally {
            fclose($stream);
        }
        self::assertSame("\x00binary-media\xFF", $part->getContents());
    }

    public function testPartReportsTheContentTypeRegisteredNow(): void
    {
        $this->writeRawZip([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Default Extension="bin" ContentType="application/octet-stream"/></Types>',
            'media/image.bin' => 'bytes',
        ]);
        $package = OpenXmlPackage::open($this->filename);
        $part = $package->getPart('/media/image.bin');
        self::assertSame('application/octet-stream', $part->getContentType());

        $package->setDefaultContentType('bin', 'image/png');

        // The handle was made before the default changed.
        self::assertSame('image/png', $part->getContentType());
        self::assertSame('image/png', $package->getPartContentType('/media/image.bin'));
        self::assertNull($package->getPartContentType('/media/absent.bin'));
    }

    public function testStreamedPartReadsBackRepeatedly(): void
    {
        $source = fopen('php://temp', 'w+b');
        self::assertNotFalse($source);
        fwrite($source, 'streamed-media');
        rewind($source);

        $package = OpenXmlPackage::create();
        $part = $package->addPartFromStream('/media/image.bin', 'application/octet-stream', $source);
        fclose($source);

        // Read in place rather than through a copy, so the second read has to find
        // the staged stream where the first one left it.
        self::assertSame('streamed-media', $part->getContents());
        self::assertSame('streamed-media', $part->getContents());
        self::assertSame('streamed-media', $package->readPart('/media/image.bin'));
    }

    public function testMovedUnchangedPartReadsFromItsSourceEntry(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/media/source.bin', 'application/octet-stream', 'moved-bytes');
        $package->saveAs($this->filename);

        $reopened = OpenXmlPackage::open($this->filename);
        $moved = $reopened->movePart('/media/source.bin', '/assets/destination.bin');

        self::assertSame('moved-bytes', $moved->getContents());
    }

    public function testPartStreamCanReplaceExistingContents(): void
    {
        $package = OpenXmlPackage::create();
        $part = $package->addPart('/media.bin', 'application/octet-stream', 'old');
        $source = fopen('php://temp', 'w+b');
        self::assertIsResource($source);
        fwrite($source, 'new streamed contents');
        rewind($source);

        $part->setContentsFromStream($source);
        fclose($source);

        self::assertSame('new streamed contents', $part->getContents());
    }

    public function testUnchangedPartExposesAReadableZipUri(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/media/image.bin', 'application/octet-stream', 'image bytes');
        $package->saveAs($this->filename);

        $part = OpenXmlPackage::open($this->filename)->getPart('/media/image.bin');
        $path = $part->getReadablePath();

        self::assertStringStartsWith('zip://', $path);
        self::assertSame('image bytes', file_get_contents($path));
    }

    public function testMovedUnchangedPartKeepsAReadableZipUri(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/media/source.bin', 'application/octet-stream', 'image bytes');
        $package->saveAs($this->filename);

        $package = OpenXmlPackage::open($this->filename);
        $part = $package->movePart('/media/source.bin', '/assets/destination.bin');
        $path = $part->getReadablePath();

        self::assertStringStartsWith('zip://', $path);
        self::assertStringEndsWith('#media/source.bin', $path);
        self::assertSame('image bytes', file_get_contents($path));
    }

    public function testReadablePathFallsBackToALocalFileWhenZipUriIsUnsafe(): void
    {
        $filename = $this->filename . '#package.docx';

        try {
            $package = OpenXmlPackage::create();
            $package->addPart('/media/image.bin', 'application/octet-stream', 'image bytes');
            $package->saveAs($filename);

            $package = OpenXmlPackage::open($filename);
            $path = $package->getPart('/media/image.bin')->getReadablePath();

            self::assertStringNotContainsString('zip://', $path);
            self::assertFileExists($path);
            self::assertSame('image bytes', file_get_contents($path));
        } finally {
            unset($package);
            if (is_file($filename)) {
                unlink($filename);
            }
        }
    }

    public function testStagedPartIsMaterializedToAStableLocalPath(): void
    {
        $package = OpenXmlPackage::create();
        $part = $package->addPart('/media/image.png', 'image/png', 'old image');

        $oldPath = $part->getReadablePath();
        self::assertSame($oldPath, $part->getLocalPath());
        self::assertSame('old image', file_get_contents($oldPath));

        $part->setContents('new image');
        $newPath = $part->getLocalPath();

        self::assertNotSame($oldPath, $newPath);
        self::assertSame('old image', file_get_contents($oldPath));
        self::assertSame('new image', file_get_contents($newPath));
    }

    public function testMaterializedFilesAreOwnedByThePackage(): void
    {
        $package = OpenXmlPackage::create();
        $part = $package->addPart('/media/image.bin', 'application/octet-stream', 'image bytes');
        $path = $part->getLocalPath();
        self::assertFileExists($path);

        unset($part, $package);

        self::assertFileDoesNotExist($path);
    }

    public function testUnchangedPartIsReadDirectlyFromTheZip(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/media/image.bin', 'application/octet-stream', str_repeat('image bytes', 1_000));
        $package->saveAs($this->filename);

        $package = OpenXmlPackage::open($this->filename);
        $stream = $package->getPart('/media/image.bin')->openStream();

        try {
            self::assertSame('zip', stream_get_meta_data($stream)['stream_type']);
            self::assertSame(str_repeat('image bytes', 1_000), stream_get_contents($stream));
        } finally {
            fclose($stream);
        }
    }

    public function testPartStreamsKeepTheirSharedContainerAlive(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/media/image.bin', 'application/octet-stream', 'image bytes');
        $package->saveAs($this->filename);

        $package = OpenXmlPackage::open($this->filename);
        $part = $package->getPart('/media/image.bin');
        $materializedPath = $part->getLocalPath();
        $firstStream = $part->openStream();
        $secondStream = $part->openStream();

        unset($part, $package);
        self::assertFileDoesNotExist($materializedPath);
        self::assertSame('image bytes', stream_get_contents($firstStream));

        fclose($firstStream);
        self::assertSame('image bytes', stream_get_contents($secondStream));

        fclose($secondStream);
    }

    public function testSourceCannotBeReplacedWhileAPartStreamIsOpen(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/document.xml', 'application/xml', '<document/>');
        $package->saveAs($this->filename);

        $package = OpenXmlPackage::open($this->filename);
        $stream = $package->getPart('/document.xml')->openStream();
        $package->getPart('/document.xml')->setContents('<updated/>');

        try {
            $package->save();
            self::fail('Saving should require caller-owned part streams to be closed.');
        } catch (OpenXmlException $exception) {
            self::assertSame(
                'Close all open part streams before replacing the source package.',
                $exception->getMessage(),
            );
        } finally {
            fclose($stream);
        }

        $package->save();
        self::assertSame(
            '<updated/>',
            OpenXmlPackage::open($this->filename)->getPart('/document.xml')->getContents(),
        );
    }

    public function testSourceStreamFromAnotherPackageInstanceBlocksReplacement(): void
    {
        $editor = OpenXmlPackage::create();
        $editor->addPart('/document.xml', 'application/xml', '<original/>');
        $editor->saveAs($this->filename);

        $editor = OpenXmlPackage::open($this->filename);
        $reader = OpenXmlPackage::open($this->filename);
        $stream = $reader->getPart('/document.xml')->openStream();
        $editor->getPart('/document.xml')->setContents('<updated/>');

        try {
            $editor->save();
            self::fail('A source stream from another package instance should block replacement.');
        } catch (OpenXmlException $exception) {
            self::assertSame(
                'Close all open part streams before replacing the source package.',
                $exception->getMessage(),
            );
        } finally {
            fclose($stream);
        }

        $editor->save();
        self::assertSame('<updated/>', OpenXmlPackage::open($this->filename)->getPart('/document.xml')->getContents());
    }

    public function testPackageCanBeSavedElsewhereWhileASourceStreamIsOpen(): void
    {
        $destination = $this->filename . '-copy.docx';
        $package = OpenXmlPackage::create();
        $package->addPart('/document.xml', 'application/xml', '<original/>');
        $package->saveAs($this->filename);

        $package = OpenXmlPackage::open($this->filename);
        $stream = $package->getPart('/document.xml')->openStream();
        $package->getPart('/document.xml')->setContents('<updated/>');

        try {
            $package->saveAs($destination);

            self::assertSame('<original/>', stream_get_contents($stream));
            self::assertSame(
                '<updated/>',
                OpenXmlPackage::open($destination)->getPart('/document.xml')->getContents(),
            );
        } finally {
            fclose($stream);
            unset($package);
            if (is_file($destination)) {
                unlink($destination);
            }
        }
    }

    public function testSaveAsCanReplaceAFileOpenedByAnotherIdlePackage(): void
    {
        $destination = tempnam(sys_get_temp_dir(), 'openxml-destination-');
        self::assertNotFalse($destination);

        try {
            $destinationPackage = OpenXmlPackage::create();
            $destinationPackage->addPart('/document.xml', 'application/xml', '<old destination/>');
            $destinationPackage->saveAs($destination);
            $observer = OpenXmlPackage::open($destination);

            $source = OpenXmlPackage::create();
            $source->addPart('/document.xml', 'application/xml', '<new destination/>');
            $source->saveAs($destination);

            self::assertSame(
                '<new destination/>',
                OpenXmlPackage::open($destination)->getPart('/document.xml')->getContents(),
            );

            $this->expectException(ConcurrentModificationException::class);
            $observer->getPart('/document.xml')->getContents();
        } finally {
            unset($destinationPackage, $observer, $source);
            if (is_file($destination)) {
                unlink($destination);
            }
        }
    }

    public function testDefaultContentTypeReplacesPerPartOverrides(): void
    {
        $package = OpenXmlPackage::create();
        $package->setDefaultContentType('svg', 'image/svg+xml');
        $package->addPart('/ppt/media/image1.svg', 'image/svg+xml', '<svg/>');
        $package->addPart('/ppt/media/photo.png', 'image/png', 'png');
        $package->movePart('/ppt/media/image1.svg', '/ppt/media/image2.svg');
        $package->saveAs($this->filename);
        unset($package);

        $reopened = OpenXmlPackage::open($this->filename);
        $contentTypes = $this->readRawEntry('[Content_Types].xml');
        self::assertStringContainsString('<Default Extension="svg" ContentType="image/svg+xml"/>', $contentTypes);
        self::assertStringNotContainsString('image2.svg', $contentTypes);
        self::assertStringContainsString('<Override PartName="/ppt/media/photo.png" ContentType="image/png"/>', $contentTypes);
        self::assertSame('image/svg+xml', $reopened->getPart('/ppt/media/image2.svg')->getContentType());
        self::assertSame([], $reopened->validate());
    }

    public function testXmlDefaultCoversGenericXmlPartsOnly(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/custom.xml', 'application/xml', '<custom/>');
        $package->addPart('/word/document.xml', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml', '<w/>');
        $package->saveAs($this->filename);

        $contentTypes = $this->readRawEntry('[Content_Types].xml');
        self::assertStringContainsString('<Default Extension="xml" ContentType="application/xml"/>', $contentTypes);
        self::assertStringNotContainsString('PartName="/custom.xml"', $contentTypes);
        self::assertStringContainsString('PartName="/word/document.xml"', $contentTypes);
        self::assertSame('application/xml', $package->getPart('/custom.xml')->getContentType());
    }

    public function testContentTypesIsTheFirstEntryOfANewPackage(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/document.xml', 'application/xml', '<document/>');
        $package->addRelationship('urn:document', 'document.xml');
        $package->saveAs($this->filename);
        unset($package);
        self::assertSame('[Content_Types].xml', $this->firstEntryName());

        $reopened = OpenXmlPackage::open($this->filename);
        $reopened->addPart('/styles.xml', 'application/xml', '<styles/>');
        $reopened->save();
        unset($reopened);
        self::assertSame('[Content_Types].xml', $this->firstEntryName());
    }

    public function testPartContentsCanBeReadFromLocalPaths(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'openxml-source-');
        self::assertNotFalse($source);

        try {
            file_put_contents($source, 'first contents');
            $package = OpenXmlPackage::create();
            $part = $package->addPartFromPath('/media.bin', 'application/octet-stream', $source);
            self::assertSame('first contents', $part->getContents());

            $second = tempnam(sys_get_temp_dir(), 'openxml-source-');
            self::assertNotFalse($second);
            file_put_contents($second, 'second contents');

            try {
                $part->setContentsFromPath($second);
                self::assertSame('second contents', $part->getContents());
                $package->saveAs($this->filename);
            } finally {
                unlink($second);
            }

            self::assertSame('second contents', OpenXmlPackage::open($this->filename)->readPart('/media.bin'));
        } finally {
            if (is_file($source)) {
                unlink($source);
            }
        }
    }

    public function testAPartSourceFileChangedBeforeSavingIsRefused(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'openxml-source-');
        self::assertNotFalse($source);

        try {
            file_put_contents($source, 'first contents');
            $package = OpenXmlPackage::create();
            $package->addPartFromPath('/media.bin', 'application/octet-stream', $source);

            // The caller owns the file until the package is saved. Changing it is
            // refused rather than silently packaged.
            unlink($source);
            file_put_contents($source, 'something else entirely');

            $this->expectException(ConcurrentModificationException::class);
            $package->saveAs($this->filename);
        } finally {
            if (is_file($source)) {
                unlink($source);
            }
        }
    }

    public function testPartPathInputMustBeALocalReadableFile(): void
    {
        $this->expectException(OpenXmlException::class);
        $this->expectExceptionMessage('Local file');

        OpenXmlPackage::create()->addPartFromPath(
            '/media.bin',
            'application/octet-stream',
            'php://memory',
        );
    }

    public function testFailedStreamWriteLeavesExistingPartUntouched(): void
    {
        $package = OpenXmlPackage::create(new PackageLimits(
            maximumPartBytes: 512,
            maximumPackageBytes: 1024,
        ));
        $part = $package->addPart('/media.bin', 'application/octet-stream', 'original');
        $source = fopen('php://temp', 'w+b');
        self::assertIsResource($source);
        fwrite($source, str_repeat('x', 513));
        rewind($source);

        try {
            $part->setContentsFromStream($source);
            self::fail('The streamed part was expected to exceed its limit.');
        } catch (PackageLimitException) {
            self::assertSame('original', $part->getContents());
        } finally {
            fclose($source);
        }
    }

    public function testSavingAnEditPreservesUnchangedEntryMetadata(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/document.xml', 'application/xml', '<original/>');
        $package->addPart('/media.bin', 'application/octet-stream', random_bytes(128 * 1024));
        $package->saveAs($this->filename);
        $before = self::zipEntryMetadata($this->filename, 'media.bin');

        $package = OpenXmlPackage::open($this->filename);
        $package->getPart('/document.xml')->setContents('<changed/>');
        $package->save();

        self::assertSame($before, self::zipEntryMetadata($this->filename, 'media.bin'));
    }

    public function testOpeningPackageDoesNotLoadLargePartContentsIntoMemory(): void
    {
        $media = tmpfile();
        self::assertIsResource($media);
        $hash = hash_init('sha256');
        for ($chunk = 0; $chunk < 128; ++$chunk) {
            $contents = random_bytes(65_536);
            hash_update($hash, $contents);
            fwrite($media, $contents);
        }
        fflush($media);
        $metadata = stream_get_meta_data($media);
        $mediaPath = $metadata['uri'] ?? null;
        if (!is_string($mediaPath)) {
            throw new \RuntimeException('Temporary media stream has no filesystem path.');
        }

        $archive = new \ZipArchive();
        self::assertTrue($archive->open($this->filename, \ZipArchive::OVERWRITE));
        self::assertTrue($archive->addFromString(
            '[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Override PartName="/media.bin" ContentType="application/octet-stream"/>'
            . '</Types>',
        ));
        self::assertTrue($archive->addFile($mediaPath, 'media.bin'));
        self::assertTrue($archive->close());
        fclose($media);

        gc_collect_cycles();
        $memoryBefore = memory_get_usage(true);
        $package = OpenXmlPackage::open($this->filename);
        $memoryIncrease = memory_get_usage(true) - $memoryBefore;
        self::assertLessThanOrEqual(2 * 1024 * 1024, $memoryIncrease);

        $stream = $package->getPart('/media.bin')->openStream();

        try {
            $streamHash = hash_init('sha256');
            hash_update_stream($streamHash, $stream);
            self::assertSame(hash_final($hash), hash_final($streamHash));
        } finally {
            fclose($stream);
        }
    }

    public function testRelationshipCollectionMutationsPersist(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/document.xml', 'application/xml', '<document/>');
        $relationships = $package->getRelationships();
        $relationships->create('urn:document', 'document.xml');
        self::assertCount(1, $package->getRelationships());
        $relationships->remove('rId1');
        self::assertCount(0, $package->getRelationships());
    }

    public function testRepeatedRelationshipLookupsShareOneLiveCollection(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/a.xml', 'application/xml', '<a/>');
        $package->addPart('/b.xml', 'application/xml', '<b/>');

        $first = $package->getRelationships();
        $second = $package->getRelationships();
        $first->create('urn:a', 'a.xml');
        $second->create('urn:b', 'b.xml');

        self::assertSame($first, $second);
        self::assertCount(2, $package->getRelationships());
        self::assertSame('rId2', $package->getRelationships()->firstByType('urn:b')?->getId());

        $package->saveAs($this->filename);
        self::assertCount(2, OpenXmlPackage::open($this->filename)->getRelationships());
    }

    public function testRelationshipsCanBeAddedBeforeTheirSourcePart(): void
    {
        $package = OpenXmlPackage::create();
        $package->addRelationship('urn:image', '../media/image.png', sourcePartName: '/ppt/slides/slide1.xml');
        $package->addPart('/ppt/media/image.png', 'image/png', 'image');
        $package->addPart('/ppt/slides/slide1.xml', 'application/xml', '<slide/>');

        self::assertSame([], $package->validate());
        $package->saveAs($this->filename);
        unset($package);

        $reopened = OpenXmlPackage::open($this->filename);
        $relationship = $reopened->getPart('/ppt/slides/slide1.xml')->getRelationships()->firstByType('urn:image');
        self::assertNotNull($relationship);
        self::assertSame('/ppt/media/image.png', $relationship->getTargetPartName());
    }

    public function testSourcePartAddedWithDifferentCaseReusesItsRelationshipPart(): void
    {
        $package = OpenXmlPackage::create();
        $package->addRelationship('urn:first', 'first.xml', sourcePartName: '/slide.xml');
        $part = $package->addPart('/Slide.xml', 'application/xml', '<slide/>');
        $part->addRelationship('urn:second', 'second.xml');
        $package->addPart('/first.xml', 'application/xml', '<first/>');
        $package->addPart('/second.xml', 'application/xml', '<second/>');

        self::assertCount(2, $part->getRelationships());
        self::assertSame([], $package->validate());
        self::assertCount(1, array_filter(
            iterator_to_array($package->getParts(), false),
            static fn(PartInterface $candidate): bool => $candidate->getName() === '/Slide.xml',
        ));
    }

    public function testRelationshipPartWithoutASourcePartFailsValidation(): void
    {
        $package = OpenXmlPackage::create();
        $package->addRelationship('urn:image', 'image.png', sourcePartName: '/missing.xml');

        self::assertSame(
            ['Relationship part "/_rels/missing.xml.rels" belongs to missing source part "/missing.xml".'],
            $package->validate(),
        );

        $this->expectException(PackageValidationException::class);
        $package->saveAs($this->filename);
    }

    public function testChangedRelationshipsStayLiveWithoutACallerReference(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/document.xml', 'application/xml', '<document/>');
        $package->addRelationship('urn:document', 'document.xml');
        $first = spl_object_id($package->getRelationships());
        $package->addRelationship('urn:styles', 'styles.xml');

        self::assertSame($first, spl_object_id($package->getRelationships()));
        self::assertStringContainsString('urn:styles', $package->readPart('/_rels/.rels'));
    }

    public function testPackageIsReleasedAfterRelationshipChanges(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/document.xml', 'application/xml', '<document/>');
        $relationship = $package->addRelationship('urn:document', 'document.xml');
        $packageReference = \WeakReference::create($package);

        unset($package);

        self::assertNull($packageReference->get());
        $this->expectException(OpenXmlException::class);
        $this->expectExceptionMessage('has been released');
        $relationship->getTargetPart();
    }

    public function testRelationshipsSurviveTheCallerDroppingThem(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/document.xml', 'application/xml', '<document/>');
        $package->addRelationship('urn:document', 'document.xml');
        $package->saveAs($this->filename);

        // Reopened, so nothing else holds the collection: an unmodified one has no
        // lazy writer keeping it alive.
        $reopened = OpenXmlPackage::open($this->filename);
        $relationships = $reopened->getRelationships();
        // A weak handle rather than an object id: ids are reused once an object is
        // freed, so a new collection could take the id the first one had.
        $first = \WeakReference::create($relationships);

        unset($relationships);
        gc_collect_cycles();

        self::assertSame($first->get(), $reopened->getRelationships());
    }

    public function testRelationshipCacheDoesNotCreateAPackageReferenceCycle(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/document.xml', 'application/xml', '<document/>');
        $relationships = $package->getRelationships();
        $packageReference = \WeakReference::create($package);

        unset($relationships, $package);

        self::assertNull($packageReference->get());
    }

    public function testRemovingRelationshipsByResolvedTargetPersists(): void
    {
        $package = OpenXmlPackage::create();
        $document = $package->addPart('/word/document.xml', 'application/xml', '<document/>');
        $package->addPart('/word/image.png', 'image/png', 'image');
        $document->addRelationship('urn:image', 'image.png');
        $document->addRelationship('urn:image', '/word/image.png');

        self::assertSame(2, $document->getRelationships()->removeByTargetPart('/word/image.png'));
        self::assertCount(0, $document->getRelationships());
        self::assertSame([], $package->validate());
    }

    public function testRemovingPartAlsoRemovesItsRelationshipPart(): void
    {
        $package = OpenXmlPackage::create();
        $part = $package->addPart('/word/document.xml', 'application/xml', 'x');
        $part->addRelationship('urn:missing', 'styles.xml');
        $package->removePart('/word/document.xml');
        self::assertFalse($package->hasPart('/word/document.xml'));
        self::assertCount(0, $package->getRelationships('/word/document.xml'));
        self::assertSame([], $package->validate());
    }

    public function testRemovingReferencedPartIsRejectedWithoutChanges(): void
    {
        $package = OpenXmlPackage::create();
        $document = $package->addPart('/word/document.xml', 'application/xml', '<document/>');
        $package->addPart('/word/media/image.png', 'image/png', 'image');
        $package->addRelationship('urn:document', 'word/document.xml', id: 'rId1');
        $document->addRelationship('urn:image', 'media/image.png', id: 'rId1');

        try {
            $package->removePart('/word/media/image.png');
            self::fail('Removing a referenced part was expected to fail.');
        } catch (PartInUseException $exception) {
            self::assertSame('/word/media/image.png', $exception->partName);
            self::assertCount(1, $exception->getReferences());
            self::assertSame('/word/document.xml', $exception->getReferences()[0]->sourcePartName);
            self::assertSame('rId1', $exception->getReferences()[0]->relationship->getId());
        }

        self::assertTrue($package->hasPart('/word/media/image.png'));
        self::assertCount(1, $document->getRelationships());
    }

    public function testPartAndInboundRelationshipsCanBeExplicitlyRemoved(): void
    {
        $package = OpenXmlPackage::create();
        $document = $package->addPart('/word/document.xml', 'application/xml', '<document/>');
        $header = $package->addPart('/word/header.xml', 'application/xml', '<header/>');
        $image = $package->addPart('/word/media/image.png', 'image/png', 'image');
        $package->addRelationship('urn:image', 'word/media/image.png', id: 'rId1');
        $document->addRelationship('urn:image', 'media/image.png', id: 'rId1');
        $header->addRelationship('urn:image', 'media/image.png', id: 'rId1');
        $image->addRelationship('urn:self', 'image.png', id: 'rId1');

        $references = $package->getInboundRelationships('/word/media/image.png');
        self::assertCount(4, $references);

        $result = $package->removePartAndRelationships('/word/media/image.png');

        self::assertSame('/word/media/image.png', $result->partName);
        self::assertCount(4, $result->getRemovedRelationships());
        self::assertFalse($package->hasPart('/word/media/image.png'));
        self::assertCount(0, $package->getRelationships());
        self::assertCount(0, $document->getRelationships());
        self::assertCount(0, $header->getRelationships());
        self::assertSame([], $package->validate());

        $package->saveAs($this->filename);
        self::assertSame([], OpenXmlPackage::open($this->filename)->validate());
    }

    public function testRemovingOneSharedResourceDoesNotAffectRelationshipsToAnother(): void
    {
        $package = OpenXmlPackage::create();
        $document = $package->addPart('/word/document.xml', 'application/xml', '<document/>');
        $package->addPart('/word/media/remove.png', 'image/png', 'remove');
        $package->addPart('/word/media/keep.png', 'image/png', 'keep');
        $document->addRelationship('urn:image', 'media/remove.png', id: 'rId1');
        $document->addRelationship('urn:image', 'media/keep.png', id: 'rId2');

        $package->removePartAndRelationships('/word/media/remove.png');

        self::assertCount(1, $document->getRelationships());
        self::assertSame('/word/media/keep.png', $document->getRelationships()->get('rId2')->getTargetPartName());
        self::assertTrue($package->hasPart('/word/media/keep.png'));
    }

    public function testPartCanBeMovedWithInboundAndOutgoingRelationships(): void
    {
        $package = OpenXmlPackage::create();
        $document = $package->addPart('/word/document.xml', 'application/xml', '<document/>');
        $package->addPart('/word/styles.xml', 'application/xml', '<styles/>');
        $header = $package->addPart('/word/header.xml', 'application/xml', '<header/>');
        $package->addRelationship('urn:document', 'word/document.xml', id: 'rId1');
        $header->addRelationship('urn:document', 'document.xml', id: 'rId1');
        $document->addRelationship('urn:styles', 'styles.xml', id: 'rId1');

        $moved = $package->movePart('/word/document.xml', '/documents/main.xml');

        self::assertSame('/documents/main.xml', $moved->getName());
        self::assertSame('<document/>', $moved->getContents());
        self::assertFalse($package->hasPart('/word/document.xml'));
        self::assertSame('documents/main.xml', $package->getRelationships()->get('rId1')->getTarget());
        self::assertSame('../documents/main.xml', $header->getRelationships()->get('rId1')->getTarget());
        self::assertSame('../word/styles.xml', $moved->getRelationships()->get('rId1')->getTarget());
        self::assertSame([], $package->validate());

        $package->saveAs($this->filename);
        $reopened = OpenXmlPackage::open($this->filename);
        self::assertSame('<document/>', $reopened->getPart('/documents/main.xml')->getContents());
        self::assertSame('/documents/main.xml', $reopened->getRelationships()->get('rId1')->getTargetPartName());
        self::assertSame('/word/styles.xml', $reopened->getPart('/documents/main.xml')->getRelationships()->get('rId1')->getTargetPartName());
    }

    public function testMovingPartPreservesAbsoluteRelationshipTargets(): void
    {
        $package = OpenXmlPackage::create();
        $document = $package->addPart('/word/document.xml', 'application/xml', '<document/>');
        $package->addPart('/styles.xml', 'application/xml', '<styles/>');
        $package->addRelationship('urn:document', '/word/document.xml');
        $document->addRelationship('urn:styles', '/styles.xml');

        $moved = $package->movePart('/word/document.xml', '/documents/main.xml');

        self::assertSame('/documents/main.xml', $package->getRelationships()->get('rId1')->getTarget());
        self::assertSame('/styles.xml', $moved->getRelationships()->get('rId1')->getTarget());
    }

    public function testMovingAnUnchangedZipEntryPreservesItsCompressedMetadata(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/media/source.bin', 'application/octet-stream', random_bytes(128 * 1024));
        $package->saveAs($this->filename);
        $before = self::zipEntryMetadata($this->filename, 'media/source.bin');

        $package = OpenXmlPackage::open($this->filename);
        $package->movePart('/media/source.bin', '/assets/destination.bin');
        $package->save();

        self::assertSame($before, self::zipEntryMetadata($this->filename, 'assets/destination.bin'));
        self::assertFalse(OpenXmlPackage::open($this->filename)->hasPart('/media/source.bin'));
    }

    public function testPartCanBeMovedOntoTheNameOfARemovedPartAndSaved(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/source.xml', 'application/xml', 'source');
        $package->addPart('/destination.xml', 'application/xml', 'destination');
        $package->saveAs($this->filename);

        $package = OpenXmlPackage::open($this->filename);
        $package->removePart('/destination.xml');
        $package->movePart('/source.xml', '/destination.xml');
        $package->save();

        $reopened = OpenXmlPackage::open($this->filename);
        self::assertFalse($reopened->hasPart('/source.xml'));
        self::assertSame('source', $reopened->getPart('/destination.xml')->getContents());
    }

    public function testPartNameIndexKeepsRemainingDescendantConflictsAfterRemoval(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/media/first/image.png', 'image/png', 'first');
        $package->addPart('/media/second/image.png', 'image/png', 'second');

        $package->removePart('/media/first/image.png');

        $this->expectException(OpenXmlException::class);
        $package->addPart('/media', 'application/octet-stream', 'conflict');
    }

    public function testMovingTheOnlyDescendantOntoItsFormerAncestorName(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/media/image.png', 'image/png', 'image');

        $moved = $package->movePart('/media/image.png', '/media');

        self::assertSame('/media', $moved->getName());
        self::assertFalse($package->hasPart('/media/image.png'));
    }

    public function testSavingToANewPathUsesUmaskPermissions(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX permissions are not applicable on Windows.');
        }
        unlink($this->filename);
        $previousUmask = umask(022);

        try {
            $this->createSavedPackage('document');
        } finally {
            umask($previousUmask);
        }

        self::assertSame(0644, fileperms($this->filename) & 0777);
    }

    public function testMovingPartToExistingDestinationIsRejectedWithoutChanges(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/source.xml', 'application/xml', 'source');
        $package->addPart('/destination.xml', 'application/xml', 'destination');

        try {
            $package->movePart('/source.xml', '/destination.xml');
            self::fail('Moving over an existing part was expected to fail.');
        } catch (OpenXmlException) {
            self::assertSame('source', $package->getPart('/source.xml')->getContents());
            self::assertSame('destination', $package->getPart('/destination.xml')->getContents());
        }
    }

    public function testValidationReportsDanglingInternalTargetButIgnoresExternalTarget(): void
    {
        $package = OpenXmlPackage::create();
        $package->addRelationship('urn:missing', 'missing.xml');
        $package->addRelationship(RelationshipType::HYPERLINK, 'https://example.com', true);
        self::assertCount(1, $package->validate());
        self::assertStringContainsString('/missing.xml', $package->validate()[0]);
    }

    public function testValidationReportsOrphanRelationshipPart(): void
    {
        $this->writeRawPackage([
            '[Content_Types].xml' => self::contentTypesXml(
                '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>',
            ),
            'word/_rels/missing.xml.rels' => self::relationshipsXml(),
        ]);

        $issues = OpenXmlPackage::open($this->filename)->validate();

        self::assertCount(1, $issues);
        self::assertStringContainsString('missing source part "/word/missing.xml"', $issues[0]);
    }

    public function testValidationReportsStaleContentTypeOverride(): void
    {
        $this->writeRawPackage([
            '[Content_Types].xml' => self::contentTypesXml(
                '<Override PartName="/missing.xml" ContentType="application/xml"/>',
            ),
        ]);

        $issues = OpenXmlPackage::open($this->filename)->validate();

        self::assertCount(1, $issues);
        self::assertStringContainsString('override for "/missing.xml" has no matching part', $issues[0]);
    }

    public function testValidationReportsWrongRelationshipContentType(): void
    {
        $this->writeRawPackage([
            '[Content_Types].xml' => self::contentTypesXml(
                '<Default Extension="rels" ContentType="application/xml"/>',
            ),
            '_rels/.rels' => self::relationshipsXml(),
        ]);

        $issues = OpenXmlPackage::open($this->filename)->validate();

        self::assertCount(1, $issues);
        self::assertStringContainsString('does not use content type', $issues[0]);
    }

    public function testValidationReportsInvalidInternalRelationshipTarget(): void
    {
        $this->writeRawPackage([
            '[Content_Types].xml' => self::contentTypesXml(
                '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>',
            ),
            '_rels/.rels' => self::relationshipsXml(
                '<Relationship Id="rId1" Type="urn:test" Target="../outside.xml"/>',
            ),
        ]);

        $issues = OpenXmlPackage::open($this->filename)->validate();

        self::assertCount(1, $issues);
        self::assertStringContainsString('invalid internal target "../outside.xml"', $issues[0]);
    }

    public function testRelationshipPartsCannotBeAddedAsOrdinaryParts(): void
    {
        $this->expectException(OpenXmlException::class);
        OpenXmlPackage::create()->addPart('/_rels/.rels', 'application/xml', '<Relationships/>');
    }

    public function testMissingPartThrowsDomainException(): void
    {
        $this->expectException(PartNotFoundException::class);
        OpenXmlPackage::create()->getPart('/missing.xml');
    }

    public function testChangesCanBeSavedToTheOriginalFile(): void
    {
        $package = $this->createSavedPackage('<document/>');
        self::assertFalse($package->hasChanges());

        $package->getPart('/document.xml')->setContents('<updated/>');
        self::assertTrue($package->hasChanges());

        $package->save();

        self::assertFalse($package->hasChanges());
        self::assertSame(
            '<updated/>',
            OpenXmlPackage::open($this->filename)->getPart('/document.xml')->getContents(),
        );
    }

    public function testChangesCanBeDiscarded(): void
    {
        $package = $this->createSavedPackage('<original/>');
        $package->getPart('/document.xml')->setContents('<changed/>');

        $package->discardChanges();

        self::assertFalse($package->hasChanges());
        self::assertSame('<original/>', $package->getPart('/document.xml')->getContents());
    }

    public function testDiscardResetsANewPackage(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/temporary.xml', 'application/xml', 'temporary');

        $package->discardChanges();

        self::assertFalse($package->hasChanges());
        self::assertFalse($package->hasPart('/temporary.xml'));
    }

    public function testEditSavesOnlyAfterTheCallbackCompletes(): void
    {
        $this->createSavedPackage('<original/>');

        OpenXmlPackage::edit($this->filename, static function (OpenXmlPackage $package): void {
            $package->getPart('/document.xml')->setContents('<edited/>');
        });

        self::assertSame(
            '<edited/>',
            OpenXmlPackage::open($this->filename)->getPart('/document.xml')->getContents(),
        );
    }

    public function testEditLeavesTheOriginalUntouchedWhenTheCallbackFails(): void
    {
        $this->createSavedPackage('<original/>');

        try {
            OpenXmlPackage::edit($this->filename, static function (OpenXmlPackage $package): void {
                $package->getPart('/document.xml')->setContents('<never-saved/>');

                throw new \RuntimeException('Editing failed.');
            });
            self::fail('The edit callback was expected to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Editing failed.', $exception->getMessage());
        }

        self::assertSame(
            '<original/>',
            OpenXmlPackage::open($this->filename)->getPart('/document.xml')->getContents(),
        );
    }

    public function testValidationFailureLeavesTheOriginalUntouched(): void
    {
        $package = $this->createSavedPackage('<original/>');
        $package->addRelationship('urn:missing', 'missing.xml');

        try {
            $package->save();
            self::fail('Saving an invalid package was expected to fail.');
        } catch (PackageValidationException $exception) {
            self::assertCount(1, $exception->getIssues());
        }

        self::assertCount(0, OpenXmlPackage::open($this->filename)->getRelationships());
    }

    public function testConcurrentModificationIsRejected(): void
    {
        $firstEditor = $this->createSavedPackage('<original/>');
        $secondEditor = OpenXmlPackage::open($this->filename);

        $firstEditor->getPart('/document.xml')->setContents('<first/>');
        $firstEditor->save();

        $secondEditor->getPart('/document.xml')->setContents('<second/>');

        $this->expectException(ConcurrentModificationException::class);
        $secondEditor->save();
    }

    public function testSavedPackageRejectsOutputChangedBeforeItsFirstRead(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/document.xml', 'application/xml', '<document/>');
        $package->saveAs($this->filename);
        file_put_contents($this->filename, 'changed outside the package');

        $this->expectException(ConcurrentModificationException::class);
        $package->getPart('/document.xml')->getContents();
    }

    public function testSavedPackageReadsItsOutputBack(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/document.xml', 'application/xml', '<document/>');
        $package->saveAs($this->filename);

        self::assertSame('<document/>', $package->getPart('/document.xml')->getContents());
        self::assertFalse($package->hasChanges());
        $package->getPart('/document.xml')->setContents('<changed/>');
        $package->save();
        self::assertSame('<changed/>', OpenXmlPackage::open($this->filename)->getPart('/document.xml')->getContents());
    }

    public function testInPlaceSaveRejectsASourceRewrittenAfterOpening(): void
    {
        $this->createSavedPackage('<original/>');
        $package = OpenXmlPackage::open($this->filename);
        $package->getPart('/document.xml')->setContents('<edited/>');
        file_put_contents($this->filename, 'rewritten outside the package');

        $this->expectException(ConcurrentModificationException::class);
        $package->save();
    }

    public function testExpectedMainDocumentTypeIsAccepted(): void
    {
        $this->createPresentation();

        $package = OpenXmlPackage::open($this->filename, expecting: self::PRESENTATION_CONTENT_TYPE);

        self::assertSame('/ppt/presentation.xml', $package->getMainDocumentPart()?->getName());
    }

    public function testExpectedMainDocumentTypeAcceptsAnyListedType(): void
    {
        $this->createPresentation();

        $package = OpenXmlPackage::open($this->filename, expecting: [
            'application/vnd.openxmlformats-officedocument.presentationml.template.main+xml',
            strtoupper(self::PRESENTATION_CONTENT_TYPE),
        ]);

        self::assertNotNull($package->getMainDocumentPart());
    }

    public function testUnexpectedMainDocumentTypeIsRejected(): void
    {
        $this->createPresentation();

        $this->expectException(UnsupportedFileFormatException::class);
        $this->expectExceptionMessage('presentationml.presentation.main+xml"; expected one of');
        OpenXmlPackage::open($this->filename, expecting: self::DOCUMENT_CONTENT_TYPE);
    }

    public function testPackageWithoutAMainDocumentPartIsRejectedWhenATypeIsExpected(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/ppt/presentation.xml', self::PRESENTATION_CONTENT_TYPE, '<presentation/>');
        $package->saveAs($this->filename);

        self::assertNull(OpenXmlPackage::open($this->filename)->getMainDocumentPart());

        $this->expectException(UnsupportedFileFormatException::class);
        $this->expectExceptionMessage('no main document part');
        OpenXmlPackage::open($this->filename, expecting: self::PRESENTATION_CONTENT_TYPE);
    }

    public function testExpectedTypeIsCheckedBeforePartNameValidation(): void
    {
        $this->writeRawZip([
            '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Override PartName="/word/document.xml" ContentType="' . self::DOCUMENT_CONTENT_TYPE . '"/>'
                . '</Types>',
            '_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="' . RelationshipType::OFFICE_DOCUMENT . '" Target="word/document.xml"/>'
                . '</Relationships>',
            'word/document.xml' => '<document/>',
            'custom/data.xml' => '<data/>',
            'custom/data.xml/child' => '<child/>',
        ]);

        // Without an expected type the conflicting names are what stops the open.
        try {
            OpenXmlPackage::open($this->filename);
            self::fail('The conflicting part names should be rejected.');
        } catch (OpenXmlException $exception) {
            self::assertStringContainsString('is derivable from part', $exception->getMessage());
        }

        $this->expectException(UnsupportedFileFormatException::class);
        $this->expectExceptionMessage('wordprocessingml.document.main+xml"; expected one of');
        OpenXmlPackage::open($this->filename, expecting: self::PRESENTATION_CONTENT_TYPE);
    }

    public function testPackageWithAnEntryLackingAContentTypeStaysUsable(): void
    {
        $this->writeRawZip([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="urn:document" Target="word/document.xml"/></Relationships>',
            'word/document.xml' => '<document/>',
            // No Default for jpeg, so this entry has no content type at all.
            'docProps/thumbnail.jpeg' => 'binary',
        ]);
        $package = OpenXmlPackage::open($this->filename);

        $names = [];
        foreach ($package->getParts() as $part) {
            $names[] = $part->getName();
        }

        self::assertSame(['/word/document.xml'], $names);
        self::assertCount(1, $package->getInboundRelationships('/word/document.xml'));
        self::assertSame('binary', $package->readPart('/docProps/thumbnail.jpeg'));

        $issues = $package->validate();
        self::assertNotSame([], array_filter(
            $issues,
            static fn(string $issue): bool => str_contains($issue, 'thumbnail.jpeg'),
        ));
    }

    public function testAPartLackingAContentTypeCanBeGivenOneAndThenUsed(): void
    {
        $this->writeRawZip([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="xml" ContentType="application/xml"/></Types>',
            'word/document.xml' => '<document/>',
            'docProps/thumbnail.jpeg' => 'binary',
        ]);
        $package = OpenXmlPackage::open($this->filename);
        $package->setDefaultContentType('jpeg', 'image/jpeg');

        self::assertSame('image/jpeg', $package->getPart('/docProps/thumbnail.jpeg')->getContentType());
        self::assertSame([], $package->validate());
    }

    private static function compressionMethod(\ZipArchive $archive, string $entryName): int
    {
        $entry = $archive->statName($entryName);
        self::assertNotFalse($entry, sprintf('ZIP entry "%s" is missing.', $entryName));

        return $entry['comp_method'];
    }

    /** @param array<string, string> $entries */
    private function writeRawZip(array $entries): void
    {
        $archive = new \ZipArchive();
        self::assertTrue($archive->open($this->filename, \ZipArchive::OVERWRITE) === true);

        foreach ($entries as $name => $contents) {
            self::assertTrue($archive->addFromString($name, $contents));
        }

        self::assertTrue($archive->close());
    }

    public function testAlreadyCompressedMediaIsStoredAndOtherPartsAreDeflated(): void
    {
        $package = OpenXmlPackage::create();
        // Compressible bytes, so a deflated entry is visibly smaller than a stored one.
        $contents = str_repeat('a', 4096);
        $package->addPart('/word/media/image1.jpeg', 'image/jpeg', $contents);
        $package->addPart('/word/media/diagram.svg', 'image/svg+xml', $contents);
        $package->addPart('/word/media/photo.png', 'image/PNG; charset=binary', $contents);
        $package->addPart('/word/document.xml', 'application/xml', $contents);
        $package->saveAs($this->filename);

        $archive = new \ZipArchive();
        self::assertTrue($archive->open($this->filename) === true);

        try {
            self::assertSame(\ZipArchive::CM_STORE, self::compressionMethod($archive, 'word/media/image1.jpeg'));
            self::assertSame(\ZipArchive::CM_STORE, self::compressionMethod($archive, 'word/media/photo.png'));
            self::assertSame(\ZipArchive::CM_DEFLATE, self::compressionMethod($archive, 'word/media/diagram.svg'));
            self::assertSame(\ZipArchive::CM_DEFLATE, self::compressionMethod($archive, 'word/document.xml'));
            self::assertSame(\ZipArchive::CM_DEFLATE, self::compressionMethod($archive, '[Content_Types].xml'));
        } finally {
            $archive->close();
        }

        $reopened = OpenXmlPackage::open($this->filename);
        self::assertSame($contents, $reopened->readPart('/word/media/image1.jpeg'));
    }

    public function testOoxmlPartsAreDeflatedAndOnlyTheEmbeddedDocumentIsStored(): void
    {
        $package = OpenXmlPackage::create();
        $contents = str_repeat('a', 4096);
        // Every one of these shares the "…officedocument.presentationml." prefix
        // with the embedded deck, and only the deck is itself a ZIP.
        $package->addPart('/ppt/presentation.xml', 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml', $contents);
        $package->addPart('/ppt/slides/slide1.xml', 'application/vnd.openxmlformats-officedocument.presentationml.slide+xml', $contents);
        $package->addPart('/ppt/drawings/vmlDrawing1.vml', 'application/vnd.openxmlformats-officedocument.vmlDrawing', $contents);
        $package->addPart('/ppt/embeddings/oleObject1.bin', 'application/vnd.openxmlformats-officedocument.oleObject', $contents);
        $package->addPart('/ppt/embeddings/deck.pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', $contents);
        $package->addPart('/ppt/embeddings/book.xlsm', 'application/vnd.ms-excel.sheet.macroEnabled.12', $contents);
        $package->saveAs($this->filename);

        $archive = new \ZipArchive();
        self::assertTrue($archive->open($this->filename) === true);

        try {
            self::assertSame(\ZipArchive::CM_DEFLATE, self::compressionMethod($archive, 'ppt/presentation.xml'));
            self::assertSame(\ZipArchive::CM_DEFLATE, self::compressionMethod($archive, 'ppt/slides/slide1.xml'));
            self::assertSame(\ZipArchive::CM_DEFLATE, self::compressionMethod($archive, 'ppt/drawings/vmlDrawing1.vml'));
            self::assertSame(\ZipArchive::CM_DEFLATE, self::compressionMethod($archive, 'ppt/embeddings/oleObject1.bin'));
            self::assertSame(\ZipArchive::CM_STORE, self::compressionMethod($archive, 'ppt/embeddings/deck.pptx'));
            self::assertSame(\ZipArchive::CM_STORE, self::compressionMethod($archive, 'ppt/embeddings/book.xlsm'));
        } finally {
            $archive->close();
        }
    }

    public function testCompressionCanBeForcedPerPart(): void
    {
        $package = OpenXmlPackage::create();
        $contents = str_repeat('a', 4096);
        // Both go against what their content type implies.
        $package->addPart('/word/media/image1.jpeg', 'image/jpeg', $contents, compress: true);
        $package->addPart('/word/embed/blob.bin', 'application/octet-stream', $contents, compress: false);
        $package->addPart('/word/document.xml', 'application/xml', $contents);
        $package->getPart('/word/document.xml')->setContents($contents, compress: false);
        $package->saveAs($this->filename);

        $archive = new \ZipArchive();
        self::assertTrue($archive->open($this->filename) === true);

        try {
            self::assertSame(\ZipArchive::CM_DEFLATE, self::compressionMethod($archive, 'word/media/image1.jpeg'));
            self::assertSame(\ZipArchive::CM_STORE, self::compressionMethod($archive, 'word/embed/blob.bin'));
            self::assertSame(\ZipArchive::CM_STORE, self::compressionMethod($archive, 'word/document.xml'));
        } finally {
            $archive->close();
        }

        self::assertSame($contents, OpenXmlPackage::open($this->filename)->readPart('/word/embed/blob.bin'));
    }

    public function testRegisteredContentTypesAreStored(): void
    {
        $contents = str_repeat('a', 4096);
        $package = OpenXmlPackage::create();
        $package->addPart('/word/media/before.jxl', 'image/jxl', $contents);

        ContentCompression::store('image/JXL');

        try {
            $package->addPart('/word/media/after.jxl', 'image/jxl; quality=90', $contents);
            $package->saveAs($this->filename);
        } finally {
            ContentCompression::reset();
        }

        $archive = new \ZipArchive();
        self::assertTrue($archive->open($this->filename) === true);

        try {
            // Registration applies from the moment the part is written, not retroactively.
            self::assertSame(\ZipArchive::CM_DEFLATE, self::compressionMethod($archive, 'word/media/before.jxl'));
            self::assertSame(\ZipArchive::CM_STORE, self::compressionMethod($archive, 'word/media/after.jxl'));
        } finally {
            $archive->close();
        }

        self::assertTrue(ContentCompression::compresses('image/jxl'), 'reset() forgot the registration');
    }

    public function testMovingAStoredPartKeepsItStored(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/word/media/image1.jpeg', 'image/jpeg', str_repeat('a', 4096));
        $package->movePart('/word/media/image1.jpeg', '/word/media/image2.jpeg');
        $package->saveAs($this->filename);

        $archive = new \ZipArchive();
        self::assertTrue($archive->open($this->filename) === true);

        try {
            self::assertSame(\ZipArchive::CM_STORE, self::compressionMethod($archive, 'word/media/image2.jpeg'));
        } finally {
            $archive->close();
        }
    }

    public function testRewritingAStoredPartKeepsItStored(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/word/media/image1.jpeg', 'image/jpeg', str_repeat('a', 4096));
        $package->writePart('/word/media/image1.jpeg', str_repeat('b', 4096));
        $package->saveAs($this->filename);

        $archive = new \ZipArchive();
        self::assertTrue($archive->open($this->filename) === true);

        try {
            self::assertSame(\ZipArchive::CM_STORE, self::compressionMethod($archive, 'word/media/image1.jpeg'));
        } finally {
            $archive->close();
        }
    }

    private function createPresentation(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/ppt/presentation.xml', self::PRESENTATION_CONTENT_TYPE, '<presentation/>');
        $package->addRelationship(RelationshipType::OFFICE_DOCUMENT, 'ppt/presentation.xml');
        $package->saveAs($this->filename);
    }

    public function testLazyPartReadRejectsAChangedSourcePackage(): void
    {
        $this->createSavedPackage('<original/>');
        $package = OpenXmlPackage::open($this->filename);
        file_put_contents($this->filename, 'changed outside the package');

        $this->expectException(ConcurrentModificationException::class);
        $package->getPart('/document.xml')->openStream();
    }

    public function testLazyPartReadRejectsSourceReplacementWithMatchingSizeAndTimestamp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Windows cannot replace a ZIP file while its source archive is open.');
        }

        $this->createSavedPackage('<original/>');
        $package = OpenXmlPackage::open($this->filename);
        $replacement = $this->filename . '.replacement';
        $modifiedAt = filemtime($this->filename);
        self::assertNotFalse($modifiedAt);

        try {
            self::assertTrue(copy($this->filename, $replacement));
            self::assertTrue(touch($replacement, $modifiedAt));
            self::assertSame(filesize($this->filename), filesize($replacement));
            self::assertTrue(rename($replacement, $this->filename));

            $this->expectException(ConcurrentModificationException::class);
            $package->getPart('/document.xml')->openStream();
        } finally {
            unset($package);
            if (is_file($replacement)) {
                unlink($replacement);
            }
        }
    }

    public function testNewPackageRequiresSaveAs(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/document.xml', 'application/xml', '<document/>');

        $this->expectException(OpenXmlException::class);
        $package->save();
    }

    private function readRawEntry(string $entryName): string
    {
        $archive = new \ZipArchive();
        self::assertTrue($archive->open($this->filename));

        try {
            $contents = $archive->getFromName($entryName);
            self::assertNotFalse($contents);

            return $contents;
        } finally {
            $archive->close();
        }
    }

    private function firstEntryName(): string
    {
        $archive = new \ZipArchive();
        self::assertTrue($archive->open($this->filename));

        try {
            $name = $archive->getNameIndex(0);
            self::assertNotFalse($name);

            return $name;
        } finally {
            $archive->close();
        }
    }

    private function createSavedPackage(string $contents): OpenXmlPackage
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/document.xml', 'application/xml', $contents);
        $package->saveAs($this->filename);

        return $package;
    }

    /** @param array<string, string> $entries */
    private function writeRawPackage(array $entries): void
    {
        $archive = new \ZipArchive();
        self::assertTrue($archive->open($this->filename, \ZipArchive::OVERWRITE));
        foreach ($entries as $name => $contents) {
            self::assertTrue($archive->addFromString($name, $contents));
        }
        self::assertTrue($archive->close());
    }

    private static function contentTypesXml(string $children): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . $children
            . '</Types>';
    }

    private static function relationshipsXml(string $children = ''): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $children
            . '</Relationships>';
    }

    /** @return array{crc: int, comp_size: int, comp_method: int} */
    private static function zipEntryMetadata(string $filename, string $entryName): array
    {
        $archive = new \ZipArchive();
        self::assertTrue($archive->open($filename));

        try {
            $metadata = $archive->statName($entryName);
            self::assertIsArray($metadata);

            return [
                'crc' => $metadata['crc'],
                'comp_size' => $metadata['comp_size'],
                'comp_method' => $metadata['comp_method'],
            ];
        } finally {
            $archive->close();
        }
    }
}
