<?php

declare(strict_types=1);

use DK\OpenXml\OpenXmlPackage;

require dirname(__DIR__) . '/vendor/autoload.php';

printf("PHP %s; %s; medians of 5 runs; construction excluded\n", PHP_VERSION, PHP_OS_FAMILY);
echo "Run with XDEBUG_MODE=off. Each package has parts but no relationships.\n";
foreach ([1000, 4000, 8000] as $count) {
    foreach (['validate', 'inbound', 'move', 'remove-signatures'] as $operation) {
        $times = [];
        $retained = [];
        for ($run = 0; $run < 5; ++$run) {
            $package = OpenXmlPackage::create();
            for ($index = 0; $index < $count; ++$index) {
                $package->addPart('/parts/p' . $index . '.xml', 'application/xml', '<part/>');
            }
            $memory = memory_get_usage();
            $start = hrtime(true);
            match ($operation) {
                'validate' => $package->validate(),
                'inbound' => $package->getInboundRelationships('/parts/p0.xml'),
                'move' => $package->movePart('/parts/p0.xml', '/parts/moved.xml'),
                'remove-signatures' => $package->removeSignatures(),
            };
            $times[] = (hrtime(true) - $start) / 1_000_000;
            $retained[] = memory_get_usage() - $memory;
            unset($package);
        }
        sort($times);
        sort($retained);
        printf("%4d parts; %-17s %8.3f ms; retained %9d B\n", $count, $operation, $times[2], $retained[2]);
    }
}
