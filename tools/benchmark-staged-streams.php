<?php

declare(strict_types=1);

use DK\OpenXml\OpenXmlPackage;

require dirname(__DIR__) . '/vendor/autoload.php';

printf("PHP %s; %s; median of 7 opens; staging excluded\n", PHP_VERSION, PHP_OS_FAMILY);
echo "Run with XDEBUG_MODE=off for performance measurements.\n";
$chunk = random_bytes(65_536);
foreach ([1, 16, 64, 128] as $mebibytes) {
    $source = tmpfile();
    if ($source === false) {
        throw new RuntimeException('Unable to create benchmark input.');
    }
    try {
        for ($index = 0; $index < $mebibytes * 16; ++$index) {
            if (fwrite($source, $chunk) !== strlen($chunk)) {
                throw new RuntimeException('Unable to write benchmark input.');
            }
        }
        rewind($source);
        $package = OpenXmlPackage::create();
        $part = $package->addPartFromStream('/payload.bin', 'application/octet-stream', $source);
    } finally {
        fclose($source);
    }

    $samples = [];
    for ($run = 0; $run < 7; ++$run) {
        $started = hrtime(true);
        $stream = $part->openStream();
        fclose($stream);
        $samples[] = (hrtime(true) - $started) / 1_000_000;
    }
    sort($samples);
    $started = hrtime(true);
    $stream = $part->openStream();
    try {
        $hash = hash_init('sha256');
        $bytes = hash_update_stream($hash, $stream);
        hash_final($hash);
        if ($bytes !== $mebibytes * 1024 * 1024) {
            throw new RuntimeException('Incomplete benchmark read.');
        }
    } finally {
        fclose($stream);
    }
    printf(
        "%3d MiB: open + close %.4f ms; open + SHA-256 read + close %.3f ms\n",
        $mebibytes,
        $samples[3],
        (hrtime(true) - $started) / 1_000_000,
    );
    unset($part, $package);
}
