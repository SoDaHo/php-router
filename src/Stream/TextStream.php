<?php

declare(strict_types=1);

namespace Sodaho\Router\Stream;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Read-only PSR-7 stream over a string, without a resource.
 *
 * Exists for the router's answer of last resort: when PHP cannot open a stream any more
 * (the php:// wrapper unregistered), not even the body of a plain-text 500 can be built
 * the usual way — this one needs nothing PHP has to open. Each answer gets one of its own,
 * so what a reader does to it (reads to the end, closes it) touches no other answer.
 *
 * @internal
 */
final class TextStream implements StreamInterface
{
    private int $pos = 0;

    private bool $open = true;

    public function __construct(private readonly string $text)
    {
    }

    public function __toString(): string
    {
        // From the beginning to the end, as the interface asks; never throws
        if (!$this->open) {
            return '';
        }
        $this->pos = strlen($this->text);

        return $this->text;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function detach()
    {
        // There is no resource to hand over; the stream is unusable afterwards
        $this->open = false;

        return null;
    }

    public function getSize(): ?int
    {
        return $this->open ? strlen($this->text) : null;
    }

    public function tell(): int
    {
        $this->assertOpen();

        return $this->pos;
    }

    public function eof(): bool
    {
        return !$this->open || $this->pos >= strlen($this->text);
    }

    public function isSeekable(): bool
    {
        return $this->open;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->assertOpen();

        $base = match ($whence) {
            SEEK_SET => 0,
            SEEK_CUR => $this->pos,
            SEEK_END => strlen($this->text),
            default => throw new RuntimeException('Invalid whence for seek()'),
        };
        // Before the start, or beyond what an integer holds
        if ($offset < -$base || $offset > PHP_INT_MAX - $base) {
            throw new RuntimeException('Cannot seek to that position');
        }

        $this->pos = $base + $offset;
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
        throw new RuntimeException('The stream is read-only');
    }

    public function isReadable(): bool
    {
        return $this->open;
    }

    public function read(int $length): string
    {
        $this->assertOpen();
        if ($length < 0) {
            throw new RuntimeException('Length must not be negative');
        }

        $chunk = substr($this->text, $this->pos, $length);
        $this->pos += strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        $this->assertOpen();

        // Behind the end there is nothing, and the position stays where it is
        $rest = substr($this->text, $this->pos);
        $this->pos += strlen($rest);

        return $rest;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }

    private function assertOpen(): void
    {
        if (!$this->open) {
            throw new RuntimeException('The stream is closed');
        }
    }
}
