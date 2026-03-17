<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\Middleware\RedirectHandler;

class RedirectHandlerTest extends TestCase
{
    public function testSimpleRedirect(): void
    {
        $handler = new RedirectHandler('/new-location');

        $response = $handler->handle(new ServerRequest('GET', '/old'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/new-location', $response->getHeaderLine('Location'));
    }

    public function testPermanentRedirect(): void
    {
        $handler = new RedirectHandler('/new-location', 301);

        $response = $handler->handle(new ServerRequest('GET', '/old'));

        $this->assertSame(301, $response->getStatusCode());
    }

    public function testRedirectWithParameterReplacement(): void
    {
        $handler = new RedirectHandler('/profile/{id}');

        // Parameters must be in _route_params (as set by RouteDispatcher)
        $request = (new ServerRequest('GET', '/users/42/profile'))
            ->withAttribute('_route_params', ['id' => 42]);

        $response = $handler->handle($request);

        $this->assertSame('/profile/42', $response->getHeaderLine('Location'));
    }

    public function testRedirectWithMultipleParameters(): void
    {
        $handler = new RedirectHandler('/posts/{year}/{slug}');

        $request = (new ServerRequest('GET', '/old'))
            ->withAttribute('_route_params', ['year' => 2025, 'slug' => 'hello']);

        $response = $handler->handle($request);

        $this->assertSame('/posts/2025/hello', $response->getHeaderLine('Location'));
    }

    public function testRedirectIgnoresNonRouteAttributes(): void
    {
        $handler = new RedirectHandler('/test/{id}');

        // Only _route_params are replaced, not other attributes
        $request = (new ServerRequest('GET', '/old'))
            ->withAttribute('_route_params', ['id' => 42])
            ->withAttribute('user_injected', 'should-be-ignored');

        $response = $handler->handle($request);

        $this->assertSame('/test/42', $response->getHeaderLine('Location'));
    }

    public function testRedirectEncodesParameters(): void
    {
        $handler = new RedirectHandler('/search/{query}');

        // Special characters should be URL-encoded
        $request = (new ServerRequest('GET', '/old'))
            ->withAttribute('_route_params', ['query' => 'hello world&foo=bar']);

        $response = $handler->handle($request);

        // rawurlencode: space → %20, & → %26, = → %3D
        $this->assertSame('/search/hello%20world%26foo%3Dbar', $response->getHeaderLine('Location'));
    }

    public function testGetTarget(): void
    {
        $handler = new RedirectHandler('/target-url');

        $this->assertSame('/target-url', $handler->getTarget());
    }

    public function testGetStatus(): void
    {
        $handler301 = new RedirectHandler('/url', 301);
        $handler302 = new RedirectHandler('/url', 302);
        $handler307 = new RedirectHandler('/url', 307);

        $this->assertSame(301, $handler301->getStatus());
        $this->assertSame(302, $handler302->getStatus());
        $this->assertSame(307, $handler307->getStatus());
    }

    public function testIsSerializable(): void
    {
        $handler = new RedirectHandler('/new', 301);

        $serialized = serialize($handler);
        $unserialized = unserialize($serialized);

        $this->assertSame('/new', $unserialized->getTarget());
        $this->assertSame(301, $unserialized->getStatus());
    }

    public function testSetState(): void
    {
        // Test __set_state for var_export() cache support
        $original = new RedirectHandler('/cached', 301);

        // Simulate what var_export does
        $exported = var_export($original, true);
        $restored = eval('return ' . $exported . ';');

        $this->assertInstanceOf(RedirectHandler::class, $restored);
        $this->assertSame('/cached', $restored->getTarget());
        $this->assertSame(301, $restored->getStatus());
    }
}
