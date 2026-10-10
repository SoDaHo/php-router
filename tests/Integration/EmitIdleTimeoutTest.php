<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Integration;

use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
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
 * and gives up only after 'emitIdleTimeout' seconds without a byte. A 304 is held to no
 * Content-Length (a 1xx, 204 or 205 must not have one, see EmitBodilessTest); a body that
 * sends no byte is a short one (see EmitFramingTest), and the answer to HEAD goes out with
 * withBody false.
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

    // The times of the next three tests are 0.2 seconds or more apart from the idle timeout
    // of 0.5, so that a busy CI machine — a pause that sleeps longer than asked, a process
    // that waits for the CPU — does not decide them. A byte within the last 50 ms before
    // the deadline (what the pause of half the time left is for) is too close to pin.

    public function testByteThatComesBeforeTheDeadlineIsTaken(): void
    {
        // Ready 0.2 seconds before the deadline: the wait takes it
        $body = new LateBody(0.3);

        $this->assertSame('AB', $this->emitted(new Psr7Response(200, [], $body), ['emitIdleTimeout' => 0.5]));
    }

    public function testByteThatComesAfterTheDeadlineIsRefused(): void
    {
        // Ready 0.3 seconds after the deadline: emit() gives up at the deadline, it does not
        // wait for a byte that would still come
        $this->assertStalledAfterOneByte(new LateBody(0.8));
    }

    public function testByteThatAReadGivesOnlyAfterTheDeadlineIsRefused(): void
    {
        // The read itself takes 0.4 seconds longer than the idle timeout: what it gives came
        // too late. Up to the first 2.2.0 candidate a byte that came after the deadline this
        // way (or after a pause) was taken, and wound the clock back
        $this->assertStalledAfterOneByte(new BlockingBody(0.9));
    }

    private function assertStalledAfterOneByte(StreamInterface $body): void
    {
        $level = ob_get_level();
        ob_start();

        try {
            Router::create(['emitIdleTimeout' => 0.5])->emit(new Psr7Response(200, [], $body));
            $this->fail('emit() took a byte that came after the deadline');
        } catch (RouterException $e) {
            $this->assertSame('Response body stalled before its end: no byte within emitIdleTimeout', $e->getMessage());
            $this->assertSame('1 bytes sent', $e->getDebugMessage());
            $this->assertSame('A', ob_get_contents());
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    public function testBodyThatGivesNoByteForTheIdleTimeoutIsGivenUp(): void
    {
        $body = new ScriptedBody(['A'], endless: true);
        $level = ob_get_level();
        ob_start();
        $start = hrtime(true);

        try {
            Router::create(['emitIdleTimeout' => 0.5])->emit(new Psr7Response(200, [], $body));
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
        $this->assertGreaterThanOrEqual(0.5, $elapsed);
        $this->assertLessThan(2.5, $elapsed);
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

    public function testA304IsNoShortBody(): void
    {
        // Its Content-Length is that of the representation it stands for: no body is sent,
        // and none is missing
        $this->assertSame('', $this->emitted(new Psr7Response(304, ['Content-Length' => '10'])));
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

        // As README shows it: emit() with withBody false for HEAD. Without it, emit() cannot
        // tell the answer to HEAD (the GET's Content-Length, no body) from a body that is
        // missing — and refuses it before anything is sent
        $level = ob_get_level();
        ob_start();
        try {
            $router->emit($response, withBody: false);
            $this->assertSame('', ob_get_contents());

            try {
                $router->emit($response);
                $this->fail('An empty body went out under a Content-Length of 3');
            } catch (RouterException $e) {
                $this->assertSame('Response body is empty, but its Content-Length is not (an answer to HEAD is emitted with withBody false)', $e->getMessage());
            }
            $this->assertSame('', ob_get_contents());
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }
}

/**
 * What keeps a broken deadline from hanging the suite: a body of these tests throws at its
 * 10,000th read or 5 seconds after its first one — far beyond what a test asks of it (a
 * few dozen reads, under a second) and far short of what reads with a pause of 50 ms
 * would take to reach a read limit alone (some 80 minutes for 100,000). A PHPUnit time
 * limit would need pcntl, which not every PHP has.
 */
trait GivesUpOnAHangingEmit
{
    private int $guardedReads = 0;
    private ?float $firstReadAt = null;

    /** Called first in every read(): throws once the limit of reads or of time is passed. */
    private function guard(): void
    {
        $now = hrtime(true) / 1e9;
        $this->firstReadAt ??= $now;

        if (++$this->guardedReads > 10_000 || $now - $this->firstReadAt > 5.0) {
            throw new \RuntimeException('emit() kept reading a body the test gave up on');
        }
    }
}

/**
 * A body that hands out its parts one read at a time — '' for a read that finds nothing
 * yet — and ends after the last one, or never.
 */
final class ScriptedBody implements StreamInterface
{
    use GivesUpOnAHangingEmit;

    public int $reads = 0;

    /**
     * @param list<string> $parts
     */
    public function __construct(private array $parts, private bool $endless = false)
    {
    }

    public function read(int $length): string
    {
        $this->guard();
        $this->reads++;

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
 * moment passed — counted from the first read that gave nothing, where emit() starts its
 * wait as well —, then 'B' and its end: what a source that is not ready yet gives.
 */
final class LateBody implements StreamInterface
{
    use GivesUpOnAHangingEmit;

    public int $reads = 0;
    private int $step = 0;
    private ?float $readyAt = null;

    public function __construct(private float $seconds)
    {
    }

    public function read(int $length): string
    {
        $this->guard();
        $this->reads++;
        if ($this->step === 0) {
            $this->step = 1;

            return 'A';
        }

        $now = hrtime(true) / 1e9;
        $this->readyAt ??= $now + $this->seconds;
        if ($now < $this->readyAt) {
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

/**
 * A body whose read blocks: 'A', one read that gives nothing, then a read that takes its
 * time before it gives 'B' and the end — a source that answers, but late.
 */
final class BlockingBody implements StreamInterface
{
    use GivesUpOnAHangingEmit;

    private int $step = 0;

    public function __construct(private float $seconds)
    {
    }

    public function read(int $length): string
    {
        $this->guard();
        $this->step++;

        return match ($this->step) {
            1 => 'A',
            2 => '',
            default => $this->late(),
        };
    }

    private function late(): string
    {
        usleep((int) ($this->seconds * 1e6));

        return 'B';
    }

    public function eof(): bool
    {
        return $this->step >= 3;
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
