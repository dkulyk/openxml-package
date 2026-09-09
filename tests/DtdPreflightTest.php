<?php

declare(strict_types=1);

namespace DK\OpenXml\Tests;

use DK\OpenXml\Exception\InvalidEncryptedPackageException;
use DK\OpenXml\Exception\OpenXmlException;
use DK\OpenXml\Internal\Encryption\AgileEncryptionInfo;
use DK\OpenXml\Internal\XmlDocument;
use DK\OpenXml\Packaging\ContentTypes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DtdPreflightTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function encodedDtds(): iterable
    {
        $xml = '<!DOCTYPE Types><';

        yield 'UTF-8' => [$xml];
        yield 'UTF-16LE' => ["\xFF\xFE" . self::wide($xml, [0, 1])];
        yield 'UTF-16BE' => ["\xFE\xFF" . self::wide($xml, [1, 0])];
        yield 'UTF-32LE' => ["\xFF\xFE\x00\x00" . self::wide($xml, [0, 1, 1, 1])];
        yield 'UTF-32BE' => ["\x00\x00\xFE\xFF" . self::wide($xml, [1, 1, 1, 0])];
        yield 'UCS-4 2143' => ["\x00\x00\xFF\xFE" . self::wide($xml, [1, 0, 1, 1])];
        yield 'UCS-4 3412' => ["\xFE\xFF\x00\x00" . self::wide($xml, [1, 1, 0, 1])];
    }

    #[DataProvider('encodedDtds')]
    public function testPackageDtdIsRejectedBeforeMalformedXmlIsParsed(string $xml): void
    {
        self::assertTrue(XmlDocument::hasDtdDeclaration($xml));

        try {
            ContentTypes::fromXml($xml);
            self::fail('Expected the DTD preflight to reject the document.');
        } catch (OpenXmlException $exception) {
            self::assertSame('DTD declarations are not allowed in package XML.', $exception->getMessage());
        }
    }

    #[DataProvider('encodedDtds')]
    public function testEncryptionInfoDtdIsRejectedBeforeMalformedXmlIsParsed(string $xml): void
    {
        $contents = pack('vvV', 4, 4, 0x40) . $xml;

        try {
            AgileEncryptionInfo::fromStream($contents, 1_000_000);
            self::fail('Expected the DTD preflight to reject EncryptionInfo.');
        } catch (InvalidEncryptedPackageException $exception) {
            self::assertSame('DTD declarations are not allowed in EncryptionInfo.', $exception->getMessage());
        }
    }

    public function testOrdinaryTextContainingNullBytesIsNotMistakenForADtd(): void
    {
        self::assertFalse(XmlDocument::hasDtdDeclaration("plain\0text"));
    }

    /**
     * Encode ASCII according to byte positions that contain zeros (1) or the byte (0).
     *
     * @param non-empty-list<0|1> $layout
     */
    private static function wide(string $ascii, array $layout): string
    {
        $encoded = '';
        foreach (str_split($ascii) as $byte) {
            foreach ($layout as $zero) {
                $encoded .= $zero === 1 ? "\0" : $byte;
            }
        }

        return $encoded;
    }
}
