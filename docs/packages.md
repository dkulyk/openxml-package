# Working with packages

`OpenXmlPackage` exposes the Open Packaging Conventions concepts shared by DOCX,
XLSX, and PPTX: parts, content types, and relationships. It deliberately does not
interpret the XML vocabulary inside a part.

Part names are strict absolute OPC IRIs such as `/word/document.xml`. Empty or
dot segments, trailing dots, malformed or aliasing percent encodings, query
strings, and fragments are rejected rather than silently normalized. Part-name
lookup and collision detection use the ASCII-case-insensitive equivalence rules
defined by OPC. Relative relationship targets remain valid and are resolved using
their source part as the base.

## Opening a package

`open()` accepts only OPC packages. A ZIP archive without `[Content_Types].xml`,
such as an OpenDocument file or a plain archive, is rejected with
`UnsupportedFileFormatException`, as are CFBF/OLE files and unrecognized data.

A package's document kind is the content type of the part its package-level
`officeDocument` relationship targets, which `getMainDocumentPart()` returns.
The library stays format-agnostic and does not decide which kinds are
acceptable; pass the content types the caller supports to `expecting`, and
`open()` rejects anything else.

```php
use DK\OpenXml\OpenXmlPackage;

$package = OpenXmlPackage::open('slides.pptx', expecting: [
    'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml',
    'application/vnd.openxmlformats-officedocument.presentationml.slideshow.main+xml',
]);

$mainDocumentPart = $package->getMainDocumentPart();
```

Comparison is case-insensitive. A package is rejected the same way when it
declares no main document part, when the relationship points at a part that is
absent from the archive, or when that part has no declared content type. The
check runs before part names are validated and indexed, so a file of the wrong
kind is rejected without paying for the index. Without `expecting`, `open()`
performs no document-kind check.

## Reading parts and relationships

```php
use DK\OpenXml\OpenXmlPackage;
use DK\OpenXml\Packaging\RelationshipType;

$package = OpenXmlPackage::open('document.docx');

$document = $package
    ->getRelationships()
    ->firstByType(RelationshipType::OFFICE_DOCUMENT)
    ?->getTargetPart();

$styles = $document
    ?->getRelationships()
    ->firstByType('urn:styles')
    ?->getTargetPart();
```

Part relationship targets are resolved relative to their source part. External
relationships retain their URI in `getTarget()` and return `null` from
`getTargetPart()`. Neither the source part nor an internal target has to exist
when a relationship is added, so parts and relationships can be registered in
any order; `validate()` and saving report relationships whose source or target
part never arrived.

## Creating a package

```php
$package = OpenXmlPackage::create();

$document = $package->addPart(
    '/word/document.xml',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml',
    '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>',
);

$package->addRelationship(RelationshipType::OFFICE_DOCUMENT, 'word/document.xml');
$document->addRelationship('urn:styles', 'styles.xml');
$package->saveAs('document.docx');
```

## Streaming large parts

Use streams for images, embedded files, and other large binary parts:

```php
$image = fopen('photo.png', 'rb');
if ($image === false) {
    throw new RuntimeException('Unable to open photo.png.');
}

try {
    $part = $package->addPartFromStream(
        '/word/media/image1.png',
        'image/png',
        $image,
    );
} finally {
    fclose($image);
}
```

Input streams are consumed from their current position to EOF and copied to
package-owned temporary storage. The library never closes the caller's resource,
and the caller may close it as soon as the method returns.

A part whose content type names an already-compressed payload — JPEG, PNG, GIF,
WebP, HEIC, HEIF, AVIF, JP2, MP3, MP4, Ogg or WebM audio, any `video/*`, ZIP,
gzip, or an embedded OPC or OpenDocument document — is stored in the package
rather than deflated a second time, which is the same size for far less work.
The content type has to match one of those exactly. A whole family is never
excluded, because an embedded document and the XML parts of the package share
their type prefix: the deck is `…presentationml.presentation` and a slide is
`…presentationml.slide+xml`. Types that do compress, including SVG, BMP, EMF,
WMF, VML drawings and OLE objects, and any type the library does not recognise,
are deflated.

Formats outlive releases of this library. A codec the list does not know about
is registered once, rather than special-cased at every call site:

```php
use DK\OpenXml\Packaging\ContentCompression;

ContentCompression::store('image/jxl');
```

Registration is additive and process-wide, applies to parts written after it,
and is undone by `ContentCompression::reset()`.

When a single part disagrees with what its content type implies — an
`application/octet-stream` that is really a ZIP, or a JPEG small enough that
deflate still wins — pass `$compress` at the point the bytes are written:

```php
$package->addPart('/word/embed/blob.bin', 'application/octet-stream', $bytes, compress: false);
$part->setContents($bytes, compress: true);
```

`null`, the default, leaves the decision to the content type. The argument is
accepted by `addPart()`, `addPartFromStream()`, `addPartFromPath()`,
`writePart()`, `writePartFromStream()`, `writePartFromPath()` and the matching
`setContents*()` methods of a part. It applies to parts the package writes; a
part carried through unchanged from the package it was opened from keeps the
compression method it already had.

```php
$contents = $part->openStream();
try {
    stream_copy_to_stream($contents, $destination);
} finally {
    fclose($contents);
}

$part->setContentsFromStream($replacement);
```

The caller owns streams returned by `openStream()` and must close them. Unchanged
parts in an opened package are read lazily from the source ZIP; staged parts use
an independent snapshot. A lazy stream keeps its backing ZIP container alive
until `fclose()` or resource destruction, so it remains usable if the caller's
package variable is released. Multiple simultaneous streams from the same
package share one open ZIP archive.

Streams guarantee sequential reading but are not guaranteed to be seekable;
seekability depends on the ZIP entry and runtime. Use `getLocalPath()` when a
consumer requires random access. Use `getContents()` and `setContents()` for
small parts. Close every caller-owned part stream before `save()` replaces the
opened source package; `saveAs()` to a different file remains available.

The package keeps its source ZIP open so its central directory is parsed only
once. Before an atomic `save()`, the library closes that archive after confirming
that no part streams are still using it, then reopens the replaced package on
its next part access. On
Windows, the library also coordinates idle source handles held by its other
package instances in the same process. An active part stream, `zip://` user, or
external process may still prevent replacement. If any reader must keep using
the source while writing, save under a different name.

## Paths for deferred consumers

Some image libraries and document writers accept a filename but not a PHP stream.
Use `getReadablePath()` when they understand PHP stream-wrapper paths:

```php
$package = OpenXmlPackage::open('slides.pptx');
$image = $package->getPart('/ppt/media/image1.png');

$writer->setImagePath($image->getReadablePath());
```

For an unchanged part in an opened ZIP, this normally returns a `zip://` URI and
avoids copying the entry before the consumer reads it. New and modified parts are
materialized automatically. Use `getLocalPath()` when the consumer specifically
requires a real local filesystem path.

A consumer reading that `zip://` URI reads it directly, so the size bound the
library applies to its own reads does not apply. For an untrusted package whose
parts go to such a consumer, prefer `getLocalPath()`, which materializes the
entry through a bounded read.

```php
$writer->setImagePath($image->getLocalPath());
```

The package owns materialized files. A returned local path remains valid while
the package is alive, including after that part is modified again. Keep the
package alive until every deferred consumer has finished reading its paths.

Local files can also be copied into a part without loading them into a string:

```php
$image = $package->addPartFromPath(
    '/ppt/media/image2.png',
    'image/png',
    'photo.png',
);

$image->setContentsFromPath('replacement.png');
```

Path input is copied immediately and is restricted to readable local files. Use
the stream methods for other PHP stream wrappers.

## Atomic edits

Mutations remain staged until `save()` or `saveAs()`:

```php
$package = OpenXmlPackage::open('document.docx');
$package->getPart('/word/document.xml')->setContents('<updated/>');

if ($package->hasChanges()) {
    $package->save();
}
```

The complete ZIP is written to a temporary file in the destination directory,
validated, and moved over the destination. Unchanged entries retain their
compressed representation through ZIP copy-through. If validation or writing
fails, the original file remains untouched.

The library detects concurrent source changes before lazy reads and saves by
comparing the file identity (device and inode), size, and second-resolution
modification and change timestamps recorded when the source was opened. This
is a fast guard rather than proof of byte identity: a replacement through
rename changes the inode and an in-place rewrite changes the change timestamp,
but an in-place rewrite of the same inode that preserves the file size and
completes within the same one-second timestamp resolution is not noticed. The
package never hashes the source or its output.
Use `discardChanges()` to restore the opened source state. For a focused edit,
use a callback that saves only after successful completion:

```php
OpenXmlPackage::edit('document.docx', function (OpenXmlPackage $package): void {
    $package->getPart('/word/document.xml')->setContents('<updated/>');
});
```

## Public API

### `OpenXmlPackage`

| Method | Description |
| --- | --- |
| `create(?PackageLimits $limits = null): self` | Create an empty OPC package. |
| `open(string $filename, ?PackageLimits $limits = null): self` | Open and validate an OPC ZIP package lazily. |
| `edit(string $filename, callable $edit, ?PackageLimits $limits = null): void` | Apply an edit and atomically save when the callback succeeds. |
| `hasPart(string $name): bool` | Check whether a valid OPC part exists; invalid names and package metadata return `false`. |
| `getPart(string $name): PartInterface` | Return an ordinary or relationship part without loading its contents. |
| `getParts(): Traversable` | Iterate ordinary package parts, excluding relationship parts and `[Content_Types].xml`. |
| `getPartContentType(string $name): ?string` | Return the content type registered for a part, or `null` when none covers it. |
| `addPart(string $name, string $contentType, string $contents): PartInterface` | Add or replace a small string-backed part. |
| `addPartFromStream(string $name, string $contentType, resource $stream): PartInterface` | Stage bytes from the stream's current position to EOF. |
| `addPartFromPath(string $name, string $contentType, string $path): PartInterface` | Stage bytes copied from a readable local file. |
| `setDefaultContentType(string $extension, string $contentType): void` | Declare a content type for every part with this extension that has no override. |
| `removePart(string $name): void` | Remove an unreferenced part and its relationship part. |
| `removeParts(array $names): void` | Remove unreferenced parts in one batch, checking all names and references before removal. |
| `getInboundRelationships(string $partName): array` | Return package and part relationships targeting a part. |
| `removePartAndRelationships(string $name): PartRemovalResult` | Explicitly remove a part and every inbound relationship. |
| `removePartsAndRelationships(array $names): array` | Remove a batch and its inbound relationships, returning a list of `PartRemovalResult`. |
| `movePart(string $source, string $destination): PartInterface` | Move a part and update relationships that depend on its name. |
| `getRelationships(?string $sourcePartName = null): Relationships` | Read package or part relationships. |
| `addRelationship(...)` | Add a package-level or part-level relationship. |
| `removeRelationship(string $id, ?string $sourcePartName = null): void` | Remove a package-level or part-level relationship. |
| `inspectSignatures(): SignatureInspection` | Inspect the OPC signature structure and return its status, parts, references, and issues. |
| `removeSignatures(): SignatureRemovalResult` | Explicitly stage removal of signature parts, certificates, relationships, and content types. |
| `validate(): array` | Return structural issues without saving. |
| `analyzeRepairs(PackageRepairOptions $options): RepairReport` | Report enabled safe repairs without changing the package. |
| `applyRepairs(PackageRepairOptions $options): RepairReport` | Stage enabled repairs and return the applied actions. |
| `hasChanges(): bool` | Report whether edits are staged. |
| `discardChanges(): void` | Restore the source package or reset a new package. |
| `save(): void` | Atomically replace the opened source. |
| `saveAs(string $filename): void` | Atomically write to another path. |

Relationship parts are readable through `getPart()` and the raw part access
methods below, but their contents cannot be replaced through `PartInterface` or
the raw write methods. Use `getRelationships()`, `addRelationship()`, and
`removeRelationship()` so the in-memory relationship collection and its XML
representation remain synchronized.

New packages declare `Default` content types for `rels` and `xml`. Adding a part
whose content type already matches the default for its extension writes no
`Override`; any other content type gets one. Declare further defaults with
`setDefaultContentType()` before adding media parts to keep
`[Content_Types].xml` small. Resolution follows OPC: an `Override` for the part
name wins, otherwise the `Default` for its extension applies.

`inspectSignatures()` is structural inspection, not cryptographic verification.
See [Digital signatures](signatures.md) for its trust boundary and examples.

`validate()` reports missing relationship targets, invalid internal targets,
orphan relationship parts, missing or stale content-type declarations, incorrect
relationship content types, and unsupported digital-signature preservation.

### Raw part access

`OpenXmlPackage` also exposes name-based access that skips the `PartInterface`
wrapper. The read methods accept relationship parts; the write methods reject
them, like `PartInterface` does.

| Method | Description |
| --- | --- |
| `readPart(string $name): string` | Materialize and return the complete part. |
| `openPartStream(string $name): resource` | Open a readable stream that retains its backing ZIP container until the caller closes it. |
| `getPartReadablePath(string $name): string` | Return a `zip://` URI for an unchanged entry, otherwise a package-owned local path. |
| `getPartLocalPath(string $name): string` | Return a package-owned local filesystem path, materializing staged contents. |
| `writePart(string $name, string $contents): void` | Replace an existing part's contents with a string. |
| `writePartFromStream(string $name, resource $stream): void` | Replace an existing part's contents from the stream's current position to EOF. |
| `writePartFromPath(string $name, string $path): void` | Replace an existing part's contents with bytes copied from a readable local file. |

### `PartInterface`

| Method | Description |
| --- | --- |
| `getName(): string` | Return the normalized absolute part name. |
| `getContentType(): string` | Return the registered MIME content type. |
| `getContents(): string` | Materialize and return the complete part. |
| `setContents(string $contents): void` | Stage complete string contents. |
| `openStream(): resource` | Open a readable stream that retains its backing ZIP container until the caller closes it. |
| `getReadablePath(): string` | Return a `zip://` URI when possible, otherwise a package-owned local path. |
| `getLocalPath(): string` | Return a package-owned local filesystem path. |
| `setContentsFromStream(resource $stream): void` | Stage data from the current cursor to EOF. |
| `setContentsFromPath(string $path): void` | Stage bytes copied from a readable local file. |
| `getRelationships(): Relationships` | Return relationships originating at this part. |
| `addRelationship(...)` | Create an internal or external relationship. |
| `removeRelationship(string $id): void` | Remove a relationship by ID. |

### `Relationships`

| Method | Description |
| --- | --- |
| `create(string $type, string $target, bool $external = false, ?string $id = null): RelationshipInterface` | Create and add a relationship. |
| `add(RelationshipInterface $relationship): void` | Add an existing relationship object. |
| `get(string $id): RelationshipInterface` | Return a relationship by ID. |
| `firstByType(string $type): ?RelationshipInterface` | Return the first relationship with an exact type. |
| `getByType(string $type): array` | Return every relationship with an exact type. |
| `firstByTarget(string $target): ?RelationshipInterface` | Match the serialized target exactly. |
| `getByTarget(string $target): array` | Return all exact serialized-target matches. |
| `getByTargetPart(string $partName): array` | Match internal relationships by normalized resolved part name. |
| `remove(string $id): void` | Remove one relationship by ID. |
| `retarget(string $id, string $target): RelationshipInterface` | Replace a target while preserving ID, type, and target mode. |
| `removeByTargetPart(string $partName): int` | Remove internal relationships to a resolved part and return their count. |

Raw target lookup distinguishes relative and absolute spellings. Resolved target
lookup treats `media/image.png` and `/word/media/image.png` as the same target
when both resolve from `/word/document.xml` to `/word/media/image.png`. External
relationships are never returned or removed by resolved-part operations.

## Explicit package repair

Repair is opt-in and separated into analysis and application. Enable only the
operations appropriate for the application:

```php
use DK\OpenXml\Repair\PackageRepairOptions;

$options = new PackageRepairOptions(
    removeDanglingRelationships: true,
    removeInvalidRelationships: true,
    removeOrphanRelationshipParts: true,
    removeStaleContentTypeOverrides: true,
    correctRelationshipContentTypes: true,
);

$report = $package->analyzeRepairs($options);
foreach ($report as $action) {
    echo $action->description, PHP_EOL;
}

$applied = $package->applyRepairs($options);
$package->save();
```

`analyzeRepairs()` never stages changes. `applyRepairs()` returns the same typed
actions and modifies only the categories enabled in `PackageRepairOptions`.
Malformed relationship XML and digital signatures are reported by validation but
are not repaired automatically because their intended meaning cannot be inferred.

`movePart()` also moves the part's relationship part. It rewrites relationships
that target the moved part and adjusts its relative outgoing targets when the
part changes directory. Existing destination parts are never overwritten.

`removePart()` refuses to remove a referenced part and throws
`PartInUseException`, whose references identify every relationship source and ID.
Use the explicitly cascading operation only when removing all inbound references
is intended:

```php
$references = $package->getInboundRelationships('/word/media/image1.png');

$result = $package->removePartAndRelationships('/word/media/image1.png');
foreach ($result->getRemovedRelationships() as $reference) {
    echo $reference->sourcePartName ?? 'package', ': ',
        $reference->relationship->getId(), PHP_EOL;
}
```

Relationships to other shared resources are unchanged. Both removal methods also
remove the deleted part's own relationship part and content-type override.

For bulk deletion, pass a list to `OpenXmlPackage::removeParts()` or
`OpenXmlPackage::removePartsAndRelationships()` instead of calling the single-part
method in a loop. Each batch scans the package's relationships once, so removing
many parts takes O(P + R + D) work for P parts, R relationships, and D input names,
apart from the lengths of names and targets.

```php
$package->removeParts(['/word/media/unused1.png', '/word/media/unused2.png']);

$results = $package->removePartsAndRelationships([
    '/word/media/image1.png',
    '/word/media/image2.png',
]);
```

Both methods check all input names and resolve internal relationship targets
before removing anything. `removeParts()` rejects a selected part that anything
outside the batch still references. A reference from another part in the same
batch does not block removal: that part's relationship part goes with it, so
nothing is left pointing at the target. This keeps a batch equivalent to the loop
of single removals it replaces, and unlike that loop the outcome does not depend
on the order of the names. Use `removePartsAndRelationships()` to remove
references held by parts the package keeps, including cycles and self-references.
External relationships are not inbound part references. Deleting a source part
still removes its entire relationship part.

Equivalent names, including case variants, are removed once. Cascading results
use stored part names and follow the first occurrence of each name in the input;
each result reports inbound relationships from the graph before removal. An empty
list is a no-op. These batch helpers are available on `OpenXmlPackage`; the
`PackageInterface` contract is unchanged. Existing single-part loops still perform
one scan per call.
