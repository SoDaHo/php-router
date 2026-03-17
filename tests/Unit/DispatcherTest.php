<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\Router\Dispatcher;
use Sodaho\Router\Route;

class DispatcherTest extends TestCase
{
    public function testStaticRouteMatch(): void
    {
        $route = new Route(['GET'], '/users', 'handler');
        $dispatcher = new Dispatcher(
            ['GET' => ['/users' => $route]],
            []
        );

        $result = $dispatcher->dispatch('GET', '/users');

        $this->assertSame(Dispatcher::FOUND, $result[0]);
        $this->assertSame($route, $result[1]);
        $this->assertSame([], $result[2]); // No params
    }

    public function testDynamicRouteMatch(): void
    {
        $route = new Route(['GET'], '/users/{id}', 'handler');
        $dispatcher = new Dispatcher(
            [],
            ['GET' => [
                [
                    'regex' => '#^/users/(?P<id>[^/]+)$#',
                    'route' => $route,
                    'casts' => [],
                ],
            ]]
        );

        $result = $dispatcher->dispatch('GET', '/users/123');

        $this->assertSame(Dispatcher::FOUND, $result[0]);
        $this->assertSame($route, $result[1]);
        $this->assertSame(['id' => '123'], $result[2]);
    }

    public function testNotFound(): void
    {
        $dispatcher = new Dispatcher([], []);

        $result = $dispatcher->dispatch('GET', '/nonexistent');

        $this->assertSame(Dispatcher::NOT_FOUND, $result[0]);
    }

    public function testMethodNotAllowed(): void
    {
        $route = new Route(['GET'], '/users', 'handler');
        $dispatcher = new Dispatcher(
            ['GET' => ['/users' => $route]],
            []
        );

        $result = $dispatcher->dispatch('POST', '/users');

        $this->assertSame(Dispatcher::METHOD_NOT_ALLOWED, $result[0]);
        $this->assertContains('GET', $result[1]); // Allowed methods
    }

    public function testCastsAreReturned(): void
    {
        $route = new Route(['GET'], '/users/{id:int}', 'handler');
        $dispatcher = new Dispatcher(
            [],
            ['GET' => [
                [
                    'regex' => '#^/users/(?P<id>\d+)$#',
                    'route' => $route,
                    'casts' => ['id' => 'int'],
                ],
            ]]
        );

        $result = $dispatcher->dispatch('GET', '/users/42');

        $this->assertSame(['id' => 'int'], $result[3]);
    }

    public function testMethodNotAllowedForDynamicRoutes(): void
    {
        $route = new Route(['GET'], '/users/{id}', 'handler');
        $dispatcher = new Dispatcher(
            [],
            ['GET' => [
                [
                    'regex' => '#^/users/(?P<id>[^/]+)$#',
                    'route' => $route,
                    'casts' => [],
                ],
            ]]
        );

        // POST to a dynamic route that only allows GET
        $result = $dispatcher->dispatch('POST', '/users/123');

        $this->assertSame(Dispatcher::METHOD_NOT_ALLOWED, $result[0]);
        $this->assertContains('GET', $result[1]);
    }

    public function testDynamicRouteNoMatchContinuesToNextRoute(): void
    {
        $route1 = new Route(['GET'], '/users/{id:int}', 'handler1');
        $route2 = new Route(['GET'], '/users/{slug}', 'handler2');
        $dispatcher = new Dispatcher(
            [],
            ['GET' => [
                [
                    'regex' => '#^/users/(?P<id>\d+)$#',
                    'route' => $route1,
                    'casts' => ['id' => 'int'],
                ],
                [
                    'regex' => '#^/users/(?P<slug>[a-z-]+)$#',
                    'route' => $route2,
                    'casts' => [],
                ],
            ]]
        );

        // Should match second route (slug pattern)
        $result = $dispatcher->dispatch('GET', '/users/john-doe');

        $this->assertSame(Dispatcher::FOUND, $result[0]);
        $this->assertSame($route2, $result[1]);
        $this->assertSame(['slug' => 'john-doe'], $result[2]);
    }

    public function testNoDynamicRoutesForMethod(): void
    {
        $route = new Route(['GET'], '/users/{id}', 'handler');
        $dispatcher = new Dispatcher(
            [],
            ['GET' => [
                [
                    'regex' => '#^/users/(?P<id>[^/]+)$#',
                    'route' => $route,
                    'casts' => [],
                ],
            ]]
        );

        // DELETE has no dynamic routes defined
        $result = $dispatcher->dispatch('DELETE', '/users/123');

        // Should be 405 because GET exists for this URI
        $this->assertSame(Dispatcher::METHOD_NOT_ALLOWED, $result[0]);
    }

    public function testCastsWithoutCastsKey(): void
    {
        $route = new Route(['GET'], '/test/{param}', 'handler');
        $dispatcher = new Dispatcher(
            [],
            ['GET' => [
                [
                    'regex' => '#^/test/(?P<param>[^/]+)$#',
                    'route' => $route,
                    'casts' => [],
                ],
            ]]
        );

        $result = $dispatcher->dispatch('GET', '/test/value');

        $this->assertSame(Dispatcher::FOUND, $result[0]);
        $this->assertSame([], $result[3]);
    }
}
