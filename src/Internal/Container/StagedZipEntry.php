<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal\Container;

use DK\OpenXml\Exception\OpenXmlException;
use DK\OpenXml\Internal\SourceFileState;
use DK\OpenXml\Internal\Zip\Entry;
use DK\OpenXml\Internal\Zip\ZipReader;

/**
 * @internal Staged contents that stay compressed in another package's archive.
 *
 * A part copied straight from one open package to another arrives as a stream
 * nobody has read yet, which means the bytes the source archive holds are still
 * exactly the bytes the destination wants. This keeps the source entry instead of
 * the decoded contents, so the copy neither inflates nor deflates anything.
 *
 * The object that kept the source stream's container alive is held here too, so
 * closing that stream does not take the archive away before the save.
 */
final class StagedZipEntry implements StagedContents
{
    private SourceFileState $state;

    public function __construct(
        private readonly ZipReader $reader,
        private readonly Entry $entry,
        private readonly mixed $sourceOwner,
    ) {
        $this->state = SourceFileState::capture($reader->filename(), 'Part source package');
    }

    public function entry(): Entry
    {
        return $this->entry;
    }

    public function reader(): ZipReader
    {
        $this->state->assertUnchanged();

        return $this->reader;
    }

    public function read(string $name): string
    {
        return $this->reader()->read($this->entry, $name);
    }

    /** @return resource */
    public function openStream(string $name)
    {
        return $this->reader()->openStream($this->entry, $name);
    }

    public function path(string $_name): string
    {
        // Only a part staged in a file of its own has a path. This one lives inside
        // another archive, so the container materializes it instead; nothing calls
        // this, and returning the archive's own path would be a lie.
        throw new OpenXmlException('A part carried from another package has no file of its own.');
    }

    /** Keeps the source container alive for as long as this staging exists. */
    public function sourceOwner(): mixed
    {
        return $this->sourceOwner;
    }
}
