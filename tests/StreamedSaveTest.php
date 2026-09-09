<?php

declare(strict_types=1);

namespace DK\OpenXml\Tests;

use DK\OpenXml\Internal\Zip\CentralDirectory;
use DK\OpenXml\OpenXmlPackage;
use DK\OpenXml\Security\PackageLimits;
use DK\OpenXml\Tests\Support\ArchiveAssertions;
use PHPUnit\Framework\TestCase;

final class StreamedSaveTest extends TestCase
{
    use ArchiveAssertions;

    /** Flag bit 3: the entry's sizes follow its data instead of preceding it. */
    private const FLAG_DATA_DESCRIPTOR = 0x0008;

    private string $filename;

    protected function setUp(): void
    {
        $filename = tempnam(sys_get_temp_dir(), 'openxml-streamed-');
        self::assertNotFalse($filename);
        $this->filename = $filename;
    }

    protected function tearDown(): void
    {
        if (is_file($this->filename)) {
            unlink($this->filename);
        }
    }

    public function testPackageIsWrittenToANonSeekableStream(): void
    {
        file_put_contents($this->filename, self::captured(self::package()));

        // ext-zip did not write this archive and checks its consistency.
        self::assertArchiveConsistent($this->filename);

        $package = OpenXmlPackage::open($this->filename);

        try {
            self::assertSame('<document/>', $package->readPart('/document.xml'));
            self::assertSame(str_repeat('payload', 4096), $package->readPart('/media/payload.bin'));
        } finally {
            unset($package);
        }
    }

    public function testAStreamedEntryCarriesADescriptorOnlyWhenTheOutputCannotSeek(): void
    {
        file_put_contents($this->filename, self::captured(self::package()));
        self::assertSame(
            self::FLAG_DATA_DESCRIPTOR,
            self::entryFlags($this->filename)['media/payload.bin'] & self::FLAG_DATA_DESCRIPTOR,
        );

        // A seekable destination still patches the header, so the entry stays
        // eligible to be carried compressed into a later save.
        self::package()->saveAs($this->filename);
        self::assertSame(0, self::entryFlags($this->filename)['media/payload.bin'] & self::FLAG_DATA_DESCRIPTOR);
    }

    public function testStreamedAndFileWrittenPackagesHoldTheSameEntries(): void
    {
        $streamed = self::captured(self::package());
        self::package()->saveAs($this->filename);

        self::assertSame(
            array_map(static fn(array $entry): int => $entry['crc'], self::archiveEntries($this->filename)),
            array_map(
                static fn(array $entry): int => $entry['crc'],
                self::archiveEntriesOf($streamed, $this->filename . '-streamed'),
            ),
        );
    }

    public function testAStreamThatAlreadyHoldsBytesGetsUsableOffsets(): void
    {
        $destination = fopen('php://temp', 'r+b');
        self::assertNotFalse($destination);
        fwrite($destination, 'PREFIX');

        self::package()->saveTo($destination);
        rewind($destination);
        $bytes = stream_get_contents($destination);
        fclose($destination);
        self::assertNotFalse($bytes);
        file_put_contents($this->filename, $bytes);

        // ext-zip reads an archive that starts partway into a file, and the entry
        // offsets have to point at where the entries really are for it to succeed.
        self::assertArchiveConsistent($this->filename);
        self::assertSame(str_repeat('payload', 4096), self::archiveContents($this->filename, 'media/payload.bin'));
    }

    /**
     * A wrapper that consumes eight bytes at a time and is seeked back into to
     * patch a header: the archive must come out byte for byte what a file gets.
     */
    public function testAStreamTakingSmallWritesProducesTheSameArchiveAsAFile(): void
    {
        stream_wrapper_register('openxml-test-chunked', ChunkedSink::class);

        try {
            $destination = fopen('openxml-test-chunked://sink', 'w+b');
            self::assertNotFalse($destination);
            self::package()->saveTo($destination);
            fclose($destination);
        } finally {
            stream_wrapper_unregister('openxml-test-chunked');
        }

        self::package()->saveAs($this->filename);
        self::assertSame(file_get_contents($this->filename), ChunkedSink::$written);
    }

    public function testSaveToRejectsAStreamItCannotWriteTo(): void
    {
        $readOnly = fopen(__FILE__, 'rb');
        self::assertNotFalse($readOnly);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('mode "rb" is read-only');
            self::package()->saveTo($readOnly);
        } finally {
            fclose($readOnly);
        }
    }

    /**
     * An append stream writes at the end of the file whatever fseek() was told,
     * so patching a local header would append twelve bytes instead of replacing
     * them and leave an archive ext-zip reports as inconsistent.
     */
    public function testSaveToRejectsAStreamOpenedForAppending(): void
    {
        $appending = fopen($this->filename, 'a+b');
        self::assertNotFalse($appending);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('opened for appending');
            self::package()->saveTo($appending);
        } finally {
            fclose($appending);
        }
    }

    public function testSaveToRejectsAnythingButAStream(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stream resource');

        /** @phpstan-ignore argument.type */
        self::package()->saveTo('not a stream');
    }

    private static function package(): OpenXmlPackage
    {
        $payload = fopen('php://temp', 'r+b');
        self::assertNotFalse($payload);
        fwrite($payload, str_repeat('payload', 4096));
        rewind($payload);

        $package = OpenXmlPackage::create();
        $package->addPart('/document.xml', 'application/xml', '<document/>');
        $package->addPartFromStream('/media/payload.bin', 'application/octet-stream', $payload);
        fclose($payload);

        return $package;
    }

    /** The bytes saveTo() puts on a stream that cannot seek. */
    private static function captured(OpenXmlPackage $package): string
    {
        $output = fopen('php://output', 'wb');
        self::assertNotFalse($output);
        self::assertFalse(stream_get_meta_data($output)['seekable']);

        ob_start();

        try {
            $package->saveTo($output);
        } finally {
            fclose($output);
            $bytes = ob_get_clean();
        }

        self::assertNotFalse($bytes);

        return $bytes;
    }

    /** @return array<string, int> */
    private static function entryFlags(string $filename): array
    {
        $handle = fopen($filename, 'rb');
        self::assertNotFalse($handle);

        try {
            $limits = new PackageLimits();
            $eocd = CentralDirectory::locate($handle, (int) filesize($filename), $limits);
            $flags = [];
            foreach (CentralDirectory::scan($handle, $eocd, $limits) as $entry) {
                $flags[$entry->name] = $entry->flags;
            }

            return $flags;
        } finally {
            fclose($handle);
        }
    }

    private static function archiveContents(string $filename, string $entryName): string
    {
        $archive = new \ZipArchive();
        self::assertTrue($archive->open($filename, \ZipArchive::RDONLY) === true);

        try {
            $contents = $archive->getFromName($entryName);
            self::assertNotFalse($contents);

            return $contents;
        } finally {
            $archive->close();
        }
    }

    /** @return array<string, array{size: int, compressedSize: int, crc: int, method: int}> */
    private static function archiveEntriesOf(string $bytes, string $scratch): array
    {
        file_put_contents($scratch, $bytes);

        try {
            return self::archiveEntries($scratch);
        } finally {
            unlink($scratch);
        }
    }
}

/**
 * Takes eight bytes per call and keeps what it took, so an archive assembled
 * through many small writes and header patches can be compared with one written
 * to a file. PHP's stream layer re-drives a wrapper until the buffer is drained,
 * so this exercises the writer's seek-back path rather than fwrite() itself
 * returning short.
 */
final class ChunkedSink
{
    public static string $written = '';

    /** @var resource */
    public $context;

    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$written = '';
        $this->position = 0;

        return true;
    }

    public function stream_write(string $data): int
    {
        $chunk = substr($data, 0, 8);
        self::$written = substr_replace(self::$written, $chunk, $this->position, strlen($chunk));
        $this->position += strlen($chunk);

        return strlen($chunk);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        $position = match ($whence) {
            SEEK_CUR => $this->position + $offset,
            SEEK_END => strlen(self::$written) + $offset,
            default => $offset,
        };
        if ($position < 0) {
            return false;
        }
        $this->position = $position;

        return true;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$written);
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [];
    }

    public function stream_close(): void {}
}
