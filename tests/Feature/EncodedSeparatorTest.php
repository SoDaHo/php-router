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
use Sodaho\Router\RouteMatch;
use Sodaho\Router\Router;

/**
 * A separator that is hidden in the path — %2F, %5C, a backslash — has no route: decoded
 * it would be two segments here and one for a proxy or the access rules of the web server
 * in front. And url() writes no address that the router refuses itself.
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
                    $r->get('/odd/{x:nope}', fn ($req, string $x) => Response::text('odd: ' . $x))->name('odd');
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

    public function testBackslashThatArrivesUnencodedHasNoRouteEither(): void
    {
        // A URI class encodes it; a request object of another make may hand it over as it is
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/files/a\\b');

        $response = $this->router()->handle((new ServerRequest('GET', '/files/x'))->withUri($uri));

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
}
