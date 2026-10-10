<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Integration;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\Response;
use Sodaho\Router\Router;

/**
 * Guards the streaming contract of Response::file() + Router::emit().
 *
 * The memory test is the point of this file: before the streaming emitter, every response
 * body was cast to a string in one piece, so a download was capped by memory_limit and one
 * concurrent big download could exhaust it. It enforces a HARD memory_limit inside the
 * isolated process — a delta against memory_get_peak_usage() would be worthless, because
 * that value is process-wide and monotonic: any earlier allocation makes a buffering
 * emitter look innocent.
 */
#[RunTestsInSeparateProcesses]
class StreamedDownloadTest extends TestCase
{
    private const FILE_BYTES = 32 * 1024 * 1024;

    /** Hard ceiling for the streaming test — 1/1000 of the file it has to serve. */
    private const MEMORY_CEILING = '32M';

    private string $routesFile;
    private string $payloadFile;

    protected function setUp(): void
    {
        $this->routesFile = sys_get_temp_dir() . '/streamed_routes_' . uniqid() . '.php';
        $this->payloadFile = sys_get_temp_dir() . '/streamed_payload_' . uniqid() . '.bin';
    }

    protected function tearDown(): void
    {
        foreach ([$this->routesFile, $this->payloadFile] as $f) {
            if (file_exists($f)) {
                @unlink($f);
            }
        }
    }

    /** Writes FILE_BYTES without holding them in memory (1 MB blocks). */
    private function createPayload(): void
    {
        $handle = fopen($this->payloadFile, 'wb');
        self::assertIsResource($handle);
        $block = str_repeat('x', 1024 * 1024);
        for ($i = 0; $i < self::FILE_BYTES / strlen($block); $i++) {
            fwrite($handle, $block);
        }
        fclose($handle);
        self::assertSame(self::FILE_BYTES, filesize($this->payloadFile));
    }

    private function createRoutes(string $body): void
    {
        file_put_contents($this->routesFile, $body);
    }

    /**
     * @param array<string, string> $server
     */
    private function serve(string $uri, array $server = []): string
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
        foreach ($server as $k => $v) {
            $_SERVER[$k] = $v;
        }

        $router = Router::create(['debug' => true])->loadRoutes($this->routesFile);

        ob_start();
        $router->run();

        return (string) ob_get_clean();
    }

    #[RunInSeparateProcess]
    public function testEmitDoesNotBufferWholeFile(): void
    {
        $this->createPayload();
        $this->createRoutes(
            <<<PHP
                <?php
                use Sodaho\\Router\\RouteCollector;
                use Sodaho\\Router\\Response;

                return function (RouteCollector \$r) {
                    \$r->get('/big', fn(\$req) => Response::file('{$this->payloadFile}', 'big.bin'));
                };
                PHP
        );

        // A buffering emitter needs ~3x the file size and dies here — deterministically,
        // regardless of what the process allocated before.
        ini_set('memory_limit', self::MEMORY_CEILING);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/big';
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

        $router = Router::create(['debug' => true])->loadRoutes($this->routesFile);

        // Discard the output in small pieces — keeping it would defeat the measurement.
        $sent = 0;
        ob_start(function (string $buffer) use (&$sent): string {
            $sent += strlen($buffer);

            return '';
        }, 8192);

        $router->run();
        ob_end_flush();

        $peak = memory_get_peak_usage(true);

        $this->assertSame(self::FILE_BYTES, $sent, 'every byte of the file must reach the client');
        $this->assertLessThan(
            32 * 1024 * 1024,
            $peak,
            sprintf('emit() must stream: serving a %d MB file peaked at %d MB', self::FILE_BYTES / 1024 / 1024, $peak / 1024 / 1024),
        );
    }

    #[RunInSeparateProcess]
    public function testRangeRequestEmitsExactlyTheRequestedSlice(): void
    {
        // NON-periodic payload: with '0123456789' repeated, every offset error that is a
        // multiple of 10 stays invisible — and with '(i * 37 + 11) % 256' every one that is
        // a multiple of 256. Blocks of SHA-256 of the block number depend on the position.
        $payload = '';
        for ($i = 0; strlen($payload) < 1000; $i++) {
            $payload .= hash('sha256', (string) $i, true);
        }
        $payload = substr($payload, 0, 1000);
        file_put_contents($this->payloadFile, $payload);

        $this->createRoutes(
            <<<PHP
                <?php
                use Sodaho\\Router\\RouteCollector;
                use Sodaho\\Router\\Response;

                return function (RouteCollector \$r) {
                    \$r->get('/media', fn(\$req) => Response::file(
                        '{$this->payloadFile}',
                        'clip.bin',
                        'application/octet-stream',
                        true,
                        \$req->getHeaderLine('Range') ?: null,
                    ));
                };
                PHP
        );

        $output = $this->serve('/media', ['HTTP_RANGE' => 'bytes=137-236']);

        $this->assertSame(substr($payload, 137, 100), $output);
    }

    #[RunInSeparateProcess]
    public function testStringBodiesAreUnchangedByTheChunkedEmitter(): void
    {
        $this->createRoutes(
            <<<'PHP'
                <?php
                use Sodaho\Router\RouteCollector;
                use Sodaho\Router\Response;

                return function (RouteCollector $r) {
                    // Deliberately larger than one emit chunk (8 KB).
                    $r->get('/blob', fn($req) => Response::text(str_repeat('ab', 20000)));
                    // Above 200 KB Nyholm switches from php://memory to its own Zval wrapper —
                    // a different emit path, and exactly the size this change is about.
                    $r->get('/huge', fn($req) => Response::text(str_repeat('c', 250000)));
                    $r->get('/empty', fn($req) => Response::noContent());
                };
                PHP
        );

        $this->assertSame(str_repeat('ab', 20000), $this->serve('/blob'));
        $this->assertSame(str_repeat('c', 250000), $this->serve('/huge'));
        $this->assertSame('', $this->serve('/empty'));
    }

    #[RunInSeparateProcess]
    public function testAlreadyConsumedBodyIsStillEmittedInFull(): void
    {
        // Guards the rewind() line: a logging/ETag middleware that read the body would
        // otherwise leave an empty response behind.
        $this->createRoutes(
            <<<'PHP'
                <?php
                use Sodaho\Router\RouteCollector;
                use Sodaho\Router\Response;

                return function (RouteCollector $r) {
                    $r->get('/consumed', function ($req) {
                        $res = Response::text('hello world');
                        $res->getBody()->getContents();   // middleware-style read
                        return $res;
                    });
                };
                PHP
        );

        $this->assertSame('hello world', $this->serve('/consumed'));
    }

    /**
     * @return array{0: string, 1: list<\Throwable>} What went out, and what the error hook got
     */
    private function serveReporting(string $uri): array
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

        $reported = [];
        $router = Router::create()->loadRoutes($this->routesFile);
        $router->on('error', function (array $data) use (&$reported): void {
            $reported[] = $data['exception'];
        });

        ob_start();
        $router->run();

        return [(string) ob_get_clean(), $reported];
    }

    #[RunInSeparateProcess]
    public function testBodyThatEndsShortOfItsContentLengthIsReported(): void
    {
        $this->createRoutes(
            <<<'PHP'
                <?php
                use Sodaho\Router\RouteCollector;
                use Sodaho\Router\Response;

                return function (RouteCollector $r) {
                    $r->get('/short', fn () => Response::text('abc')->withHeader('Content-Length', '10'));
                    $r->get('/whole', fn () => Response::text('abc')->withHeader('Content-Length', '3'));
                };
                PHP
        );

        [$sent, $reported] = $this->serveReporting('/short');

        $this->assertSame('abc', $sent);
        $this->assertCount(1, $reported);
        $this->assertInstanceOf(RouterException::class, $reported[0]);
        $this->assertSame('Response body ended before its Content-Length', $reported[0]->getMessage());
        $this->assertSame('3 of 10 bytes sent', $reported[0]->getDebugMessage());

        [$sent, $reported] = $this->serveReporting('/whole');
        $this->assertSame('abc', $sent);
        $this->assertSame([], $reported);
    }

    #[RunInSeparateProcess]
    public function testEmitThrowsForABodyThatEndsShortOfItsContentLength(): void
    {
        $level = ob_get_level();
        ob_start();

        try {
            Router::create()->emit(Response::text('abc')->withHeader('Content-Length', '10'));
            $this->fail('emit() passed a short body off as a whole one');
        } catch (RouterException $e) {
            $this->assertSame('Response body ended before its Content-Length', $e->getMessage());
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    #[RunInSeparateProcess]
    public function testClosedBodyFailsLoudlyInsteadOfSendingAnEmptyResponse(): void
    {
        $this->createRoutes(
            <<<'PHP'
                <?php
                use Sodaho\Router\RouteCollector;
                use Sodaho\Router\Response;

                return function (RouteCollector $r) {
                    $r->get('/closed', function ($req) {
                        $res = Response::text('gone');
                        $res->getBody()->close();
                        return $res;
                    });
                };
                PHP
        );

        // run() looks before anything is sent and answers with a 500 (EmitStatusTest);
        // emit() has no request to report it for, and throws
        $response = Response::text('gone');
        $response->getBody()->close();

        $level = ob_get_level();
        ob_start();

        try {
            Router::create()->emit($response);
            $this->fail('emit() must refuse a body that was closed before it ran');
        } catch (RouterException $e) {
            $this->assertSame('Response body is not readable (closed or detached before emit)', $e->getMessage());
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        $this->assertSame('Internal Server Error', $this->serve('/closed'));
        $this->assertSame(500, http_response_code());
    }

    #[RunInSeparateProcess]
    public function testTransientEmptyReadsDoNotTruncateTheBody(): void
    {
        // Pump/append streams return '' while eof() is still false. Breaking on the first
        // empty read would silently cut the body short. Two empty reads in a row, twice:
        // the wait for the next byte starts anew after each chunk (EmitIdleTimeoutTest has
        // the rest of the idle rule).
        $this->createRoutes(
            <<<'PHP'
                <?php
                use Sodaho\Router\RouteCollector;
                use Sodaho\Router\Response;
                use Nyholm\Psr7\Response as Psr7Response;
                use Psr\Http\Message\StreamInterface;

                return function (RouteCollector $r) {
                    $r->get('/pump', function ($req) {
                        $body = new class implements StreamInterface {
                            private array $parts = ['A', '', '', 'B', '', '', 'C'];
                            private int $i = 0;
                            public function __toString(): string { return 'ABC'; }
                            public function close(): void {}
                            public function detach() { return null; }
                            public function getSize(): ?int { return null; }
                            public function tell(): int { return 0; }
                            public function eof(): bool { return $this->i >= count($this->parts); }
                            public function isSeekable(): bool { return false; }
                            public function seek(int $o, int $w = SEEK_SET): void {}
                            public function rewind(): void {}
                            public function isWritable(): bool { return false; }
                            public function write(string $s): int { return 0; }
                            public function isReadable(): bool { return true; }
                            public function read(int $length): string { return $this->parts[$this->i++] ?? ''; }
                            public function getContents(): string { return 'ABC'; }
                            public function getMetadata(?string $key = null) { return $key === null ? [] : null; }
                        };
                        return new Psr7Response(200, [], $body);
                    });
                };
                PHP
        );

        $this->assertSame('ABC', $this->serve('/pump'));
    }
}
