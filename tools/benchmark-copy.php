<?php

declare(strict_types=1);

/** Moving one large part from one package to another. */

require __DIR__ . '/../vendor/autoload.php';

use DK\OpenXml\OpenXmlPackage;

$source = tempnam(sys_get_temp_dir(), 'openxml-copy-source-');
$destination = tempnam(sys_get_temp_dir(), 'openxml-copy-destination-');

try {
    $payload = tmpfile();
    if ($payload === false) {
        fwrite(STDERR, "Unable to create the benchmark payload.\n");
        exit(1);
    }
    // Compressible, so the deflate the copy avoids is real work rather than a
    // pass over incompressible bytes that deflate gives up on.
    $block = str_repeat('<paragraph>the quick brown fox</paragraph>', 512);
    $bytes = 0;
    while ($bytes < 16 * 1024 * 1024) {
        fwrite($payload, $block);
        $bytes += strlen($block);
    }
    rewind($payload);

    $package = OpenXmlPackage::create();
    $package->addPart('/document.xml', 'application/xml', '<document/>');
    $package->addPartFromStream('/word/body.xml', 'application/xml', $payload);
    $package->saveAs($source);
    unset($package);
    gc_collect_cycles();

    $package = OpenXmlPackage::open($source);
    $stream = $package->getPart('/word/body.xml')->openStream();

    $started = hrtime(true);
    $copy = OpenXmlPackage::create();
    $copy->addPartFromStream('/word/body.xml', 'application/xml', $stream);
    $copy->saveAs($destination);
    $seconds = (hrtime(true) - $started) / 1_000_000_000;
    fclose($stream);
    unset($copy, $package);

    printf(
        "Copy a %.0f MiB part between packages: %.1f ms\n",
        $bytes / 1024 / 1024,
        $seconds * 1000,
    );
} finally {
    foreach ([$source, $destination] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}
