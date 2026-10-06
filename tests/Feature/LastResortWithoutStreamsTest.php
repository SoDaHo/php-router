<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Feature;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Sodaho\Router\RouteCollector;
use Sodaho\Router\RouteDispatcher;
use Sodaho\Router\Router;
use Sodaho\Router\Stream\TextStream;

/**
 * PHP cannot open a stream any more (the php:// wrapper unregistered): not a single
 * response can be built the usual way. The plain-text 500 goes out with a body that needs
 * no stream, a new one for each answer. Each test in a process of its own: the wrapper is
 * taken away and given back, and nothing of an earlier test may help.
 *
 * Two ways PHP reports what fails then, both pinned: an error handler that makes warnings
 * exceptions (fopen() throws ErrorException), and one that lets them pass, as production
 * does without one (fopen() returns false, fwrite() throws TypeError).
 */
#[RunTestsInSeparateProcesses]
class LastResortWithoutStreamsTest extends TestCase
{
    private string $routesFile = '';

    protected function tearDown(): void
    {
        if ($this->routesFile !== '') {
            @unlink($this->routesFile);
        }
    }

    /**
     * @return array<string, array{0: bool, 1: string}> warnings made exceptions?, what a failed stream reports
     */
    public static function variants(): array
    {
        return [
            'warnings made exceptions' => [true, 'ErrorException'],
            'warnings passed' => [false, 'TypeError'],
        ];
    }

    private function routes(): string
    {
        // One file per test, also when a test builds several routers
        if ($this->routesFile !== '') {
            return $this->routesFile;
        }
        $this->routesFile = sys_get_temp_dir() . '/router_no_streams_' . uniqid() . '.php';
        file_put_contents($this->routesFile, <<<'PHP'
            <?php
            return function ($r) {
                $r->get('/boom', fn () => throw new \RuntimeException('handler failed'));
                $r->get('/plain', fn () => Sodaho\Router\Response::text('plain'));
                $r->get('/answers', fn () => new \Nyholm\Psr7\Response(200));
            };
            PHP);

        return $this->routesFile;
    }

    /**
     * Runs $answer while PHP cannot open a stream. What it returns, and the body of an
     * answer read in that window (it has to be readable without streams).
     *
     * @return array{0: mixed, 1: string}
     */
    private function withoutStreams(bool $exceptions, \Closure $answer): array
    {
        set_error_handler($exceptions
            ? static function (int $severity, string $message): bool {
                throw new \ErrorException($message, 0, $severity);
            }
            : static fn (): bool => true);
        stream_wrapper_unregister('php');

        try {
            $result = $answer();
            $body = $result instanceof ResponseInterface ? (string) $result->getBody() : '';
        } finally {
            restore_error_handler();
            if (!in_array('php', stream_get_wrappers(), true)) {
                stream_wrapper_restore('php');
            }
        }

        return [$result, $body];
    }

    /**
     * @param list<string> $reports
     */
    private function router(array &$reports): Router
    {
        $router = Router::create(['debug' => false]);
        $router->on('error', function (array $data) use (&$reports): void {
            $reports[] = (new \ReflectionClass($data['exception']))->getShortName();
        });

        return $router;
    }

    #[DataProvider('variants')]
    public function testHandlerAndErrorHandlerFailTheRouterAnswersAPlain500(bool $exceptions, string $failed): void
    {
        foreach (['GET' => 'Internal Server Error', 'HEAD' => ''] as $method => $text) {
            $reports = [];
            $router = $this->router($reports)->loadRoutes($this->routes())
                ->setErrorHandler(fn () => throw new \LogicException('error handler failed'));

            [$response, $body] = $this->withoutStreams($exceptions, fn () => $router->handle(new ServerRequest($method, '/boom')));

            $this->assertSame(500, $response->getStatusCode(), $method);
            $this->assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'), $method);
            $this->assertSame($text, $body, $method);
            // The handler, the error handler, the responder's 500, the plain one — and for
            // HEAD the empty body the dispatcher could not open
            $expected = ['RuntimeException', 'LogicException', $failed, $failed];
            $this->assertSame($method === 'HEAD' ? [...$expected, $failed] : $expected, $reports, $method);
        }
    }

    #[DataProvider('variants')]
    public function testRouterWithoutRoutesAnswersAPlain500(bool $exceptions, string $failed): void
    {
        foreach (['GET' => 'Internal Server Error', 'HEAD' => ''] as $method => $text) {
            $reports = [];
            $router = $this->router($reports);

            [$response, $body] = $this->withoutStreams($exceptions, fn () => $router->handle(new ServerRequest($method, '/x')));

            $this->assertSame(500, $response->getStatusCode(), $method);
            $this->assertSame($text, $body, $method);
            // handle() answers through its own fallback, not the dispatcher's
            $expected = ['RouterException', $failed, $failed];
            $this->assertSame($method === 'HEAD' ? [...$expected, $failed] : $expected, $reports, $method);
        }
    }

    #[DataProvider('variants')]
    public function testAnswerOfTheErrorHandlerThatTakesNoEmptyBodyGivesTheDispatchersEmpty500(bool $exceptions, string $failed): void
    {
        // HEAD: the handler's answer gets no empty body, as none can be opened — so the
        // error handler is asked, and its answer gets none either
        $reports = [];
        $router = $this->router($reports)->loadRoutes($this->routes())
            ->setErrorHandler(fn () => new \Nyholm\Psr7\Response(503));

        [$response, $body] = $this->withoutStreams($exceptions, fn () => $router->handle(new ServerRequest('HEAD', '/answers')));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('', $body);
        $this->assertInstanceOf(TextStream::class, $response->getBody());
        $this->assertSame([$failed, $failed], $reports);
    }

    public function testDispatcherOnItsOwnAnswersHeadWithAnEmpty500(): void
    {
        $collector = new RouteCollector();
        $collector->get('/boom', fn () => throw new \RuntimeException('handler failed'));
        // The error responder's answer refuses another body — and there is no stream for one anyway
        $refuses = $this->createStub(ResponseInterface::class);
        $refuses->method('withBody')->willThrowException(new \LogicException('refuses'));
        $dispatcher = (new RouteDispatcher($collector->getData()))
            ->setImplicitHead(true)
            ->setErrorResponder(fn () => $refuses);

        [$response, $body] = $this->withoutStreams(true, fn () => $dispatcher->handle(new ServerRequest('HEAD', '/boom')));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('', $body);
    }

    public function testEachAnswerHasABodyOfItsOwn(): void
    {
        $router = Router::create(['debug' => false]);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/x';

        [[$second, $secondBody, $sent]] = $this->withoutStreams(true, function () use ($router): array {
            // What a reader does to the first answer's body …
            $first = $router->handle(new ServerRequest('GET', '/x'));
            $firstBody = $first->getBody();
            $this->assertSame('Internal Server Error', $firstBody->getContents());
            try {
                $firstBody->write('POISON');
            } catch (\RuntimeException) {
                // read-only
            }
            $firstBody->close();

            // … touches no later one: neither handle() nor run()
            $second = $router->handle(new ServerRequest('GET', '/x'));
            $this->assertNotSame($first, $second);
            ob_start();
            try {
                $router->run();
            } finally {
                $sent = (string) ob_get_clean();
            }

            return [$second, $second->getBody()->getContents(), $sent];
        });

        $this->assertSame(500, $second->getStatusCode());
        $this->assertSame('Internal Server Error', $secondBody);
        $this->assertSame('Internal Server Error', $sent);
    }

    /**
     * @return array<string, array{0: bool, 1: string, 2: int, 3: string, 4: list<string>}>
     */
    public static function runsWithoutStreams(): array
    {
        return [
            // php://input cannot be opened: the request cannot be built — anything but the
            // PSR-7 objects' refusal is a 500
            'request body, warnings made exceptions' => [true, 'localhost', 500, 'Internal Server Error', ['ErrorException:500', 'ErrorException:500', 'ErrorException:500']],
            // fopen() just fails: the request has no body. The host the PSR-7 objects refuse
            // is a 400 — and stays one, also as the plain answer
            'refused host, warnings passed' => [false, 'x:99999999', 400, 'Bad Request', ['RouterException:400', 'TypeError:400', 'TypeError:400']],
            // A route whose handler builds a response, the responder's 500, the plain one
            'route, warnings passed' => [false, 'localhost', 500, 'Internal Server Error', ['TypeError:500', 'TypeError:500', 'TypeError:500']],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('runsWithoutStreams')]
    public function testRunAnswersEveryRequest(bool $exceptions, string $host, int $status, string $text, array $expected): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/plain';
        $_SERVER['HTTP_HOST'] = $host;
        $reports = [];
        $router = Router::create(['debug' => false])->loadRoutes($this->routes())
            ->on('error', function (array $data) use (&$reports): void {
                $reports[] = (new \ReflectionClass($data['exception']))->getShortName() . ':' . $data['status'];
            });

        [$sent] = $this->withoutStreams($exceptions, function () use ($router): string {
            ob_start();
            try {
                $router->run();
            } finally {
                $sent = (string) ob_get_clean();
            }

            return $sent;
        });

        $this->assertSame($text, $sent);
        $this->assertSame($status, http_response_code());
        $this->assertSame($expected, $reports);
    }
}
