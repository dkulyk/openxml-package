# Encryption and file detection

Office Open XML encryption wraps an OPC ZIP package in a CFBF/OLE container.
The optional [`dkulyk/compound-file`](https://packagist.org/packages/dkulyk/compound-file)
dependency supplies that outer container implementation.

```shell
composer require "dkulyk/compound-file:^0.2.7 || ^0.3"
```

The OpenSSL PHP extension is also required for encryption and decryption.

## Detecting the container

`OpenXmlPackage::open()` identifies the outer container before attempting ZIP
handling. Applications may use the same detector to route a file explicitly:

```php
use DK\OpenXml\OfficeFileDetector;
use DK\OpenXml\OfficeFileFormat;

$format = OfficeFileDetector::detect('document.docx');

if ($format === OfficeFileFormat::EncryptedOpcPackage) {
    // Ask for a password and use EncryptedOfficeFile::decrypt().
}
```

Detection distinguishes OPC ZIP, CFBF/OLE, encrypted OPC, and unknown input. A
CFBF signature requires `dkulyk/compound-file` for inspection. Without it,
`MissingDependencyException` includes the installation command. Incomplete or
invalid encryption streams produce dedicated exceptions.

## Encrypting and decrypting

```php
use DK\OpenXml\Encryption\EncryptedOfficeFile;

EncryptedOfficeFile::encrypt(
    source: 'document.docx',
    destination: 'protected.docx',
    password: 'a strong password',
);

EncryptedOfficeFile::decrypt(
    source: 'protected.docx',
    destination: 'decrypted.docx',
    password: 'a strong password',
);
```

New files use ECMA-376 Agile Encryption with AES-256-CBC, SHA-512, a
100,000-iteration password hash, random salts, password verification, and an
integrity HMAC. Payloads are processed in 4096-byte segments.

Agile decryption accepts AES-128, AES-192, and AES-256 with SHA-1, SHA-256,
SHA-384, or SHA-512 when the key-data and password profiles match. Decryption
also recognizes older Standard Encryption using AES-128, AES-192, or AES-256
with SHA-1. Standard Encryption remains read-only because it lacks Agile's
authenticated integrity metadata.

The destination is replaced only after cryptographic verification and OPC
validation succeed. A wrong password, modified ciphertext, or invalid package
leaves an existing destination untouched.

## Unsupported encryption

The library does not support:

- Extensible Encryption;
- RC4 encryption;
- XOR obfuscation;
- encrypted legacy binary `.doc`, `.xls`, and `.ppt` files.

## Public API

| API | Description |
| --- | --- |
| `OfficeFileDetector::detect(string $filename): OfficeFileFormat` | Identify OPC ZIP, CFBF, encrypted OPC, or unknown input. A ZIP without `[Content_Types].xml` reports `Unknown`. |
| `EncryptedOfficeFile::encrypt(...)` | Write Agile AES-256/SHA-512 encryption. |
| `EncryptedOfficeFile::decrypt(...)` | Atomically decrypt Agile or Standard Encryption into a validated OPC package. |
| `AgileEncryptionOptions` | Configure the password hash work factor for new files. |
| `EncryptionLimits` | Bound password work, encryption metadata, and decrypted output size. |
