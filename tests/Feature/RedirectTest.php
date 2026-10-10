<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Feature;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\RouteCollector;
use Sodaho\Router\RouteDispatcher;
use Sodaho\Router\Router;

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

        foreach (['/go/evil.example', '/go/evil.example/x', '/go/https:evil.example', '/go/'] as $path) {
            $location = $dispatcher->handle(new ServerRequest('GET', $path))->getHeaderLine('Location');

            $this->assertStringNotContainsString('evil.example/', $location, 'a slash of the value is %2F');
            $this->assertSchemeAndHost($location, $path, $scheme, $host);
        }

        // A value with an empty segment does not even reach the route: {path:any} takes none
        foreach (['/go///evil.example', '/go//evil.example'] as $path) {
            $response = $dispatcher->handle(new ServerRequest('GET', $path));

            $this->assertSame(404, $response->getStatusCode(), $path);
            $this->assertFalse($response->hasHeader('Location'), $path);
        }
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string|null, 4?: string|null, 5?: string}>
     */
    public static function rendersWithAnEmptyValue(): array
    {
        // Source, target, request path, Location (null: refused), its own scheme, its own host
        return [
            // A placeholder that renders as '' in front of a slash: '//evil.example' — another host
            'bool false in front of a slash' => ['/go/{a:bool}/{b}', '/{a}/{b}', '/go/false/evil.example', null],
            'bool true in front of a slash' => ['/go/{a:bool}/{b}', '/{a}/{b}', '/go/true/evil.example', '/1/evil.example'],
            'empty value in front of a slash' => ['/go/{x:any}/{b}', '/{x}/{b}', '/go//evil.example', null],
            'two empty values' => ['/go/{x:any}/{y:any}/{b}', '/{x}/{y}/{b}', '/go///evil.example', null],
            'empty value in front of a backslash' => ['/go/{x:any}/{b}', '/{x}\\{b}', '/go//evil.example', null],
            'empty value in front of a tab and a slash' => ['/go/{x:any}/{b}', "/{x}\t/{b}", '/go//evil.example', null],
            'empty value, blank in front' => ['/go/{x:any}/{b}', ' /{x}/{b}', '/go//evil.example', null],
            // Where '' cannot change the host, the redirect goes out
            'relative target, empty first value' => ['/go/{x:any}/{b}', '{x}/{b}', '/go//evil.example', '/evil.example'],
            'empty value that is not in front' => ['/go/{x:any}/{b}', '/new/{x}/{b}', '/go//evil.example', '/new//evil.example'],
            'empty value behind a host of its own' => ['/go/{x:any}/{b}', 'https://trusted.example/{x}/{b}', '/go//evil.example', 'https://trusted.example//evil.example', 'https', 'trusted.example'],
            'empty value in the query' => ['/go/{x:any}/{b}', '?next=/{x}/{b}', '/go//evil.example', '?next=//evil.example'],
        ];
    }

    /**
     * What the values render as is checked once more when the redirect goes out: an
     * address that would begin with '//' (as a browser reads it) where the target does
     * not, or that would carry a scheme the target does not, is not sent — RouterException,
     * through Router::handle() a 500. Everything else keeps scheme and host.
     */
    #[DataProvider('rendersWithAnEmptyValue')]
    public function testRenderingThatWouldChangeTheHostIsNotSent(string $source, string $target, string $path, ?string $location, ?string $scheme = null, string $host = 'app.example'): void
    {
        $collector = new RouteCollector();
        $collector->redirect($source, $target);
        $dispatcher = new RouteDispatcher($collector->getData());

        try {
            $actual = $dispatcher->handle(new ServerRequest('GET', $path))->getHeaderLine('Location');
        } catch (RouterException $e) {
            $this->assertNull($location, 'refused: ' . $e->getMessage());
            $this->assertSame('Redirect not sent: its placeholders would change scheme or host of the target (an empty value in front of a slash)', $e->getMessage());

            return;
        }

        $this->assertSame($location, $actual);
        $this->assertSchemeAndHost($actual, $path, $scheme, $host);
    }

    public function testRenderingThatWouldChangeTheHostIsA500ThroughTheRouter(): void
    {
        $routes = sys_get_temp_dir() . '/router_redirect_render_' . uniqid() . '.php';
        file_put_contents($routes, "<?php\nreturn function (\$r) { \$r->redirect('/go/{a:bool}/{b}', '/{a}/{b}'); };\n");

        try {
            $router = Router::create()->loadRoutes($routes);
            $reported = [];
            $router->on('error', function (array $data) use (&$reported): void {
                $reported[] = $data['exception'];
            });

            $response = $router->handle(new ServerRequest('GET', '/go/false/evil.example'));

            $this->assertSame(500, $response->getStatusCode());
            $this->assertFalse($response->hasHeader('Location'));
            $this->assertCount(1, $reported);
            $this->assertInstanceOf(RouterException::class, $reported[0]);
            $this->assertSame('//evil.example', $reported[0]->getDebugMessage());

            $this->assertSame('/1/evil.example', $router->handle(new ServerRequest('GET', '/go/true/evil.example'))->getHeaderLine('Location'));
        } finally {
            unlink($routes);
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

    /**
     * A value alone that would be — or make — a '.' or '..' segment is not sent: '/go{path:any}'
     * takes '.' from '/go.', and '/a/.' in front makes '/a/..' (a client asks for '/');
     * '/go/{x}-z' takes '..' from '/go/..-z' (no dot segment of the request path).
     * Through the router: 500 and the error hook.
     */
    public function testValueThatWouldMakeADotSegmentIsA500ThroughTheRouter(): void
    {
        $routes = sys_get_temp_dir() . '/router_redirect_dots_' . uniqid() . '.php';
        file_put_contents($routes, <<<'PHP'
            <?php
            return function ($r) {
                $r->redirect('/go{path:any}', '/a/.{path}');
                $r->redirect('/docs/{x}-z', '/docs/{x}/');
            };
            PHP);

        try {
            $router = Router::create()->loadRoutes($routes);
            $reported = [];
            $router->on('error', function (array $data) use (&$reported): void {
                $reported[] = $data['exception'];
            });

            foreach (['/go.' => '/a/..', '/docs/..-z' => '/docs/../', '/docs/.-z' => '/docs/./'] as $path => $rendered) {
                $response = $router->handle(new ServerRequest('GET', $path));

                $this->assertSame(500, $response->getStatusCode(), $path);
                $this->assertFalse($response->hasHeader('Location'), $path);
                $reason = array_pop($reported);
                $this->assertInstanceOf(RouterException::class, $reason);
                $this->assertSame('Redirect not sent: a value would make a "." or ".." path segment, which a client resolves before it asks', $reason->getMessage());
                $this->assertSame($rendered, $reason->getDebugMessage());
            }

            $this->assertSame('/docs/a/', $router->handle(new ServerRequest('GET', '/docs/a-z'))->getHeaderLine('Location'));
            $this->assertSame('/docs/.../', $router->handle(new ServerRequest('GET', '/docs/...-z'))->getHeaderLine('Location'));
        } finally {
            unlink($routes);
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
