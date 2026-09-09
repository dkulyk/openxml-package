# DK OpenXml Package

[![Latest Stable Version](https://img.shields.io/packagist/v/dkulyk/openxml-package.svg)](https://packagist.org/packages/dkulyk/openxml-package)
[![Total Downloads](https://img.shields.io/packagist/dt/dkulyk/openxml-package.svg)](https://packagist.org/packages/dkulyk/openxml-package)
[![PHP Version](https://img.shields.io/packagist/dependency-v/dkulyk/openxml-package/php.svg)](https://packagist.org/packages/dkulyk/openxml-package)
[![CI](https://github.com/dkulyk/openxml-package/actions/workflows/ci.yml/badge.svg)](https://github.com/dkulyk/openxml-package/actions/workflows/ci.yml)
[![License](https://img.shields.io/packagist/l/dkulyk/openxml-package.svg)](https://github.com/dkulyk/openxml-package/blob/main/LICENSE)

A PHP 8.1+ library for reading, creating, editing, encrypting, and decrypting
Open Packaging Conventions (OPC) packages used by DOCX, XLSX, and PPTX files.

The library works with package-level concepts—parts, content types, and
relationships. It does not model WordprocessingML, SpreadsheetML, or
PresentationML documents.

## Features

- Read, create, and atomically update OPC packages.
- Lazily stream large images and embedded files without keeping them in PHP strings.
- Write a package to an open stream, such as `php://output`, without staging a
  temporary file and without waiting for the whole package before the first byte.
- Pass unchanged parts to path-based consumers through `zip://` URIs where the ZIP
  extension is installed, with automatic package-owned local materialization when
  a native URI is unavailable.
- Navigate and modify package-level and part-level relationships.
- Inspect OPC digital-signature parts and references without claiming cryptographic verification.
- Explicitly remove signature material when producing an unsigned copy.
- Detect ZIP, CFBF/OLE, encrypted OOXML, and unknown containers.
- Read Agile and Standard Office encryption and write modern Agile encryption.
- Apply configurable limits to untrusted ZIP and encrypted input.
- Reject unsafe paths, duplicate entries, DTDs, malformed metadata, and modified ciphertext.

## Requirements

- PHP 8.1 or newer;
- DOM extension;
- zlib extension.

The ZIP extension is no longer needed. The library reads and writes the archive
itself.

Encryption additionally requires the OpenSSL extension and
[`dkulyk/compound-file:^0.2`](https://packagist.org/packages/dkulyk/compound-file).

## Installation

```shell
composer require dkulyk/openxml-package
```

For encrypted Office files, also install the optional CFBF implementation:

```shell
composer require dkulyk/compound-file:^0.2
```

## Quick start

Open a package and follow its office-document relationship:

```php
use DK\OpenXml\OpenXmlPackage;
use DK\OpenXml\Packaging\RelationshipType;

$package = OpenXmlPackage::open('document.docx');

$document = $package
    ->getRelationships()
    ->firstByType(RelationshipType::OFFICE_DOCUMENT)
    ?->getTargetPart();

$xml = $document?->getContents();
```

Create and save a minimal package:

```php
$package = OpenXmlPackage::create();

$document = $package->addPart(
    '/word/document.xml',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml',
    '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>',
);

$package->addRelationship(
    RelationshipType::OFFICE_DOCUMENT,
    'word/document.xml',
);

$package->saveAs('document.docx');
```

Changes to an opened package stay staged until `save()` or `saveAs()` succeeds.
The destination is replaced atomically; validation and write failures leave the
existing file untouched. `saveTo()` writes the package to an open stream, which
need not be seekable, and leaves the opened source and its staged edits alone.

```php
OpenXmlPackage::edit('document.docx', function (OpenXmlPackage $package): void {
    $package->getPart('/word/document.xml')->setContents('<updated/>');
});
```

## Documentation

- [Working with packages](docs/packages.md) — parts, streams, relationships,
  atomic edits, and the public packaging API.
- [Encryption and file detection](docs/encryption.md) — container routing,
  encryption support, and password-protected Office files.
- [Digital signatures](docs/signatures.md) — structural inspection, reported
  metadata, trust boundaries, and save behavior.
- [Security and limits](docs/security.md) — safe processing of untrusted files
  and current validation boundaries.
- [Architecture](docs/architecture.md) — package layers, internal ZIP boundary,
  extension points, and current limitations.
- [Contributing](CONTRIBUTING.md) — development setup, tests, benchmarks, and
  pull-request expectations.
- [Security policy](SECURITY.md) — supported versions and private reporting.

## Development

```shell
composer install
composer check
composer benchmark
```

`composer check` runs Composer validation, syntax checks, PHP CS Fixer, PHPStan
at level `max`, and PHPUnit. CI additionally audits locked dependencies and
covers PHP 8.1–8.5, lowest supported dependencies, and Windows. Scheduled
workflows exercise LibreOffice interoperability and publish package benchmark
results.

## License

DK OpenXml Package is open-source software licensed under the
[MIT License](https://github.com/dkulyk/openxml-package/blob/main/LICENSE).
