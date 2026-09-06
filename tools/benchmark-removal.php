<?php

declare(strict_types=1);

use DK\OpenXml\OpenXmlPackage;

require dirname(__DIR__) . '/vendor/autoload.php';

printf("PHP %s; %s; median of 3 runs; construction excluded\n", PHP_VERSION, PHP_OS_FAMILY);
echo "Run with XDEBUG_MODE=off for performance measurements.\n";

foreach ([false, true] as $cascade) {
    echo $cascade ? "Parts with package-level inbound relationships:\n" : "Unreferenced parts:\n";
    foreach ([500, 1000, 2000, 4000] as $partCount) {
        $sequential = removalMilliseconds($partCount, $cascade, false);
        $batch = removalMilliseconds($partCount, $cascade, true);
        printf(
            "  %4d parts: loop %9.3f ms; batch %8.3f ms; %6.1fx\n",
            $partCount,
            $sequential,
            $batch,
            $sequential / $batch,
        );
    }
}

function removalMilliseconds(int $partCount, bool $cascade, bool $batch): float
{
    $samples = [];
    for ($run = 0; $run < 3; ++$run) {
        $package = OpenXmlPackage::create();
        $names = [];
        for ($index = 0; $index < $partCount; ++$index) {
            $names[] = $name = '/parts/part-' . $index . '.xml';
            $package->addPart($name, 'application/xml', '<part/>');
            if ($cascade) {
                $package->addRelationship('urn:part', $name);
            }
        }
        $started = hrtime(true);
        if ($batch) {
            if ($cascade) {
                $package->removePartsAndRelationships($names);
            } else {
                $package->removeParts($names);
            }
        } else {
            foreach ($names as $name) {
                if ($cascade) {
                    $package->removePartAndRelationships($name);
                } else {
                    $package->removePart($name);
                }
            }
        }
        $samples[] = (hrtime(true) - $started) / 1_000_000;
        unset($package);
    }
    sort($samples);

    return $samples[1];
}
