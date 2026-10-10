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
use Sodaho\Router\RouteCollector;
use Sodaho\Router\Router;

/**
 * A route serves every request after the table was built from it — in a worker process
 * (RoadRunner, FrankenPHP, handle() in a loop) the next one too. What a request changed on
 * it held for all of them; once the table is built a route is frozen.
 */
class FrozenRouteTest extends TestCase
{
    private const FROZEN = 'Route cannot be changed once the route table is built: it serves every request after that';

    private string $routesFile;

    protected function setUp(): void
    {
        $this->routesFile = sys_get_temp_dir() . '/router_frozen_route_' . uniqid() . '.php';

        file_put_contents(
            $this->routesFile,
            <<<'PHP'
                <?php
                use Sodaho\Router\Response;
                use Sodaho\Router\Route;
                use Sodaho\Router\Tests\Feature\Escalate;

                return function (Sodaho\Router\RouteCollector $r) {
                    $r->get('/admin', fn ($req) => Response::text('role: ' . var_export($req->getAttribute(Route::class)->getAttribute('role'), true)))
                        ->middleware(new Escalate())
                        ->attribute('area', 'admin');
                };
                PHP
        );
    }

    protected function tearDown(): void
    {
        unlink($this->routesFile);
    }

    /**
     * The worker case: a request that writes to its route must not change what the next
     * one sees. It is refused, and the next request finds the route as the routes file left it.
     */
    public function testWhatARequestWritesToItsRouteDoesNotReachTheNextOne(): void
    {
        $router = Router::create()->loadRoutes($this->routesFile);
        $reported = [];
        $router->on('error', function (array $data) use (&$reported): void {
            $reported[] = $data;
        });

        $first = $router->handle(new ServerRequest('GET', '/admin')->withHeader('X-Escalate', 'attacker'));

        $this->assertSame(500, $first->getStatusCode());
        $this->assertCount(1, $reported);
        $this->assertInstanceOf(RouterException::class, $reported[0]['exception']);
        $this->assertSame(self::FROZEN, $reported[0]['exception']->getMessage());
        $this->assertSame('/admin', $reported[0]['exception']->getDebugMessage());

        $second = $router->handle(new ServerRequest('GET', '/admin'));

        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame('role: NULL', (string) $second->getBody());
    }

    public function testEveryWayToChangeAFrozenRouteThrows(): void
    {
        $route = $this->frozenRoute();

        $changes = [
            'middleware()' => fn () => $route->middleware(new Escalate()),
            'name()' => fn () => $route->name('other'),
            'attribute()' => fn () => $route->attribute('area', 'public'),
            'assign middleware' => function () use ($route): void {
                $route->middleware = [];
            },
            'assign name' => function () use ($route): void {
                $route->name = null;
            },
            'assign attributes' => function () use ($route): void {
                $route->attributes = [];
            },
        ];

        foreach ($changes as $label => $change) {
            try {
                $change();
                $this->fail($label . ' was accepted');
            } catch (RouterException $e) {
                $this->assertSame(self::FROZEN, $e->getMessage(), $label);
            }
        }

        // Read as before, unchanged
        $this->assertSame('admin', $route->getAttribute('area'));
        $this->assertSame(['area' => 'admin'], $route->attributes);
        $this->assertSame('panel', $route->name);
        $this->assertCount(1, $route->middleware);
    }

    public function testRouteIsChangeableUntilTheTableIsBuilt(): void
    {
        $collector = new RouteCollector();
        $route = $collector->get('/a', 'handler');

        $route->name('a')->attribute('x', 1)->middleware(new Escalate());
        $route->name = 'b';
        $route->attributes = ['y' => 2];
        $route->middleware = [];

        $this->assertSame('b', $route->name);
        $this->assertSame(['y' => 2], $route->attributes);
        $this->assertSame([], $route->middleware);
    }

    public function testTableThatCouldNotBeBuiltFreezesNothing(): void
    {
        $collector = new RouteCollector();
        $route = $collector->get('/a/{id:nope}', 'handler');

        try {
            $collector->getData();
            $this->fail('The table was built');
        } catch (RouterException) {
            // a type nobody defined
        }

        $route->attribute('still', 'open');
        $this->assertSame('open', $route->getAttribute('still'));
    }

    public function testArrayOfARouteIsNeverWrittenInPlace(): void
    {
        $route = new RouteCollector()->get('/a', 'handler');

        // The class and the property, not the wording of the engine: that may change with PHP
        $this->expectException(\Error::class);
        $this->expectExceptionMessageMatches('/Route::\$attributes\b/');

        $route->attributes['k'] = 'v';
    }

    private function frozenRoute(): Route
    {
        $collector = new RouteCollector();
        $route = $collector->get('/admin', fn () => Response::text('ok'))
            ->name('panel')
            ->attribute('area', 'admin')
            ->middleware(new Escalate());

        $collector->getData();

        return $route;
    }
}

/**
 * Route middleware of the fixture: writes what the request asks for onto the shared route.
 */
final class Escalate implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $role = $request->getHeaderLine('X-Escalate');
        $route = $request->getAttribute(Route::class);

        if ($role !== '' && $route instanceof Route) {
            $route->attribute('role', $role);
        }

        return $handler->handle($request);
    }
}
