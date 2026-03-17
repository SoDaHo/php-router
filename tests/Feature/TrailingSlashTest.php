<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Feature;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\Response;
use Sodaho\Router\RouteCollector;
use Sodaho\Router\RouteDispatcher;

class TrailingSlashTest extends TestCase
{
    public function testStrictModeExactMatch(): void
    {
        $collector = new RouteCollector();
        $collector->get('/users', fn ($req) => Response::success(['route' => '/users']));

        $dispatcher = new RouteDispatcher($collector->getData(), null, '', 'strict');

        // Exact match works
        $response = $dispatcher->handle(new ServerRequest('GET', '/users'));
        $this->assertSame(200, $response->getStatusCode());

        // With trailing slash fails
        $response = $dispatcher->handle(new ServerRequest('GET', '/users/'));
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testIgnoreModeMatchesBoth(): void
    {
        $collector = new RouteCollector();
        $collector->get('/users', fn ($req) => Response::success(['route' => '/users']));

        $dispatcher = new RouteDispatcher($collector->getData(), null, '', 'ignore');

        // Without trailing slash works
        $response = $dispatcher->handle(new ServerRequest('GET', '/users'));
        $this->assertSame(200, $response->getStatusCode());

        // With trailing slash also works
        $response = $dispatcher->handle(new ServerRequest('GET', '/users/'));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testIgnoreModePreservesRootPath(): void
    {
        $collector = new RouteCollector();
        $collector->get('/', fn ($req) => Response::success(['route' => '/']));

        $dispatcher = new RouteDispatcher($collector->getData(), null, '', 'ignore');

        // Root path should still work
        $response = $dispatcher->handle(new ServerRequest('GET', '/'));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testIgnoreModeWithDynamicRoutes(): void
    {
        $collector = new RouteCollector();
        $collector->get('/users/{id}', fn ($req, $id) => Response::success(['id' => $id]));

        $dispatcher = new RouteDispatcher($collector->getData(), null, '', 'ignore');

        // Without trailing slash
        $response = $dispatcher->handle(new ServerRequest('GET', '/users/42'));
        $this->assertSame(200, $response->getStatusCode());

        // With trailing slash
        $response = $dispatcher->handle(new ServerRequest('GET', '/users/42/'));
        $this->assertSame(200, $response->getStatusCode());
    }
}
