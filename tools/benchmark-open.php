<?php

declare(strict_types=1);

use DK\OpenXml\OfficeFileDetector;
use DK\OpenXml\OfficeFileFormat;
use DK\OpenXml\OpenXmlPackage;

require dirname(__DIR__) . '/vendor/autoload.php';

printf("PHP %s; %s; median of 5 runs\n", PHP_VERSION, PHP_OS_FAMILY);
echo "Run with XDEBUG_MODE=off for performance measurements.\n";
echo "Opening a package reads its whole ZIP directory; nothing else is measured.\n";

foreach ([100, 500, 1000, 2000, 4000] as $partCount) {
    $filename = buildPackage($partCount);

    try {
        printf(
            "  %4d parts: open %8.3f ms; detect %8.3f ms\n",
            $partCount,
            medianMilliseconds(static fn(): object => OpenXmlPackage::open($filename)),
            medianMilliseconds(static fn(): OfficeFileFormat => OfficeFileDetector::detect($filename)),
        );
    } finally {
        unlink($filename);
    }
}

function buildPackage(int $partCount): string
{
    $filename = tempnam(sys_get_temp_dir(), 'openxml-open-');
    if ($filename === false) {
        throw new RuntimeException('Unable to create a temporary file.');
    }
    $package = OpenXmlPackage::create();
    for ($index = 0; $index < $partCount; ++$index) {
        $package->addPart('/parts/part-' . $index . '.xml', 'application/xml', '<part/>');
    }
    $package->saveAs($filename);

    return $filename;
}

function medianMilliseconds(Closure $subject): float
{
    $samples = [];
    for ($run = 0; $run < 5; ++$run) {
        $started = hrtime(true);
        $subject();
        $samples[] = (hrtime(true) - $started) / 1_000_000;
    }
    sort($samples);

    return $samples[2];
}
