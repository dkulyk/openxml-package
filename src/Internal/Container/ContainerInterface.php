<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal\Container;

/** @internal */
interface ContainerInterface
{
    public function has(string $name): bool;

    public function read(string $name): string;

    /** @return resource */
    public function openStream(string $name);

    /** Return a PHP-readable native URI when the current contents have one. */
    public function getReadablePath(string $name): ?string;

    /** @return iterable<string> */
    public function entries(): iterable;

    /** $compress is a hint: contents that cannot shrink are stored rather than deflated. */
    public function write(string $name, string $contents, bool $compress = true): void;

    /**
     * Stage contents produced on first read or save. The producer is invoked at
     * most once; byte limits are enforced when it runs.
     *
     * @param \Closure(): string $contents
     */
    public function writeLazy(string $name, \Closure $contents): void;

    /** @param resource $stream */
    public function writeStream(string $name, $stream, bool $compress = true): void;

    /** Stage a local file where it is: it is read when the part is read or saved, not copied now. */
    public function writePath(string $name, string $path, bool $compress = true): void;

    public function remove(string $name): void;

    public function move(string $source, string $destination): void;

    public function saveAs(string $filename): void;

    /** @param resource $destination */
    public function writeTo($destination): void;
}
