<?php

declare(strict_types=1);

namespace DK\OpenXml\Tests;

use DK\OpenXml\OpenXmlPackage;
use DK\OpenXml\Tests\Support\ArchiveAssertions;
use PHPUnit\Framework\TestCase;

final class LibreOfficeInteropTest extends TestCase
{
    use ArchiveAssertions;

    /** Flag bit 3: the entry's sizes follow its data instead of preceding it. */
    private const FLAG_DATA_DESCRIPTOR = 0x0008;

    private string $directory;

    protected function setUp(): void
    {
        $soffice = getenv('SOFFICE');
        if ($soffice === false || $soffice === '' || !is_executable($soffice)) {
            self::markTestSkipped('Set SOFFICE to an executable LibreOffice binary to run this test.');
        }

        $directory = sys_get_temp_dir() . '/openxml-libreoffice-' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) {
            self::fail('Unable to create the LibreOffice interoperability directory.');
        }
        $this->directory = $directory;
    }

    protected function tearDown(): void
    {
        if (!isset($this->directory) || !is_dir($this->directory)) {
            return;
        }
        self::removeDirectory($this->directory);
    }

    public function testLibreOfficeOpensARewrittenPackage(): void
    {
        $rewritten = $this->directory . '/rewritten.docx';
        $package = OpenXmlPackage::open($this->documentWrittenByLibreOffice());
        self::assertNotEmpty(iterator_to_array($package->getParts()));
        $package->saveAs($rewritten);
        self::assertArchiveConsistent($rewritten);

        $this->assertLibreOfficeConverts($rewritten);
    }

    /**
     * A destination that cannot seek gets the entry sizes in a descriptor after
     * the data instead of in the local header. That is the form of archive
     * PhpSpreadsheet refuses to write for fear of the consumer, so check that a
     * real consumer reads it.
     */
    public function testLibreOfficeOpensAPackageWrittenToAStreamThatCannotSeek(): void
    {
        $package = OpenXmlPackage::open($this->documentWrittenByLibreOffice());

        // Entries carried from the source keep their headers, so the descriptor
        // only appears once a part is written from a stream.
        $document = fopen('php://temp', 'r+b');
        self::assertNotFalse($document);
        fwrite($document, $package->readPart('/word/document.xml'));
        rewind($document);
        $package->writePartFromStream('/word/document.xml', $document);

        $streamed = $this->directory . '/streamed.docx';
        file_put_contents($streamed, self::captured($package));
        fclose($document);

        self::assertArchiveConsistent($streamed);
        self::assertSame(
            self::FLAG_DATA_DESCRIPTOR,
            self::entryFlags($streamed)['word/document.xml'] & self::FLAG_DATA_DESCRIPTOR,
        );

        $this->assertLibreOfficeConverts($streamed);
    }

    /** A .docx LibreOffice itself produced, so the fixture is not of our making. */
    private function documentWrittenByLibreOffice(): string
    {
        $html = $this->directory . '/document.html';
        file_put_contents($html, '<html><body><h1>DK OpenXml</h1><p>Interoperability fixture.</p></body></html>');
        $this->runLibreOffice([
            '--headless',
            '--convert-to',
            'docx:Office Open XML Text',
            '--outdir',
            $this->directory,
            $html,
        ]);

        $source = $this->directory . '/document.docx';
        self::assertFileExists($source);

        return $source;
    }

    private function assertLibreOfficeConverts(string $filename): void
    {
        $outputDirectory = $this->directory . '/output-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($outputDirectory, 0700));
        $this->runLibreOffice(['--headless', '--convert-to', 'pdf', '--outdir', $outputDirectory, $filename]);

        $pdf = $outputDirectory . '/' . basename($filename, '.docx') . '.pdf';
        self::assertFileExists($pdf);
        self::assertGreaterThan(0, filesize($pdf));
    }

    /** @param list<string> $arguments */
    private function runLibreOffice(array $arguments): void
    {
        $soffice = getenv('SOFFICE');
        if (!is_string($soffice)) {
            self::fail('SOFFICE is unavailable.');
        }
        $profile = $this->directory . '/profile-' . bin2hex(random_bytes(4));
        $command = [
            $soffice,
            '-env:UserInstallation=' . self::fileUrl($profile),
            ...$arguments,
        ];
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            self::fail('Unable to start LibreOffice.');
        }

        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        self::assertSame(0, $exitCode, trim((string) $output . "\n" . (string) $errors));
    }

    private static function fileUrl(string $path): string
    {
        return 'file://' . str_replace('%2F', '/', rawurlencode($path));
    }

    private static function removeDirectory(string $directory): void
    {
        $entries = new \FilesystemIterator(
            $directory,
            \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO,
        );
        for ($entries->rewind(); $entries->valid(); $entries->next()) {
            $entry = new \SplFileInfo($entries->getPathname());
            if ($entry->isDir() && !$entry->isLink()) {
                self::removeDirectory($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }
}
