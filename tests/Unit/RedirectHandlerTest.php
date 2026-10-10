<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\Exception\RouterException;
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
        $request = new ServerRequest('GET', '/users/42/profile')
            ->withAttribute('_route_params', ['id' => 42]);

        $response = $handler->handle($request);

        $this->assertSame('/profile/42', $response->getHeaderLine('Location'));
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string}>
     */
    public static function handlersThatCouldNeverRedirect(): array
    {
        $schemeOrHost = 'Redirect target has a placeholder where scheme or host belong: write them into the target, the host closed by "/", "?" or "#"';

        return [
            // Up to 2.1.1 only RouteCollector::redirect() refused these; built by hand they went out
            'placeholder that could be a scheme' => ['{a}:{b}', 302, $schemeOrHost],
            'placeholder in scheme position' => ['https:{path}', 302, $schemeOrHost],
            'placeholder in host position' => ['//{host}/x', 302, $schemeOrHost],
            'placeholder behind an empty host' => ['https:///{path}', 302, $schemeOrHost],
            'line break' => ["/new\r\nX-Evil: 1", 302, 'Redirect target must not contain a control character'],
            'no redirect status' => ['/new', 200, 'Redirect status must be a 3xx status'],
            'placeholder with a type' => ['/new/{id:int}', 302, 'Redirect target has a placeholder that is not of the form {name}'],
        ];
    }

    /**
     * Whoever builds the handler gets the rules RouteCollector::redirect() applies: a target
     * that could never be sent, or that leaves scheme or host to a value, is refused where
     * it is written — a handler built by hand included.
     */
    #[DataProvider('handlersThatCouldNeverRedirect')]
    public function testHandlerThatCouldNeverRedirectIsRefusedWhenItIsBuilt(string $target, int $status, string $message): void
    {
        $this->expectException(RouterException::class);
        $this->expectExceptionMessage($message);

        new RedirectHandler($target, $status);
    }

    /**
     * A scheme is read as RFC 3986 has it — it begins with a letter: '1abc:y' is a path, for
     * the rule in the constructor and for the check where the address goes out. Read with a
     * digit in front, '1{x}' would turn into a scheme the target does not have (a 500), and
     * '1:relative/{x}' would be refused.
     */
    public function testSchemeBeginsWithALetterWhenItIsWrittenAndWhenItGoesOut(): void
    {
        $handler = new RedirectHandler('1{x}:y');
        $request = new ServerRequest('GET', '/x')->withAttribute('_route_params', ['x' => 'abc']);

        $this->assertSame('1abc:y', $handler->handle($request)->getHeaderLine('Location'));

        $relative = new RedirectHandler('1:relative/{x}');
        $this->assertSame('1:relative/abc', $relative->handle($request)->getHeaderLine('Location'));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string|null}>
     */
    public static function valuesAroundDotSegments(): array
    {
        // Target, value, Location (null: not sent)
        return [
            // A client resolves these before it asks: '/docs/../' is '/'
            'value that is the parent segment' => ['/docs/{x}/', '..', null],
            'value that is the current segment' => ['/docs/{x}/edit', '.', null],
            'value that ends the path as a parent segment' => ['/docs/{x}', '..', null],
            'dot that makes one with an encoded dot of the target' => ['/a/%2e{x}', '.', null],
            'dot that makes one with a dot of the target' => ['/a/.{x}', '.', null],
            'parent segment behind a host of its own' => ['https://app.example/{x}/x', '..', null],
            // Dots that make no segment of their own go out
            'three dots' => ['/docs/{x}', '...', '/docs/...'],
            'dots in front of a suffix' => ['/dl/{x}.json', '..', '/dl/...json'],
            'dots and a slash' => ['/docs/{x}', '../x', '/docs/..%2Fx'],
            'dots in the query' => ['/search?q={x}', '..', '/search?q=..'],
            'dots in the fragment' => ['/page#{x}', '..', '/page#..'],
            // A dot segment the target writes itself stays its own business
            'parent segment of the target' => ['../{x}', 'a', '../a'],
        ];
    }

    #[DataProvider('valuesAroundDotSegments')]
    public function testValueThatWouldMakeADotSegmentIsNotSent(string $target, string $value, ?string $location): void
    {
        $handler = new RedirectHandler($target);
        $request = new ServerRequest('GET', '/x')->withAttribute('_route_params', ['x' => $value]);

        if ($location !== null) {
            $this->assertSame($location, $handler->handle($request)->getHeaderLine('Location'));

            return;
        }

        try {
            $response = $handler->handle($request);
            $this->fail('Sent with Location: ' . $response->getHeaderLine('Location'));
        } catch (RouterException $e) {
            $this->assertSame('Redirect not sent: a value would make a "." or ".." path segment, which a client resolves before it asks', $e->getMessage());
        }
    }

    /**
     * The check compares the target as written with the address as rendered — not with the
     * host the request came to (that would trust its Host header). '//app.example' names
     * another host than the target, even where the request came to app.example: not sent.
     */
    public function testEmptyValueInFrontOfASlashIsNotSentEvenWhenItWouldNameTheOwnHost(): void
    {
        $handler = new RedirectHandler('/{a}/{b}');
        $request = new ServerRequest('GET', 'http://app.example/go//app.example')
            ->withAttribute('_route_params', ['a' => '', 'b' => 'app.example']);

        try {
            $response = $handler->handle($request);
            $this->fail('Sent with Location: ' . $response->getHeaderLine('Location'));
        } catch (RouterException $e) {
            $this->assertSame('Redirect not sent: its placeholders would change scheme or host of the target (an empty value in front of a slash)', $e->getMessage());
            $this->assertSame('//app.example', $e->getDebugMessage());
        }
    }

    public function testSchemeOfTheTargetItselfIsSent(): void
    {
        $handler = new RedirectHandler('HTTPS://app.example/{b}');
        $request = new ServerRequest('GET', '/x')->withAttribute('_route_params', ['b' => 'x']);

        $this->assertSame('HTTPS://app.example/x', $handler->handle($request)->getHeaderLine('Location'));
    }

    public function testRedirectWithMultipleParameters(): void
    {
        $handler = new RedirectHandler('/posts/{year}/{slug}');

        $request = new ServerRequest('GET', '/old')
            ->withAttribute('_route_params', ['year' => 2025, 'slug' => 'hello']);

        $response = $handler->handle($request);

        $this->assertSame('/posts/2025/hello', $response->getHeaderLine('Location'));
    }

    public function testRedirectIgnoresNonRouteAttributes(): void
    {
        $handler = new RedirectHandler('/test/{id}');

        // Only _route_params are replaced, not other attributes
        $request = new ServerRequest('GET', '/old')
            ->withAttribute('_route_params', ['id' => 42])
            ->withAttribute('user_injected', 'should-be-ignored');

        $response = $handler->handle($request);

        $this->assertSame('/test/42', $response->getHeaderLine('Location'));
    }

    public function testRedirectEncodesParameters(): void
    {
        $handler = new RedirectHandler('/search/{query}');

        // Special characters should be URL-encoded
        $request = new ServerRequest('GET', '/old')
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
}
