<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Feature;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
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
     * A value is encoded as a whole, slashes included (%2F) — as in 2.1.0. A value with
     * slashes ({path:any}) therefore leads to a path the router answers with 404; for
     * redirects by segments a route or handler of your own is the way (known limitation).
     */
    public function testValueWithSlashesIsEncodedAsAWhole(): void
    {
        $collector = new RouteCollector();
        $collector->redirect('/old/{path:any}', '/new/{path}', 301);

        $response = new RouteDispatcher($collector->getData())->handle(new ServerRequest('GET', '/old/docs/my%20intro'));

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/new/docs%2Fmy%20intro', $response->getHeaderLine('Location'));
    }

    /**
     * @return array<string, array{0: string, 1: string|null, 2: string}>
     */
    public static function targetsThatFixSchemeAndHost(): array
    {
        // Target, its own scheme (null: that of the page), its own host
        return [
            'host of its own' => ['https://trusted.example/{path}', 'https', 'trusted.example'],
            'host of its own, query' => ['https://trusted.example?next={path}', 'https', 'trusted.example'],
            'host of its own, scheme of the page' => ['//cdn.example/assets/{path}', null, 'cdn.example'],
            'path' => ['/new/{path}', null, 'app.example'],
            'a slash and nothing else' => ['/{path}', null, 'app.example'],
            'nothing in front' => ['{path}', null, 'app.example'],
            'relative path' => ['docs/{path}', null, 'app.example'],
            'query' => ['?next={path}', null, 'app.example'],
            'fragment' => ['#{path}', null, 'app.example'],
            // A browser drops tabs and leading blanks
            'tab in front' => ["/\t{path}", null, 'app.example'],
            'blank in front (trimmed by the response)' => [' /{path}', null, 'app.example'],
        ];
    }

    /**
     * Scheme and host are those the target writes, or those of the page, whatever the
     * value — checked as a browser reads the address (WHATWG URL) from a page of either
     * scheme, for values without a slash, with one inside, and with two in front.
     * (Targets that leave scheme or host to a value are refused when the route is
     * registered, see RouteDefinitionTest.)
     */
    #[DataProvider('targetsThatFixSchemeAndHost')]
    public function testValueNeverChangesSchemeOrHost(string $target, ?string $scheme, string $host): void
    {
        $collector = new RouteCollector();
        $collector->redirect('/go/{path:any}', $target);
        $dispatcher = new RouteDispatcher($collector->getData());

        foreach (['/go/evil.example', '/go/evil.example/x', '/go///evil.example', '/go//evil.example', '/go/https:evil.example'] as $path) {
            $location = $dispatcher->handle(new ServerRequest('GET', $path))->getHeaderLine('Location');

            $this->assertStringNotContainsString('evil.example/', $location, 'a slash of the value is %2F');
            $this->assertSchemeAndHost($location, $path, $scheme, $host);
        }
    }

    /**
     * Nor does a value make a '.' or '..' segment together with the target: '/go{path:any}'
     * takes './b' from '/go./b', and '/a/.' or '/a/%2e' in front would turn it into '..'
     */
    public function testValueMakesNoDotSegmentTogetherWithTheTarget(): void
    {
        $collector = new RouteCollector();
        $collector->redirect('/go{path:any}', '/a/.{path}');
        $collector->redirect('/encoded{path:any}', '/a/%2e{path}');
        $dispatcher = new RouteDispatcher($collector->getData());

        foreach (['/go./b' => '/a/..%2Fb', '/encoded./b' => '/a/%2e.%2Fb'] as $path => $location) {
            $this->assertSame($location, $dispatcher->handle(new ServerRequest('GET', $path))->getHeaderLine('Location'));

            $resolved = \Uri\WhatWg\Url::parse($location, \Uri\WhatWg\Url::parse('https://app.example' . $path));
            $this->assertNotNull($resolved);
            // Still below /a/: no segment was resolved away
            $this->assertStringStartsWith('/a/', $resolved->getPath());
        }
    }

    /**
     * Scheme and host of the address as a browser resolves it from a page at $path, for a
     * page of either scheme. The address has to be one a browser can read: one it cannot
     * read would make this check say nothing.
     *
     * @param string|null $scheme The target's own scheme; null: that of the page
     */
    private function assertSchemeAndHost(string $location, string $path, ?string $scheme, string $host): void
    {
        foreach (['http', 'https'] as $page) {
            $base = \Uri\WhatWg\Url::parse($page . '://app.example' . $path);
            $this->assertNotNull($base);
            $resolved = \Uri\WhatWg\Url::parse($location, $base);

            $this->assertNotNull($resolved, "{$location} from {$page} is no address a browser can read");
            $this->assertSame($scheme ?? $page, $resolved->getScheme(), "scheme of {$location} from {$page}");
            $this->assertSame($host, $resolved->getAsciiHost(), "host of {$location} from {$page}");
        }
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
