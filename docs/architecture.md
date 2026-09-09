# Architecture

The public surface follows Open Packaging Conventions terminology. ZIP and CFBF
are storage mechanisms rather than domain concepts exposed to package consumers.

```text
DK\OpenXml\OpenXmlPackage
├── Packaging\PackageInterface
├── Packaging\PartInterface / Part
├── Packaging\Relationships / RelationshipInterface
├── Packaging\ContentTypes
├── Signature\SignatureInspection / PackageSignature
└── Internal
    ├── Container\ContainerInterface / ZipContainer
    ├── Zip\CentralDirectory / Eocd / Entry (ZIP directory reader)
    ├── PartNameIndex
    ├── MaterializationPool
    └── SourceFileState

DK\OpenXml\Encryption\EncryptedOfficeFile
├── Internal\Encryption\AgileEncryption (compatible profiles read, modern profile write)
├── Internal\Encryption\StandardEncryption (read-only)
└── dkulyk/compound-file (optional CFBF integration)
```

## Packaging boundary

`Packaging` is the public OPC API. `Internal\Container` hides ZIP implementation
details and is not a compatibility surface. The container retains entry metadata
when opening a package and loads content only when requested.

`Internal\Zip` reads the ZIP central directory directly. It is a generator, so a
caller that wants one entry does not pay for the rest: opening a package walks
the whole directory to build its entry map and apply the limits that only make
sense over all of it, while file-format detection stops at the first match.
Nothing the archive declares is trusted before it is checked against the file
size. Entry contents are decoded by the same code, in bounded chunks: a part is
never held in memory in full unless the caller asks for it as a string, and the
decoder stops the moment a part produces more bytes than its directory record
declares. A part is accepted only when its decompressed size, its checksum and
the number of compressed bytes the decoder consumed all match that record. The
checksum comes from zlib rather than from PHP, through the trailer of a gzip
stream that compresses nothing: PHP computes CRC-32 a byte at a time, and on a
part of any size that dominated everything else the reader does.

Streamed writes are staged in temporary storage. Reads of unchanged entries go
through a stream wrapper over the decoder, so a part is decoded as it is read.
The wrapper reads forward and rewinds; it does not seek elsewhere. A container
opens its source archive once, shares it between active entry streams, and is
retained by each stream context until the caller closes it. Complete output is validated in a same-directory temporary
file before atomic replacement.

A part written from a stream that this library opened for an unchanged entry of
another package is staged as that entry rather than as its contents, so the copy
neither inflates nor deflates. The object that keeps the source container alive
travels with the staging, so the source stream can be closed straight away.

A save writes the output archive itself. Unchanged entries keep their compressed
representation: those that are adjacent in the source archive are copied in one
pass with their local headers, and the rest one at a time. Only replaced,
renamed and added entries are encoded. Content types are written first so that a
consumer reading the package as a stream knows what every later part is.

A weak internal registry coordinates containers for the same source path. Before
replacement it closes idle archives held by this process and rejects the write if
any container still has an active source stream. The registry does not extend a
container's lifetime.

`SourceFileState` records the file identity, size, and timestamps while a
package is opened. Lazy reads, the deferred reopen after a save, and an
in-place save immediately before atomic replacement all compare that metadata;
the package never hashes its source or output. A part added from a local path
records the same metadata for that file, which the package reads in place
instead of copying.

Staged contents that live in a file are held behind `StagedContents`: a
`StagedFile` owns a temporary file copied from a stream, a `StagedPath` refers to
a file the caller owns. Both open independent read handles rather than copying
the payload again, and a `StagedFile` outlives the part it was staged for as long
as a reader holds it.

Unchanged ZIP-backed parts can expose a native `zip://` URI to deferred
path-based consumers where the ZIP extension is installed, and a part staged from
a local path exposes that path. When neither applies, the internal
materialization pool copies the entry to private temporary storage. This pool is an implementation detail: callers receive
ordinary strings, and the package owns their lifetime.

The internal boundary allows container infrastructure to move into a shared
package later if ODF or another format demonstrates a real common abstraction. No
separate generic ZIP package is needed today.

## Domain boundary

This library owns OPC concerns only:

- package parts and part names;
- content-type declarations;
- package and part relationships;
- container safety, persistence, and Office encryption.

WordprocessingML, SpreadsheetML, and PresentationML object models belong in
specialized libraries built on top of this package. Resource deduplication also
remains outside the default package behavior until its relationship and semantic
policies are defined.

## Current limitations

- `getContents()` materializes a complete part; use `openStream()` or the path
  APIs for large payloads.
- Encrypted documents must be decrypted before opening them as OPC.
- Digital-signature structure can be inspected, but signatures are not
  cryptographically verified or preserved when saving.
- Atomic replacement requires same-directory rename support from the filesystem.
- Validation covers OPC structure, not the schemas of Office XML vocabularies.
