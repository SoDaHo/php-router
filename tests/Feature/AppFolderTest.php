<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Feature;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Sodaho\Router\AppFolder;
use Sodaho\Router\Router;

/**
 * Router::app(): a folder with a built web app, served under a prefix — where no route
 * matches. The larger half of this file is what must never be served.
 */
class AppFolderTest extends TestCase
{
    private string $base;
    private string $app;
    private string $routesFile;

    protected function setUp(): void
    {
        $this->base = (string) realpath(sys_get_temp_dir()) . '/router_app_' . uniqid();
        $this->app = $this->base . '/login';

        $files = [
            'login/index.html' => '<!doctype html><title>login</title>',
            'login/favicon.ico' => 'ICO',
            'login/robots.txt' => 'User-agent: *',
            'login/assets/app.4f9a2b1c.js' => 'console.log("app")',
            'login/assets/index-B1fQx9cD.css' => 'body{}',
            'login/assets/style.css' => 'p{}',
            'login/assets/logo.SVG' => '<svg/>',
            'login/assets/app.4f9a2b1c.js.map' => '{"sources":["src/main.ts"]}',
            'login/static/js/main.a1b2c3d4.chunk.js' => '//',
            'login/app.4f9a2b1c.js' => '// copied from public/, the name proves nothing',
            'login/ends-in-dot./inside.js' => 'outside the rules',
            'login/ends-in-space /inside.js' => 'outside the rules',
            // A colon names a stream of a file on Windows
            'login/colon:name.js' => 'outside the rules',
            'login/co:lon/inside.js' => 'outside the rules',
            'login/docs/index.html' => '<!doctype html><title>docs</title>',
            'login/caps/INDEX.HTML' => 'caps',
            'login/big.txt' => str_repeat('0123456789', 100),
            'login/.env' => 'SECRET=1',
            'login/.hidden/inside.js' => 'hidden',
            'login/secret.php' => '<?php echo "source";',
            'login/script.phtml' => '<?php echo "source";',
            'login/LICENSE' => 'no extension',
            'login/data.bin' => 'unknown type',
            // A name with a backslash is an ordinary file here and a path with a separator on Windows
            'login/back\\slash.js' => 'backslash in the name',
            'outside/outside.js' => 'outside',
            'outside/index.html' => 'outside index',
            'site/index.html' => '<!doctype html><title>site</title>',
            'site/login/shadow.js' => 'must not be reachable through the root app',
            'site/about.html' => 'about',
        ];
        foreach ($files as $path => $content) {
            @mkdir(dirname($this->base . '/' . $path), 0o777, true);
            file_put_contents($this->base . '/' . $path, $content);
        }

        symlink($this->base . '/outside/outside.js', $this->app . '/link-out.js');
        symlink($this->base . '/outside', $this->app . '/linkdir');
        symlink($this->app . '/.hidden/inside.js', $this->app . '/link-to-hidden.js');
        symlink($this->app . '/assets/style.css', $this->app . '/link-inside.css');
        symlink($this->app . '/assets/app.4f9a2b1c.js', $this->app . '/current.js');

        $this->routesFile = $this->base . '/routes.php';
        file_put_contents(
            $this->routesFile,
            <<<'PHP'
                <?php
                use Sodaho\Router\Response;

                return function (Sodaho\Router\RouteCollector $r) {
                    $r->get('/login/api/status', fn () => Response::text('from the route'));
                    $r->get('/login/robots.txt', fn () => Response::text('robots from the route'));
                    $r->post('/login/submit', fn () => Response::text('submitted'));
                    $r->get('/health', fn () => Response::text('ok'));
                };
                PHP
        );
    }

    protected function tearDown(): void
    {
        $this->remove($this->base);
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            $this->remove($path . '/' . $entry);
        }
        rmdir($path);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function router(array $config = []): Router
    {
        /** @phpstan-ignore argument.type */
        return Router::create($config + ['debug' => false])->loadRoutes($this->routesFile)->app('/login', $this->app);
    }

    /**
     * A router whose app says that the bundler's hashed files never change.
     */
    private function routerThatTrustsHashedNames(): Router
    {
        return Router::create(['debug' => false])->loadRoutes($this->routesFile)
            ->app('/login', $this->app, ['immutable' => AppFolder::HASHED]);
    }

    private function get(Router $router, string $path, string $method = 'GET'): ResponseInterface
    {
        return $router->handle(new ServerRequest($method, $path));
    }

    // ==================== what is served ====================

    public function testFileOfTheFolder(): void
    {
        $response = $this->get($this->router(), '/login/assets/style.css');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('p{}', (string) $response->getBody());
        $this->assertSame('text/css; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('3', $response->getHeaderLine('Content-Length'));
        $this->assertSame('bytes', $response->getHeaderLine('Accept-Ranges'));
        $this->assertFalse($response->hasHeader('Content-Disposition'), 'an asset is not a download');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function typesAndCaching(): array
    {
        return [
            'start page' => ['/login/index.html', 'text/html; charset=utf-8', 'no-cache'],
            'start page of a subfolder' => ['/login/docs/', 'text/html; charset=utf-8', 'no-cache'],
            'name with a hash after a dot' => ['/login/assets/app.4f9a2b1c.js', 'text/javascript; charset=utf-8', 'public, max-age=31536000, immutable'],
            'name with a hash after a hyphen' => ['/login/assets/index-B1fQx9cD.css', 'text/css; charset=utf-8', 'public, max-age=31536000, immutable'],
            'name with a hash, webpack chunk' => ['/login/static/js/main.a1b2c3d4.chunk.js', 'text/javascript; charset=utf-8', 'public, max-age=31536000, immutable'],
            'plain name' => ['/login/assets/style.css', 'text/css; charset=utf-8', 'no-cache'],
            'name with a hash outside assets/ and static/' => ['/login/app.4f9a2b1c.js', 'text/javascript; charset=utf-8', 'no-cache'],
            // The browser caches by address: /login/current.js may point elsewhere tomorrow
            'plain name that is a link to a hashed file' => ['/login/current.js', 'text/javascript; charset=utf-8', 'no-cache'],
            'extension in capitals' => ['/login/assets/logo.SVG', 'image/svg+xml', 'no-cache'],
            'icon' => ['/login/favicon.ico', 'image/x-icon', 'no-cache'],
            'link that stays inside the folder' => ['/login/link-inside.css', 'text/css; charset=utf-8', 'no-cache'],
        ];
    }

    #[DataProvider('typesAndCaching')]
    public function testContentTypeAndCacheControl(string $path, string $type, string $cache): void
    {
        $response = $this->get($this->routerThatTrustsHashedNames(), $path);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($type, $response->getHeaderLine('Content-Type'));
        $this->assertSame($cache, $response->getHeaderLine('Cache-Control'));

        // Unless the application says so, no file counts as unchanging: a form proves
        // nothing, and a year in every cache cannot be called back
        $byDefault = $this->get($this->router(), $path);
        $this->assertSame(200, $byDefault->getStatusCode());
        $this->assertSame('no-cache', $byDefault->getHeaderLine('Cache-Control'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function pathsOfTheAppsOwnRouter(): array
    {
        return [
            'the prefix' => ['/login'],
            'the prefix with a slash' => ['/login/'],
            'a page of the app' => ['/login/reset'],
            'a deep page' => ['/login/account/42/edit'],
            'a folder without a start page' => ['/login/assets/'],
            'a page that ends in a slash' => ['/login/reset/'],
            'a page below a segment with a dot' => ['/login/v1.2/page'],
            // Never looked up as a file, still a path of the app
            'a page with colons' => ['/login/item/urn:isbn:9780131103627'],
            'a page that spells a folder with a colon' => ['/login/co:lon/inside'],
        ];
    }

    #[DataProvider('pathsOfTheAppsOwnRouter')]
    public function testEveryOtherPathUnderThePrefixGetsTheStartPage(string $path): void
    {
        $response = $this->get($this->router(), $path);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('<!doctype html><title>login</title>', (string) $response->getBody());
        $this->assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame('no-cache', $response->getHeaderLine('Cache-Control'));
    }

    public function testStartPageOfASubfolderIsItsOwn(): void
    {
        $this->assertSame('<!doctype html><title>docs</title>', (string) $this->get($this->router(), '/login/docs')->getBody());
    }

    /**
     * A missing script must not come back as HTML — the browser would try to run the
     * start page as JavaScript and report a syntax error that helps nobody.
     */
    public function testMissingFileIsNotFound(): void
    {
        $notFound = [];
        $router = $this->router();
        $router->on('notFound', function (array $data) use (&$notFound): void {
            $notFound[] = $data['path'];
        });

        foreach (['/login/assets/gone.js', '/login/gone.css', '/login/v1.2/page.html'] as $path) {
            $response = $this->get($router, $path);

            $this->assertSame(404, $response->getStatusCode(), $path);
            $this->assertStringContainsString('"code":"NOT_FOUND"', (string) $response->getBody(), $path);
        }

        // The hook fires for these — and only for these: a served file is not a 404
        $this->get($router, '/login/assets/style.css');
        $this->get($router, '/login/reset');

        $this->assertSame(['/login/assets/gone.js', '/login/gone.css', '/login/v1.2/page.html'], $notFound);
    }

    public function testHeadAndRange(): void
    {
        $router = $this->router();

        $get = $this->get($router, '/login/big.txt');
        $head = $this->get($router, '/login/big.txt', 'HEAD');

        $this->assertSame(200, $head->getStatusCode());
        $this->assertSame($get->getHeaders(), $head->getHeaders());
        $this->assertSame('1000', $head->getHeaderLine('Content-Length'));
        $this->assertSame('', (string) $head->getBody());

        $part = $router->handle((new ServerRequest('GET', '/login/big.txt'))->withHeader('Range', 'bytes=10-19'));

        $this->assertSame(206, $part->getStatusCode());
        $this->assertSame('0123456789', (string) $part->getBody());
        $this->assertSame('bytes 10-19/1000', $part->getHeaderLine('Content-Range'));
        $this->assertSame('text/plain; charset=utf-8', $part->getHeaderLine('Content-Type'));

        $this->assertSame(416, $router->handle((new ServerRequest('GET', '/login/big.txt'))->withHeader('Range', 'bytes=5000-'))->getStatusCode());

        // HEAD with the switch off: a HEAD request is still answered, headers as for GET
        $off = $this->get($this->router(['implicitHead' => false]), '/login/big.txt', 'HEAD');
        $this->assertSame(200, $off->getStatusCode());
        $this->assertSame('1000', $off->getHeaderLine('Content-Length'));
    }

    public function testStartPageIsWhatWasAskedForNotWhereALinkLeads(): void
    {
        // The start page as a link to a file with a hashed name, and a plain name that
        // links to the start page
        file_put_contents($this->app . '/assets/entry-B1fQx9cD.html', 'entry');
        symlink($this->app . '/assets/entry-B1fQx9cD.html', $this->app . '/entry.html');
        symlink($this->app . '/assets/entry-B1fQx9cD.html', $this->app . '/docs/entry.html');
        symlink($this->app . '/entry.html', $this->app . '/copy.html');

        $router = Router::create(['debug' => false])->loadRoutes($this->routesFile)->app('/login', $this->app, [
            'index' => 'entry.html',
            'immutable' => AppFolder::HASHED,
            'cacheIndex' => 'no-store',
        ]);

        foreach (['/login', '/login/', '/login/client-route', '/login/entry.html', '/login/docs/', '/login/docs/entry.html'] as $path) {
            $response = $this->get($router, $path);

            $this->assertSame('entry', (string) $response->getBody(), $path);
            $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'), $path);
        }

        // By its own address the same file is what its name says
        $this->assertSame('public, max-age=31536000, immutable', $this->get($router, '/login/assets/entry-B1fQx9cD.html')->getHeaderLine('Cache-Control'));
        // Another name that leads to the start page of its directory is the start page too:
        // the file decides, not the spelling
        $this->assertSame('no-store', $this->get($router, '/login/copy.html')->getHeaderLine('Cache-Control'));
    }

    public function testStartPageUnderAnotherSpellingKeepsItsCacheRule(): void
    {
        // A second name for the same file (a hard link here; on a file system that folds
        // case or normalizes Unicode, another spelling of the name does the same). An
        // 'immutable' rule that matches the other name must not get hold of the start page.
        link($this->app . '/index.html', $this->app . '/ПРОЧТИ-B1fQx9cD.html');
        file_put_contents($this->app . '/other-B1fQx9cD.html', 'other');

        $router = Router::create(['debug' => false])->loadRoutes($this->routesFile)->app('/login', $this->app, [
            'immutable' => '~-B1fQx9cD\.html$~',
            'cacheIndex' => 'no-store',
        ]);

        $startPage = $this->get($router, '/login/' . rawurlencode('ПРОЧТИ-B1fQx9cD.html'));
        $this->assertSame('<!doctype html><title>login</title>', (string) $startPage->getBody());
        $this->assertSame('no-store', $startPage->getHeaderLine('Cache-Control'));

        // A file of its own with such a name is what the rule says
        $this->assertSame('public, max-age=31536000, immutable', $this->get($router, '/login/other-B1fQx9cD.html')->getHeaderLine('Cache-Control'));
    }

    public function testStartPageIsRecognizedWithoutRegardToCase(): void
    {
        // On a file system that ignores case /login/INDEX.HTML is the start page. Here the
        // file carries that name itself, so the test says the same on every system.
        $router = Router::create(['debug' => false])->loadRoutes($this->routesFile)
            ->app('/login', $this->app, ['cacheIndex' => 'no-store']);

        $response = $this->get($router, '/login/caps/INDEX.HTML');

        $this->assertSame('caps', (string) $response->getBody());
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    // ==================== routes come first ====================

    public function testRoutesAlwaysWin(): void
    {
        $router = $this->router();

        $this->assertSame('from the route', (string) $this->get($router, '/login/api/status')->getBody());

        // Also where the folder has a file of that name
        $this->assertSame('robots from the route', (string) $this->get($router, '/login/robots.txt')->getBody());

        // A path the table knows for another method is a 405 — not a case for the folder
        $response = $this->get($router, '/login/submit');
        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('POST', $response->getHeaderLine('Allow'));

        // Outside the prefix nothing changes
        $this->assertSame('ok', (string) $this->get($router, '/health')->getBody());
        $this->assertSame(404, $this->get($router, '/elsewhere')->getStatusCode());
        $this->assertSame(404, $this->get($router, '/loginx')->getStatusCode());
    }

    public function testOnlyGetAndHead(): void
    {
        $router = $this->router();

        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $this->assertSame(404, $this->get($router, '/login/assets/style.css', $method)->getStatusCode(), $method);
            $this->assertSame(404, $this->get($router, '/login/reset', $method)->getStatusCode(), $method);
        }
    }

    // ==================== what is never served ====================

    /**
     * @return array<string, array{0: string}>
     */
    public static function pathsThatMustNotBeServed(): array
    {
        return [
            'parent folder' => ['/login/../outside/outside.js'],
            'parent folder, twice' => ['/login/assets/../../outside/outside.js'],
            'parent folder, dots encoded' => ['/login/%2e%2e/outside/outside.js'],
            'parent folder, separator encoded' => ['/login/..%2foutside%2foutside.js'],
            'parent folder, everything encoded' => ['/login/%2e%2e%2foutside%2foutside.js'],
            'encoded separator on an honest path' => ['/login/assets%2fstyle.css'],
            'encoded separator, capitals' => ['/login/assets%2Fstyle.css'],
            'encoded backslash' => ['/login/assets%5cstyle.css'],
            'backslash' => ['/login/assets\\style.css'],
            'file that has a backslash in its name' => ['/login/back\\slash.js'],
            'file that has a backslash in its name, encoded' => ['/login/back%5Cslash.js'],
            'NUL, encoded' => ['/login/index.html%00.js'],
            'hidden file' => ['/login/.env'],
            'hidden folder' => ['/login/.hidden/inside.js'],
            'link to a hidden file' => ['/login/link-to-hidden.js'],
            'the folder itself, spelled with a dot' => ['/login/./assets/style.css'],
            // Windows drops a dot or a space at the end of a name: such a segment names
            // something else there than here, so it is refused everywhere
            'folder whose name ends in a dot' => ['/login/ends-in-dot./inside.js'],
            'folder whose name ends in a space' => ['/login/ends-in-space%20/inside.js'],
            'path of the app, segment ends in a dot' => ['/login/v1./page'],
            'path of the app, segment ends in a space' => ['/login/my%20/page'],
            'source map' => ['/login/assets/app.4f9a2b1c.js.map'],
            'file with a colon in its name' => ['/login/colon:name.js'],
            'file in a folder with a colon in its name' => ['/login/co:lon/inside.js'],
            'stream of a file, as Windows spells it' => ['/login/assets/style.css::$DATA'],
            'empty segment at the end' => ['/login//'],
            'empty segments at the end' => ['/login///'],
            'empty segment behind a folder' => ['/login/docs//'],
            'empty segment behind a file' => ['/login/assets/style.css//'],
            'empty segment' => ['/login//assets/style.css'],
            'PHP source' => ['/login/secret.php'],
            'PHP source, other extension' => ['/login/script.phtml'],
            'file without extension' => ['/login/LICENSE'],
            'type that is not on the list' => ['/login/data.bin'],
            'link to a file outside' => ['/login/link-out.js'],
            'file behind a link to a folder outside' => ['/login/linkdir/outside.js'],
            'start page behind a link to a folder outside' => ['/login/linkdir/'],
            'start page behind a link to a folder outside, no slash' => ['/login/linkdir'],
        ];
    }

    #[DataProvider('pathsThatMustNotBeServed')]
    public function testNeverServed(string $path): void
    {
        $router = $this->router();

        foreach (['GET', 'HEAD'] as $method) {
            $response = $this->get($router, $path, $method);

            $this->assertSame(404, $response->getStatusCode(), $method);
            $this->assertSame('application/json', $response->getHeaderLine('Content-Type'), $method);
        }

        // Not the file, and not the start page either
        $body = (string) $this->get($router, $path)->getBody();
        $this->assertStringContainsString('"code":"NOT_FOUND"', $body);
        foreach (['outside', 'hidden', '<?php', 'SECRET', 'no extension', 'unknown type', 'doctype', 'p{}', 'backslash in the name', 'sources'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
    }

    public function testFileThatCannotBeReadIsNotFound(): void
    {
        $locked = $this->app . '/assets/locked.js';
        file_put_contents($locked, 'must not be served');
        chmod($locked, 0o000);

        if (is_readable($locked)) {
            $this->markTestSkipped('root bypasses file permissions');
        }

        $router = $this->router();
        $errors = 0;
        $router->on('error', function () use (&$errors): void {
            $errors++;
        });

        // A file that is there and cannot be opened is missing for the visitor — not a
        // 500 whose message carries the path on the server
        $response = $this->get($router, '/login/assets/locked.js');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('"code":"NOT_FOUND"', (string) $response->getBody());
        $this->assertSame(0, $errors);
    }

    /**
     * A request object whose path carries what a URI class would have encoded. The folder
     * looks at the path the route table was asked with, too.
     */
    public function testRawNulAndBackslashInThePathAreRefusedAsWell(): void
    {
        $router = $this->router();

        foreach (["/login/assets/style.css\0", "/login/index.html\0.js", '/login/assets\\style.css'] as $path) {
            $uri = $this->createMock(\Psr\Http\Message\UriInterface::class);
            $uri->method('getPath')->willReturn($path);

            $response = $router->handle((new ServerRequest('GET', '/login/'))->withUri($uri));

            $this->assertSame(404, $response->getStatusCode(), addcslashes($path, "\0"));
        }
    }

    public function testEmptySegmentInFrontIsRefusedWhereTheRequestStillCarriesIt(): void
    {
        $router = $this->router();

        // A request object that hands the path over as it came
        $uri = $this->createMock(\Psr\Http\Message\UriInterface::class);
        $uri->method('getPath')->willReturn('//login/assets/style.css');
        $this->assertSame(404, $router->handle((new ServerRequest('GET', '/login/'))->withUri($uri))->getStatusCode());

        // Most PSR-7 implementations fold slashes at the start into one (a path that begins
        // with '//' would read as a host). Then the folder is asked for an honest path —
        // the same file as without the extra slash, for routes as for apps.
        $folded = new ServerRequest('GET', 'http://example.org//login/assets/style.css');
        $this->assertSame('/login/assets/style.css', $folded->getUri()->getPath());
        $this->assertSame('p{}', (string) $router->handle($folded)->getBody());
    }
}
