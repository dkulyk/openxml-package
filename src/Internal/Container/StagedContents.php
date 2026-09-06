<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal\Container;

/** @internal Staged part contents backed by a file the container can read repeatedly. */
interface StagedContents
{
    public function read(string $name): string;

    /** @return resource */
    public function openStream(string $name);

    /** A local filesystem path holding the contents, valid until the contents are released. */
    public function path(string $name): string;
}
