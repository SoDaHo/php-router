<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Feature;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\RouteCollector;
use Sodaho\Router\RouteDispatcher;

class RedirectTest extends TestCase
{
    public function testSimpleRedirect(): void
    {
        $collector = new RouteCollector();
        $collector->redirect('/old', '/new');

        $dispatcher = new RouteDispatcher($collector->getData());

        $response = $dispatcher->handle(new ServerRequest('GET', '/old'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/new', $response->getHeaderLine('Location'));
    }

    public function testPermanentRedirect(): void
    {
        $collector = new RouteCollector();
        $collector->redirect('/old', '/new', 301);

        $dispatcher = new RouteDispatcher($collector->getData());

        $response = $dispatcher->handle(new ServerRequest('GET', '/old'));

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/new', $response->getHeaderLine('Location'));
    }

    public function testRedirectWithParameters(): void
    {
        $collector = new RouteCollector();
        $collector->redirect('/users/{id}/profile', '/profile/{id}');

        $dispatcher = new RouteDispatcher($collector->getData());

        $response = $dispatcher->handle(new ServerRequest('GET', '/users/42/profile'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/profile/42', $response->getHeaderLine('Location'));
    }

    /**
     * A value with slashes ({path:any}) goes out segment by segment, as url() writes it:
     * encoded as a whole it was '/new/docs%2Fintro' — and the router answers %2F with 404
     */
    public function testTargetOfAValueWithSlashesLeadsToTheRouteOfThatPath(): void
    {
        $collector = new RouteCollector();
        $collector->redirect('/old/{path:any}', '/new/{path}', 301);
        $collector->get('/new/{path:any}', fn ($req, string $path) => \Sodaho\Router\Response::text('new: ' . $path));

        $dispatcher = new RouteDispatcher($collector->getData());

        $response = $dispatcher->handle(new ServerRequest('GET', '/old/docs/my%20intro'));
        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/new/docs/my%20intro', $response->getHeaderLine('Location'));

        $followed = $dispatcher->handle(new ServerRequest('GET', $response->getHeaderLine('Location')));
        $this->assertSame(200, $followed->getStatusCode());
        $this->assertSame('new: docs/my intro', (string) $followed->getBody());
    }

    /**
     * Segment by segment, a value that begins with a slash would turn '/{path}' into an
     * address of another host ('//evil.example/x'): there its slashes are encoded as well
     */
    public function testValueWithSlashesNeverMakesTheTargetNameAnotherHost(): void
    {
        $collector = new RouteCollector();
        $collector->redirect('/go/{path:any}', '/{path}');
        $collector->redirect('/bare/{path:any}', '{path}');

        $dispatcher = new RouteDispatcher($collector->getData());

        $this->assertSame('/%2Fevil.example%2Fx', $dispatcher->handle(new ServerRequest('GET', '/go//evil.example/x'))->getHeaderLine('Location'));
        $this->assertSame('%2F%2Fevil.example', $dispatcher->handle(new ServerRequest('GET', '/bare///evil.example'))->getHeaderLine('Location'));
        // Below a path of its own, a leading slash of the value is harmless and stays a slash
        $this->assertSame('/a/b', $dispatcher->handle(new ServerRequest('GET', '/go/a/b'))->getHeaderLine('Location'));
    }

    public function testRedirectWorksWithHead(): void
    {
        $collector = new RouteCollector();
        $collector->redirect('/old', '/new');

        $dispatcher = new RouteDispatcher($collector->getData());

        $response = $dispatcher->handle(new ServerRequest('HEAD', '/old'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/new', $response->getHeaderLine('Location'));
    }

    public function testRedirectRouteHasAnInspectableHandler(): void
    {
        $collector = new RouteCollector();
        $collector->redirect('/old', '/new');

        $routes = $collector->getRoutes();
        $handler = $routes[0]->handler;

        $this->assertInstanceOf(\Sodaho\Router\Middleware\RedirectHandler::class, $handler);
        $this->assertSame('/new', $handler->getTarget());
        $this->assertSame(302, $handler->getStatus());
    }
}
