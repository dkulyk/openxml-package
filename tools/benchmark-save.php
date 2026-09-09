<?php

declare(strict_types=1);

use DK\OpenXml\OpenXmlPackage;

require dirname(__DIR__) . '/vendor/autoload.php';

printf("PHP %s; %s; median of 5 runs\n", PHP_VERSION, PHP_OS_FAMILY);
echo "Run with XDEBUG_MODE=off for performance measurements.\n";
echo "One part is replaced; every other part is carried to the output unchanged.\n";

foreach ([[40, 512 * 1024], [200, 128 * 1024], [2_000, 4 * 1024]] as [$partCount, $partBytes]) {
    $source = buildPackage($partCount, $partBytes);

    try {
        printf(
            "  %4d parts of %7d bytes (%5.1f MB): save %9.3f ms; peak %6.1f MB\n",
            $partCount,
            $partBytes,
            filesize($source) / 1_048_576,
            medianMilliseconds($source),
            memory_get_peak_usage(true) / 1_048_576,
        );
    } finally {
        unlink($source);
    }
}

function buildPackage(int $partCount, int $partBytes): string
{
    $filename = tempnam(sys_get_temp_dir(), 'openxml-save-');
    if ($filename === false) {
        throw new RuntimeException('Unable to create a temporary file.');
    }
    // Half random, half repetitive: compressible enough to be realistic, not a pathological case.
    $body = random_bytes(intdiv($partBytes, 2)) . str_repeat('x', $partBytes - intdiv($partBytes, 2));
    $package = OpenXmlPackage::create();
    for ($index = 0; $index < $partCount; ++$index) {
        $package->addPart('/parts/part-' . $index . '.bin', 'application/octet-stream', $body);
    }
    $package->saveAs($filename);

    return $filename;
}

function medianMilliseconds(string $source): float
{
    $samples = [];
    for ($run = 0; $run < 5; ++$run) {
        $destination = tempnam(sys_get_temp_dir(), 'openxml-save-out-');
        if ($destination === false) {
            throw new RuntimeException('Unable to create a temporary file.');
        }
        $package = OpenXmlPackage::open($source);
        $package->writePart('/parts/part-0.bin', 'replaced');
        $started = hrtime(true);
        $package->saveAs($destination);
        $samples[] = (hrtime(true) - $started) / 1_000_000;
        unset($package);
        unlink($destination);
    }
    sort($samples);

    return $samples[2];
}
