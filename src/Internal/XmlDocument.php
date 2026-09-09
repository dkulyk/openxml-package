<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal;

use DK\OpenXml\Exception\OpenXmlException;

/** @internal */
final class XmlDocument
{
    public const DEFAULT_MAXIMUM_BYTES = 32 * 1024 * 1024;

    /** `<!DOCTYPE` in zero-padded XML encodings libxml can infer without a declaration. */
    private const WIDE_DTD_SIGNATURES = [
        "<\x00!\x00D\x00O\x00C\x00T\x00Y\x00P\x00E\x00",
        "\x00<\x00!\x00D\x00O\x00C\x00T\x00Y\x00P\x00E",
        "<\x00\x00\x00!\x00\x00\x00D\x00\x00\x00O\x00\x00\x00C\x00\x00\x00T\x00\x00\x00Y\x00\x00\x00P\x00\x00\x00E\x00\x00\x00",
        "\x00\x00\x00<\x00\x00\x00!\x00\x00\x00D\x00\x00\x00O\x00\x00\x00C\x00\x00\x00T\x00\x00\x00Y\x00\x00\x00P\x00\x00\x00E",
        "\x00\x00<\x00\x00\x00!\x00\x00\x00D\x00\x00\x00O\x00\x00\x00C\x00\x00\x00T\x00\x00\x00Y\x00\x00\x00P\x00\x00\x00E\x00",
        "\x00<\x00\x00\x00!\x00\x00\x00D\x00\x00\x00O\x00\x00\x00C\x00\x00\x00T\x00\x00\x00Y\x00\x00\x00P\x00\x00\x00E\x00\x00",
    ];

    private function __construct() {}

    /**
     * Escape a value for an XML attribute, matching what DOM produces: `&`, `<`, `>`
     * and `"` become entities and `'` is left alone.
     */
    public static function attributeValue(string $value, string $attributeName): string
    {
        $escaped = htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        if ($escaped === '' && $value !== '') {
            throw new OpenXmlException(sprintf('The %s attribute is not valid UTF-8.', $attributeName));
        }

        return $escaped;
    }

    /** Wrap serialized children in a root element, matching DOM's formatted output. */
    public static function serialize(string $rootName, string $namespace, string $body): string
    {
        $header = sprintf(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<%s xmlns=\"%s\"",
            $rootName,
            self::attributeValue($namespace, 'xmlns'),
        );

        return $body === ''
            ? $header . "/>\n"
            : $header . ">\n" . $body . '</' . $rootName . ">\n";
    }

    public static function load(
        string $xml,
        string $expectedRootName,
        string $expectedNamespace,
        int $maximumBytes,
    ): \DOMDocument {
        if (strlen($xml) > $maximumBytes) {
            throw new OpenXmlException(sprintf(
                'XML document exceeds the configured limit of %d bytes.',
                $maximumBytes,
            ));
        }

        if (self::hasDtdDeclaration($xml)) {
            throw new OpenXmlException('DTD declarations are not allowed in package XML.');
        }

        $document = new \DOMDocument();
        $document->preserveWhiteSpace = false;

        if (!@$document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new OpenXmlException('Invalid package XML.');
        }

        // Keep this structural check as defence in depth for encodings libxml may
        // accept beyond the signatures above.
        if ($document->doctype !== null) {
            throw new OpenXmlException('DTD declarations are not allowed in package XML.');
        }

        $root = $document->documentElement;
        if (
            $root === null
            || $root->localName !== $expectedRootName
            || $root->namespaceURI !== $expectedNamespace
        ) {
            throw new OpenXmlException(sprintf(
                'Expected {%s}%s as the document root.',
                $expectedNamespace,
                $expectedRootName,
            ));
        }

        return $document;
    }

    /** Detect a DTD before libxml can spend time expanding its internal entities. */
    public static function hasDtdDeclaration(string $xml): bool
    {
        if (str_contains($xml, '<!DOCTYPE')) {
            return true;
        }
        // ASCII-compatible XML cannot contain a wide signature. This keeps the
        // common UTF-8 path to two native byte scans regardless of document size.
        if (!str_contains($xml, "\0")) {
            return false;
        }

        foreach (self::WIDE_DTD_SIGNATURES as $signature) {
            if (str_contains($xml, $signature)) {
                return true;
            }
        }

        return false;
    }
}
