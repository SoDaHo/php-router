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

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function targetsWhoseHostAValueMustNotName(): array
    {
        return [
            // 2.1.1 candidate: 'https://evil.example' — the scheme stands, a value adds the slashes
            'scheme without host, value with two slashes' => ['https:{path}', '/go///evil.example', 'https:%2F%2Fevil.example'],
            // A browser reads 'https:/evil.example' as https://evil.example from a page of another scheme
            'scheme without host, value with one slash' => ['https:{path}', '/go//evil.example', 'https:%2Fevil.example'],
            'scheme without host, value with a slash inside' => ['https:{path}', '/go/a/b', 'https:a%2Fb'],
            'scheme and one slash' => ['http:/{path}', '/go//evil.example', 'http:/%2Fevil.example'],
            'host that the value would continue' => ['https://app.example{path}', '/go/.evil.example/x', 'https://app.example.evil.example%2Fx'],
            'a slash and nothing else' => ['/{path}', '/go//evil.example/x', '/%2Fevil.example%2Fx'],
            'nothing in front' => ['{path}', '/go///evil.example', '%2F%2Fevil.example'],
            'scheme made of the value' => ['{a}:{path}', '/two/https///evil.example', 'https:%2F%2Fevil.example'],
            // A browser drops tabs and leading blanks and reads a backslash as a slash
            'tab in front' => ["/\t{path}", '/go//evil.example', "/\t%2Fevil.example"],
            'blank in front (trimmed by the response)' => [' /{path}', '/go//evil.example', '/%2Fevil.example'],
            'backslash in front' => ['/\\{path}', '/go//evil.example', '/\\%2Fevil.example'],
        ];
    }

    /**
     * Where a slash of a value could begin a host — or end one — the slashes are encoded
     * as in 2.1.0: segment by segment only where the target as written fixes scheme and
     * host (or has none of them). Checked as a browser reads the address (WHATWG URL),
     * from a page of either scheme.
     */
    #[DataProvider('targetsWhoseHostAValueMustNotName')]
    public function testValueWithSlashesNeverNamesAnotherHost(string $target, string $path, string $location): void
    {
        $collector = new RouteCollector();
        str_starts_with($target, '{a}')
            ? $collector->redirect('/two/{a}/{path:any}', $target)
            : $collector->redirect('/go/{path:any}', $target);

        $response = new RouteDispatcher($collector->getData())->handle(new ServerRequest('GET', $path));

        $this->assertSame($location, $response->getHeaderLine('Location'));
        $this->assertNoOtherHost($response->getHeaderLine('Location'), $path, ['app.example']);
    }

    /**
     * A target that names its host itself, followed by a slash: the host stays whatever
     * the value is, and the path is encoded segment by segment
     */
    public function testTargetWithAHostOfItsOwnKeepsItAndTakesThePathSegmentBySegment(): void
    {
        $collector = new RouteCollector();
        $collector->redirect('/go/{path:any}', 'https://app.example/{path}');
        $collector->redirect('/cdn/{path:any}', '//cdn.example/assets/{path}');
        $collector->redirect('/rel/{path:any}', 'docs/{path}');
        $collector->redirect('/q/{path:any}', '?next={path}');
        $dispatcher = new RouteDispatcher($collector->getData());

        $cases = [
            '/go/docs/my%20intro' => 'https://app.example/docs/my%20intro',
            '/go//evil.example' => 'https://app.example//evil.example',
            '/go///evil.example/x' => 'https://app.example///evil.example/x',
            '/cdn//evil.example' => '//cdn.example/assets//evil.example',
            '/rel/a/b' => 'docs/a/b',
            '/q//evil.example' => '?next=/evil.example',
        ];

        foreach ($cases as $path => $location) {
            $actual = $dispatcher->handle(new ServerRequest('GET', $path))->getHeaderLine('Location');
            $this->assertSame($location, $actual, $path);
            $this->assertNoOtherHost($actual, $path, ['app.example', 'cdn.example']);
        }
    }

    public function testValueMakesNoDotSegmentTogetherWithTheTarget(): void
    {
        // '/go{path:any}' takes './b' from '/go./b' (no dot segment in the request); with
        // '/a/.' in front it would be '/a/../b', which a client resolves to '/b'
        $collector = new RouteCollector();
        $collector->redirect('/go{path:any}', '/a/.{path}');
        $collector->redirect('/q{path:any}', '?next=/a/.{path}');
        $dispatcher = new RouteDispatcher($collector->getData());

        $this->assertSame('/a/..%2Fb', $dispatcher->handle(new ServerRequest('GET', '/go./b'))->getHeaderLine('Location'));
        // In the query a dot segment is none: it stays segment by segment
        $this->assertSame('?next=/a/../b', $dispatcher->handle(new ServerRequest('GET', '/q./b'))->getHeaderLine('Location'));
    }

    /**
     * @param list<string> $allowed
     */
    private function assertNoOtherHost(string $location, string $path, array $allowed): void
    {
        foreach (['http', 'https'] as $scheme) {
            $base = \Uri\WhatWg\Url::parse($scheme . '://app.example' . $path);
            $this->assertNotNull($base);
            $resolved = \Uri\WhatWg\Url::parse($location, $base);

            // An address a browser cannot read leads nowhere — not to another host either
            if ($resolved !== null) {
                $this->assertContains($resolved->getAsciiHost(), $allowed, "{$location} from {$scheme}");
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
