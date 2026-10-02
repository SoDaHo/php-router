<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Feature;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\Response;
use Sodaho\Router\Route;
use Sodaho\Router\RouteCollector;
use Sodaho\Router\RouteDispatcher;
use Sodaho\Router\RouteMatch;
use Sodaho\Router\Router;

/**
 * Looking a request up without running it — and the result travelling with the request.
 */
class RouteMatchTest extends TestCase
{
    private string $routesFile;

    protected function setUp(): void
    {
        $this->routesFile = sys_get_temp_dir() . '/router_match_routes_' . uniqid() . '.php';

        file_put_contents(
            $this->routesFile,
            <<<'PHP'
                <?php
                use Sodaho\Router\Tests\Feature\{MatchController, MatchSpy};

                return function (Sodaho\Router\RouteCollector $r) {
                    $r->get('/health', [MatchController::class, 'show'])->name('health');
                    $r->get('/users/{id:int}', [MatchController::class, 'show'])->name('users.show')->middleware(MatchSpy::class);
                    $r->patch('/users/{id:int}', [MatchController::class, 'show'])->name('users.update');
                    $r->post('/token', [MatchController::class, 'show'])->name('token');
                    $r->delete('/token', [MatchController::class, 'show'])->name('token.revoke');
                    $r->post('/things/new', [MatchController::class, 'show'])->name('things.create');
                    $r->get('/things/{id}', [MatchController::class, 'show'])->name('things.show');
                };
                PHP
        );

        MatchSpy::$seen = [];
        MatchController::$seen = [];
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
        return Router::create($config + ['debug' => false, 'cacheFile' => ''])->loadRoutes($this->routesFile);
    }

    public function testHit(): void
    {
        $match = $this->router()->match(new ServerRequest('GET', '/users/5'));

        $this->assertSame(RouteMatch::FOUND, $match->status);
        $this->assertTrue($match->isFound());
        $this->assertSame('GET', $match->method);
        $this->assertSame('/users/5', $match->path);
        $this->assertInstanceOf(Route::class, $match->route);
        $this->assertSame('users.show', $match->route->name);
        $this->assertSame('/users/{id:int}', $match->route->pattern);
        $this->assertFalse($match->viaGet);

        // As they stand in the path; the cast to int happens when the request is handled
        $this->assertSame(['id' => '5'], $match->params);
        $this->assertSame(['id' => 'int'], $match->casts);

        // Every method the path knows, in the order of the 405 list
        $this->assertSame(['GET', 'PATCH'], $match->allowedMethods());
    }

    public function testStaticHit(): void
    {
        $match = $this->router()->match(new ServerRequest('GET', '/health'));

        $this->assertTrue($match->isFound());
        $this->assertSame([], $match->params);
        $this->assertSame([], $match->casts);
        $this->assertSame(['GET'], $match->allowedMethods());
    }

    public function testNoRoute(): void
    {
        $match = $this->router()->match(new ServerRequest('GET', '/nowhere'));

        $this->assertSame(RouteMatch::NOT_FOUND, $match->status);
        $this->assertFalse($match->isFound());
        $this->assertNull($match->route);
        $this->assertSame([], $match->params);
        $this->assertSame([], $match->allowedMethods());
        $this->assertSame('/nowhere', $match->path);
    }

    /**
     * A CORS preflight is an OPTIONS request and therefore a 405 for the router. That is
     * exactly where an application needs the route: its pattern for the log, its attributes
     * for the decision. So the match carries a route of the path — the GET route if there
     * is one, otherwise that of the first allowed method.
     */
    public function testMethodNotAllowedCarriesARouteOfThePath(): void
    {
        $router = $this->router();

        $withGet = $router->match(new ServerRequest('OPTIONS', '/users/5'));
        $this->assertSame(RouteMatch::METHOD_NOT_ALLOWED, $withGet->status);
        $this->assertFalse($withGet->isFound());
        $this->assertSame(['GET', 'PATCH'], $withGet->allowedMethods());
        $this->assertSame('users.show', $withGet->route?->name);
        $this->assertSame([], $withGet->params);

        // The GET route also when it is not the first in the list (methods of static routes come first)
        $getSecond = $router->match(new ServerRequest('OPTIONS', '/things/new'));
        $this->assertSame(['POST', 'GET'], $getSecond->allowedMethods());
        $this->assertSame('things.show', $getSecond->route?->name);

        $withoutGet = $router->match(new ServerRequest('OPTIONS', '/token'));
        $this->assertSame(['POST', 'DELETE'], $withoutGet->allowedMethods());
        $this->assertSame('token', $withoutGet->route?->name);
    }

    public function testPathIsTheOneTheTableIsAskedWith(): void
    {
        $router = $this->router(['basePath' => '/api', 'trailingSlash' => 'ignore']);

        $match = $router->match(new ServerRequest('GET', '/api/users/5/'));
        $this->assertTrue($match->isFound());
        $this->assertSame('/users/5', $match->path);

        $match = $router->match(new ServerRequest('GET', '/api/he%61lth'));
        $this->assertTrue($match->isFound());
        $this->assertSame('/health', $match->path);

        // Outside the base path there is nothing to strip: the path as requested, decoded
        $outside = $router->match(new ServerRequest('GET', '/other/he%61lth'));
        $this->assertSame(RouteMatch::NOT_FOUND, $outside->status);
        $this->assertSame('/other/health', $outside->path);
        $this->assertSame([], $outside->allowedMethods());
    }

    public function testLookupRunsNothing(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->never())->method('has');
        $container->expects($this->never())->method('get');

        $hooks = [];
        $router = $this->router()->setContainer($container);
        foreach (['dispatch', 'notFound', 'methodNotAllowed', 'error'] as $event) {
            $router->on($event, function () use (&$hooks, $event): void {
                $hooks[] = $event;
            });
        }
        $router->middleware(new MatchSpy('global'));

        $router->match(new ServerRequest('GET', '/users/5'));
        $router->match(new ServerRequest('GET', '/users/not-a-number'));
        $router->match(new ServerRequest('GET', '/nowhere'));
        $router->match(new ServerRequest('PUT', '/users/5'));

        $this->assertSame([], $hooks);
        $this->assertSame([], MatchSpy::$seen);
        $this->assertSame([], MatchController::$seen);
    }

    public function testMatchWithoutRoutesThrows(): void
    {
        $this->expectException(RouterException::class);
        $this->expectExceptionMessage('No routes loaded');

        Router::create(['debug' => false, 'cacheFile' => ''])->match(new ServerRequest('GET', '/'));
    }

    /**
     * An application may want to know the route before it has built its container (to pick
     * the error format, to decide whether configuration is needed at all). What it adds
     * afterwards — container, middleware, error handler, hooks — must still take effect.
     * (Base path and trailing slash mode are fixed with the first use, as ever.)
     */
    public function testEarlyLookupFreezesNothing(): void
    {
        $router = $this->router();
        $this->assertTrue($router->match(new ServerRequest('GET', '/users/5'))->isFound());

        // Only now: container, middleware for every request, error handler, a hook
        $controller = new MatchController('from the container');
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(fn (string $id): bool => $id === MatchController::class);
        $container->method('get')->willReturn($controller);

        $dispatched = 0;
        $router
            ->setContainer($container)
            ->middleware(new MatchSpy('global'))
            ->setErrorHandler(fn (\Throwable $e): ResponseInterface => Response::text('handled: ' . $e->getMessage(), 503))
            ->on('dispatch', function () use (&$dispatched): void {
                $dispatched++;
            });

        $response = $router->handle((new ServerRequest('GET', '/users/5')));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('from the container', json_decode((string) $response->getBody(), true)['data']['origin']);
        $this->assertSame(['global', 'route'], array_column(MatchSpy::$seen, 'label'));
        $this->assertSame(1, $dispatched);

        $failing = $router->handle((new ServerRequest('GET', '/users/5'))->withHeader('X-Throw', '1'));
        $this->assertSame(503, $failing->getStatusCode());
        $this->assertSame('handled: controller failed', (string) $failing->getBody());
    }

    public function testMatchTravelsWithTheRequest(): void
    {
        $router = $this->router()->middleware(new MatchSpy('global'));

        $router->handle((new ServerRequest('GET', '/users/5')));

        [$global, $route] = MatchSpy::$seen;

        // The middleware for every request knows the route before the handler runs ...
        $this->assertSame(RouteMatch::FOUND, $global['match']->status);
        $this->assertSame('users.show', $global['match']->route->name);
        $this->assertSame('users.show', $global['route']->name);

        // ... route middleware and handler get the same objects
        $this->assertSame($global['match'], $route['match']);
        $this->assertSame($global['route'], $route['route']);
        $this->assertSame($global['match'], MatchController::$seen[0]['match']);
        $this->assertSame($global['route'], MatchController::$seen[0]['route']);
        $this->assertSame(['id' => 5], MatchController::$seen[0]['params']);
    }

    public function testRequestsWithoutAHitCarryTheMatchButNoRoute(): void
    {
        $router = $this->router()->middleware(new MatchSpy('global'));

        $this->assertSame(404, $router->handle(new ServerRequest('GET', '/nowhere'))->getStatusCode());
        $this->assertSame(405, $router->handle(new ServerRequest('OPTIONS', '/users/5'))->getStatusCode());

        [$notFound, $notAllowed] = MatchSpy::$seen;

        $this->assertSame(RouteMatch::NOT_FOUND, $notFound['match']->status);
        $this->assertNull($notFound['route']);

        // 405: the match names a route of the path, but the request is not "at" that route
        $this->assertSame(RouteMatch::METHOD_NOT_ALLOWED, $notAllowed['match']->status);
        $this->assertSame('users.show', $notAllowed['match']->route->name);
        $this->assertNull($notAllowed['route']);
    }

    public function testLookupMadeBeforehandIsTakenOver(): void
    {
        $router = $this->router()->middleware(new MatchSpy('global'));

        $request = (new ServerRequest('GET', '/users/5'));
        $match = $router->match($request);

        $router->handle($request->withAttribute(RouteMatch::class, $match));

        $this->assertSame($match, MatchSpy::$seen[0]['match']);
    }

    /**
     * The attribute is a hint, not an instruction. It counts only while it still describes
     * the request — otherwise a request rewritten after the lookup (another method, another
     * path) would be dispatched to the route of the old one.
     */
    public function testLookupThatNoLongerFitsTheRequestIsRedone(): void
    {
        $router = $this->router()->middleware(new MatchSpy('global'));

        $request = (new ServerRequest('GET', '/users/5'));
        $stale = $router->match($request);

        // Method changed after the lookup
        $response = $router->handle($request->withMethod('PATCH')->withAttribute(RouteMatch::class, $stale));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('users.update', json_decode((string) $response->getBody(), true)['data']['route']);

        // Path changed after the lookup
        $response = $router->handle(
            $request->withUri($request->getUri()->withPath('/health'))->withAttribute(RouteMatch::class, $stale)
        );
        $this->assertSame('health', json_decode((string) $response->getBody(), true)['data']['route']);

        // A match of another router
        $foreign = $this->router()->match($request);
        $router->handle($request->withAttribute(RouteMatch::class, $foreign));
        $this->assertNotSame($foreign, MatchSpy::$seen[2]['match']);
        $this->assertSame('users.show', MatchSpy::$seen[2]['match']->route->name);

        // Anything else under that name
        $response = $router->handle($request->withAttribute(RouteMatch::class, 'not a match'));
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * With a base path, '/health' outside it and '/api/health' inside it are the same path
     * for the table. A lookup made for the one must never stand in for the other.
     */
    public function testLookupIsBoundToTheRequestPathItWasMadeFor(): void
    {
        $router = $this->router(['basePath' => '/api']);
        $inside = new ServerRequest('GET', '/api/health');
        $outside = new ServerRequest('GET', '/health');

        $hit = $router->match($inside);
        $miss = $router->match($outside);

        $this->assertTrue($hit->isFound());
        $this->assertSame(RouteMatch::NOT_FOUND, $miss->status);
        $this->assertSame($hit->path, $miss->path);

        // The hit does not open the route for the request outside the base path ...
        $this->assertSame(404, $router->handle($outside->withAttribute(RouteMatch::class, $hit))->getStatusCode());
        $this->assertSame([], MatchController::$seen);

        // ... and the miss does not hide it from the request inside
        $this->assertSame(200, $router->handle($inside->withAttribute(RouteMatch::class, $miss))->getStatusCode());
    }

    public function testRewriteAcrossTheBasePathIsLookedUpAgain(): void
    {
        $rewriteTo = fn (string $path): MiddlewareInterface => new class ($path) implements MiddlewareInterface {
            public function __construct(private readonly string $path)
            {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request->withUri($request->getUri()->withPath($this->path)));
            }
        };

        $into = $this->router(['basePath' => '/api'])->middleware($rewriteTo('/api/health'));
        $this->assertSame(200, $into->handle(new ServerRequest('GET', '/health'))->getStatusCode());

        $outOf = $this->router(['basePath' => '/api'])->middleware($rewriteTo('/health'));
        $this->assertSame(404, $outOf->handle(new ServerRequest('GET', '/api/health'))->getStatusCode());
        $this->assertCount(1, MatchController::$seen, 'only the first of the two reached the controller');
    }

    /**
     * Only what the router looked up itself is taken over. A RouteMatch somebody built — with
     * whatever route in it — is looked up again like any other stale attribute.
     */
    public function testSelfMadeMatchIsNotTakenOver(): void
    {
        $ran = false;
        $foreign = new Route(['GET'], '/nowhere', function () use (&$ran): ResponseInterface {
            $ran = true;

            return Response::text('forged');
        });
        $forged = new RouteMatch(RouteMatch::FOUND, 'GET', '/nowhere', $foreign);
        $request = (new ServerRequest('GET', '/nowhere'))
            ->withAttribute(RouteMatch::class, $forged)
            ->withAttribute(Route::class, $foreign);

        $this->assertSame(404, $this->router()->handle($request)->getStatusCode());

        // The dispatcher on its own is no more trusting
        $collector = new RouteCollector();
        $collector->get('/x', fn () => Response::text('x'));
        $dispatcher = new RouteDispatcher($collector->getData());

        $this->assertSame(404, $dispatcher->handle($request)->getStatusCode());
        $this->assertSame('x', (string) $dispatcher->handle($request->withUri($request->getUri()->withPath('/x')))->getBody());
        $this->assertFalse($ran);

        // What such a match says about itself still works
        $this->assertSame([], $forged->allowedMethods());
        $this->assertSame(['GET'], (new RouteMatch(RouteMatch::METHOD_NOT_ALLOWED, 'PUT', '/x', allowedMethods: ['GET']))->allowedMethods());
    }
}

final class MatchController
{
    /** @var list<array<string, mixed>> */
    public static array $seen = [];

    public function __construct(private readonly string $origin = 'instantiated')
    {
    }

    public function show(ServerRequestInterface $request, mixed $id = null): ResponseInterface
    {
        self::$seen[] = [
            'match' => $request->getAttribute(RouteMatch::class),
            'route' => $request->getAttribute(Route::class),
            'params' => $request->getAttribute('_route_params'),
        ];

        if ($request->hasHeader('X-Throw')) {
            throw new \RuntimeException('controller failed');
        }

        return Response::success([
            'origin' => $this->origin,
            'route' => $request->getAttribute(Route::class)?->name,
            'id' => $id,
        ]);
    }
}

final class MatchSpy implements MiddlewareInterface
{
    /** @var list<array<string, mixed>> */
    public static array $seen = [];

    public function __construct(private readonly string $label = 'route')
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        self::$seen[] = [
            'label' => $this->label,
            'match' => $request->getAttribute(RouteMatch::class),
            'route' => $request->getAttribute(Route::class),
        ];

        return $handler->handle($request);
    }
}
