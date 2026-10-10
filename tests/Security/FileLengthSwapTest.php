<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Security;

use PHPUnit\Framework\TestCase;
use Sodaho\Router\Response;

/**
 * Response::file() took the length from filesize() and then opened the file: a file
 * replaced in between went out under a Content-Length of the other one — a corrupt
 * download. Length, range and body come from the one opened handle now — for the whole
 * file and for a range (206 and 416) alike.
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
        $mismatches = 0;
        $sizes = [];

        $this->whileTheFileIsReplaced(function () use (&$mismatches, &$sizes): void {
            for ($i = 0; $i < 3000; $i++) {
                $response = Response::file($this->dir . '/file.bin');
                $body = (string) $response->getBody();
                $sizes[strlen($body)] = true;
                if ($response->getHeaderLine('Content-Length') !== (string) strlen($body)) {
                    $mismatches++;
                }
            }
        });

        $this->assertSame(0, $mismatches, 'Content-Length did not describe the body');
        $this->assertCount(2, $sizes, 'the file was not replaced while it was served');
    }

    /**
     * The range path as well: Content-Range and the 416 are measured on the file that was
     * opened. The two files are told apart by their bytes ('c' x 10, 'b' x 20 — and the 'a'
     * x 10 the test starts with), so that each answer can be held to the file it sent from.
     */
    public function testRangeIsThatOfTheFileThatGoesOutWhileTheFileIsReplaced(): void
    {
        $mismatches = [];
        $seen = [];

        $this->whileTheFileIsReplaced(function () use (&$mismatches, &$seen): void {
            for ($i = 0; $i < 3000; $i++) {
                // From the 6th byte to the end: a range of either file
                $response = Response::file($this->dir . '/file.bin', range: 'bytes=5-');
                $body = (string) $response->getBody();
                $total = str_starts_with($body, 'b') ? 20 : 10;
                $seen[$body[0] ?? ''] = true;
                $expected = [206, sprintf('bytes 5-%d/%d', $total - 1, $total), (string) ($total - 5), str_repeat($body[0] ?? '', $total - 5)];
                $actual = [$response->getStatusCode(), $response->getHeaderLine('Content-Range'), $response->getHeaderLine('Content-Length'), $body];
                if ($actual !== $expected) {
                    $mismatches[] = json_encode($actual);
                }

                // From the 16th byte: a range of the long file only, the short one is a 416
                $response = Response::file($this->dir . '/file.bin', range: 'bytes=15-');
                $body = (string) $response->getBody();
                $actual = [$response->getStatusCode(), $response->getHeaderLine('Content-Range'), $response->getHeaderLine('Content-Length'), $body];
                if ($actual !== [206, 'bytes 15-19/20', '5', 'bbbbb'] && $actual !== [416, 'bytes */10', '0', '']) {
                    $mismatches[] = json_encode($actual);
                }
            }
        });

        $this->assertSame([], array_slice($mismatches, 0, 5), 'Content-Range, Content-Length or status did not describe the file that was sent');
        $this->assertArrayHasKey('b', $seen, 'the long file was never sent');
        $this->assertArrayHasKey('c', $seen, 'the short file was never sent');
    }

    /**
     * Runs $serve while a second process replaces file.bin by one of 20 and one of 10 bytes
     * in turn. Skipped where the swapper did not start in time.
     *
     * @param \Closure(): void $serve
     */
    private function whileTheFileIsReplaced(\Closure $serve): void
    {
        $process = proc_open(
            [PHP_BINARY, '-r', self::SWAPPER, $this->dir],
            // Nothing to read from it: what it might print must not fill a pipe and stop it
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);

        try {
            // The swapper is a process of its own: wait until it has replaced the file once
            // (a slow machine starts it late), so that $serve runs while it swaps (one look
            // decides: the swapper writes 20 and 10 bytes in turn, so a second look may find
            // 10 again although it runs)
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

            $serve();
        } finally {
            touch($this->dir . '/stop');
            proc_close($process);
        }
    }

    private static function sizeOf(string $file): int
    {
        clearstatcache(true, $file);

        return (int) @filesize($file);
    }

    /**
     * Replaces file.bin by one of 20 bytes ('b') and one of 10 ('c') in turn (rename() is
     * atomic) until told to stop
     */
    private const SWAPPER = <<<'PHP'
        $dir = $argv[1];
        for ($i = 0; !file_exists($dir . '/stop') && $i < 2000000; $i++) {
            @file_put_contents($dir . '/next.bin', $i % 2 === 0 ? str_repeat('b', 20) : str_repeat('c', 10));
            @rename($dir . '/next.bin', $dir . '/file.bin');
        }
        PHP;
}
