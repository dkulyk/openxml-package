<?php

declare(strict_types=1);

namespace DK\OpenXml\Tests;

use DK\OpenXml\OpenXmlPackage;
use DK\OpenXml\Tests\Support\ArchiveAssertions;
use PHPUnit\Framework\TestCase;

final class LibreOfficeInteropTest extends TestCase
{
    use ArchiveAssertions;

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
        $rewritten = $this->directory . '/rewritten.docx';
        $package = OpenXmlPackage::open($source);
        self::assertNotEmpty(iterator_to_array($package->getParts()));
        $package->saveAs($rewritten);
        self::assertArchiveConsistent($rewritten);

        $outputDirectory = $this->directory . '/output';
        self::assertTrue(mkdir($outputDirectory, 0700));
        $this->runLibreOffice(['--headless', '--convert-to', 'pdf', '--outdir', $outputDirectory, $rewritten]);
        self::assertFileExists($outputDirectory . '/rewritten.pdf');
        self::assertGreaterThan(0, filesize($outputDirectory . '/rewritten.pdf'));
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
