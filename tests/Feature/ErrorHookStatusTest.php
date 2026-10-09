<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Feature;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sodaho\Router\Contract\ResponderInterface;
use Sodaho\Router\Response;
use Sodaho\Router\RouteCollector;
use Sodaho\Router\RouteDispatcher;
use Sodaho\Router\Router;

/**
 * The error hook tells the status of the router's own answer: 400 for a request it cannot
 * use, 500 for everything else it answers itself — whatever an error handler answers
 * instead is the error handler's.
 */
class ErrorHookStatusTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        Response::reset();

        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    private function routes(string $body): string
    {
        $file = sys_get_temp_dir() . '/router_hook_status_' . uniqid() . '.php';
        file_put_contents($file, '<?php ' . $body);

        return $this->files[] = $file;
    }

    /**
     * @return list<array{0: string, 1: int, 2: int}> message, status, response status
     */
    private function reports(Router $router, ServerRequestInterface $request): array
    {
        $reports = [];
        $router->on('error', function (array $data) use (&$reports): void {
            $reports[] = [$data['exception']->getMessage(), $data['status']];
        });

        $response = $router->handle($request);

        return array_map(static fn (array $report): array => [...$report, $response->getStatusCode()], $reports);
    }

    public function testParameterThatCannotBeCastIs400(): void
    {
        $router = Router::create()->loadRoutes($this->routes('return function ($r) { $r->get("/n/{id:int}", fn ($request, int $id) => Sodaho\Router\Response::text("n")); };'));

        $this->assertSame([["Parameter 'id': expected integer", 400, 400]], $this->reports($router, new ServerRequest('GET', '/n/01')));
    }

    public function testWhatTheRouterAnswersWithA500Is500(): void
    {
        $routes = $this->routes('return function ($r) { $r->get("/boom", fn () => throw new \RuntimeException("handler failed")); };');

        // A handler that throws
        $this->assertSame([['handler failed', 500, 500]], $this->reports(Router::create()->loadRoutes($routes), new ServerRequest('GET', '/boom')));

        // No routes at all
        $this->assertSame([['No routes loaded. Use loadRoutes() first.', 500, 500]], $this->reports(Router::create(), new ServerRequest('GET', '/x')));

        // A middleware for every request that throws
        $throws = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                throw new \RuntimeException('middleware failed');
            }
        };
        $this->assertSame([['middleware failed', 500, 500]], $this->reports(Router::create()->loadRoutes($routes)->middleware($throws), new ServerRequest('GET', '/boom')));
    }

    public function testErrorHandlerAnswersForItselfTheReportTellsTheRoutersAnswer(): void
    {
        $router = Router::create()
            ->loadRoutes($this->routes('return function ($r) { $r->get("/boom", fn () => throw new \RuntimeException("handler failed")); };'))
            ->setErrorHandler(fn () => Response::text('later', 503));

        // The router would have answered 500; the application answered 503 itself
        $this->assertSame([['handler failed', 500, 503]], $this->reports($router, new ServerRequest('GET', '/boom')));
    }

    private function responderThatFails(): void
    {
        Response::setResponder(new class () implements ResponderInterface {
            public function formatSuccess(mixed $data, ?string $message = null, ?array $meta = null): array
            {
                return ['data' => $data];
            }

            public function formatError(string $message, ?string $code = null, ?array $details = null): array
            {
                throw new \LogicException('responder failed');
            }

            public function getContentType(): string
            {
                return 'application/json';
            }

            public function getSuccessContentType(): string
            {
                return 'application/json';
            }
        });
    }

    public function testFailuresOnTheWayAre500(): void
    {
        $this->responderThatFails();

        $router = Router::create()
            ->loadRoutes($this->routes('return function ($r) { $r->get("/boom", fn () => throw new \RuntimeException("handler failed")); };'))
            ->setErrorHandler(fn () => throw new \DomainException('error handler failed'));

        $this->assertSame(
            [['handler failed', 500, 500], ['error handler failed', 500, 500], ['responder failed', 500, 500]],
            $this->reports($router, new ServerRequest('GET', '/boom'))
        );
    }

    public function testResponderThatFailsAtThe400MakesItA500(): void
    {
        // handle() takes a failing responder as it takes every failure: the error handler is
        // asked, else a 500 goes out. The cast is reported with 400, the failure with the
        // status the router then answers.
        $this->responderThatFails();
        $routes = $this->routes('return function ($r) { $r->get("/n/{id:int}", fn ($request, int $id) => Sodaho\Router\Response::text("n")); };');

        $this->assertSame(
            [["Parameter 'id': expected integer", 400, 500], ['responder failed', 500, 500], ['responder failed', 500, 500]],
            $this->reports(Router::create()->loadRoutes($routes), new ServerRequest('GET', '/n/01'))
        );

        $handled = Router::create()->loadRoutes($routes)->setErrorHandler(fn () => Response::text('handled', 422));
        $this->assertSame(
            [["Parameter 'id': expected integer", 400, 422], ['responder failed', 500, 422]],
            $this->reports($handled, new ServerRequest('GET', '/n/01'))
        );
    }

    public function testStatusIsTheLastKeySoTheOrderOfTheOthersStays(): void
    {
        $keys = [];
        $router = Router::create()->loadRoutes($this->routes('return function ($r) { $r->get("/n/{id:int}", fn ($request, int $id) => Sodaho\Router\Response::text("n")); $r->get("/boom", fn () => throw new \RuntimeException("x")); };'));
        $router->on('error', function (array $data) use (&$keys): void {
            $keys[] = array_keys($data);
        });

        $router->handle(new ServerRequest('GET', '/n/01'));
        $router->handle(new ServerRequest('GET', '/boom'));

        $this->assertSame([['method', 'path', 'exception', 'status'], ['exception', 'method', 'path', 'status']], $keys);
    }

    public function testDispatcherOnItsOwnTellsTheStatusAsWell(): void
    {
        $collector = new RouteCollector();
        $collector->get('/n/{id:int}', fn ($request, int $id) => Response::text('n'));
        $refuses = function (string $message): ResponseInterface {
            $stubborn = $this->createStub(ResponseInterface::class);
            $stubborn->method('withBody')->willThrowException(new \RuntimeException($message));

            return $stubborn;
        };
        $collector->get('/x', fn () => $refuses('route answer refuses'));

        // The responder's answer refuses too: that one the dispatcher reports itself
        $statuses = [];
        $dispatcher = new RouteDispatcher($collector->getData())->setImplicitHead(true)->setErrorResponder(fn () => $refuses('withBody failed'));
        $dispatcher->on('error', function (array $data) use (&$statuses): void {
            $statuses[] = [$data['exception']->getMessage(), $data['status'], array_keys($data)];
        });

        $dispatcher->handle(new ServerRequest('GET', '/n/01'));
        $dispatcher->handle(new ServerRequest('HEAD', '/x'));

        $this->assertSame([
            ["Parameter 'id': expected integer", 400, ['method', 'path', 'exception', 'status']],
            ['withBody failed', 500, ['exception', 'method', 'path', 'status']],
        ], $statuses);
    }
}
