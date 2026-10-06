<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\Router\Stream\TextStream;

class TextStreamTest extends TestCase
{
    public function testReadsLikeAStream(): void
    {
        $stream = new TextStream('Internal Server Error');

        $this->assertTrue($stream->isReadable());
        $this->assertTrue($stream->isSeekable());
        $this->assertFalse($stream->isWritable());
        $this->assertSame(21, $stream->getSize());
        $this->assertSame([], $stream->getMetadata());
        $this->assertNull($stream->getMetadata('uri'));

        $this->assertSame('Inte', $stream->read(4));
        $this->assertSame('rnal', $stream->read(4));
        $this->assertSame(8, $stream->tell());
        $this->assertFalse($stream->eof());
        $this->assertSame(' Server Error', $stream->getContents());
        $this->assertTrue($stream->eof());
        $this->assertSame('', $stream->read(8));

        $stream->seek(-5, SEEK_END);
        $this->assertSame('Error', $stream->getContents());
        $stream->seek(9);
        $stream->seek(-1, SEEK_CUR);
        $this->assertSame(' Server', $stream->read(7));
        $stream->rewind();
        $this->assertSame('Internal Server Error', $stream->getContents());
        // From the beginning, wherever the position was — and to the end
        $stream->seek(3);
        $this->assertSame('Internal Server Error', (string) $stream);
        $this->assertTrue($stream->eof());
        $this->assertSame(21, $stream->tell());

        // A loop with a small buffer, as an emitter reads
        $stream->rewind();
        $read = '';
        while (!$stream->eof()) {
            $read .= $stream->read(4);
        }
        $this->assertSame('Internal Server Error', $read);

        // As far as an integer holds
        $stream->seek(PHP_INT_MAX);
        $this->assertSame(PHP_INT_MAX, $stream->tell());

        // Behind the end: nothing, and the position stays
        $stream->seek(30);
        $this->assertSame('', $stream->getContents());
        $this->assertSame(30, $stream->tell());
    }

    public function testRefusesWhatItCannotDo(): void
    {
        $stream = new TextStream('abc');

        foreach ([
            'write' => fn () => $stream->write('x'),
            'negative length' => fn () => $stream->read(-1),
            'seek before the start' => fn () => $stream->seek(-1),
            'seek before the start from the end' => fn () => $stream->seek(-4, SEEK_END),

            'seek beyond what an integer holds, from the end' => fn () => $stream->seek(PHP_INT_MAX, SEEK_END),
            'unknown whence' => fn () => $stream->seek(0, 99),
        ] as $label => $call) {
            // Not with fail() inside the try: PHPUnit's failure is a RuntimeException as well
            $refused = false;
            try {
                $call();
            } catch (\RuntimeException) {
                $refused = true;
            }
            $this->assertTrue($refused, $label . ' was accepted');
        }

        // Nothing of that changed the text
        $this->assertSame('abc', (string) $stream);
    }

    public function testSeekBeyondWhatAnIntegerHoldsFromTheCurrentPositionIsRefused(): void
    {
        // A test of its own: in the loop above a failing setup would pass for the refusal
        $stream = new TextStream('abc');
        $stream->read(1);
        $this->assertSame(1, $stream->tell());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot seek to that position');
        $stream->seek(PHP_INT_MAX, SEEK_CUR);
    }

    public function testIsUnusableOnceClosedOrDetached(): void
    {
        foreach (['close', 'detach'] as $how) {
            $stream = new TextStream('abc');
            $this->assertNull($how === 'detach' ? $stream->detach() : $stream->close());

            $this->assertFalse($stream->isReadable(), $how);
            $this->assertFalse($stream->isSeekable(), $how);
            $this->assertTrue($stream->eof(), $how);
            $this->assertNull($stream->getSize(), $how);
            $this->assertSame('', (string) $stream, $how);
            foreach (['tell' => fn () => $stream->tell(), 'read' => fn () => $stream->read(1), 'getContents' => fn () => $stream->getContents(), 'seek' => fn () => $stream->seek(0)] as $label => $call) {
                try {
                    $call();
                    $this->fail("{$how}: {$label} worked");
                } catch (\RuntimeException $e) {
                    $this->assertSame('The stream is closed', $e->getMessage());
                }
            }
        }
    }
}
