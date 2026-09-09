<?php

declare(strict_types=1);

namespace DK\OpenXml\Tests;

use DK\OpenXml\OpenXmlPackage;
use DK\OpenXml\Repair\PackageRepairOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SparseRelationshipsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function walks(): iterable
    {
        foreach (['validate', 'inbound', 'move', 'remove', 'remove-signatures', 'analyze-repairs', 'apply-repairs'] as $operation) {
            yield $operation => [$operation];
        }
    }

    #[DataProvider('walks')]
    public function testInternalWalkDoesNotRetainEmptyCollections(string $operation): void
    {
        $package = OpenXmlPackage::create();
        for ($index = 0; $index < 1000; ++$index) {
            $package->addPart('/p' . $index . '.xml', 'application/xml', '<part/>');
        }
        switch ($operation) {
            case 'validate':
                self::assertSame([], $package->validate());
                break;
            case 'inbound':
                self::assertSame([], $package->getInboundRelationships('/p0.xml'));
                break;
            case 'move':
                $package->movePart('/p0.xml', '/moved.xml');
                self::assertTrue($package->hasPart('/moved.xml'));
                break;
            case 'remove':
                $package->removeParts(['/p0.xml', '/p1.xml']);
                self::assertFalse($package->hasPart('/p0.xml'));
                break;
            case 'analyze-repairs':
                $package->analyzeRepairs(new PackageRepairOptions(removeDanglingRelationships: true));
                break;
            case 'apply-repairs':
                $package->applyRepairs(new PackageRepairOptions(removeDanglingRelationships: true));
                break;
            case 'remove-signatures':
                $package->removeSignatures();
                break;
        }

        // Count retained collections rather than allocator-dependent byte totals.
        // Publicly requesting a collection would itself create it.
        self::assertSame([], (new \ReflectionProperty(OpenXmlPackage::class, 'relationships'))->getValue($package));
    }

    public function testExplicitEmptyHandlesRemainLiveAcrossInternalWalks(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/source.xml', 'application/xml', '<source/>');
        $package->addPart('/target.xml', 'application/xml', '<target/>');
        $root = $package->getRelationships();
        $source = $package->getRelationships('/source.xml');
        self::assertSame([], $package->validate());
        self::assertSame([], $package->getInboundRelationships('/target.xml'));
        $package->removeSignatures();
        self::assertSame($root, $package->getRelationships());
        self::assertSame($source, $package->getRelationships('/SOURCE.xml'));

        $root->create('urn:target', 'target.xml');
        $source->create('urn:target', 'target.xml', id: 'target');
        self::assertCount(2, $package->getInboundRelationships('/target.xml'));
        $source->remove('target');
        self::assertSame([], $package->validate());
        self::assertSame($source, $package->getRelationships('/source.xml'));
        $source->create('urn:target', 'target.xml', id: 'again');
        $package->movePart('/target.xml', '/moved.xml');
        self::assertSame('/moved.xml', $source->get('again')->getTargetPartName());
        self::assertCount(2, $package->getInboundRelationships('/moved.xml'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function relationshipXml(): iterable
    {
        yield 'malformed' => ['<broken>', 'is invalid:'];
        yield 'missing target' => [
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="urn:target" Target="missing.xml"/></Relationships>',
            'targets missing part "/missing.xml"',
        ];
    }

    #[DataProvider('relationshipXml')]
    public function testExistingRelationshipsOfUntypedPartAreStillValidated(string $xml, string $expectedIssue): void
    {
        $filename = tempnam(sys_get_temp_dir(), 'openxml-sparse-');
        self::assertNotFalse($filename);

        try {
            $archive = new \ZipArchive();
            self::assertTrue($archive->open($filename, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
            $archive->addFromString(
                '[Content_Types].xml',
                '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '</Types>',
            );
            $archive->addFromString('source.bin', 'source');
            $archive->addFromString('_rels/source.bin.rels', $xml);
            self::assertTrue($archive->close());
            $package = OpenXmlPackage::open($filename);
            $issues = implode("\n", $package->validate());
            self::assertStringContainsString('has no registered content type', $issues);
            self::assertStringContainsString($expectedIssue, $issues);
            unset($package);
        } finally {
            unlink($filename);
        }
    }

    public function testSparseRelationshipsPersistAndReloadAfterSaveAndDiscard(): void
    {
        $filename = tempnam(sys_get_temp_dir(), 'openxml-sparse-');
        self::assertNotFalse($filename);

        try {
            $package = OpenXmlPackage::create();
            for ($index = 0; $index < 100; ++$index) {
                $package->addPart('/p' . $index . '.xml', 'application/xml', '<part/>');
            }
            $package->addRelationship('urn:part', 'p1.xml');
            $package->getRelationships('/p0.xml')->create('urn:part', 'p1.xml', id: 'part');
            $package->saveAs($filename);
            unset($package);
            $package = OpenXmlPackage::open($filename);
            self::assertSame([], $package->validate());
            self::assertCount(2, $package->getInboundRelationships('/p1.xml'));
            $collections = (new \ReflectionProperty(OpenXmlPackage::class, 'relationships'))->getValue($package);
            self::assertIsArray($collections);
            self::assertCount(2, $collections);
            unset($collections);
            $package->getRelationships('/p0.xml')->remove('part');
            self::assertCount(1, $package->getInboundRelationships('/p1.xml'));
            $package->discardChanges();
            self::assertCount(2, $package->getInboundRelationships('/p1.xml'));
            $package->removePartAndRelationships('/p1.xml');
            $package->save();
            unset($package);
            $package = OpenXmlPackage::open($filename);
            self::assertSame([], $package->validate());
            self::assertSame([], (new \ReflectionProperty(OpenXmlPackage::class, 'relationships'))->getValue($package));
            unset($package);
        } finally {
            unlink($filename);
        }
    }
}
