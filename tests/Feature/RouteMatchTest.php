<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Feature;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\Response;
use Sodaho\Router\Route;
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

    public function testMatchWithoutRoutesThrows(): void
    {
        $this->expectException(RouterException::class);
        $this->expectExceptionMessage('No routes loaded');

        Router::create(['debug' => false, 'cacheFile' => ''])->match(new ServerRequest('GET', '/'));
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
