<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal\Zip;

/**
 * MS-DOS timestamps: local time, two-second resolution, 1980 to 2107.
 *
 * @internal
 */
final class Dos
{
    private function __construct() {}

    /**
     * @return array{int, int} Time and date fields.
     */
    public static function stamp(?int $timestamp = null): array
    {
        $parts = getdate($timestamp ?? time());
        $year = min(2107, max(1980, $parts['year']));

        return [
            ($parts['hours'] << 11) | ($parts['minutes'] << 5) | ($parts['seconds'] >> 1),
            (($year - 1980) << 9) | ($parts['mon'] << 5) | $parts['mday'],
        ];
    }
}
