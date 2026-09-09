<?php

declare(strict_types=1);

/**
 * End-to-end check that needs nothing but the runtime requirements.
 *
 * The test suite reads its own output with ext-zip, which is exactly the
 * extension this library no longer requires. This script covers the same ground
 * without it, so continuous integration can run it on a PHP build that has no
 * zip extension at all and prove the claim in composer.json.
 */

require __DIR__ . '/../vendor/autoload.php';

use DK\OpenXml\OfficeFileDetector;
use DK\OpenXml\OfficeFileFormat;
use DK\OpenXml\OpenXmlPackage;

if (extension_loaded('zip')) {
    fwrite(STDERR, "This check is meant for a PHP build without ext-zip.\n");
}

$failures = [];

function check(string $what, mixed $expected, mixed $actual): void
{
    global $failures;
    if ($expected !== $actual) {
        $failures[] = sprintf('%s: expected %s, got %s', $what, var_export($expected, true), var_export($actual, true));
    }
}

$filename = tempnam(sys_get_temp_dir(), 'openxml-smoke-');
$body = str_repeat('<paragraph>text</paragraph>', 5_000);
$binary = random_bytes(512 * 1024);

try {
    $package = OpenXmlPackage::create();
    $package->addPart('/word/document.xml', 'application/xml', $body);
    $package->addPart('/media/payload.bin', 'application/octet-stream', $binary);
    $package->addRelationship(
        'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument',
        '/word/document.xml',
    );
    $package->saveAs($filename);
    unset($package);

    check('detected format', OfficeFileFormat::OpcPackage, OfficeFileDetector::detect($filename));

    $package = OpenXmlPackage::open($filename);
    check('document contents', $body, $package->getPart('/word/document.xml')->getContents());

    $stream = $package->getPart('/media/payload.bin')->openStream();
    $read = stream_get_contents($stream);
    fclose($stream);
    check('streamed payload', $binary, $read);
    check('relationship count', 1, count($package->getRelationships()));

    // Replacing one part carries the other through untouched, which is the path
    // that copies compressed bytes from the source archive.
    $package->writePart('/word/document.xml', '<document/>');
    $package->saveAs($filename);
    unset($package);

    $package = OpenXmlPackage::open($filename);
    check('replaced part', '<document/>', $package->getPart('/word/document.xml')->getContents());
    check('carried payload', $binary, $package->getPart('/media/payload.bin')->getContents());

    // Without the ZIP extension there is no zip:// URI, so this has to fall back
    // to a package-owned copy rather than return nothing.
    check('readable path', $binary, (string) file_get_contents($package->getPart('/media/payload.bin')->getReadablePath()));
    check('local path', $binary, (string) file_get_contents($package->getPart('/media/payload.bin')->getLocalPath()));
    unset($package);
} finally {
    if (is_file($filename)) {
        unlink($filename);
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Smoke check passed without ext-zip.\n";
