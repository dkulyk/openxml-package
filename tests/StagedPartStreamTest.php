<?php

declare(strict_types=1);

namespace DK\OpenXml\Tests;

use DK\OpenXml\OpenXmlPackage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StagedPartStreamTest extends TestCase
{
    public function testReadersShareTheFileWithIndependentCursors(): void
    {
        $package = OpenXmlPackage::create();
        $this->stage($package, '0123456789');
        $first = $package->openPartStream('/payload.bin');
        $second = $package->openPartStream('/payload.bin');

        try {
            // Deterministic regression check: opening another reader must not
            // materialize another file, irrespective of timing or file size.
            self::assertSame($this->streamPath($first), $this->streamPath($second));
            self::assertSame('012', fread($first, 3));
            self::assertSame('01', fread($second, 2));
            self::assertSame('0123456789', $package->readPart('/payload.bin'));
            self::assertSame('34', fread($first, 2));
            self::assertSame('23456789', stream_get_contents($second));
            self::assertSame(0, fseek($first, 8));
            self::assertSame('89', stream_get_contents($first));
        } finally {
            fclose($first);
            fclose($second);
        }
        self::assertSame('0123456789', $package->readPart('/payload.bin'));
    }

    public function testSharedFileReaderCannotWriteIntoStagedContents(): void
    {
        $package = OpenXmlPackage::create();
        $this->stage($package, 'original');
        $stream = $package->openPartStream('/payload.bin');

        try {
            self::assertFalse(@fwrite($stream, 'changed'));
            self::assertSame('original', stream_get_contents($stream));
            self::assertSame('original', $package->readPart('/payload.bin'));
        } finally {
            fclose($stream);
        }
    }

    public function testReadersOfDifferentRevisionsRetainOnlyTheirOwnFile(): void
    {
        $package = OpenXmlPackage::create();
        $this->stage($package, 'first revision');
        $first = $package->openPartStream('/payload.bin');
        $firstPath = $this->streamPath($first);
        $this->stage($package, 'second revision');
        $second = $package->openPartStream('/payload.bin');
        $secondPath = $this->streamPath($second);
        unset($package);

        try {
            self::assertNotSame($firstPath, $secondPath);
            self::assertSame('first revision', stream_get_contents($first));
            fclose($first);
            clearstatcache(true, $firstPath);
            self::assertFileDoesNotExist($firstPath);
            self::assertSame('second revision', stream_get_contents($second));
            fclose($second);
            clearstatcache(true, $secondPath);
            self::assertFileDoesNotExist($secondPath);
        } finally {
            if (is_resource($first)) {
                fclose($first);
            }
            if (is_resource($second)) {
                fclose($second);
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function snapshotMutations(): iterable
    {
        foreach (['replace-string', 'replace-stream', 'remove', 'move', 'discard', 'release', 'save'] as $mutation) {
            yield $mutation => [$mutation];
        }
    }

    #[DataProvider('snapshotMutations')]
    public function testSnapshotSurvivesMutationAndFileIsReleasedAfterLastReader(string $mutation): void
    {
        $filename = tempnam(sys_get_temp_dir(), 'openxml-staged-test-');
        self::assertNotFalse($filename);
        $package = OpenXmlPackage::create();
        $this->stage($package, 'original');
        $first = $package->openPartStream('/payload.bin');
        $second = $package->openPartStream('/payload.bin');
        $path = $this->streamPath($first);
        self::assertFileExists($path);
        $packageReference = \WeakReference::create($package);

        try {
            self::assertSame('ori', fread($first, 3));
            switch ($mutation) {
                case 'replace-string':
                    $package->writePart('/payload.bin', 'replacement');
                    self::assertSame('replacement', $package->readPart('/payload.bin'));
                    break;
                case 'replace-stream':
                    $this->stage($package, 'replacement');
                    self::assertSame('replacement', $package->readPart('/payload.bin'));
                    break;
                case 'remove':
                    $package->removePart('/payload.bin');
                    self::assertFalse($package->hasPart('/payload.bin'));
                    break;
                case 'move':
                    $package->movePart('/payload.bin', '/moved.bin');
                    self::assertSame('original', $package->readPart('/moved.bin'));
                    break;
                case 'discard':
                    $package->discardChanges();
                    self::assertFalse($package->hasPart('/payload.bin'));
                    break;
                case 'save':
                    $package->saveAs($filename);
                    self::assertSame('original', $package->readPart('/payload.bin'));
                    break;
            }
            unset($package);
            self::assertNull($packageReference->get(), 'Readers must retain only their staged snapshot.');
            self::assertSame('ginal', stream_get_contents($first));
            fclose($first);
            clearstatcache(true, $path);
            self::assertFileExists($path);
            self::assertSame('original', stream_get_contents($second));
            fclose($second);
            clearstatcache(true, $path);
            self::assertFileDoesNotExist($path);
        } finally {
            if (is_resource($first)) {
                fclose($first);
            }
            if (is_resource($second)) {
                fclose($second);
            }
            unset($package);
            unlink($filename);
        }
    }

    public function testSavingReplacementWhileOldStagedReaderIsOpenPersistsNewBytes(): void
    {
        $filename = tempnam(sys_get_temp_dir(), 'openxml-staged-save-');
        self::assertNotFalse($filename);
        $package = OpenXmlPackage::create();
        $this->stage($package, 'initial');
        $package->saveAs($filename);
        $this->stage($package, 'first staged revision');
        $reader = $package->openPartStream('/payload.bin');

        try {
            $this->stage($package, 'second staged revision');
            $package->save();
            self::assertSame('first staged revision', stream_get_contents($reader));
            self::assertSame('second staged revision', OpenXmlPackage::open($filename)->readPart('/payload.bin'));
        } finally {
            fclose($reader);
            unset($package);
            unlink($filename);
        }
    }

    public function testClosingLastReaderKeepsFileUntilPackageReleasesIt(): void
    {
        $package = OpenXmlPackage::create();
        $this->stage($package, 'contents');
        $stream = $package->openPartStream('/payload.bin');
        $path = $this->streamPath($stream);
        fclose($stream);
        clearstatcache(true, $path);
        self::assertFileExists($path);
        self::assertSame('contents', $package->readPart('/payload.bin'));
        unset($package);
        clearstatcache(true, $path);
        self::assertFileDoesNotExist($path);
    }

    public function testEmptyStagedFileCanBeReadAndReleased(): void
    {
        $package = OpenXmlPackage::create();
        $this->stage($package, '');
        $stream = $package->openPartStream('/payload.bin');
        unset($package);

        try {
            self::assertSame('', stream_get_contents($stream));
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream */
    private function streamPath($stream): string
    {
        $path = stream_get_meta_data($stream)['uri'] ?? null;
        self::assertIsString($path);

        return $path;
    }

    private function stage(OpenXmlPackage $package, string $contents): void
    {
        $source = fopen('php://temp', 'w+b');
        self::assertNotFalse($source);

        try {
            fwrite($source, $contents);
            rewind($source);
            $package->addPartFromStream('/payload.bin', 'application/octet-stream', $source);
        } finally {
            fclose($source);
        }
    }
}
