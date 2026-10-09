<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Feature;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sodaho\Router\Response;
use Sodaho\Router\RouteCollector;
use Sodaho\Router\RouteDispatcher;
use Sodaho\Router\RouteMatch;
use Sodaho\Router\Router;

/**
 * Middleware for every request and the error handler: from the outside in
 *
 *   middleware()  →  error handler  →  404/405 or route middleware + handler
 *
 * so that an access log, security headers or CORS see every response the router gives,
 * not only the ones a route produced.
 */
class PipelineTest extends TestCase
{
    private string $routesFile;

    protected function setUp(): void
    {
        $this->routesFile = sys_get_temp_dir() . '/router_pipeline_routes_' . uniqid() . '.php';

        file_put_contents(
            $this->routesFile,
            <<<'PHP'
                <?php
                use Sodaho\Router\Response;
                use Sodaho\Router\Tests\Feature\{PipelineTag, NeverBuilt};

                return function (Sodaho\Router\RouteCollector $r) {
                    $r->get('/ok', fn () => Response::success('ok'))->attribute('format', 'plain');
                    $r->get('/n/{id:int}', fn ($req, int $id) => Response::success($id));
                    $r->post('/ok', fn () => Response::success('posted'));
                    $r->get('/boom', fn () => throw new \RuntimeException('handler failed'))->attribute('format', 'oauth');
                    $r->get('/mw-boom', fn () => Response::success('never'))->middleware(new PipelineTag('route', throw: true));
                    $r->get('/unresolvable', [NeverBuilt::class, 'show']);
                    $r->get('/returned-500', fn () => Response::serverError('returned, not thrown'));
                    $r->get('/tagged', fn () => Response::success('ok'))->middleware(new PipelineTag('route'));
                    $r->delete('/ok', fn ($request) => Response::success([
                        'method' => $request->getMethod(),
                        'matched' => $request->getAttribute(Sodaho\Router\RouteMatch::class)->method,
                        'route' => $request->getAttribute(Sodaho\Router\Route::class)->getAttribute('is'),
                    ]))->attribute('is', 'delete');
                };
                PHP
        );

        PipelineTag::$log = [];
    }

    protected function tearDown(): void
    {
        if (file_exists($this->routesFile)) {
            unlink($this->routesFile);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function router(array $config = []): Router
    {
        /** @phpstan-ignore argument.type */
        return Router::create($config + ['debug' => false])->loadRoutes($this->routesFile);
    }

    // ==================== middleware for every request ====================

    /**
     * @return array<string, array{0: string, 1: string, 2: int, 3: int}>
     */
    public static function everyKindOfAnswer(): array
    {
        return [
            'handler' => ['GET', '/ok', 200, RouteMatch::FOUND],
            'no route' => ['GET', '/nowhere', 404, RouteMatch::NOT_FOUND],
            'method not allowed' => ['PATCH', '/ok', 405, RouteMatch::METHOD_NOT_ALLOWED],
            'parameter does not cast' => ['GET', '/n/' . '9999999999999999999999', 400, RouteMatch::FOUND],
            'handler throws' => ['GET', '/boom', 500, RouteMatch::FOUND],
            'route middleware throws' => ['GET', '/mw-boom', 500, RouteMatch::FOUND],
            'controller cannot be built' => ['GET', '/unresolvable', 500, RouteMatch::FOUND],
            'handler returns a 500' => ['GET', '/returned-500', 500, RouteMatch::FOUND],
        ];
    }

    #[DataProvider('everyKindOfAnswer')]
    public function testMiddlewareSeesEveryAnswerAndKnowsTheMatchBeforehand(string $method, string $path, int $status, int $match): void
    {
        $router = $this->router()->middleware(new PipelineTag('outer'))->middleware(new PipelineTag('inner'));

        $response = $router->handle(new ServerRequest($method, $path));

        $this->assertSame($status, $response->getStatusCode());

        // Both ran, first added = outermost, and both saw the response on its way out
        $this->assertSame('inner, outer', $response->getHeaderLine('X-Seen-By'));
        $this->assertSame(["outer in {$match}", "inner in {$match}", "inner out {$status}", "outer out {$status}"], array_merge(array_slice(PipelineTag::$log, 0, 2), array_slice(PipelineTag::$log, -2)));
    }

    public function testOrderAroundRouteMiddleware(): void
    {
        $router = $this->router()->middleware([new PipelineTag('a'), new PipelineTag('b')]);

        $response = $router->handle(new ServerRequest('GET', '/tagged'));

        $this->assertSame('route, b, a', $response->getHeaderLine('X-Seen-By'));
        $this->assertSame(
            ['a in 1', 'b in 1', 'route in 1', 'route out 200', 'b out 200', 'a out 200'],
            PipelineTag::$log
        );
    }

    public function testWithoutAnyNothingChanges(): void
    {
        $response = $this->router()->handle(new ServerRequest('GET', '/tagged'));

        $this->assertSame('route', $response->getHeaderLine('X-Seen-By'));
    }

    public function testMiddlewareCanAnswerItself(): void
    {
        // What a static 404 page or a CORS preflight does: decide on the lookup result
        // and never reach the router's own answer
        $page = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $match = $request->getAttribute(RouteMatch::class);

                return match (true) {
                    $match->status === RouteMatch::NOT_FOUND => Response::html('<h1>Not here</h1>', 404),
                    $request->getMethod() === 'OPTIONS' => Response::noContent()->withHeader('Access-Control-Allow-Methods', implode(', ', $match->allowedMethods())),
                    default => $handler->handle($request),
                };
            }
        };

        $hooks = [];
        $router = $this->router()->middleware($page);
        foreach (['notFound', 'methodNotAllowed'] as $event) {
            $router->on($event, function () use (&$hooks, $event): void {
                $hooks[] = $event;
            });
        }

        $notFound = $router->handle(new ServerRequest('GET', '/nowhere'));
        $this->assertSame(404, $notFound->getStatusCode());
        $this->assertSame('<h1>Not here</h1>', (string) $notFound->getBody());

        $preflight = $router->handle(new ServerRequest('OPTIONS', '/ok'));
        $this->assertSame(204, $preflight->getStatusCode());
        $this->assertSame('GET, HEAD, POST, DELETE', $preflight->getHeaderLine('Access-Control-Allow-Methods'));

        // The router never got to answer, so its hooks did not fire
        $this->assertSame([], $hooks);

        $this->assertSame(405, $router->handle(new ServerRequest('PATCH', '/ok'))->getStatusCode());
        $this->assertSame(['methodNotAllowed'], $hooks);
    }

    public function testMiddlewareByClassName(): void
    {
        $fromContainer = new PipelineTag('container');
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(fn (string $id): bool => $id === 'app.tag');
        $container->method('get')->willReturn($fromContainer);

        $router = $this->router()->setContainer($container)->middleware(['app.tag', PipelineTag::class]);

        $response = $router->handle(new ServerRequest('GET', '/ok'));

        $this->assertSame('class, container', $response->getHeaderLine('X-Seen-By'));
    }

    public function testMiddlewareAddedAfterTheFirstRequestApplies(): void
    {
        $router = $this->router()->middleware(new PipelineTag('early'));
        $this->assertSame('early', $router->handle(new ServerRequest('GET', '/ok'))->getHeaderLine('X-Seen-By'));

        $this->assertSame($router, $router->middleware(new PipelineTag('late')));
        $this->assertSame('late, early', $router->handle(new ServerRequest('GET', '/ok'))->getHeaderLine('X-Seen-By'));
    }

    public function testDispatcherOnItsOwn(): void
    {
        $collector = new RouteCollector();
        $collector->get('/ok', fn () => Response::success('ok'));
        $collector->get('/boom', fn () => throw new \RuntimeException('handler failed'));

        $dispatcher = new RouteDispatcher($collector->getData())->setMiddleware([new PipelineTag('global')]);

        $this->assertSame('global', $dispatcher->handle(new ServerRequest('GET', '/ok'))->getHeaderLine('X-Seen-By'));
        $this->assertSame('global', $dispatcher->handle(new ServerRequest('GET', '/nowhere'))->getHeaderLine('X-Seen-By'));

        // The lookup result is on the request here as well
        $this->assertSame(
            ['global in ' . RouteMatch::FOUND, 'global out 200', 'global in ' . RouteMatch::NOT_FOUND, 'global out 404'],
            PipelineTag::$log
        );

        // Without an error responder exceptions leave the dispatcher as they always did ...
        try {
            $dispatcher->handle(new ServerRequest('GET', '/boom'));
            $this->fail('The exception was swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('handler failed', $e->getMessage());
        }

        // ... with one they become a response inside the middleware
        $dispatcher->setErrorResponder(fn (\Throwable $e): ResponseInterface => Response::text($e->getMessage(), 500));
        $response = $dispatcher->handle(new ServerRequest('GET', '/boom'));
        $this->assertSame('handler failed', (string) $response->getBody());
        $this->assertSame('global', $response->getHeaderLine('X-Seen-By'));

        $dispatcher->setErrorResponder(null);
        $this->expectException(\RuntimeException::class);
        $dispatcher->handle(new ServerRequest('GET', '/boom'));
    }

    // ==================== error handler ====================

    public function testErrorHandlerBuildsTheResponseForAnException(): void
    {
        $calls = [];
        $hook = [];
        $router = $this->router()
            ->middleware(new PipelineTag('global'))
            ->setErrorHandler(function (\Throwable $e, ServerRequestInterface $request) use (&$calls): ResponseInterface {
                $match = $request->getAttribute(RouteMatch::class);
                $calls[] = [$e->getMessage(), $match->status, $match->route?->getAttribute('format')];

                return Response::json(['error' => 'server_error'], 500);
            })
            ->on('error', function (array $data) use (&$hook): void {
                $hook[] = $data['exception']->getMessage();
            });

        $response = $router->handle(new ServerRequest('GET', '/boom'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('{"error":"server_error"}', (string) $response->getBody());

        // The handler knew the route — and with it the format this route answers in
        $this->assertSame([['handler failed', RouteMatch::FOUND, 'oauth']], $calls);

        // The response went back out through the middleware for every request
        $this->assertSame('global', $response->getHeaderLine('X-Seen-By'));

        // The error hook fired as before, once
        $this->assertSame(['handler failed'], $hook);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function failuresInsideTheBoundary(): array
    {
        return [
            'handler' => ['/boom', 'handler failed'],
            'route middleware' => ['/mw-boom', 'route middleware failed'],
            'building the controller' => ['/unresolvable', 'cannot be built'],
        ];
    }

    #[DataProvider('failuresInsideTheBoundary')]
    public function testEverythingARouteDoesIsInsideTheBoundary(string $path, string $message): void
    {
        $router = $this->router()
            ->middleware(new PipelineTag('global'))
            ->setErrorHandler(fn (\Throwable $e): ResponseInterface => Response::text($e->getMessage(), 500));

        $response = $router->handle(new ServerRequest('GET', $path));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString($message, (string) $response->getBody());
        $this->assertSame('global', $response->getHeaderLine('X-Seen-By'));
    }

    public function testErrorHandlerIsForExceptionsOnly(): void
    {
        $called = 0;
        $router = $this->router()->setErrorHandler(function () use (&$called): ?ResponseInterface {
            $called++;

            return null;
        });

        // A 500 a handler returns is a response like any other, and so are 404, 405, 400
        $response = $router->handle(new ServerRequest('GET', '/returned-500'));
        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('returned, not thrown', (string) $response->getBody());

        $this->assertSame(404, $router->handle(new ServerRequest('GET', '/nowhere'))->getStatusCode());
        $this->assertSame(405, $router->handle(new ServerRequest('PATCH', '/ok'))->getStatusCode());
        $this->assertSame(400, $router->handle(new ServerRequest('GET', '/n/9999999999999999999999'))->getStatusCode());

        $this->assertSame(0, $called);
    }

    public function testNullFromTheErrorHandlerMeansTheRoutersOwn500(): void
    {
        $router = $this->router()->setErrorHandler(fn (): ?ResponseInterface => null);
        $response = $router->handle(new ServerRequest('GET', '/boom'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(
            '{"success":false,"message":"Internal Server Error","error":{"message":"Internal Server Error","code":"SERVER_ERROR"}}',
            (string) $response->getBody()
        );

        // ... with the details in debug mode, as without a handler
        $debug = $this->router(['debug' => true])->setErrorHandler(fn (): ?ResponseInterface => null);
        $body = (string) $debug->handle(new ServerRequest('GET', '/boom'))->getBody();
        $this->assertStringContainsString('handler failed', $body);
        $this->assertStringContainsString('"trace"', $body);
    }

    /**
     * handle() never throws. An error handler that fails itself is reported through the
     * error hook, and the answer is the router's own 500 for the original exception.
     */
    public function testFailingErrorHandlerFallsBackToTheRoutersOwn500(): void
    {
        $hook = [];
        $router = $this->router()
            ->middleware(new PipelineTag('global'))
            ->setErrorHandler(fn () => throw new \LogicException('error handler failed'))
            ->on('error', function (array $data) use (&$hook): void {
                $hook[] = $data['exception']->getMessage();
            });

        $response = $router->handle(new ServerRequest('GET', '/boom'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('"code":"SERVER_ERROR"', (string) $response->getBody());
        $this->assertStringNotContainsString('failed', (string) $response->getBody());
        $this->assertSame('global', $response->getHeaderLine('X-Seen-By'));
        $this->assertSame(['handler failed', 'error handler failed'], $hook);
    }

    /**
     * A middleware for every request that throws is outside the boundary: nothing further
     * out is left that could turn the exception into a response but handle() itself. Same
     * error handler, same request — with its RouteMatch.
     */
    public function testExceptionOfAMiddlewareForEveryRequestIsTheLastResort(): void
    {
        $calls = [];
        $router = $this->router()
            ->middleware(new PipelineTag('outer'))
            ->middleware(new PipelineTag('failing', throw: true))
            ->setErrorHandler(function (\Throwable $e, ServerRequestInterface $request) use (&$calls): ResponseInterface {
                $match = $request->getAttribute(RouteMatch::class);
                $calls[] = [$e->getMessage(), $match?->status, $match?->route?->getAttribute('format')];

                return Response::text('last resort', 500);
            });

        $response = $router->handle(new ServerRequest('GET', '/boom'));

        $this->assertSame('last resort', (string) $response->getBody());
        $this->assertSame([['failing middleware failed', RouteMatch::FOUND, 'oauth']], $calls);

        // This response did not pass through the middleware any more
        $this->assertSame('', $response->getHeaderLine('X-Seen-By'));
    }

    public function testMiddlewareThatCannotBeResolvedIsTheLastResortToo(): void
    {
        $hook = [];
        $seen = 'not called';
        $router = $this->router()
            ->middleware('No\Such\Middleware')
            ->setErrorHandler(function (\Throwable $e, ServerRequestInterface $request) use (&$seen): ?ResponseInterface {
                $seen = $request->getAttribute(RouteMatch::class)?->route?->getAttribute('format');

                return null;
            })
            ->on('error', function (array $data) use (&$hook): void {
                $hook[] = $data['exception']->getMessage();
            });

        $response = $router->handle(new ServerRequest('GET', '/ok'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(["Cannot resolve middleware 'No\\Such\\Middleware'"], $hook);

        // Nothing ran yet, and still the error handler knows the route
        $this->assertSame('plain', $seen);
    }

    public function testWithoutRoutesTheErrorHandlerGetsTheBareRequest(): void
    {
        $seen = 'not called';
        $router = Router::create(['debug' => false])
            ->setErrorHandler(function (\Throwable $e, ServerRequestInterface $request) use (&$seen): ResponseInterface {
                $seen = $request->getAttribute(RouteMatch::class);

                return Response::text($e->getMessage(), 503);
            });

        $response = $router->handle(new ServerRequest('GET', '/ok'));

        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringContainsString('No routes loaded', (string) $response->getBody());
        $this->assertNull($seen);
    }

    public function testFailingErrorHandlerInTheLastResortStillGivesAResponse(): void
    {
        $router = Router::create(['debug' => false])
            ->setErrorHandler(fn () => throw new \LogicException('error handler failed'));

        $response = $router->handle(new ServerRequest('GET', '/ok'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('"code":"SERVER_ERROR"', (string) $response->getBody());
    }

    // ==================== middleware that changes the request ====================

    /**
     * A middleware for every request sits in front of the routing. When it passes a
     * request on whose method or path is no longer the one that was looked up — a method
     * override, a stripped prefix — the route is the one of the request as passed on.
     */
    public function testMiddlewareThatChangesTheMethodChangesTheRoute(): void
    {
        $override = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $method = $request->getHeaderLine('X-HTTP-Method-Override');

                return $handler->handle($method === '' ? $request : $request->withMethod($method));
            }
        };

        $router = $this->router()->middleware($override);

        $response = $router->handle(new ServerRequest('POST', '/ok')->withHeader('X-HTTP-Method-Override', 'DELETE'));

        // The DELETE route ran, and what it found on the request describes the DELETE request
        $this->assertSame(
            '{"success":true,"data":{"method":"DELETE","matched":"DELETE","route":"delete"}}',
            (string) $response->getBody()
        );

        // Without the header POST stays POST
        $this->assertStringContainsString('"posted"', (string) $router->handle(new ServerRequest('POST', '/ok'))->getBody());
    }

    public function testMiddlewareThatChangesThePathChangesTheRoute(): void
    {
        $stripLocale = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $path = (string) preg_replace('#^/de(?=/)#', '', $request->getUri()->getPath());

                return $handler->handle($request->withUri($request->getUri()->withPath($path)));
            }
        };

        $seen = [];
        $router = $this->router()
            ->middleware($stripLocale)
            ->setErrorHandler(function (\Throwable $e, ServerRequestInterface $request) use (&$seen): ?ResponseInterface {
                $match = $request->getAttribute(RouteMatch::class);
                $seen[] = [$match->path, $match->status, $request->getAttribute(\Sodaho\Router\Route::class)?->getAttribute('format')];

                return null;
            });
        $notFound = [];
        $router->on('notFound', function (array $data) use (&$notFound): void {
            $notFound[] = $data['path'];
        });

        // '/de/ok' is no route — the lookup before the middleware says so, the one after it finds '/ok'
        $this->assertSame(RouteMatch::NOT_FOUND, $router->match(new ServerRequest('GET', '/de/ok'))->status);
        $this->assertSame(200, $router->handle(new ServerRequest('GET', '/de/ok'))->getStatusCode());
        $this->assertSame([], $notFound);

        // The error handler gets the request as the middleware passed it on, with its match
        $this->assertSame(500, $router->handle(new ServerRequest('GET', '/de/boom'))->getStatusCode());
        $this->assertSame([['/boom', RouteMatch::FOUND, 'oauth']], $seen);

        // And when the new path is no route, the 404 is for the new path
        $this->assertSame(404, $router->handle(new ServerRequest('GET', '/de/nowhere'))->getStatusCode());
        $this->assertSame(['/nowhere'], $notFound);
    }

    public function testRequestThatComesWithAStaleRouteLosesIt(): void
    {
        $router = $this->router();
        $hit = $router->match(new ServerRequest('GET', '/ok'));

        $seen = [];
        $router->middleware(new class ($seen) implements MiddlewareInterface {
            /** @param array<int, mixed> $seen */
            public function __construct(private array &$seen)
            {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->seen[] = [
                    $request->getAttribute(RouteMatch::class)->status,
                    $request->getAttribute(\Sodaho\Router\Route::class)?->pattern,
                ];

                return $handler->handle($request);
            }
        });

        // A request that was matched for '/ok' and then sent somewhere else
        $request = new ServerRequest('GET', '/nowhere')
            ->withAttribute(RouteMatch::class, $hit)
            ->withAttribute(\Sodaho\Router\Route::class, $hit->route);

        $this->assertSame(404, $router->handle($request)->getStatusCode());
        $this->assertSame(200, $router->handle($request->withUri($request->getUri()->withPath('/tagged')))->getStatusCode());

        $this->assertSame([[RouteMatch::NOT_FOUND, null], [RouteMatch::FOUND, '/tagged']], $seen);
    }

    public function testLookupHandedInWithoutTheRouteGetsItsRoute(): void
    {
        $router = $this->router();
        $request = new ServerRequest('DELETE', '/ok');

        // Only the RouteMatch is handed in, as the README shows it — Route::class follows
        $response = $router->handle($request->withAttribute(RouteMatch::class, $router->match($request)));

        $this->assertStringContainsString('"route":"delete"', (string) $response->getBody());
    }

    public function testErrorHandlerThatReturnsSomethingElseCountsAsFailed(): void
    {
        $hook = [];
        /** @phpstan-ignore argument.type */
        $router = $this->router()->setErrorHandler(fn () => 'not a response');
        $router->on('error', function (array $data) use (&$hook): void {
            $hook[] = $data['exception']::class;
        });

        $response = $router->handle(new ServerRequest('GET', '/boom'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('"code":"SERVER_ERROR"', (string) $response->getBody());
        $this->assertSame([\RuntimeException::class, \TypeError::class], $hook);
    }

    /**
     * The route is looked up again at every step inwards, not only at the end: a guard that
     * sits behind the rewriting middleware decides on the route that is going to run.
     */
    public function testMiddlewareBehindARewriteSeesTheRouteOfTheRewrittenRequest(): void
    {
        $seen = [];
        $spy = function (string $label) use (&$seen): MiddlewareInterface {
            return new class ($label, $seen) implements MiddlewareInterface {
                /** @param array<int, mixed> $seen */
                public function __construct(private readonly string $label, private array &$seen)
                {
                }

                public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                {
                    $route = $request->getAttribute(\Sodaho\Router\Route::class);
                    $this->seen[] = [$this->label, $request->getMethod(), $request->getAttribute(RouteMatch::class)->method, $route?->getAttribute('is')];

                    if ($this->label === 'guard' && $route?->getAttribute('is') === 'delete') {
                        return Response::text('guarded', 403);
                    }
                    if ($this->label === 'override') {
                        $request = $request->withMethod('DELETE');
                    }

                    return $handler->handle($request);
                }
            };
        };

        $router = $this->router()->middleware([$spy('outer'), $spy('override'), $spy('guard')]);

        $response = $router->handle(new ServerRequest('POST', '/ok'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('guarded', (string) $response->getBody());
        $this->assertSame(
            [
                ['outer', 'POST', 'POST', null],
                ['override', 'POST', 'POST', null],
                ['guard', 'DELETE', 'DELETE', 'delete'],
            ],
            $seen
        );
    }

    public function testErrorHandlerThatHandsTheExceptionBackIsNotReportedTwice(): void
    {
        $hook = [];
        $router = $this->router()->setErrorHandler(fn (\Throwable $e) => throw $e);
        $router->on('error', function (array $data) use (&$hook): void {
            $hook[] = $data['exception']->getMessage();
        });

        $response = $router->handle(new ServerRequest('GET', '/boom'));

        // Throwing counts as null: the router's own 500 — and one report for one exception
        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('"code":"SERVER_ERROR"', (string) $response->getBody());
        $this->assertSame(['handler failed'], $hook);
    }

    /**
     * The router's own 500 is formatted by the responder — which an application can replace
     * (Response::setResponder()). One that throws there made handle() throw in 1.x. Now it
     * is reported, and the answer is a 500 that needs no responder; the error handler is
     * not asked a second time.
     */
    public function testFailingResponderGetsAnAnswerThatNeedsNoResponder(): void
    {
        $responder = new class () implements \Sodaho\Router\Contract\ResponderInterface {
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
        };

        $hook = [];
        $handled = [];
        $routers = [
            'handler throws' => $this->router(),
            'inside the middleware for every request' => $this->router()->middleware(new PipelineTag('global')),
            'with an error handler that declines' => $this->router()->setErrorHandler(function (\Throwable $e) use (&$handled): ?ResponseInterface {
                $handled[] = $e->getMessage();

                return null;
            }),
            'last resort' => Router::create(['debug' => false]),
        ];

        foreach ($routers as $label => $router) {
            $router->on('error', function (array $data) use (&$hook, $label): void {
                $hook[$label][] = $data['exception']->getMessage();
            });

            Response::setResponder($responder);

            try {
                $response = $router->handle(new ServerRequest('GET', '/boom'));
            } finally {
                Response::reset();
            }

            $this->assertSame(500, $response->getStatusCode(), $label);
            $this->assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'), $label);
            $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'), $label);
            $this->assertSame('Internal Server Error', (string) $response->getBody(), $label);

            // ... and the router is not stuck in that state
            $response = $router->handle(new ServerRequest('GET', '/boom'));
            $this->assertSame(500, $response->getStatusCode(), $label);
            $this->assertStringContainsString('"code":"SERVER_ERROR"', (string) $response->getBody(), $label);
        }

        $this->assertSame(
            [
                'handler throws' => ['handler failed', 'responder failed', 'handler failed'],
                'inside the middleware for every request' => ['handler failed', 'responder failed', 'handler failed'],
                'with an error handler that declines' => ['handler failed', 'responder failed', 'handler failed'],
                'last resort' => ['No routes loaded. Use loadRoutes() first.', 'responder failed', 'No routes loaded. Use loadRoutes() first.'],
            ],
            $hook
        );
        $this->assertSame(['handler failed', 'handler failed'], $handled);
    }

    /**
     * A responder that keeps throwing the very same exception object (it remembers why it
     * could not be set up). Each request reports it anew — once: a responder that fails
     * with the very exception it is asked to format has nothing new to report.
     */
    public function testFailingResponderIsReportedInEveryRequest(): void
    {
        $responder = new class () implements \Sodaho\Router\Contract\ResponderInterface {
            private ?\Throwable $failure = null;

            public function formatSuccess(mixed $data, ?string $message = null, ?array $meta = null): array
            {
                return ['data' => $data];
            }

            public function formatError(string $message, ?string $code = null, ?array $details = null): array
            {
                throw $this->failure ??= new \LogicException('responder not configured');
            }

            public function getContentType(): string
            {
                return 'application/json';
            }

            public function getSuccessContentType(): string
            {
                return 'application/json';
            }
        };

        $swallow = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                try {
                    return $handler->handle($request);
                } catch (\LogicException $e) {
                    return Response::text('middleware answered: ' . $e->getMessage(), 500);
                }
            }
        };

        foreach (['plain' => $this->router(), 'with a middleware that catches everything' => $this->router()->middleware($swallow)] as $label => $router) {
            $hook = [];
            $router->on('error', function (array $data) use (&$hook): void {
                $hook[] = $data['exception']->getMessage();
            });

            Response::setResponder($responder);

            try {
                // 404: the responder throws its remembered exception straight from the route table's answer
                foreach (['/nowhere', '/nowhere', '/boom', '/nowhere'] as $path) {
                    $response = $router->handle(new ServerRequest('GET', $path));

                    $this->assertSame(500, $response->getStatusCode(), $label);
                    $this->assertSame('Internal Server Error', (string) $response->getBody(), $label);
                }
            } finally {
                Response::reset();
            }

            $this->assertSame(
                ['responder not configured', 'responder not configured', 'handler failed', 'responder not configured', 'responder not configured'],
                $hook,
                $label
            );
        }
    }

    /**
     * A middleware for every request throws behind one that rewrote the request: the error
     * handler gets the request as far as it came — the route it decides the format by is
     * the one that was going to run.
     */
    public function testLastResortGetsTheRequestAsFarAsItCame(): void
    {
        $rewrite = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request->withUri($request->getUri()->withPath('/boom')));
            }
        };

        $seen = [];
        $hook = [];
        $router = $this->router()
            ->middleware([$rewrite, new PipelineTag('failing', throw: true)])
            ->setErrorHandler(function (\Throwable $e, ServerRequestInterface $request) use (&$seen): ?ResponseInterface {
                $match = $request->getAttribute(RouteMatch::class);
                $seen[] = [$e->getMessage(), $request->getUri()->getPath(), $match->path, $match->route?->getAttribute('format')];

                return Response::text('last resort', 500);
            })
            ->on('error', function (array $data) use (&$hook): void {
                $hook[] = $data['path'];
            });

        $response = $router->handle(new ServerRequest('GET', '/ok'));

        $this->assertSame('last resort', (string) $response->getBody());
        $this->assertSame([['failing middleware failed', '/boom', '/boom', 'oauth']], $seen);
        $this->assertSame(['/boom'], $hook);
    }

    /**
     * handle() called from inside a handler, with a responder that fails: the inner call
     * settles its own failure, and the outer handler gets a response like any other.
     */
    public function testFailingResponderInNestedCallsIsSettledByEachCallItself(): void
    {
        $responder = new class () implements \Sodaho\Router\Contract\ResponderInterface {
            public int $calls = 0;

            public function formatSuccess(mixed $data, ?string $message = null, ?array $meta = null): array
            {
                return ['data' => $data];
            }

            public function formatError(string $message, ?string $code = null, ?array $details = null): array
            {
                throw new \LogicException('responder failed, call ' . ++$this->calls);
            }

            public function getContentType(): string
            {
                return 'application/json';
            }

            public function getSuccessContentType(): string
            {
                return 'application/json';
            }
        };

        $routes = sys_get_temp_dir() . '/router_nested_routes_' . uniqid() . '.php';
        file_put_contents($routes, '<?php return function ($r) {
            $r->get("/inner", fn () => throw new \RuntimeException("inner handler failed"));
            $r->get("/outer", fn ($request) => $request->getAttribute("router")->handle($request->withUri($request->getUri()->withPath("/inner"))));
        };');

        try {
            $hook = [];
            $router = Router::create(['debug' => false])->loadRoutes($routes);
            $router->on('error', function (array $data) use (&$hook): void {
                $hook[] = $data['exception']->getMessage();
            });

            Response::setResponder($responder);

            try {
                $response = $router->handle(new ServerRequest('GET', '/outer')->withAttribute('router', $router));
            } finally {
                Response::reset();
            }

            $this->assertSame(500, $response->getStatusCode());
            $this->assertSame('Internal Server Error', (string) $response->getBody());
            $this->assertSame(['inner handler failed', 'responder failed, call 1'], $hook);
            $this->assertSame(1, $responder->calls);
        } finally {
            unlink($routes);
        }
    }

    /**
     * An exception object that once came out of a failing responder is an exception like
     * any other when a handler throws it in a later request.
     */
    public function testExceptionThatOnceCameFromTheResponderIsAnsweredLater(): void
    {
        $shared = new \LogicException('shared failure');
        $responder = new class ($shared) implements \Sodaho\Router\Contract\ResponderInterface {
            public function __construct(private readonly \Throwable $failure)
            {
            }

            public function formatSuccess(mixed $data, ?string $message = null, ?array $meta = null): array
            {
                return ['data' => $data];
            }

            public function formatError(string $message, ?string $code = null, ?array $details = null): array
            {
                throw $this->failure;
            }

            public function getContentType(): string
            {
                return 'application/json';
            }

            public function getSuccessContentType(): string
            {
                return 'application/json';
            }
        };

        $hook = [];
        $router = $this->router();
        $router->on('error', function (array $data) use (&$hook): void {
            $hook[] = $data['exception']->getMessage();
        });

        Response::setResponder($responder);

        try {
            $this->assertSame('Internal Server Error', (string) $router->handle(new ServerRequest('GET', '/boom'))->getBody());
        } finally {
            Response::reset();
        }

        // A middleware throws the very same object in the next request
        $throwsShared = new class ($shared) implements MiddlewareInterface {
            public function __construct(private readonly \Throwable $shared)
            {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                throw $this->shared;
            }
        };
        $response = $router->middleware($throwsShared)->handle(new ServerRequest('GET', '/ok'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('"code":"SERVER_ERROR"', (string) $response->getBody());
        $this->assertSame(['handler failed', 'shared failure', 'shared failure'], $hook);
    }

    public function testDispatcherWithoutAnErrorResponderLetsAResponseOutThatDoesNotTakeAnotherBody(): void
    {
        $stubborn = $this->createStub(ResponseInterface::class);
        $stubborn->method('withBody')->willThrowException(new \RuntimeException('withBody failed'));

        $collector = new RouteCollector();
        $collector->get('/x', fn () => $stubborn);
        $dispatcher = new RouteDispatcher($collector->getData())->setImplicitHead(true);

        $this->assertSame($stubborn, $dispatcher->handle(new ServerRequest('GET', '/x')));

        // Without a responder exceptions leave handle(), as before
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('withBody failed');
        $dispatcher->handle(new ServerRequest('HEAD', '/x'));
    }

    public function testErrorResponderIsCalledWithTwoArgumentsAsIn1x(): void
    {
        $collector = new RouteCollector();
        $collector->get('/x', fn () => throw new \RuntimeException('handler failed'));

        $calls = [];
        $responders = [
            'two parameters' => function (\Throwable $e, ServerRequestInterface $request) use (&$calls): ResponseInterface {
                $calls['two parameters'] = func_get_args();

                return Response::text('two', 500);
            },
            'variadic' => function (...$arguments) use (&$calls): ResponseInterface {
                $calls['variadic'] = $arguments;

                return Response::text('variadic', 500);
            },
            'optional third of another type' => function (\Throwable $e, ServerRequestInterface $request, ?string $legacy = 'legacy') use (&$calls): ResponseInterface {
                $calls['optional third of another type'] = func_get_args();

                return Response::text((string) $legacy, 500);
            },
            'optional third typed WeakMap' => function (\Throwable $e, ServerRequestInterface $request, ?\WeakMap $known = null) use (&$calls): ResponseInterface {
                $calls['optional third typed WeakMap'] = func_get_args();

                return Response::text('weakmap', 500);
            },
        ];

        foreach ($responders as $responder) {
            new RouteDispatcher($collector->getData())->setErrorResponder($responder)->handle(new ServerRequest('GET', '/x'));
        }

        $this->assertSame(
            ['two parameters' => 2, 'variadic' => 2, 'optional third of another type' => 2, 'optional third typed WeakMap' => 2],
            array_map('count', $calls)
        );

        // The router's own responder gets the call's record as a third argument
        $record = null;
        $dispatcher = new RouteDispatcher($collector->getData())->setErrorResponderWithRecord(
            function (\Throwable $e, ServerRequestInterface $request, \WeakMap $known) use (&$record): ResponseInterface {
                $record = $known;

                return Response::text('router', 500);
            }
        );
        $dispatcher->handle(new ServerRequest('GET', '/x'));
        $this->assertInstanceOf(\WeakMap::class, $record);

        // … and a responder set afterwards is called with two again
        $calls = [];
        $dispatcher->setErrorResponder($responders['variadic'])->handle(new ServerRequest('GET', '/x'));
        $this->assertCount(2, $calls['variadic']);

        // … and a responder set back to null is no responder: the exception leaves handle()
        $dispatcher->setErrorResponder(null);
        $this->expectException(\RuntimeException::class);
        $dispatcher->handle(new ServerRequest('GET', '/x'));
    }

    public function testDispatcherOnItsOwnReportsThroughItsOwnErrorHook(): void
    {
        // Without a router there is no reporter: what the dispatcher settles itself goes to
        // its own error hook
        $refuses = function (string $message): ResponseInterface {
            $stubborn = $this->createStub(ResponseInterface::class);
            $stubborn->method('withBody')->willThrowException(new \RuntimeException($message));

            return $stubborn;
        };

        $collector = new RouteCollector();
        $collector->get('/x', fn () => $refuses('route answer refuses'));

        $reports = [];
        $dispatcher = new RouteDispatcher($collector->getData())
            ->setImplicitHead(true)
            ->setErrorResponder(fn (\Throwable $e) => $refuses('responder answer refuses'));
        $dispatcher->on('error', function (array $data) use (&$reports): void {
            $reports[] = [$data['exception']->getMessage(), $data['method'], $data['path']];
        });

        $response = $dispatcher->handle(new ServerRequest('HEAD', '/x'));

        $this->assertSame([500, ''], [$response->getStatusCode(), (string) $response->getBody()]);
        // The route's exception went to the responder (its business to report); what its
        // answer threw is the dispatcher's
        $this->assertSame([['responder answer refuses', 'HEAD', '/x']], $reports);
    }

    public function testErrorResponderOfTheDispatcherThatThrowsIsCalledOnce(): void
    {
        $collector = new RouteCollector();
        $collector->get('/boom', fn () => throw new \RuntimeException('handler failed'));

        $calls = [];
        $dispatcher = new RouteDispatcher($collector->getData())
            ->setMiddleware([new PipelineTag('global')])
            ->setErrorResponder(function (\Throwable $e) use (&$calls): ResponseInterface {
                $calls[] = $e->getMessage();

                throw new \LogicException('responder failed, call ' . count($calls));
            });

        try {
            $dispatcher->handle(new ServerRequest('GET', '/boom'));
            $this->fail('handle() answered');
        } catch (\LogicException $e) {
            $this->assertSame('responder failed, call 1', $e->getMessage());
        }

        $this->assertSame(['handler failed'], $calls);
    }
}

final class PipelineTag implements MiddlewareInterface
{
    /** @var list<string> */
    public static array $log = [];

    public function __construct(private readonly string $label = 'class', private readonly bool $throw = false)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        self::$log[] = "{$this->label} in " . $request->getAttribute(RouteMatch::class)?->status;

        if ($this->throw) {
            throw new \RuntimeException("{$this->label} middleware failed");
        }

        $response = $handler->handle($request);
        self::$log[] = "{$this->label} out " . $response->getStatusCode();

        $seen = $response->getHeaderLine('X-Seen-By');

        return $response->withHeader('X-Seen-By', $seen === '' ? $this->label : "{$seen}, {$this->label}");
    }
}

final class NeverBuilt
{
    public function __construct()
    {
        throw new \RuntimeException('controller cannot be built');
    }

    public function show(): ResponseInterface
    {
        return Response::success('never');
    }
}
