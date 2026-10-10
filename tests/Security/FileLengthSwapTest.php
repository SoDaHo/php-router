<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Security;

use PHPUnit\Framework\TestCase;
use Sodaho\Router\Response;

/**
 * Response::file() took the length from filesize() and then opened the file: a file
 * replaced in between went out under a Content-Length of the other one — a corrupt
 * download. Length, range and body come from the one opened handle now.
 */
class FileLengthSwapTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/router_file_swap_' . uniqid();
        mkdir($this->dir);
        file_put_contents($this->dir . '/file.bin', str_repeat('a', 10));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->dir);
    }

    public function testLengthIsThatOfTheBytesThatGoOutWhileTheFileIsReplaced(): void
    {
        $process = proc_open(
            [PHP_BINARY, '-r', self::SWAPPER, $this->dir],
            // Nothing to read from it: what it might print must not fill a pipe and stop it
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);

        $mismatches = 0;
        $sizes = [];

        try {
            // The swapper is a process of its own: wait until it has replaced the file once
            // (a slow machine starts it late), so that the loop below runs while it swaps
            // (one look decides: the swapper writes 10 and 20 bytes in turn, so a second look
            // may find 10 again although it runs)
            $started = false;
            $deadline = microtime(true) + 20;
            while (microtime(true) < $deadline) {
                if (self::sizeOf($this->dir . '/file.bin') !== 10) {
                    $started = true;

                    break;
                }
                usleep(1000);
            }
            if (!$started) {
                $this->markTestSkipped('The swapper did not start within 20 seconds');
            }

            for ($i = 0; $i < 3000; $i++) {
                $response = Response::file($this->dir . '/file.bin');
                $body = (string) $response->getBody();
                $sizes[strlen($body)] = true;
                if ($response->getHeaderLine('Content-Length') !== (string) strlen($body)) {
                    $mismatches++;
                }
            }
        } finally {
            touch($this->dir . '/stop');
            proc_close($process);
        }

        $this->assertSame(0, $mismatches, 'Content-Length did not describe the body');
        $this->assertCount(2, $sizes, 'the file was not replaced while it was served');
    }

    private static function sizeOf(string $file): int
    {
        clearstatcache(true, $file);

        return (int) @filesize($file);
    }

    /** Replaces file.bin by one of 10 and one of 20 bytes in turn (rename() is atomic) until told to stop */
    private const SWAPPER = <<<'PHP'
        $dir = $argv[1];
        for ($i = 0; !file_exists($dir . '/stop') && $i < 2000000; $i++) {
            @file_put_contents($dir . '/next.bin', str_repeat('b', $i % 2 === 0 ? 20 : 10));
            @rename($dir . '/next.bin', $dir . '/file.bin');
        }
        PHP;
}
