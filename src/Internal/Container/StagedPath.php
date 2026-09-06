<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal\Container;

use DK\OpenXml\Exception\OpenXmlException;
use DK\OpenXml\Internal\SourceFileState;

/**
 * @internal Staged contents that stay in the caller's own file.
 *
 * A part added from a path used to be copied into a temporary file, so its bytes
 * were read once to stage and once to save. The file is read only when the
 * package needs it, and the identity and timestamps recorded here are checked
 * every time, so a file replaced behind the package's back is refused rather
 * than silently saved.
 */
final class StagedPath implements StagedContents
{
    private SourceFileState $state;

    public function __construct(private string $path)
    {
        $this->state = SourceFileState::capture($path, 'Part source file');
    }

    public function read(string $name): string
    {
        $contents = @file_get_contents($this->assertUnchanged($name));
        if ($contents === false) {
            throw new OpenXmlException(sprintf('Unable to read staged contents for part "%s".', $name));
        }

        return $contents;
    }

    /** @return resource */
    public function openStream(string $name)
    {
        $stream = @fopen($this->assertUnchanged($name), 'rb');
        if ($stream === false) {
            throw new OpenXmlException(sprintf('Unable to open staged contents for part "%s".', $name));
        }

        return $stream;
    }

    public function path(string $name): string
    {
        return $this->assertUnchanged($name);
    }

    private function assertUnchanged(string $_name): string
    {
        $this->state->assertUnchanged();

        return $this->path;
    }
}
