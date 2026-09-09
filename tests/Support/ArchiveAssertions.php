<?php

declare(strict_types=1);

namespace DK\OpenXml\Tests\Support;

use DK\OpenXml\Internal\Zip\CentralDirectory;
use DK\OpenXml\Internal\Zip\ZipReader;
use DK\OpenXml\OpenXmlPackage;
use DK\OpenXml\Security\PackageLimits;
use PHPUnit\Framework\Assert;

/** Checks archives this library writes against ext-zip, which did not write them. */
trait ArchiveAssertions
{
    protected static function assertArchiveConsistent(string $filename): void
    {
        $archive = new \ZipArchive();
        $opened = $archive->open($filename, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS);
        Assert::assertTrue($opened === true, sprintf('ext-zip rejected "%s": %s', $filename, var_export($opened, true)));
        $archive->close();
    }

    /** @return array<string, array{size: int, compressedSize: int, crc: int, method: int}> */
    protected static function archiveEntries(string $filename): array
    {
        $archive = new \ZipArchive();
        Assert::assertTrue($archive->open($filename, \ZipArchive::RDONLY) === true);

        $entries = [];

        try {
            for ($index = 0; $index < $archive->numFiles; ++$index) {
                $stat = $archive->statIndex($index);
                Assert::assertNotFalse($stat);
                $entries[$stat['name']] = [
                    'size' => $stat['size'],
                    'compressedSize' => $stat['comp_size'],
                    'crc' => $stat['crc'],
                    'method' => $stat['comp_method'],
                ];
            }
        } finally {
            $archive->close();
        }

        return $entries;
    }

    /** @return list<string> */
    protected static function archiveOrder(string $filename): array
    {
        return array_keys(self::archiveEntries($filename));
    }

    /** The entry's compressed bytes exactly as the archive holds them. */
    protected static function rawEntryBytes(string $filename, string $entryName): string
    {
        $handle = fopen($filename, 'rb');
        Assert::assertNotFalse($handle);

        try {
            $limits = new PackageLimits();
            $eocd = CentralDirectory::locate($handle, (int) filesize($filename), $limits);
            foreach (CentralDirectory::scan($handle, $eocd, $limits) as $entry) {
                if ($entry->name !== $entryName) {
                    continue;
                }
                $buffer = fopen('php://temp', 'r+b');
                Assert::assertNotFalse($buffer);
                (new ZipReader($filename))->copyRawTo($entry, $buffer);
                rewind($buffer);
                $bytes = stream_get_contents($buffer);
                fclose($buffer);
                Assert::assertNotFalse($bytes);

                return $bytes;
            }
        } finally {
            fclose($handle);
        }

        Assert::fail(sprintf('Entry "%s" is not in "%s".', $entryName, $filename));
    }

    /**
     * The general purpose flags of every entry, keyed by entry name.
     *
     * @return array<string, int>
     */
    protected static function entryFlags(string $filename): array
    {
        $handle = fopen($filename, 'rb');
        Assert::assertNotFalse($handle);

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

    /** The bytes saveTo() puts on a stream that cannot seek. */
    protected static function captured(OpenXmlPackage $package): string
    {
        $output = fopen('php://output', 'wb');
        Assert::assertNotFalse($output);
        Assert::assertFalse(stream_get_meta_data($output)['seekable']);

        ob_start();

        try {
            $package->saveTo($output);
        } finally {
            fclose($output);
            $bytes = ob_get_clean();
        }

        Assert::assertNotFalse($bytes);

        return $bytes;
    }
}
