<?php

declare(strict_types=1);

namespace DK\OpenXml\Tests;

use DK\OpenXml\Exception\OpenXmlException;
use DK\OpenXml\Exception\PartInUseException;
use DK\OpenXml\OpenXmlPackage;
use DK\OpenXml\Packaging\RelationshipInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BulkPartRemovalTest extends TestCase
{
    public function testUnreferencedBatchRemovesEquivalentNamesOnce(): void
    {
        $package = OpenXmlPackage::create();
        $first = $package->addPart('/first.xml', 'application/xml', '<first/>');
        $package->addPart('/second.xml', 'application/xml', '<second/>');
        $package->addPart('/keep.xml', 'application/xml', '<keep/>');
        $first->addRelationship('urn:keep', 'keep.xml');

        $package->removeParts(['/FIRST.xml', '/second.xml', '/first.xml']);

        self::assertFalse($package->hasPart('/first.xml'));
        self::assertFalse($package->hasPart('/second.xml'));
        self::assertFalse($package->hasPart('/_rels/first.xml.rels'));
        self::assertTrue($package->hasPart('/keep.xml'));
        self::assertSame([], $package->validate());
    }

    public function testReferencedBatchIsRejectedBeforeAnyPartIsRemoved(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/first.xml', 'application/xml', '<first/>');
        $package->addPart('/second.xml', 'application/xml', '<second/>');
        $package->addRelationship('urn:second', 'second.xml', id: 'root');
        $package->getRelationships('/first.xml')->create('urn:second', 'second.xml', id: 'part');

        try {
            $package->removeParts(['/first.xml', '/second.xml']);
            self::fail('Expected a referenced part to reject the complete batch.');
        } catch (PartInUseException $exception) {
            self::assertSame('/second.xml', $exception->partName);
            self::assertCount(2, $exception->getReferences());
        }

        self::assertTrue($package->hasPart('/first.xml'));
        self::assertTrue($package->hasPart('/second.xml'));
        self::assertCount(1, $package->getRelationships());
        self::assertCount(1, $package->getRelationships('/first.xml'));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function invalidBatches(): iterable
    {
        foreach ([false, true] as $cascade) {
            foreach (['/missing.xml', '/_rels/.rels', 'invalid'] as $name) {
                yield $name . ($cascade ? ' cascade' : ' strict') => [$name, $cascade];
            }
        }
    }

    #[DataProvider('invalidBatches')]
    public function testInvalidBatchDoesNotRemovePartsOrReferences(string $invalidName, bool $cascade): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/first.xml', 'application/xml', '<first/>');
        $package->addRelationship('urn:first', 'first.xml');

        try {
            if ($cascade) {
                $package->removePartsAndRelationships(['/first.xml', $invalidName]);
            } else {
                $package->removeParts(['/first.xml', $invalidName]);
            }
            self::fail('Expected invalid batch input to fail.');
        } catch (OpenXmlException) {
            self::assertTrue($package->hasPart('/first.xml'));
            self::assertCount(1, $package->getRelationships());
        }
    }

    public function testEmptyBatchDoesNotInspectRelationshipsOrChangePackage(): void
    {
        $package = OpenXmlPackage::create();
        self::assertFalse($package->hasChanges());
        $package->removeParts([]);
        self::assertSame([], $package->removePartsAndRelationships([]));
        self::assertFalse($package->hasChanges());
    }

    public function testBatchResolvesEachRelationshipOnlyOnce(): void
    {
        $package = OpenXmlPackage::create();
        $names = [];
        for ($index = 0; $index < 100; ++$index) {
            $names[] = $name = '/part-' . $index . '.xml';
            $package->addPart($name, 'application/xml', '<part/>');
        }
        $package->addPart('/keep.xml', 'application/xml', '<keep/>');
        $relationship = $this->createMock(RelationshipInterface::class);
        $relationship->method('getId')->willReturn('keep');
        $relationship->method('getType')->willReturn('urn:keep');
        $relationship->method('getTarget')->willReturn('keep.xml');
        $relationship->method('isExternal')->willReturn(false);
        $relationship->expects(self::once())->method('getTargetPartName')->willReturn('/keep.xml');
        $package->getRelationships()->add($relationship);

        $package->removeParts($names);

        self::assertCount(1, $package->getRelationships());
        self::assertTrue($package->hasPart('/keep.xml'));
        self::assertFalse($package->hasPart('/part-99.xml'));
    }

    public function testMalformedTargetIsRejectedBeforeAnyMutation(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/first.xml', 'application/xml', '<first/>');
        $package->addRelationship('urn:first', 'first.xml');
        $package->getRelationships()->create('urn:invalid', '../outside.xml');

        try {
            $package->removePartsAndRelationships(['/first.xml']);
            self::fail('Expected malformed target to fail.');
        } catch (OpenXmlException) {
            self::assertTrue($package->hasPart('/first.xml'));
            self::assertCount(2, $package->getRelationships());
        }
    }

    public function testCascadingBatchWithCyclesAndSharedTargetsPersists(): void
    {
        $filename = tempnam(sys_get_temp_dir(), 'openxml-bulk-');
        self::assertNotFalse($filename);

        try {
            $package = OpenXmlPackage::create();
            $package->addPart('/first.xml', 'application/x-first+xml', '<first/>');
            $package->addPart('/second.xml', 'application/x-second+xml', '<second/>');
            $package->addPart('/keep.xml', 'application/xml', '<keep/>');
            $package->addRelationship('urn:first', 'FIRST.xml', id: 'root');
            $package->getRelationships('/first.xml')->create('urn:second', 'second.xml', id: 'forward');
            $package->getRelationships('/second.xml')->create('urn:first', '/first.xml', id: 'back');
            $package->getRelationships('/second.xml')->create('urn:self', 'second.xml', id: 'self');
            $package->getRelationships('/keep.xml')->create('urn:first', 'first.xml', id: 'shared');
            $package->getRelationships('/keep.xml')->create('urn:keep', 'keep.xml', id: 'keep');
            $package->getRelationships('/keep.xml')->create('urn:external', 'first.xml', external: true, id: 'external');
            $package->saveAs($filename);
            unset($package);
            $package = OpenXmlPackage::open($filename);

            $results = $package->removePartsAndRelationships(['/FIRST.xml', '/second.xml', '/first.xml']);

            self::assertCount(2, $results);
            self::assertSame('/first.xml', $results[0]->partName);
            self::assertSame('/second.xml', $results[1]->partName);
            self::assertSame(['root', 'back', 'shared'], array_map(
                static fn($reference): string => $reference->relationship->getId(),
                $results[0]->getRemovedRelationships(),
            ));
            self::assertCount(2, $results[1]->getRemovedRelationships());
            self::assertSame([], $package->validate());
            $package->save();
            unset($package);
            $reopened = OpenXmlPackage::open($filename);
            self::assertFalse($reopened->hasPart('/first.xml'));
            self::assertFalse($reopened->hasPart('/second.xml'));
            self::assertFalse($reopened->hasPart('/_rels/first.xml.rels'));
            self::assertFalse($reopened->hasPart('/_rels/second.xml.rels'));
            self::assertCount(0, $reopened->getRelationships());
            self::assertCount(2, $reopened->getRelationships('/keep.xml'));
            self::assertTrue($reopened->getRelationships('/keep.xml')->get('external')->isExternal());
            self::assertSame([], $reopened->validate());
            unset($reopened);
        } finally {
            unlink($filename);
        }
    }
}
