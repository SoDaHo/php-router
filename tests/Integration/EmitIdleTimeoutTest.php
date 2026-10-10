<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Integration;

use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\Router;

/**
 * A body that has not ended may give '' while its next bytes are on their way (PSR-7).
 * 2.1.1 stopped silently at the third empty read in a row, and the first 2.2.0 candidate
 * threw there — both cut 'A', '', '', '', 'B' short. emit() waits now, with a growing pause,
 * and gives up only after 'emitIdleTimeout' seconds without a byte. A body that sent no
 * byte, or belongs to a 1xx, 204 or 304, is no short body whatever its Content-Length says.
 */
#[RunTestsInSeparateProcesses]
class EmitIdleTimeoutTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     */
    private function emitted(ResponseInterface $response, array $config = []): string
    {
        $level = ob_get_level();
        ob_start();

        try {
            Router::create($config)->emit($response);

            return (string) ob_get_contents();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    public function testEmptyReadsInARowDoNotCutTheBodyShort(): void
    {
        $body = new ScriptedBody(['A', '', '', '', 'B']);

        $this->assertSame('AB', $this->emitted(new Psr7Response(200, [], $body)));
    }

    public function testBodyWhoseNextBytesTakeAMomentIsWaitedForWithoutSpinning(): void
    {
        $body = new LateBody(0.15);

        $this->assertSame('AB', $this->emitted(new Psr7Response(200, [], $body)));
        // The pause grows to 50 ms: some ten reads in 150 ms, not thousands
        $this->assertLessThan(30, $body->reads);
    }

    public function testBodyThatGivesNoByteForTheIdleTimeoutIsGivenUp(): void
    {
        $body = new ScriptedBody(['A'], endless: true);
        $level = ob_get_level();
        ob_start();
        $start = hrtime(true);

        try {
            Router::create(['emitIdleTimeout' => 0.2])->emit(new Psr7Response(200, [], $body));
            $this->fail('emit() passed a stalled body off as a whole one');
        } catch (RouterException $e) {
            $this->assertSame('Response body stalled before its end: no byte within emitIdleTimeout', $e->getMessage());
            $this->assertSame('1 bytes sent', $e->getDebugMessage());
            $this->assertSame('A', ob_get_contents());
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        $elapsed = (hrtime(true) - $start) / 1e9;
        $this->assertGreaterThanOrEqual(0.2, $elapsed);
        $this->assertLessThan(2.0, $elapsed);
        $this->assertGreaterThan(3, $body->reads, 'more than the three reads 2.1.1 gave it');
    }

    public function testRunReportsAStalledBody(): void
    {
        $routesFile = sys_get_temp_dir() . '/router_idle_' . uniqid() . '.php';
        file_put_contents($routesFile, <<<'PHP'
            <?php
            use Nyholm\Psr7\Response as Psr7Response;
            use Sodaho\Router\Tests\Integration\ScriptedBody;

            return function (Sodaho\Router\RouteCollector $r) {
                $r->get('/stall', fn () => new Psr7Response(200, [], new ScriptedBody([], endless: true)));
            };
            PHP);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/stall';
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

        $reported = [];
        $router = Router::create(['emitIdleTimeout' => '0.1'])->loadRoutes($routesFile);
        $router->on('error', function (array $data) use (&$reported): void {
            $reported[] = $data['exception'];
        });

        ob_start();
        try {
            $router->run();
            $sent = (string) ob_get_clean();
        } finally {
            unlink($routesFile);
        }

        $this->assertSame('', $sent);
        $this->assertCount(1, $reported);
        $this->assertInstanceOf(RouterException::class, $reported[0]);
        $this->assertSame('Response body stalled before its end: no byte within emitIdleTimeout', $reported[0]->getMessage());
        $this->assertSame('0 bytes sent', $reported[0]->getDebugMessage());
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function responsesThatAreNoShortBody(): array
    {
        return [
            // The answer to HEAD keeps the GET's Content-Length, and emit() cannot know it is one
            'no byte at all' => [200, ''],
            '304 with a body object' => [304, 'abc'],
            '204 with a body object' => [204, 'abc'],
            '1xx with a body object' => [103, 'abc'],
        ];
    }

    #[DataProvider('responsesThatAreNoShortBody')]
    public function testResponseWithoutABodyIsNoShortBody(int $status, string $body): void
    {
        $this->assertSame($body, $this->emitted(new Psr7Response($status, ['Content-Length' => '10'], $body)));
    }

    public function testAnswerToHeadGoesOutThroughEmit(): void
    {
        $routesFile = sys_get_temp_dir() . '/router_idle_head_' . uniqid() . '.php';
        file_put_contents($routesFile, <<<'PHP'
            <?php
            use Sodaho\Router\Response;

            return function (Sodaho\Router\RouteCollector $r) {
                $r->get('/f', fn () => Response::download('abc', 'f.txt'));
            };
            PHP);

        try {
            $router = Router::create()->loadRoutes($routesFile);
            $response = $router->handle(new ServerRequest('HEAD', '/f'));
        } finally {
            unlink($routesFile);
        }
        $this->assertSame('3', $response->getHeaderLine('Content-Length'));

        // As README shows it: emit() without withBody false
        $level = ob_get_level();
        ob_start();
        try {
            $router->emit($response);
            $this->assertSame('', ob_get_contents());
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }
}

/**
 * A body that hands out its parts one read at a time — '' for a read that finds nothing
 * yet — and ends after the last one, or never.
 */
final class ScriptedBody implements StreamInterface
{
    public int $reads = 0;

    /**
     * @param list<string> $parts
     */
    public function __construct(private array $parts, private bool $endless = false)
    {
    }

    public function read(int $length): string
    {
        // Fails loudly instead of hanging the suite where nothing gives up
        if (++$this->reads > 100_000) {
            throw new \RuntimeException('emit() kept reading a stalled body');
        }

        return array_shift($this->parts) ?? '';
    }

    public function eof(): bool
    {
        return !$this->endless && $this->parts === [];
    }

    public function __toString(): string
    {
        return '';
    }

    public function close(): void
    {
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return 0;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
    }

    public function rewind(): void
    {
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        return 0;
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function getContents(): string
    {
        return '';
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }
}

/**
 * A body whose second part is there only after a moment: 'A', then nothing until the
 * moment passed, then 'B' and its end — what a source that is not ready yet gives.
 */
final class LateBody implements StreamInterface
{
    public int $reads = 0;
    private int $step = 0;
    private ?int $readyAt = null;

    public function __construct(private float $seconds)
    {
    }

    public function read(int $length): string
    {
        $this->reads++;
        if ($this->step === 0) {
            $this->step = 1;
            $this->readyAt = hrtime(true) + (int) ($this->seconds * 1e9);

            return 'A';
        }

        if (hrtime(true) < $this->readyAt) {
            return '';
        }

        $this->step = 2;

        return 'B';
    }

    public function eof(): bool
    {
        return $this->step === 2;
    }

    public function __toString(): string
    {
        return '';
    }

    public function close(): void
    {
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return 0;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
    }

    public function rewind(): void
    {
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        return 0;
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function getContents(): string
    {
        return '';
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }
}
