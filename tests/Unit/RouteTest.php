<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\Router\Route;

class RouteTest extends TestCase
{
    public function testConstructor(): void
    {
        $route = new Route(
            ['GET', 'POST'],
            '/users/{id}',
            'UserController@show',
            ['AuthMiddleware'],
            'users.show'
        );

        $this->assertSame(['GET', 'POST'], $route->methods);
        $this->assertSame('/users/{id}', $route->pattern);
        $this->assertSame('UserController@show', $route->handler);
        $this->assertSame(['AuthMiddleware'], $route->middleware);
        $this->assertSame('users.show', $route->name);
    }

    public function testDefaultValues(): void
    {
        $route = new Route(['GET'], '/test', 'handler');

        $this->assertSame([], $route->middleware);
        $this->assertNull($route->name);
    }

    public function testFluentMiddleware(): void
    {
        $route = new Route(['GET'], '/test', 'handler');

        $result = $route->middleware('AuthMiddleware');

        $this->assertSame($route, $result); // Fluent API
        $this->assertContains('AuthMiddleware', $route->middleware);
    }

    public function testMiddlewareAcceptsArray(): void
    {
        $route = new Route(['GET'], '/test', 'handler');

        $route->middleware(['Auth', 'Log', 'Cors']);

        $this->assertCount(3, $route->middleware);
        $this->assertContains('Auth', $route->middleware);
        $this->assertContains('Log', $route->middleware);
        $this->assertContains('Cors', $route->middleware);
    }

    public function testMiddlewareAccumulatesMultipleCalls(): void
    {
        $route = new Route(['GET'], '/test', 'handler');

        $route->middleware('First');
        $route->middleware('Second');

        $this->assertSame(['First', 'Second'], $route->middleware);
    }

    public function testFluentName(): void
    {
        $route = new Route(['GET'], '/test', 'handler');

        $result = $route->name('test.route');

        $this->assertSame($route, $result); // Fluent API
        $this->assertSame('test.route', $route->name);
    }

    public function testNameOverwritesPreviousName(): void
    {
        $route = new Route(['GET'], '/test', 'handler', [], 'old.name');

        $route->name('new.name');

        $this->assertSame('new.name', $route->name);
    }

    public function testHandlerCanBeArray(): void
    {
        $route = new Route(['GET'], '/test', ['UserController', 'index']);

        $this->assertSame(['UserController', 'index'], $route->handler);
    }

    public function testHandlerCanBeClosure(): void
    {
        $closure = fn () => 'test';
        $route = new Route(['GET'], '/test', $closure);

        $this->assertSame($closure, $route->handler);
    }

    public function testMiddlewareAcceptsObject(): void
    {
        $middlewareInstance = new class () {
            public function process(): void
            {
            }
        };

        $route = new Route(['GET'], '/test', 'handler');
        $route->middleware($middlewareInstance);

        $this->assertContains($middlewareInstance, $route->middleware);
    }

    public function testAttributes(): void
    {
        $route = new Route(['GET'], '/token', 'handler');
        $this->assertSame([], $route->attributes);
        $this->assertNull($route->getAttribute('format'));
        $this->assertSame('envelope', $route->getAttribute('format', 'envelope'));

        $this->assertSame($route, $route->attribute('format', 'oauth')->attribute('cors', false));
        $this->assertSame(['format' => 'oauth', 'cors' => false], $route->attributes);
        $this->assertSame('oauth', $route->getAttribute('format', 'envelope'));

        // An attribute that is set to null or false is set — the default is for missing ones
        $this->assertFalse($route->getAttribute('cors', true));
        $route->attribute('tag', null);
        $this->assertNull($route->getAttribute('tag', 'default'));

        $route->attribute('format', 'metadata');
        $this->assertSame('metadata', $route->getAttribute('format'));
    }

    public function testAttributesThroughConstructor(): void
    {
        $route = new Route(['GET'], '/x', 'h', [], null, ['format' => 'oauth']);

        $this->assertSame(['format' => 'oauth'], $route->attributes);
    }

    public function testRouteSerializedBeforeAttributesExistedWakesUpWithNone(): void
    {
        // What an application that serializes routes itself may still hold from 1.1: five properties
        $before = 'O:19:"Sodaho\Router\Route":5:{s:7:"methods";a:1:{i:0;s:3:"GET";}s:7:"pattern";s:2:"/x";'
            . 's:7:"handler";s:1:"h";s:10:"middleware";a:0:{}s:4:"name";N;}';

        $route = unserialize($before);

        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame([], $route->attributes);
        $this->assertSame('fallback', $route->getAttribute('format', 'fallback'));
    }
}
