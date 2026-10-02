<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\Stream\FileStream;

/**
 * Stream wrapper that reports a size but cannot seek — and, like every user-space wrapper,
 * still claims `seekable => true` in stream_get_meta_data(). Guards that FileStream probes
 * seekability instead of believing the metadata.
 */
class NonSeekableWrapper
{
    public static string $data = '';

    /** @var resource|null */
    public $context;

    private int $pos = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        $this->pos = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $out = substr(self::$data, $this->pos, $count);
        $this->pos += strlen($out);

        return $out;
    }

    public function stream_eof(): bool
    {
        return $this->pos >= strlen(self::$data);
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return ['size' => strlen(self::$data)];
    }
}

/**
 * Seekable stream wrapper that can be told to fail: a source that went away between two
 * calls (network mount, revoked handle).
 */
class FlakyWrapper
{
    public static bool $failSeek = false;
    public static bool $failRead = false;

    /** @var resource|null */
    public $context;

    private int $pos = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        $this->pos = 0;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        if (self::$failRead) {
            return false;
        }

        $out = substr('abcdefghij', $this->pos, $count);
        $this->pos += strlen($out);

        return $out;
    }

    public function stream_eof(): bool
    {
        return $this->pos >= 10;
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        if (self::$failSeek) {
            return false;
        }

        $this->pos = $offset;

        return true;
    }

    public function stream_tell(): int
    {
        return $this->pos;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return ['size' => 10];
    }
}

class FileStreamTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/filestream_test_' . uniqid();
        file_put_contents($this->path, '0123456789');

        NonSeekableWrapper::$data = 'abcdefghij';
        stream_wrapper_register('nonseek', NonSeekableWrapper::class);
        stream_wrapper_register('flaky', FlakyWrapper::class);
        FlakyWrapper::$failSeek = false;
        FlakyWrapper::$failRead = false;
    }

    protected function tearDown(): void
    {
        stream_wrapper_unregister('nonseek');
        stream_wrapper_unregister('flaky');

        if (file_exists($this->path)) {
            @unlink($this->path);
        }
    }

    public function testReadsWholeFile(): void
    {
        $stream = new FileStream($this->path);

        $this->assertSame(10, $stream->getSize());
        $this->assertSame('0123456789', $stream->getContents());
        $this->assertTrue($stream->eof());
    }

    public function testReadsSlice(): void
    {
        $stream = new FileStream($this->path, 3, 4);

        $this->assertSame(4, $stream->getSize());
        $this->assertSame('3456', $stream->getContents());
    }

    public function testSliceStopsAtItsEndEvenWithLargerReads(): void
    {
        $stream = new FileStream($this->path, 8, 2);

        $this->assertSame('89', $stream->read(8192));
        $this->assertSame('', $stream->read(8192));
        $this->assertTrue($stream->eof());
    }

    public function testLengthIsClampedToFileSize(): void
    {
        $stream = new FileStream($this->path, 5, 999);

        $this->assertSame(5, $stream->getSize());
        $this->assertSame('56789', $stream->getContents());
    }

    public function testStartBeyondEndOfFileYieldsEmptyStream(): void
    {
        $stream = new FileStream($this->path, 50);

        $this->assertSame(0, $stream->getSize());
        $this->assertTrue($stream->eof());
        $this->assertSame('', $stream->read(10));
    }

    public function testSeekAndTellAreSliceRelative(): void
    {
        $stream = new FileStream($this->path, 2, 5);

        $stream->seek(1);
        $this->assertSame(1, $stream->tell());
        $this->assertSame('34', $stream->read(2));

        $stream->rewind();
        $this->assertSame(0, $stream->tell());
        $this->assertSame('23456', $stream->getContents());

        $stream->seek(-1, SEEK_END);
        $this->assertSame('6', $stream->read(1));
    }

    public function testSeekOutsideSliceThrows(): void
    {
        $stream = new FileStream($this->path, 0, 4);

        $this->expectException(RuntimeException::class);
        $stream->seek(5);
    }

    public function testToStringRewindsAndReturnsSlice(): void
    {
        $stream = new FileStream($this->path, 1, 3);
        $stream->read(2);

        $this->assertSame('123', (string) $stream);
    }

    public function testIsNotWritable(): void
    {
        $stream = new FileStream($this->path);

        $this->assertFalse($stream->isWritable());
        $this->assertTrue($stream->isReadable());
        $this->assertTrue($stream->isSeekable());

        $this->expectException(RuntimeException::class);
        $stream->write('nope');
    }

    public function testNegativeReadLengthThrows(): void
    {
        $stream = new FileStream($this->path);

        $this->expectException(InvalidArgumentException::class);
        $stream->read(-1);
    }

    public function testTruncatedFileEndsTheStreamInsteadOfSpinning(): void
    {
        // The file shrinks while it is being served (rotation, overwrite). eof() is
        // arithmetic, so without the exhausted-flag a `while (!eof()) read()` loop would
        // spin forever on empty reads.
        file_put_contents($this->path, str_repeat('x', 40000));
        $stream = new FileStream($this->path);
        $this->assertSame(8192, strlen($stream->read(8192)));

        file_put_contents($this->path, str_repeat('x', 8192));
        clearstatcache();

        $this->assertSame('', $stream->read(8192));
        $this->assertTrue($stream->eof(), 'stream must report eof after a short read');

        // Seeking back must clear the exhausted flag — otherwise the stream stays dead
        // although the (now shorter) file still has readable bytes.
        $stream->rewind();
        $this->assertFalse($stream->eof(), 'seek() must reset the exhausted flag');
        $this->assertSame(8192, strlen($stream->getContents()));
    }

    public function testDetachReleasesTheHandleAndUnknownsTheSize(): void
    {
        $stream = new FileStream($this->path);
        $handle = $stream->detach();

        $this->assertIsResource($handle);
        $this->assertFalse($stream->isReadable());
        $this->assertNull($stream->getSize(), 'PSR-7: size is unknown once detached');
        $this->assertTrue($stream->eof(), 'a detached stream has nothing left to read');
        $this->assertSame([], $stream->getMetadata());
        fclose($handle);
    }

    public function testClosedStreamReportsUnknownSizeAndEof(): void
    {
        $stream = new FileStream($this->path);
        $stream->close();

        $this->assertNull($stream->getSize());
        // eof() must agree with the read methods: they throw, so the stream is done.
        // Otherwise `while (!eof()) read()` runs straight into that exception.
        $this->assertTrue($stream->eof());
        $this->assertFalse($stream->isReadable());
    }

    public function testTellThrowsAfterClose(): void
    {
        $stream = new FileStream($this->path);
        $stream->close();

        $this->expectException(RuntimeException::class);
        $stream->tell();
    }

    public function testGetContentsThrowsAfterClose(): void
    {
        $stream = new FileStream($this->path);
        $stream->close();

        $this->expectException(RuntimeException::class);
        $stream->getContents();
    }

    public function testReadThrowsAfterClose(): void
    {
        $stream = new FileStream($this->path);
        $stream->close();

        $this->expectException(RuntimeException::class);
        $stream->read(1);
    }

    public function testMetadataExposesStreamInfo(): void
    {
        $stream = new FileStream($this->path);

        $this->assertSame('plainfile', $stream->getMetadata('wrapper_type'));
        $this->assertNull($stream->getMetadata('does_not_exist'));
    }

    public function testToStringSwallowsErrorsOnAClosedStream(): void
    {
        $stream = new FileStream($this->path);
        $stream->close();

        // PSR-7: __toString() must not throw, whatever the state.
        $this->assertSame('', (string) $stream);
    }

    public function testSeekOnANonSeekableSourceThrows(): void
    {
        $stream = new FileStream('nonseek://x');

        $this->expectException(RuntimeException::class);
        $stream->seek(1);
    }

    public function testInvalidWhenceThrows(): void
    {
        $stream = new FileStream($this->path);

        $this->expectException(RuntimeException::class);
        $stream->seek(0, 999);
    }

    public function testGetContentsStopsWhenTheFileShrinks(): void
    {
        file_put_contents($this->path, str_repeat('y', 40000));
        $stream = new FileStream($this->path);
        $stream->read(8192);

        file_put_contents($this->path, str_repeat('y', 8192));
        clearstatcache();

        $this->assertSame('', $stream->getContents());
        $this->assertTrue($stream->eof());
    }

    public function testZeroLengthReadReturnsEmptyStringInsteadOfThrowing(): void
    {
        // fread($h, 0) raises a ValueError on PHP 8 — the shortcut in read() is load-bearing.
        $stream = new FileStream($this->path);

        $this->assertSame('', $stream->read(0));
        $this->assertSame('0123456789', $stream->getContents());
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(RouterException::class);
        new FileStream($this->path . '_missing');
    }

    public function testNonSeekableSourceIsReportedAsSuch(): void
    {
        $stream = new FileStream('nonseek://x');

        $this->assertFalse($stream->isSeekable(), 'meta claims seekable, the wrapper is not');
        $this->assertSame('abcdefghij', $stream->getContents());
    }

    public function testSliceOnNonSeekableSourceThrowsInsteadOfServingTheWrongBytes(): void
    {
        // Silently starting at byte 0 under a Content-Range promising byte 4 would produce
        // a corrupt download that neither side notices.
        $this->expectException(RouterException::class);
        new FileStream('nonseek://x', 4, 2);
    }

    public function testUnsizableSourceThrows(): void
    {
        $gz = sys_get_temp_dir() . '/filestream_test_' . uniqid() . '.gz';
        file_put_contents($gz, (string) gzencode(str_repeat('z', 100)));

        try {
            $this->expectException(RouterException::class);
            new FileStream('compress.zlib://' . $gz);
        } finally {
            @unlink($gz);
        }
    }

    public function testSeekFailureOfTheSourceIsReported(): void
    {
        $stream = new FileStream('flaky://x');
        $this->assertTrue($stream->isSeekable());
        $this->assertSame('abcd', $stream->read(4));

        FlakyWrapper::$failSeek = true;

        try {
            $stream->seek(2);
            $this->fail('A failed seek went unnoticed');
        } catch (\RuntimeException $e) {
            $this->assertSame('Seek failed', $e->getMessage());
        }

        // The position is only moved once the source confirmed the seek
        $this->assertSame(4, $stream->tell());
    }

    public function testReadFailureOfTheSourceIsReported(): void
    {
        $stream = new FileStream('flaky://x');

        // Before the first read: PHP buffers, a later read would be served from memory
        FlakyWrapper::$failRead = true;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Read failed');
        $stream->read(2);
    }
}
