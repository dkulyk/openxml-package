<?php

declare(strict_types=1);

use DK\OpenXml\OpenXmlPackage;

require dirname(__DIR__) . '/vendor/autoload.php';

printf("PHP %s; %s; median of 5 runs\n", PHP_VERSION, PHP_OS_FAMILY);
echo "Run with XDEBUG_MODE=off for performance measurements.\n";
echo "The destination is a pipe, as a socket would be. \"temporary file\" writes the\n";
echo "package to disk and copies it out, which is what a download needs without\n";
echo "saveTo(); \"stream\" hands the same package straight to the pipe.\n";

foreach ([[4, 1_048_576], [1, 16 * 1_048_576], [1, 64 * 1_048_576]] as [$partCount, $partBytes]) {
    $results = [];
    foreach (['temporary file', 'stream'] as $strategy) {
        $totals = [];
        $firstBytes = [];
        for ($run = 0; $run < 5; ++$run) {
            [$total, $firstByte] = measure($strategy, $partCount, $partBytes);
            $totals[] = $total;
            $firstBytes[] = $firstByte;
        }
        sort($totals);
        sort($firstBytes);
        $results[$strategy] = [$totals[2], $firstBytes[2]];
    }

    printf("  %d part(s) of %5.1f MB\n", $partCount, $partBytes / 1_048_576);
    foreach ($results as $strategy => [$total, $firstByte]) {
        printf("    %-14s total %8.1f ms; first byte %8.1f ms\n", $strategy, $total, $firstByte);
    }
}

/** @return array{float, float} Milliseconds until the last and the first byte reach the pipe. */
function measure(string $strategy, int $partCount, int $partBytes): array
{
    $package = buildPackage($partCount, $partBytes);
    $sink = popen('cat > /dev/null', 'w');
    if ($sink === false) {
        throw new RuntimeException('Unable to open the destination pipe.');
    }

    try {
        $started = hrtime(true);
        if ($strategy === 'stream') {
            // The first entry reaches the pipe as soon as it is written.
            $firstByte = $started;
            $package->saveTo($sink);
        } else {
            $temporaryFile = tempnam(sys_get_temp_dir(), 'openxml-stream-save-');
            if ($temporaryFile === false) {
                throw new RuntimeException('Unable to create a temporary file.');
            }

            try {
                $package->saveAs($temporaryFile);
                // Nothing can be sent before the whole package exists.
                $firstByte = hrtime(true);
                $source = fopen($temporaryFile, 'rb');
                if ($source === false) {
                    throw new RuntimeException('Unable to reread the temporary file.');
                }
                stream_copy_to_stream($source, $sink);
                fclose($source);
            } finally {
                unlink($temporaryFile);
            }
        }
        $finished = hrtime(true);
    } finally {
        pclose($sink);
    }

    return [($finished - $started) / 1_000_000, ($firstByte - $started) / 1_000_000];
}

function buildPackage(int $partCount, int $partBytes): OpenXmlPackage
{
    $package = OpenXmlPackage::create();
    $package->addPart('/document.xml', 'application/xml', '<document/>');
    for ($index = 0; $index < $partCount; ++$index) {
        $payload = fopen('php://temp/maxmemory:0', 'r+b');
        if ($payload === false) {
            throw new RuntimeException('Unable to stage a benchmark payload.');
        }
        // Incompressible, so the measurement is the archive's own work.
        for ($written = 0; $written < $partBytes; $written += 65_536) {
            fwrite($payload, random_bytes(min(65_536, $partBytes - $written)));
        }
        rewind($payload);
        $package->addPartFromStream('/media/payload-' . $index . '.bin', 'application/octet-stream', $payload, false);
        fclose($payload);
    }

    return $package;
}
