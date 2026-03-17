<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Feature;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\Response;
use Sodaho\Router\RouteCollector;
use Sodaho\Router\RouteDispatcher;

class HooksTest extends TestCase
{
    private function createDispatcher(callable $routeSetup): RouteDispatcher
    {
        $collector = new RouteCollector();
        $routeSetup($collector);
        return new RouteDispatcher($collector->getData());
    }

    public function testDispatchHookIsTriggered(): void
    {
        $hookData = null;

        $dispatcher = $this->createDispatcher(function (RouteCollector $r) {
            $r->get('/users/{id}', fn ($req, $id) => Response::success(['id' => $id]));
        });

        $dispatcher->on('dispatch', function (array $data) use (&$hookData) {
            $hookData = $data;
        });

        $request = new ServerRequest('GET', '/users/42');
        $dispatcher->handle($request);

        $this->assertNotNull($hookData);
        $this->assertSame('GET', $hookData['method']);
        $this->assertSame('/users/42', $hookData['path']);
        $this->assertSame('/users/{id}', $hookData['route']);
        $this->assertArrayHasKey('id', $hookData['params']);
        $this->assertArrayHasKey('duration', $hookData);
    }

    public function testNotFoundHookIsTriggered(): void
    {
        $hookData = null;

        $dispatcher = $this->createDispatcher(function (RouteCollector $r) {
            $r->get('/users', fn ($req) => Response::success([]));
        });

        $dispatcher->on('notFound', function (array $data) use (&$hookData) {
            $hookData = $data;
        });

        $request = new ServerRequest('GET', '/nonexistent');
        $response = $dispatcher->handle($request);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertNotNull($hookData);
        $this->assertSame('GET', $hookData['method']);
        $this->assertSame('/nonexistent', $hookData['path']);
    }

    public function testMethodNotAllowedHookIsTriggered(): void
    {
        $hookData = null;

        $dispatcher = $this->createDispatcher(function (RouteCollector $r) {
            $r->get('/users', fn ($req) => Response::success([]));
        });

        $dispatcher->on('methodNotAllowed', function (array $data) use (&$hookData) {
            $hookData = $data;
        });

        $request = new ServerRequest('POST', '/users');
        $response = $dispatcher->handle($request);

        $this->assertSame(405, $response->getStatusCode());
        $this->assertNotNull($hookData);
        $this->assertSame('POST', $hookData['method']);
        $this->assertSame('/users', $hookData['path']);
        $this->assertContains('GET', $hookData['allowed_methods']);
    }

    public function testMultipleHooksCanBeRegistered(): void
    {
        $calls = [];

        $dispatcher = $this->createDispatcher(function (RouteCollector $r) {
            $r->get('/test', fn ($req) => Response::success([]));
        });

        $dispatcher->on('dispatch', function () use (&$calls) {
            $calls[] = 'hook1';
        });

        $dispatcher->on('dispatch', function () use (&$calls) {
            $calls[] = 'hook2';
        });

        $request = new ServerRequest('GET', '/test');
        $dispatcher->handle($request);

        $this->assertSame(['hook1', 'hook2'], $calls);
    }

    public function testHookExceptionDoesNotAffectResponse(): void
    {
        $dispatcher = $this->createDispatcher(function (RouteCollector $r) {
            $r->get('/test', fn ($req) => Response::success(['ok' => true]));
        });

        $dispatcher->on('dispatch', function () {
            throw new \RuntimeException('Hook crashed!');
        });

        $request = new ServerRequest('GET', '/test');
        $response = $dispatcher->handle($request);

        // Response should still be successful despite hook exception
        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertTrue($body['success']);
    }

    public function testHooksCanChainWithFluentApi(): void
    {
        $dispatcher = $this->createDispatcher(function (RouteCollector $r) {
            $r->get('/test', fn ($req) => Response::success([]));
        });

        $result = $dispatcher
            ->on('dispatch', fn () => null)
            ->on('notFound', fn () => null)
            ->on('methodNotAllowed', fn () => null);

        $this->assertSame($dispatcher, $result);
    }
}
