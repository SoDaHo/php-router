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
     * @return array<string, array{0: string, 1: string, 2: string, 3?: string|null, 4?: string|null}>
     */
    public static function targetsWhoseSchemeAndHostAValueMustNotChange(): array
    {
        return [
            // The counterexamples of the reviews of the segment-wise encoding: each named
            // another host with it — encoded as a whole, none does
            'scheme without host, value with two slashes' => ['https:{path}', '/go///evil.example', 'https:%2F%2Fevil.example', 'https'],
            'scheme without host, value with one slash' => ['https:{path}', '/go//evil.example', 'https:%2Fevil.example', 'https'],
            'scheme without host, value with a slash inside' => ['https:{path}', '/go/a/b', 'https:a%2Fb', 'https'],
            'scheme and one slash' => ['http:/{path}', '/go//evil.example', 'http:/%2Fevil.example', 'http'],
            'scheme and an empty host' => ['https:///{path}', '/go/evil.example/x', 'https:///evil.example%2Fx', 'https'],
            'empty host without scheme' => ['///{path}', '/go/evil.example/x', '///evil.example%2Fx'],
            'host that the value would continue' => ['https://app.example{path}', '/go/.evil.example/x', 'https://app.example.evil.example%2Fx', 'https', 'app.example'],
            'host of its own' => ['https://app.example/{path}', '/go//evil.example', 'https://app.example/%2Fevil.example', 'https', 'app.example'],
            'host of its own, scheme of the page' => ['//cdn.example/assets/{path}', '/go//evil.example', '//cdn.example/assets/%2Fevil.example', null, 'cdn.example'],
            'a slash and nothing else' => ['/{path}', '/go//evil.example/x', '/%2Fevil.example%2Fx'],
            'a slash, value with a slash inside' => ['/{path}', '/go/a/b', '/a%2Fb'],
            'nothing in front' => ['{path}', '/go///evil.example', '%2F%2Fevil.example'],
            'relative path' => ['docs/{path}', '/go//evil.example', 'docs/%2Fevil.example'],
            'query' => ['?next={path}', '/go//evil.example', '?next=%2Fevil.example'],
            'scheme made of the value' => ['{a}:{path}', '/two/https///evil.example', 'https:%2F%2Fevil.example'],
            // A browser drops tabs and leading blanks and reads a backslash as a slash
            'tab in front' => ["/\t{path}", '/go//evil.example', "/\t%2Fevil.example"],
            'blank in front (trimmed by the response)' => [' /{path}', '/go//evil.example', '/%2Fevil.example'],
            'backslash in front' => ['/\\{path}', '/go//evil.example', '/\\%2Fevil.example'],
        ];
    }

    /**
     * Checked as a browser reads the address (WHATWG URL), from a page of either scheme:
     * scheme and host are those the target names, or those of the page — or the address
     * leads nowhere
     */
    #[DataProvider('targetsWhoseSchemeAndHostAValueMustNotChange')]
    public function testValueNeverChangesSchemeOrHost(string $target, string $path, string $location, ?string $scheme = null, ?string $host = null): void
    {
        $collector = new RouteCollector();
        str_starts_with($target, '{a}')
            ? $collector->redirect('/two/{a}/{path:any}', $target)
            : $collector->redirect('/go/{path:any}', $target);

        $response = new RouteDispatcher($collector->getData())->handle(new ServerRequest('GET', $path));

        $this->assertSame($location, $response->getHeaderLine('Location'));
        $this->assertSchemeAndHost($response->getHeaderLine('Location'), $path, $scheme, $host ?? 'app.example');
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
     * page of either scheme. An address it cannot read leads nowhere — not to another host
     * either.
     *
     * @param string|null $scheme The target's own scheme; null: that of the page
     */
    private function assertSchemeAndHost(string $location, string $path, ?string $scheme, string $host): void
    {
        foreach (['http', 'https'] as $page) {
            $base = \Uri\WhatWg\Url::parse($page . '://app.example' . $path);
            $this->assertNotNull($base);
            $resolved = \Uri\WhatWg\Url::parse($location, $base);

            if ($resolved !== null) {
                $this->assertSame($scheme ?? $page, $resolved->getScheme(), "scheme of {$location} from {$page}");
                $this->assertSame($host, $resolved->getAsciiHost(), "host of {$location} from {$page}");
            }
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
