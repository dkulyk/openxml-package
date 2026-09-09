<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal\Zip;

use DK\OpenXml\Exception\OpenXmlException;

/**
 * A read-only stream over one ZIP entry, decoded as the caller reads it.
 *
 * The entry and its reader arrive through the stream context, which is also
 * where the container attaches the object that keeps it alive for as long as the
 * stream is open.
 *
 * @internal
 */
final class InflateStream
{
    public const SCHEME = 'openxml-zip';

    /** @var resource|null Set by PHP before stream_open(). */
    public $context;

    private ZipReader $reader;

    private Entry $entry;

    private string $reportedName;

    private EntryInflater $inflater;

    private string $buffer = '';

    /** How much of the buffer the caller has already taken. */
    private int $offset = 0;

    private int $position = 0;

    /** Whether the caller has asked this stream for anything yet. */
    private bool $touched = false;

    /**
     * @return resource
     */
    public static function open(ZipReader $reader, Entry $entry, string $reportedName)
    {
        self::register();

        $stream = fopen(self::SCHEME . '://' . rawurlencode($reportedName), 'rb', false, stream_context_create([
            self::SCHEME => ['reader' => $reader, 'entry' => $entry, 'name' => $reportedName],
        ]));
        if ($stream === false) {
            throw new OpenXmlException(sprintf('Unable to open ZIP entry "%s".', $reportedName));
        }
        // Ask PHP to fill its buffer a whole decoded chunk at a time. A caller
        // reading in small pieces then gets them sliced in C, and this wrapper is
        // asked for bytes once per chunk instead of once per read.
        stream_set_chunk_size($stream, EntryInflater::CHUNK);

        return $stream;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $settings = $this->context === null
            ? null
            : (stream_context_get_options($this->context)[self::SCHEME] ?? null);
        if (
            !is_array($settings)
            || !($settings['reader'] ?? null) instanceof ZipReader
            || !($settings['entry'] ?? null) instanceof Entry
            || !is_string($settings['name'] ?? null)
        ) {
            throw new OpenXmlException('A ZIP entry stream was opened without the entry it reads.');
        }

        $this->reader = $settings['reader'];
        $this->entry = $settings['entry'];
        $this->reportedName = $settings['name'];
        $this->restart();

        return true;
    }

    /**
     * A stream nobody has read from still holds the entry exactly as the archive
     * stores it, so a container asked to copy it can move the compressed bytes
     * instead of decoding and re-encoding them.
     */
    public function untouched(): bool
    {
        return !$this->touched;
    }

    public function reader(): ZipReader
    {
        return $this->reader;
    }

    public function entry(): Entry
    {
        return $this->entry;
    }

    public function stream_read(int $count): string
    {
        $this->touched = true;

        if ($this->offset === strlen($this->buffer)) {
            // Nothing held back. A decoded chunk that fits the request is handed
            // over as it stands, which is the usual case once PHP asks for bytes a
            // chunk at a time, and costs no copy at all.
            $chunk = '';
            while ($chunk === '' && !$this->inflater->finished()) {
                $chunk = $this->inflater->next();
            }
            if (strlen($chunk) <= $count) {
                $this->buffer = '';
                $this->offset = 0;
                $this->position += strlen($chunk);

                return $chunk;
            }
            $this->buffer = $chunk;
            $this->offset = 0;
        }

        // The buffer is consumed by moving an offset through it rather than by
        // reslicing it: a part decodes to far more than one read asks for, and
        // dropping the front of a multi-megabyte string on every read costs more
        // than the decoding does.
        while (strlen($this->buffer) - $this->offset < $count && !$this->inflater->finished()) {
            if ($this->offset > 0) {
                $this->buffer = substr($this->buffer, $this->offset);
                $this->offset = 0;
            }
            $this->buffer .= $this->inflater->next();
        }

        $chunk = substr($this->buffer, $this->offset, $count);
        $this->offset += strlen($chunk);
        $this->position += strlen($chunk);
        if ($this->offset === strlen($this->buffer)) {
            $this->buffer = '';
            $this->offset = 0;
            $this->offset = 0;
        }

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->offset === strlen($this->buffer) && $this->inflater->finished();
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        if ($whence === SEEK_CUR && $offset === 0) {
            return true;
        }
        if ($whence === SEEK_SET && $offset === 0) {
            $this->restart();

            return true;
        }

        // Anything else would mean decoding the entry again to throw the result away.
        return false;
    }

    /** @return array<array-key, int> */
    public function stream_stat(): array
    {
        $stat = array_fill(0, 13, 0);
        $stat[7] = $this->entry->uncompressedSize;
        $stat['size'] = $this->entry->uncompressedSize;

        return $stat;
    }

    public function stream_close(): void
    {
        $this->buffer = '';
        $this->offset = 0;
    }

    private function restart(): void
    {
        $this->inflater = new EntryInflater($this->reader, $this->entry, $this->reportedName);
        $this->buffer = '';
        $this->offset = 0;
        $this->position = 0;
        $this->touched = false;
    }

    private static function register(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        if (
            !in_array(self::SCHEME, stream_get_wrappers(), true)
            && !stream_wrapper_register(self::SCHEME, self::class)
        ) {
            throw new OpenXmlException('Unable to register the ZIP entry stream wrapper.');
        }
        $registered = true;
    }
}
