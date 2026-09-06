<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal\Container;

use DK\OpenXml\Exception\OpenXmlException;

/** @internal Owns an immutable staged temporary file shared by independent readers. */
final class StagedFile implements StagedContents
{
    /** @param resource $stream Ownership of the temporary file is transferred to this object. */
    public function __construct(private $stream) {}

    public function __destruct()
    {
        fclose($this->stream);
    }

    public function read(string $name): string
    {
        $position = ftell($this->stream);
        if ($position === false || fseek($this->stream, 0) !== 0) {
            throw new OpenXmlException(sprintf('Unable to rewind streamed contents for part "%s".', $name));
        }

        try {
            $contents = stream_get_contents($this->stream);
            if ($contents === false) {
                throw new OpenXmlException(sprintf('Unable to read ZIP entry "%s".', $name));
            }

            return $contents;
        } finally {
            fseek($this->stream, $position);
        }
    }

    /** @return resource */
    public function openStream(string $name)
    {
        $stream = @fopen($this->path($name), 'rb');
        if ($stream === false) {
            throw new OpenXmlException(sprintf('Unable to open staged contents for part "%s".', $name));
        }
        // Bind to the opened resource: plain file streams need not retain the
        // context passed to fopen(). Retain only this snapshot, not the container.
        if (!stream_context_set_option($stream, 'dk-openxml', 'staged-file-owner', $this)) {
            fclose($stream);

            throw new OpenXmlException(sprintf('Unable to bind staged part stream "%s" to its file.', $name));
        }

        return $stream;
    }

    public function path(string $name): string
    {
        if (!fflush($this->stream)) {
            throw new OpenXmlException(sprintf('Unable to flush streamed contents for part "%s".', $name));
        }
        $metadata = stream_get_meta_data($this->stream);
        $path = $metadata['uri'] ?? null;
        if (!is_string($path) || !is_file($path)) {
            throw new OpenXmlException(sprintf('Streamed contents for part "%s" have no temporary file.', $name));
        }

        return $path;
    }
}
