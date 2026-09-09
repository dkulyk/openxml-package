<?php

declare(strict_types=1);

namespace DK\OpenXml\Tests;

use DK\OpenXml\OpenXmlPackage;
use DK\OpenXml\Packaging\RelationshipType;
use DK\OpenXml\Tests\Support\ArchiveAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OfficePackageIntegrationTest extends TestCase
{
    use ArchiveAssertions;

    /**
     * @param array<string, array{string, string}> $additionalParts
     */
    #[DataProvider('officePackageProvider')]
    public function testOfficeStylePackageRoundTrip(
        string $mainPartName,
        string $mainContentType,
        string $mainXml,
        array $additionalParts,
    ): void {
        $filename = tempnam(sys_get_temp_dir(), 'openxml-office-');
        self::assertNotFalse($filename);

        try {
            $package = OpenXmlPackage::create();
            $mainPart = $package->addPart($mainPartName, $mainContentType, $mainXml);
            $package->addRelationship(RelationshipType::OFFICE_DOCUMENT, ltrim($mainPartName, '/'));

            foreach ($additionalParts as $partName => [$contentType, $contents]) {
                $package->addPart($partName, $contentType, $contents);
                $mainPart->addRelationship('urn:test-resource', self::relativeTarget($mainPartName, $partName));
            }

            $package->saveAs($filename);
            self::assertArchiveConsistent($filename);
            $reopened = OpenXmlPackage::open($filename);

            self::assertSame([], $reopened->validate());
            self::assertSame(
                $mainXml,
                $reopened->getRelationships()
                    ->firstByType(RelationshipType::OFFICE_DOCUMENT)
                    ?->getTargetPart()
                    ?->getContents(),
            );
            self::assertCount(1 + count($additionalParts), iterator_to_array($reopened->getParts()));
        } finally {
            unset($mainPart, $package, $reopened);
            if (is_file($filename)) {
                unlink($filename);
            }
        }
    }

    /**
     * @return iterable<string, array{string, string, string, array<string, array{string, string}>}>
     */
    public static function officePackageProvider(): iterable
    {
        yield 'DOCX with PNG media' => [
            '/word/document.xml',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml',
            '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>',
            ['/word/media/image1.png' => ['image/png', "\x89PNG\r\n\x1A\n"]],
        ];

        yield 'XLSX with worksheet' => [
            '/xl/workbook.xml',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml',
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"/>',
            ['/xl/worksheets/sheet1.xml' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml', '<worksheet/>']],
        ];

        yield 'PPTX with slide' => [
            '/ppt/presentation.xml',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml',
            '<p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>',
            ['/ppt/slides/slide1.xml' => ['application/vnd.openxmlformats-officedocument.presentationml.slide+xml', '<p:sld xmlns:p="urn:p"/>']],
        ];
    }

    private static function relativeTarget(string $sourcePartName, string $targetPartName): string
    {
        $sourceDirectory = trim(dirname($sourcePartName), '/');
        $target = ltrim($targetPartName, '/');

        if (str_starts_with($target, $sourceDirectory . '/')) {
            return substr($target, strlen($sourceDirectory) + 1);
        }

        return '/' . $target;
    }
}
