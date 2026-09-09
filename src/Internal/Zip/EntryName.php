<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal\Zip;

use DK\OpenXml\Exception\OpenXmlException;

/** @internal */
final class EntryName
{
    private function __construct() {}

    public static function assertSafe(string $name): void
    {
        $segments = explode('/', $name);
        if (
            $name === ''
            || str_starts_with($name, '/')
            || str_contains($name, '\\')
            || str_contains($name, "\0")
            || in_array('', $segments, true)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
        ) {
            throw new OpenXmlException(sprintf('Unsafe ZIP entry name "%s".', $name));
        }
    }
}
