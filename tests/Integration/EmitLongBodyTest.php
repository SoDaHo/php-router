<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Integration;

use Nyholm\Psr7\Response as Psr7Response;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\Router;

/**
 * A body longer than its Content-Length went out whole: on a kept-alive connection the
 * client reads what goes beyond as the start of the next response. emit() sends up to the
 * length and throws; run() reports it.
 */
#[RunTestsInSeparateProcesses]
class EmitLongBodyTest extends TestCase
{
    /**
     * What emit() sent before it threw, and what it threw.
     *
     * @param array<string, mixed> $config
     *
     * @return array{string, ?RouterException}
     */
    private function emit(ResponseInterface $response, array $config = []): array
    {
        $level = ob_get_level();
        ob_start();

        try {
            Router::create($config)->emit($response);

            return [(string) ob_get_contents(), null];
        } catch (RouterException $e) {
            return [(string) ob_get_contents(), $e];
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    public function testBodyLongerThanItsContentLengthIsSentUpToItAndThrows(): void
    {
        [$sent, $thrown] = $this->emit(new Psr7Response(200, ['Content-Length' => '3'], 'abcdef'));

        $this->assertSame('abc', $sent);
        $this->assertInstanceOf(RouterException::class, $thrown);
        $this->assertSame('Response body is longer than its Content-Length', $thrown->getMessage());
        $this->assertSame('3 bytes sent, more followed', $thrown->getDebugMessage());
    }

    public function testLengthIsCountedOverTheReads(): void
    {
        // 1024 bytes a read: the length ends inside the second read
        $body = str_repeat('x', 3000);
        [$sent, $thrown] = $this->emit(new Psr7Response(200, ['Content-Length' => '1500'], $body), ['emitChunkSize' => 1024]);

        $this->assertSame(1500, strlen($sent));
        $this->assertInstanceOf(RouterException::class, $thrown);
    }

    public function testDataBehindALengthThatEndsWithAReadThrows(): void
    {
        // The first read ends exactly at the length; what the next one gives is beyond it —
        // not one byte of it goes out
        $body = str_repeat('a', 1024) . 'b';
        [$sent, $thrown] = $this->emit(new Psr7Response(200, ['Content-Length' => '1024'], $body), ['emitChunkSize' => 1024]);

        $this->assertSame(str_repeat('a', 1024), $sent);
        $this->assertInstanceOf(RouterException::class, $thrown);
        $this->assertSame('Response body is longer than its Content-Length', $thrown->getMessage());
        $this->assertSame('1024 bytes sent, more followed', $thrown->getDebugMessage());
    }

    public function testBodyOfItsLengthGoesOutWhole(): void
    {
        $this->assertSame(['abcdef', null], $this->emit(new Psr7Response(200, ['Content-Length' => '6'], 'abcdef')));
    }

    public function testNoLengthIsHeldAgainstA304(): void
    {
        // The length of the representation it stands for; a 304 has no body to hold to it
        // (one that has is refused, see EmitBodilessTest)
        $this->assertSame(['', null], $this->emit(new Psr7Response(304, ['Content-Length' => '1'])));
    }

    public function testRunReportsABodyLongerThanItsContentLength(): void
    {
        $routesFile = sys_get_temp_dir() . '/router_long_' . uniqid() . '.php';
        file_put_contents($routesFile, <<<'PHP'
            <?php
            use Sodaho\Router\Response;

            return function (Sodaho\Router\RouteCollector $r) {
                $r->get('/long', fn () => Response::text('abcdef')->withHeader('Content-Length', '3'));
            };
            PHP);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/long';
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

        $reported = [];
        $router = Router::create()->loadRoutes($routesFile);
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

        $this->assertSame('abc', $sent);
        $this->assertCount(1, $reported);
        $this->assertInstanceOf(RouterException::class, $reported[0]);
        $this->assertSame('Response body is longer than its Content-Length', $reported[0]->getMessage());
    }
}
