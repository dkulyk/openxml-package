# Security and limits

Treat uploaded Office documents as untrusted archives. Compressed data,
relationship XML, content types, and encryption metadata may all be controlled by
an attacker.

## OPC package limits

ZIP entries are inspected before their contents are materialized. Configure
limits appropriate for the application:

```php
use DK\OpenXml\OpenXmlPackage;
use DK\OpenXml\Security\PackageLimits;

$package = OpenXmlPackage::open('upload.docx', new PackageLimits(
    maximumEntries: 2_000,
    maximumPartBytes: 32 * 1024 * 1024,
    maximumPackageBytes: 256 * 1024 * 1024,
    maximumCompressionRatio: 200.0,
    maximumXmlBytes: 8 * 1024 * 1024,
));
```

The package reader rejects unsafe and duplicate ZIP names, suspicious expansion
ratios, DTDs, malformed content-type and relationship XML, encrypted ZIP
entries, split archives, directories that point outside the file, and data
beyond the configured limits. It also rejects case-equivalent part names, names derivable
from another part name, and percent-encoded aliases before part contents are read.

Limits are checked against what the ZIP directory declares. An entry that
inflates past its declared size is stopped while it is being read, so a lying
directory buys an attacker one buffer rather than the whole expansion. That
covers `getContents()`, `openStream()`, `getLocalPath()`, and everything the
library itself parses. A copy-through save carries such an entry to the output
unchanged; nothing expands, and reading it from the output fails the same way.

The one read the library cannot bound is a path `getReadablePath()` returns: the
consumer opens it themselves, so nothing the library holds is in that path. That
is the `zip://` URI of an unchanged entry, or the caller's own file for a part
added from a local path. Applications that hand untrusted packages to a consumer reading raw
paths should use `getLocalPath()`, which materializes the entry through the
bounded read, or apply their own limit to what they read.

`getLocalPath()` and the local fallback of `getReadablePath()` create private
temporary files containing the uncompressed part bytes. They use a private
directory and are removed when the owning package is released. Applications must
keep the package alive while paths are in use and should treat those paths as
sensitive when parts contain confidential data.

## Encryption limits

Encrypted input has separate bounds for attacker-controlled password work and
decrypted output size:

```php
use DK\OpenXml\Encryption\EncryptedOfficeFile;
use DK\OpenXml\Encryption\EncryptionLimits;

EncryptedOfficeFile::decrypt(
    'upload.docx',
    'document.docx',
    $password,
    new EncryptionLimits(
        maximumSpinCount: 500_000,
        maximumDecryptedBytes: 256 * 1024 * 1024,
    ),
);
```

Agile encryption integrity is authenticated before output replacement. Wrong
passwords and malformed metadata fail with specific exceptions; modified Agile
payloads also fail integrity verification. Standard Encryption is supported for
legacy reads but does not provide Agile's authenticated integrity metadata.

`EncryptionLimits` bounds password-hash iterations, the `EncryptionInfo` stream,
and the declared decrypted package size. Unsigned 64-bit size fields that cannot
be represented safely by the current PHP build are rejected before arithmetic or
allocation.

## Validation boundary

The library validates OPC container structure and the integrity rules it manages.
It does not validate WordprocessingML, SpreadsheetML, or PresentationML schemas.
Applications handling those vocabularies remain responsible for domain-specific
validation.

Digitally signed packages may be inspected, but inspection does not authenticate
their contents or signer. It does not recalculate reference digests, verify the
signature value, validate certificates, or establish trust. See
[Digital signatures](signatures.md) for the complete boundary.

Saving is blocked whenever signature material is detected because a rewrite
cannot currently preserve a valid signature.

For reporting vulnerabilities and supported release information, see the
[security policy](../SECURITY.md).
