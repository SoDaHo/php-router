<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Feature;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\RouteMatch;
use Sodaho\Router\Router;

/**
 * A separator that is hidden in the path — %2F, %5C, a backslash — has no route: decoded
 * it would be two segments here and one for a proxy or the access rules of the web server
 * in front. Nor has a control character or a '.'/'..' segment. And url() writes no
 * address that the router refuses itself.
 */
class EncodedSeparatorTest extends TestCase
{
    private string $routesFile;

    protected function setUp(): void
    {
        $this->routesFile = sys_get_temp_dir() . '/router_separator_' . uniqid() . '.php';
        file_put_contents(
            $this->routesFile,
            <<<'PHP'
                <?php
                use Sodaho\Router\Response;

                return function (Sodaho\Router\RouteCollector $r) {
                    $r->addPattern('pair', '\d+/\d+');
                    $r->addPattern('side', 'left/in|right/out');
                    $r->get('/files/{path:any}', fn ($req, string $path) => Response::text('files: ' . $path))->name('files');
                    $r->get('/tags/{tag}', fn ($req, string $tag) => Response::text('tag: ' . $tag))->name('tag');
                    $r->get('/pairs/{pair:pair}', fn ($req, string $pair) => Response::text('pair: ' . $pair))->name('pair');
                    $r->get('/sides/{side:side}', fn ($req, string $side) => Response::text('side: ' . $side))->name('side');
                    $r->get('/dl/{name}.json', fn ($req, string $name) => Response::text('dl: ' . $name))->name('dl');
                    $r->get('/ab/{a}-{b}', fn ($req, string $a, string $b) => Response::text('ab: ' . $a . ' ' . $b))->name('ab');
                    $r->get('/pre/{p:any}.', fn ($req, string $p) => Response::text('pre: ' . $p))->name('pre');
                    $r->get('/name{suffix}', fn ($req, string $suffix) => Response::text('suffix: ' . $suffix))->name('suffix');
                    $r->get('/end/{a}.', fn ($req, string $a) => Response::text('end: ' . $a))->name('end');
                    $r->get('/docs/{id:int}', fn ($req, int $id) => Response::text('doc: ' . $id))->name('doc');
                    $r->get('/a/b', fn () => Response::text('static'))->name('static');
                    $r->post('/tags/{tag}', fn ($req, string $tag) => Response::text('posted'));
                };
                PHP
        );
    }

    protected function tearDown(): void
    {
        unlink($this->routesFile);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function router(array $config = []): Router
    {
        /** @phpstan-ignore argument.type */
        return Router::create($config + ['debug' => false])->loadRoutes($this->routesFile);
    }

    private function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }

    // ==================== requests ====================

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function pathsWithAHiddenSeparator(): array
    {
        return [
            'encoded slash in a one-segment parameter' => ['GET', '/tags/a%2Fb'],
            'encoded slash, lower case' => ['GET', '/tags/a%2fb'],
            'encoded slash where the placeholder takes slashes' => ['GET', '/files/a%2Fb'],
            'encoded slash that would spell a static route' => ['GET', '/a%2Fb'],
            'encoded slash that would spell a dynamic route' => ['GET', '/tags%2Fnews'],
            'encoded backslash' => ['GET', '/files/a%5Cb'],
            'encoded backslash, lower case' => ['GET', '/files/a%5cb'],
            'encoded slash at the end' => ['GET', '/tags/a%2F'],
            'HEAD' => ['HEAD', '/tags/a%2Fb'],
            // Not a 405: the table is not asked at all
            'method the path has no route for' => ['DELETE', '/tags/a%2Fb'],
            'method the path has a route for' => ['POST', '/tags/a%2Fb'],
        ];
    }

    #[DataProvider('pathsWithAHiddenSeparator')]
    public function testPathWithAHiddenSeparatorHasNoRoute(string $method, string $path): void
    {
        $router = $this->router();
        $reported = [];
        $router->on('notFound', function (array $data) use (&$reported): void {
            $reported[] = $data;
        });

        $response = $router->handle(new ServerRequest($method, $path));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Allow'));
        if ($method !== 'HEAD') {
            $this->assertStringContainsString('"code":"NOT_FOUND"', $this->body($response));
        }

        // The hook gets the path as it came: decoded it would read like a route that exists
        $this->assertCount(1, $reported);
        $this->assertSame($path, $reported[0]['path']);
        $this->assertSame($method, $reported[0]['method']);
    }

    /**
     * @return array<string, array{0: string, 1?: string}>
     */
    public static function pathsWithAControlCharacter(): array
    {
        return [
            'line break at the end of a one-segment parameter' => ['/tags/a%0A'],
            'line break inside, lower case' => ['/tags/a%0ab'],
            'carriage return' => ['/tags/a%0Db'],
            'NUL' => ['/tags/a%00'],
            'tab' => ['/tags/a%09b'],
            'unit separator' => ['/tags/a%1F'],
            'DEL' => ['/tags/a%7Fb'],
            'DEL, lower case' => ['/tags/a%7fb'],
            'where the placeholder takes slashes' => ['/files/a/b%0A.txt'],
            'behind a static route' => ['/a/b%0A'],
            'HEAD' => ['/tags/a%0A', 'HEAD'],
            // Not a 405: the table is not asked at all
            'method the path has a route for' => ['/tags/a%0A', 'POST'],
            'method the path has no route for' => ['/tags/a%0A', 'DELETE'],
        ];
    }

    #[DataProvider('pathsWithAControlCharacter')]
    public function testPathWithAControlCharacterHasNoRoute(string $path, string $method = 'GET'): void
    {
        $router = $this->router();
        $reported = [];
        $router->on('notFound', function (array $data) use (&$reported): void {
            $reported[] = $data['path'];
        });

        $response = $router->handle(new ServerRequest($method, $path));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Allow'));
        if ($method !== 'HEAD') {
            $this->assertStringContainsString('"code":"NOT_FOUND"', $this->body($response));
        }
        $this->assertSame([$path], $reported);
    }

    /**
     * @return array<string, array{0: string, 1?: string}>
     */
    public static function pathsWithADotSegment(): array
    {
        return [
            'parent segment in a one-segment parameter' => ['/tags/..'],
            'current segment in a one-segment parameter' => ['/tags/.'],
            'parent segment where the placeholder takes slashes' => ['/files/../../etc/passwd'],
            'current segment where the placeholder takes slashes' => ['/files/a/./b'],
            'parent segment at the end' => ['/files/a/..'],
            'encoded dots' => ['/files/%2e%2e/%2E%2E/etc/passwd'],
            'one dot encoded' => ['/tags/.%2E'],
            'the other dot encoded' => ['/files/a/%2e./b'],
            'current segment encoded' => ['/tags/%2e'],
            'in front of a static route' => ['/a/./b'],
            'HEAD' => ['/tags/..', 'HEAD'],
            // Not a 405: the table is not asked at all
            'method the path has a route for' => ['/tags/..', 'POST'],
            'method the path has no route for' => ['/tags/..', 'DELETE'],
        ];
    }

    /**
     * A client resolves '.' and '..' before it asks — what arrives with one was written
     * by hand, and a placeholder would hand the handler a value that climbs out of its folder
     */
    #[DataProvider('pathsWithADotSegment')]
    public function testPathWithADotSegmentHasNoRoute(string $path, string $method = 'GET'): void
    {
        $router = $this->router();
        $reached = false;
        $reported = [];
        $router->on('dispatch', function () use (&$reached): void {
            $reached = true;
        });
        $router->on('notFound', function (array $data) use (&$reported): void {
            $reported[] = $data['path'];
        });

        $response = $router->handle(new ServerRequest($method, $path));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Allow'));
        $this->assertFalse($reached);
        if ($method !== 'HEAD') {
            $this->assertStringContainsString('"code":"NOT_FOUND"', $this->body($response));
        }
        // The hook gets the path as it came, like one with a hidden separator
        $this->assertSame([$path], $reported);
    }

    public function testDotsThatAreNoSegmentOfTheirOwnStayAllowed(): void
    {
        $router = $this->router();

        $this->assertSame('tag: ...', $this->body($router->handle(new ServerRequest('GET', '/tags/...'))));
        $this->assertSame('tag: ...', $this->body($router->handle(new ServerRequest('GET', '/tags/%2e%2e%2e'))));
        $this->assertSame('tag: ..a', $this->body($router->handle(new ServerRequest('GET', '/tags/..a'))));
        $this->assertSame('tag: a..', $this->body($router->handle(new ServerRequest('GET', '/tags/a..'))));
        $this->assertSame('files: a/.b/c.', $this->body($router->handle(new ServerRequest('GET', '/files/a/.b/c.'))));
        $this->assertSame('dl: ..', $this->body($router->handle(new ServerRequest('GET', '/dl/...json'))));
    }

    public function testWhatIsNoControlCharacterStaysAllowed(): void
    {
        $router = $this->router();

        // %20 is a space, %7E a tilde, %80 and above are bytes of UTF-8: none is a control character
        $this->assertSame('tag: a b~ä', $this->body($router->handle(new ServerRequest('GET', '/tags/a%20b%7E%C3%A4'))));
        // A literal '%0A' in a value is spelled %250A
        $this->assertSame('tag: a%0A', $this->body($router->handle(new ServerRequest('GET', '/tags/a%250A'))));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function controlCharactersAsTheyStand(): array
    {
        return [
            'line break' => ["\n"],
            'carriage return' => ["\r"],
            'tab' => ["\t"],
            'NUL' => ["\0"],
            'unit separator' => ["\x1F"],
            'DEL' => ["\x7F"],
        ];
    }

    /**
     * A URI class encodes them; a request object of another make may hand them over as
     * they are
     */
    #[DataProvider('controlCharactersAsTheyStand')]
    public function testControlCharacterThatArrivesUnencodedHasNoRouteEither(string $character): void
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn("/tags/a{$character}b");

        $response = $this->router()->handle(new ServerRequest('GET', '/tags/x')->withUri($uri));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testPathIsReadOnceForTheCheckAndTheLookup(): void
    {
        // Not what a PSR-7 URI does (it does not change) — but what was checked is what is
        // looked up, whatever a request object of another make answers the second time
        $calls = 0;
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturnCallback(static function () use (&$calls): string {
            return $calls++ === 0 ? '/tags/safe' : '/tags/a%0Ab';
        });

        $match = $this->router()->match(new ServerRequest('GET', '/tags/x')->withUri($uri));

        $this->assertSame(RouteMatch::FOUND, $match->status);
        $this->assertSame(['tag' => 'safe'], $match->params);
    }

    public function testBackslashThatArrivesUnencodedHasNoRouteEither(): void
    {
        // A URI class encodes it; a request object of another make may hand it over as it is
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/files/a\\b');

        $response = $this->router()->handle(new ServerRequest('GET', '/files/x')->withUri($uri));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testSeparatorCheckComesBeforeTheBasePath(): void
    {
        $router = $this->router(['basePath' => '/api']);
        $paths = [];
        $router->on('notFound', function (array $data) use (&$paths): void {
            $paths[] = $data['path'];
        });

        $this->assertSame(200, $router->handle(new ServerRequest('GET', '/api/tags/a'))->getStatusCode());
        $this->assertSame(404, $router->handle(new ServerRequest('GET', '/api/tags/a%2Fb'))->getStatusCode());
        // The base path itself spelled with an encoded slash
        $this->assertSame(404, $router->handle(new ServerRequest('GET', '/api%2Ftags/a'))->getStatusCode());
        $this->assertSame(404, $router->handle(new ServerRequest('GET', '/elsewhere/a%2Fb'))->getStatusCode());

        $this->assertSame(['/api/tags/a%2Fb', '/api%2Ftags/a', '/elsewhere/a%2Fb'], $paths);
    }

    public function testWhatStaysAllowed(): void
    {
        $router = $this->router();

        // A slash that is a slash
        $this->assertSame('files: a/b c.txt', $this->body($router->handle(new ServerRequest('GET', '/files/a/b%20c.txt'))));
        // An encoded percent sign: the value is the text '%2F', no separator
        $this->assertSame('tag: a%2Fb', $this->body($router->handle(new ServerRequest('GET', '/tags/a%252Fb'))));
        // The query string is not the path
        $this->assertSame('tag: a', $this->body($router->handle(new ServerRequest('GET', '/tags/a?next=%2Fhome%5C'))));
        // Other encoded characters
        $this->assertSame('tag: a b?#', $this->body($router->handle(new ServerRequest('GET', '/tags/a%20b%3F%23'))));
    }

    public function testMatchAndMiddlewareSeeAPathWithoutRoute(): void
    {
        $router = $this->router();
        $request = new ServerRequest('GET', '/a%2Fb');

        $match = $router->match($request);
        $this->assertSame(RouteMatch::NOT_FOUND, $match->status);
        $this->assertSame('/a%2Fb', $match->path);
        $this->assertNull($match->route);

        $seen = [];
        $router->middleware(new class ($seen) implements MiddlewareInterface {
            /** @param list<RouteMatch> $seen */
            public function __construct(private array &$seen)
            {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $match = $request->getAttribute(RouteMatch::class);
                assert($match instanceof RouteMatch);
                $this->seen[] = $match;

                return $handler->handle($request);
            }
        });

        $this->assertSame(404, $router->handle($request)->getStatusCode());
        $this->assertCount(1, $seen);
        $this->assertSame(RouteMatch::NOT_FOUND, $seen[0]->status);
        $this->assertSame('/a%2Fb', $seen[0]->path);
    }

    // ==================== url() ====================

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string, 3: string}>
     */
    public static function valuesThatHaveAnAddress(): array
    {
        return [
            'several segments, each encoded on its own' => ['files', ['path' => 'my dir/b c.txt'], '/files/my%20dir/b%20c.txt', 'files: my dir/b c.txt'],
            'one segment' => ['files', ['path' => 'a b'], '/files/a%20b', 'files: a b'],
            'empty segments' => ['files', ['path' => 'a//b'], '/files/a//b', 'files: a//b'],
            'own pattern that takes a slash' => ['pair', ['pair' => '1/2'], '/pairs/1/2', 'pair: 1/2'],
            'own pattern with alternatives' => ['side', ['side' => 'right/out'], '/sides/right/out', 'side: right/out'],
            'dots that are no segment of their own' => ['files', ['path' => '.env/a..b/...'], '/files/.env/a..b/...', 'files: .env/a..b/...'],
            'dots in a one-segment value' => ['tag', ['tag' => 'v1.2..'], '/tags/v1.2..', 'tag: v1.2..'],
            'percent sign' => ['tag', ['tag' => 'a%2Fb'], '/tags/a%252Fb', 'tag: a%2Fb'],
            'number' => ['doc', ['id' => 5], '/docs/5', 'doc: 5'],
            // Dots that become no segment of their own in the finished address
            'two dots in front of a suffix' => ['dl', ['name' => '..'], '/dl/...json', 'dl: ..'],
            'one dot in front of a suffix' => ['dl', ['name' => '.'], '/dl/..json', 'dl: .'],
            'a dot behind a literal in the same segment' => ['suffix', ['suffix' => '.'], '/name.', 'suffix: .'],
            'a dot next to another placeholder' => ['ab', ['a' => '.', 'b' => 'x'], '/ab/.-x', 'ab: . x'],
            'two dots next to another placeholder' => ['ab', ['a' => 'x', 'b' => '..'], '/ab/x-..', 'ab: x ..'],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('valuesThatHaveAnAddress')]
    public function testUrlLeadsBackToItsRoute(string $name, array $params, string $url, string $body): void
    {
        $router = $this->router();

        $this->assertSame($url, $router->url($name, $params));
        $this->assertSame($body, $this->body($router->handle(new ServerRequest('GET', $url))));
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string, 3?: string}>
     */
    public static function valuesWithoutAnAddress(): array
    {
        $fit = 'The parameters do not fit the pattern of route "%s": the address would not lead back to it';
        $backslash = 'Parameter "%s" contains a backslash, which no route accepts';
        $control = 'Parameter "%s" contains a control character, which no route accepts';
        $dots = 'The address would contain a "." or ".." path segment, which a client resolves before it asks';

        return [
            'slash in a one-segment placeholder' => ['tag', ['tag' => 'a/b'], sprintf($fit, 'tag'), '/tags/a/b'],
            'slash in a typed one-segment placeholder' => ['doc', ['id' => '1/2'], sprintf($fit, 'doc'), '/docs/1/2'],
            'slash that the own pattern does not take' => ['pair', ['pair' => 'a/b'], sprintf($fit, 'pair'), '/pairs/a/b'],
            'value that only starts like the own pattern' => ['pair', ['pair' => '1/2x'], sprintf($fit, 'pair'), '/pairs/1/2x'],
            // What "$" instead of "\z" would let through — and a path the router answers with 404
            'line break at the end of a value' => ['pair', ['pair' => "1/2\n"], sprintf($control, 'pair')],
            'line break inside a value' => ['tag', ['tag' => "a\nb"], sprintf($control, 'tag')],
            'NUL where slashes are taken' => ['files', ['path' => "a/\0b"], sprintf($control, 'path')],
            'tab' => ['tag', ['tag' => "a\tb"], sprintf($control, 'tag')],
            'DEL' => ['tag', ['tag' => "a\x7Fb"], sprintf($control, 'tag')],
            'value that only ends like the own pattern' => ['pair', ['pair' => 'x/1/2'], sprintf($fit, 'pair'), '/pairs/x/1/2'],
            'value that only starts like an alternative of the own pattern' => ['side', ['side' => 'left/in/deep'], sprintf($fit, 'side'), '/sides/left/in/deep'],
            'value that only ends like an alternative of the own pattern' => ['side', ['side' => 'far/right/out'], sprintf($fit, 'side'), '/sides/far/right/out'],
            'backslash' => ['tag', ['tag' => 'a\\b'], sprintf($backslash, 'tag')],
            'backslash where slashes are taken' => ['files', ['path' => 'a/b\\c'], sprintf($backslash, 'path')],
            'parent segment' => ['files', ['path' => '../secret'], $dots, '/files/../secret'],
            'parent segment in the middle' => ['files', ['path' => 'a/../b'], $dots, '/files/a/../b'],
            'parent segment at the end' => ['files', ['path' => 'a/..'], $dots, '/files/a/..'],
            'current segment' => ['files', ['path' => 'a/./b'], $dots, '/files/a/./b'],
            'value that is the parent segment' => ['tag', ['tag' => '..'], $dots, '/tags/..'],
            'value that is the current segment' => ['tag', ['tag' => '.'], $dots, '/tags/.'],
            // A segment that only pattern and value together make
            'empty value in front of a dot of the pattern' => ['end', ['a' => ''], sprintf($fit, 'end'), '/end/.'],
            'slash in front of a dot of the pattern' => ['pre', ['p' => 'x/'], $dots, '/pre/x/.'],
            'dot in front of a dot of the pattern' => ['end', ['a' => '.'], $dots, '/end/..'],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('valuesWithoutAnAddress')]
    public function testUrlRefusesWhatCannotReachTheRoute(string $name, array $params, string $message, ?string $debug = null): void
    {
        $router = $this->router(['baseUrl' => 'https://example.org']);
        $value = $debug ?? (string) array_values($params)[0];

        foreach (['url', 'absoluteUrl'] as $method) {
            try {
                $router->{$method}($name, $params);
                $this->fail('An address was generated');
            } catch (RouterException $e) {
                // The message names the parameter at most; values are for the debug message only
                $this->assertSame($message, $e->getMessage());
                $this->assertSame($value, $e->getDebugMessage());
            }
        }
    }

    public function testBasePathWithABackslashGivesNoAddress(): void
    {
        // The router puts a slash in front of what it is given: '\evil.example' becomes
        // '/\evil.example' — which a client reads as '//evil.example'
        // … and a tab alone becomes '/<tab>', which a client drops: '/<tab>/a/b' is '//a/b'
        // Refused where it is configured, before an address is asked for
        foreach (['\\evil.example', "\t", "\n"] as $basePath) {
            try {
                $this->router(['basePath' => $basePath]);
                $this->fail('The router was created');
            } catch (RouterException $e) {
                $this->assertStringStartsWith("Config 'basePath' must be a plain path", $e->getMessage());
            }
        }
    }

    public function testInTheModeIgnoreAValueThatEndsInASlashDoesNotComeBack(): void
    {
        $fit = 'The parameters do not fit the pattern of route "files": the address would not lead back to it';

        // strict: the slash at the end is part of the value, and arrives
        $strict = $this->router();
        foreach (['a/' => '/files/a/', '' => '/files/', '/' => '/files//', 'a//' => '/files/a//'] as $value => $url) {
            $this->assertSame($url, $strict->url('files', ['path' => (string) $value]));
            $this->assertSame('files: ' . $value, $this->body($strict->handle(new ServerRequest('GET', $url))));
        }

        // ignore: the router drops the slashes at the end of the path before it looks the
        // route up — 'a/' would arrive as 'a', and '/files/' has no route at all
        $ignore = $this->router(['trailingSlash' => 'ignore']);
        foreach (['a/' => '/files/a/', '' => '/files/', '/' => '/files//', 'a//' => '/files/a//'] as $value => $candidate) {
            try {
                $ignore->url('files', ['path' => (string) $value]);
                $this->fail('An address was generated');
            } catch (RouterException $e) {
                $this->assertSame($fit, $e->getMessage());
                $this->assertSame($candidate, $e->getDebugMessage());
            }
        }

        // What does come back is written as before, also with a base path
        $this->assertSame('/files/a/b', $ignore->url('files', ['path' => 'a/b']));
        $this->assertSame('files: a/b', $this->body($ignore->handle(new ServerRequest('GET', '/files/a/b'))));
        $this->assertSame('/api/files/a', $this->router(['trailingSlash' => 'ignore', 'basePath' => '/api'])->url('files', ['path' => 'a']));

        // … and without URL encoding nothing is checked, as in the mode strict
        $this->assertSame('/files/a/', $this->router(['trailingSlash' => 'ignore', 'urlEncoding' => false])->url('files', ['path' => 'a/']));
    }

    public function testWithoutUrlEncodingValuesGoInAsGiven(): void
    {
        $router = $this->router(['urlEncoding' => false]);

        // The application encodes itself — and answers for what it writes
        $this->assertSame('/tags/a/b', $router->url('tag', ['tag' => 'a/b']));
        $this->assertSame('/tags/a%2Fb', $router->url('tag', ['tag' => 'a%2Fb']));
        $this->assertSame('/files/../x', $router->url('files', ['path' => '../x']));
        $this->assertSame('/tags/..', $router->url('tag', ['tag' => '..']));
        $this->assertSame('/tags/a\\b', $router->url('tag', ['tag' => 'a\\b']));
    }
}
