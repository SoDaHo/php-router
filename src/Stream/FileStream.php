<?php

declare(strict_types=1);

namespace Sodaho\Router\Stream;

use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Sodaho\Router\Exception\RouterException;

/**
 * Read-only PSR-7 stream over a file — or over a byte slice of it.
 *
 * Exists so large files can be sent without ever holding them in memory: the emitter
 * pulls the body in chunks (see Router::emit()), so peak memory stays at the chunk size
 * regardless of file size. A slice ($start/$length) backs HTTP Range responses.
 *
 * Note: getContents() and __toString() DO materialize the remaining bytes — that is what
 * the interface promises. Never call them on large files; read() in a loop instead.
 */
final class FileStream implements StreamInterface
{
    /** @var resource|null */
    private $handle;

    private int $start;

    /** Number of bytes this stream exposes, counted from $start. */
    private int $length;

    /** Read cursor relative to $start (0 .. $length). */
    private int $pos = 0;

    private bool $seekable;

    /**
     * Set when a read came back empty although bytes were still expected — the file was
     * truncated underneath us. Without this, eof() (which is arithmetic) would never turn
     * true and a consumer looping `while (!eof()) read()` would spin forever.
     */
    private bool $exhausted = false;

    /**
     * @param string $path Readable file path
     * @param int $start First byte to expose (clamped to the file size)
     * @param int|null $length Bytes to expose from $start; null = until end of file
     *
     * @throws RouterException When the file cannot be opened, sized or positioned
     */
    public function __construct(string $path, int $start = 0, ?int $length = null)
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RouterException('Cannot open file for reading', debugMessage: $path);
        }

        $this->adopt($handle, $path, $start, $length);
    }

    /**
     * A stream over a handle the caller opened — and checked: AppFolder checks the file it
     * opened against its folder before a byte of it is read, so that what is sent is the
     * file it checked, not one put in its place a moment later. The stream takes the handle
     * over: closing the stream closes it.
     *
     * @internal
     *
     * @param resource $handle Opened for reading
     * @param string $path What the handle was opened from, for messages
     *
     * @throws RouterException As the constructor, when size or range cannot be determined
     */
    public static function fromHandle(mixed $handle, string $path, int $start = 0, ?int $length = null): self
    {
        $stream = new \ReflectionClass(self::class)->newInstanceWithoutConstructor();
        $stream->adopt($handle, $path, $start, $length);

        return $stream;
    }

    /**
     * Take an opened handle over: its size, whether it can seek, the slice it exposes.
     *
     * @param resource $handle
     *
     * @throws RouterException When the size cannot be determined or the start not reached
     */
    private function adopt(mixed $handle, string $path, int $start, ?int $length): void
    {
        $this->handle = $handle;

        // No size, no slice arithmetic: assuming 0 here would silently serve an empty body
        // (happens for stream wrappers without stream_stat(), e.g. compress.zlib://).
        $stat = fstat($handle);
        if ($stat === false) {
            fclose($handle);
            $this->handle = null;

            throw new RouterException('Cannot determine size of file', debugMessage: $path);
        }
        $size = (int) $stat['size'];

        // stream_get_meta_data()['seekable'] lies for user-space wrappers that never
        // implement stream_seek() — it reports true regardless. Probe instead of believing,
        // otherwise isSeekable() promises something seek()/rewind() then throws on.
        $meta = stream_get_meta_data($handle);
        $this->seekable = (bool) $meta['seekable'] && @fseek($handle, 0, SEEK_CUR) === 0;

        $this->start = max(0, min($start, $size));
        $remaining = $size - $this->start;
        $this->length = $length === null ? $remaining : max(0, min($length, $remaining));

        // From the start of the slice — also for a handle the caller read from before (an
        // ETag over the content); one that cannot seek is read from where it stands
        if ($this->seekable ? @fseek($handle, $this->start) === -1 : $this->start > 0) {
            fclose($handle);
            $this->handle = null;

            // Silently starting at byte 0 would serve the head of the file under a
            // Content-Range header promising something else — a corrupt download nobody sees.
            throw new RouterException('Cannot seek to the start of the range in file', debugMessage: sprintf('byte %d in %s', $this->start, $path));
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function __toString(): string
    {
        try {
            if ($this->seekable) {
                $this->rewind();
            }

            return $this->getContents();
        } catch (RuntimeException) {
            // PSR-7: __toString() MUST NOT throw.
            return '';
        }
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }

        $this->handle = null;
    }

    /**
     * @return resource|null
     */
    public function detach()
    {
        $handle = $this->handle;
        $this->handle = null;
        $this->pos = 0;

        return $handle;
    }

    /** @return int|null Null once the stream is closed/detached — the size is then unknown. */
    public function getSize(): ?int
    {
        return is_resource($this->handle) ? $this->length : null;
    }

    public function tell(): int
    {
        if (!is_resource($this->handle)) {
            throw new RuntimeException('Stream is closed or detached');
        }

        return $this->pos;
    }

    public function eof(): bool
    {
        // The handle first: after close()/detach(), read(), tell() and getContents() throw,
        // so eof() has to say true — otherwise the usual loop of a consumer,
        // `while (!eof()) read()`, runs straight into that exception (Nyholm says true there).
        return !is_resource($this->handle) || $this->exhausted || $this->pos >= $this->length;
    }

    public function isSeekable(): bool
    {
        return $this->seekable && is_resource($this->handle);
    }

    /**
     * Move within the slice, not the file: positions count from $start, and nothing before
     * the slice or beyond its length can be reached — a 206 must not send other bytes.
     */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if (!$this->isSeekable()) {
            throw new RuntimeException('Stream is not seekable');
        }

        /** @var resource $handle */
        $handle = $this->handle;

        $target = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->pos + $offset,
            SEEK_END => $this->length + $offset,
            default => throw new RuntimeException('Invalid whence'),
        };

        if ($target < 0 || $target > $this->length) {
            throw new RuntimeException('Cannot seek outside of the stream');
        }

        if (fseek($handle, $this->start + $target) === -1) {
            throw new RuntimeException('Seek failed');
        }

        $this->pos = $target;
        $this->exhausted = false;
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('Stream is not writable');
    }

    public function isReadable(): bool
    {
        return is_resource($this->handle);
    }

    /**
     * Up to $length bytes, never past the end of the slice. A read that comes back empty
     * although bytes were expected marks the stream exhausted (the file shrank), so that a
     * loop on eof() ends — and the emitter reports a body that ended short.
     */
    public function read(int $length): string
    {
        if (!is_resource($this->handle)) {
            throw new RuntimeException('Stream is closed or detached');
        }

        if ($length < 0) {
            // A RuntimeException, as PSR-7 promises for what read() refuses
            throw new RuntimeException('Length must not be negative');
        }

        $remaining = $this->length - $this->pos;
        if ($length === 0 || $remaining <= 0) {
            return '';
        }

        $data = fread($this->handle, min($length, $remaining));
        if ($data === false) {
            throw new RuntimeException('Read failed');
        }

        if ($data === '') {
            // Bytes were expected but none came: the file shrank (rotation, overwrite).
            // Mark the stream done so callers stop instead of spinning.
            $this->exhausted = true;

            return '';
        }

        $this->pos += strlen($data);

        return $data;
    }

    /**
     * The rest of the slice as one string — which holds it in memory as a whole: for large
     * files read() in a loop instead (the emitter does).
     */
    public function getContents(): string
    {
        if (!is_resource($this->handle)) {
            throw new RuntimeException('Stream is closed or detached');
        }

        $out = '';
        while (!$this->eof()) {
            $chunk = $this->read(8192);
            if ($chunk === '') {
                break;
            }

            $out .= $chunk;
        }

        return $out;
    }

    /**
     * @return ($key is null ? array<string, mixed> : mixed)
     */
    public function getMetadata(?string $key = null)
    {
        if (!is_resource($this->handle)) {
            return $key === null ? [] : null;
        }

        $meta = stream_get_meta_data($this->handle);

        return $key === null ? $meta : ($meta[$key] ?? null);
    }
}
