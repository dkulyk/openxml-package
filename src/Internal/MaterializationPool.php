<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal;

use DK\OpenXml\Exception\OpenXmlException;

/** @internal Owns local files materialized for deferred consumers. */
final class MaterializationPool
{
    private ?string $directory = null;

    /** @var array<string, string> Immutable snapshot key => local path. */
    private array $paths = [];

    public function __destruct()
    {
        $this->clear();
    }

    /**
     * @param \Closure(): resource $openSource
     */
    public function materialize(string $key, string $suggestedName, \Closure $openSource): string
    {
        if (isset($this->paths[$key])) {
            return $this->paths[$key];
        }

        $directory = $this->directory();
        $extension = pathinfo($suggestedName, PATHINFO_EXTENSION);
        $suffix = preg_match('/^[A-Za-z0-9]{1,16}$/', $extension) === 1 ? '.' . $extension : '';
        $path = $directory . DIRECTORY_SEPARATOR . hash('sha256', $key) . $suffix;
        $source = $openSource();
        $destination = @fopen($path, 'x+b');
        if ($destination === false) {
            fclose($source);

            throw new OpenXmlException(sprintf('Unable to create materialized part file "%s".', $path));
        }

        try {
            if (stream_copy_to_stream($source, $destination) === false || !fflush($destination)) {
                throw new OpenXmlException(sprintf('Unable to materialize part contents in "%s".', $path));
            }
        } catch (\Throwable $exception) {
            fclose($destination);
            @unlink($path);

            throw $exception;
        } finally {
            fclose($source);
        }

        fclose($destination);
        @chmod($path, 0600);
        $this->paths[$key] = $path;

        return $path;
    }

    public function clear(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        $this->paths = [];

        if ($this->directory !== null) {
            @rmdir($this->directory);
            $this->directory = null;
        }
    }

    private function directory(): string
    {
        if ($this->directory !== null) {
            return $this->directory;
        }

        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'openxml-' . bin2hex(random_bytes(12));
        if (!@mkdir($directory, 0700) && !is_dir($directory)) {
            throw new OpenXmlException(sprintf('Unable to create materialization directory "%s".', $directory));
        }
        @chmod($directory, 0700);

        return $this->directory = $directory;
    }
}
