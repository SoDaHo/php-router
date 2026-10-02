<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Feature;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sodaho\Router\AppFolder;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\Response;
use Sodaho\Router\RouteMatch;
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

    // ==================== the folder ====================

    public function testFolderThatIsALinkServesWhatTheLinkPointsTo(): void
    {
        symlink($this->base . '/site', $this->base . '/current');

        $router = Router::create(['debug' => false])->loadRoutes($this->routesFile)->app('/site', $this->base . '/current');

        $this->assertSame('about', (string) $this->get($router, '/site/about.html')->getBody());

        // ... and follows when the link is pointed elsewhere while the process lives. Done
        // by another process, as a deployment would: PHP remembers resolved paths for two
        // minutes and only forgets them by itself when the change was its own.
        exec(sprintf('ln -sfn %s %s', escapeshellarg($this->base . '/login'), escapeshellarg($this->base . '/current')), $output, $code);
        $this->assertSame(0, $code);

        $this->assertSame(404, $this->get($router, '/site/about.html')->getStatusCode());
        $this->assertSame('p{}', (string) $this->get($router, '/site/assets/style.css')->getBody());
    }

    public function testFolderThatDisappearsIsNotFound(): void
    {
        $router = Router::create(['debug' => false])->loadRoutes($this->routesFile)->app('/site', $this->base . '/site');
        $this->assertSame(200, $this->get($router, '/site/')->getStatusCode());

        $this->remove($this->base . '/site');

        $this->assertSame(404, $this->get($router, '/site/')->getStatusCode());
        $this->assertSame(404, $this->get($router, '/site/about.html')->getStatusCode());
    }

    public function testRelativeFolderMeansTheWorkingDirectoryOfTheRegistration(): void
    {
        $before = (string) getcwd();

        try {
            chdir($this->base);
            $router = Router::create(['debug' => false])->loadRoutes($this->routesFile)->app('/login', 'login');

            // Another directory that has a 'login' folder with other content, and a start page
            chdir($this->base . '/site');

            $this->assertSame('<!doctype html><title>login</title>', (string) $this->get($router, '/login/')->getBody());
            $this->assertSame('p{}', (string) $this->get($router, '/login/assets/style.css')->getBody());
            $this->assertSame(404, $this->get($router, '/login/shadow.js')->getStatusCode());
        } finally {
            chdir($before);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function relativeNamesThatLookAbsoluteElsewhere(): array
    {
        return [
            'drive letter' => ['C:/site'],
            'backslash in front' => ['\\site'],
            'drive letter and backslash' => ['C:\\site'],
        ];
    }

    #[DataProvider('relativeNamesThatLookAbsoluteElsewhere')]
    public function testWhatIsAbsoluteIsTheSystemsMatter(string $name): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('on Windows these names are bound or refused, see the next test');
        }

        $before = (string) getcwd();
        @mkdir($this->base . '/' . $name, 0o777, true);
        file_put_contents($this->base . '/' . $name . '/index.html', 'bound');

        try {
            chdir($this->base);
            $router = Router::create(['debug' => false])->loadRoutes($this->routesFile)->app('/x', $name);

            // Another directory with a folder of the same relative name
            @mkdir($this->base . '/site/' . $name, 0o777, true);
            file_put_contents($this->base . '/site/' . $name . '/index.html', 'swapped');
            chdir($this->base . '/site');

            $this->assertSame('bound', (string) $this->get($router, '/x/')->getBody());
        } finally {
            chdir($before);
        }
    }

    /**
     * @return array<string, array{0: string, 1: string|false, 2: string, 3: string}>
     */
    public static function foldersAndWhatTheyAreBoundTo(): array
    {
        return [
            // where '/' is the separator
            'absolute' => ['/var/www/site', '/srv', '/', '/var/www/site'],
            'absolute, no working directory needed' => ['/var/www/site', false, '/', '/var/www/site'],
            'relative' => ['site', '/srv', '/', '/srv/site'],
            'relative with a parent step' => ['../site', '/srv', '/', '/srv/../site'],
            'drive letter is a relative name' => ['C:/site', '/srv', '/', '/srv/C:/site'],
            'backslash in front is a relative name' => ['\\site', '/srv', '/', '/srv/\\site'],
            'two backslashes in front are a relative name' => ['\\\\server\\share', '/srv', '/', '/srv/\\\\server\\share'],
            'empty' => ['', '/srv', '/', ''],
            'relative without a working directory' => ['site', false, '/', ''],
            // on Windows
            'drive and backslash' => ['C:\\site', 'D:\\srv', '\\', 'C:\\site'],
            'drive and slash' => ['c:/site', 'D:\\srv', '\\', 'c:/site'],
            'server' => ['\\\\server\\share\\site', 'D:\\srv', '\\', '\\\\server\\share\\site'],
            'server, slashes' => ['//server/share/site', 'D:\\srv', '\\', '//server/share/site'],
            'drive, no working directory needed' => ['C:\\site', false, '\\', 'C:\\site'],
            'relative on Windows' => ['site', 'D:\\srv', '\\', 'D:\\srv\\site'],
            'relative on Windows, slash inside' => ['web/site', 'D:\\srv', '\\', 'D:\\srv\\web/site'],
            // Half bound: Windows would complete these from the current drive or its working
            // directory — and fold 'D:\srv\\site' into a folder that was never meant
            'root of the current drive' => ['\\site', 'D:\\srv', '\\', ''],
            'root of the current drive, slash' => ['/site', 'D:\\srv', '\\', ''],
            'working directory of a drive' => ['C:site', 'D:\\srv', '\\', ''],
            'a drive alone' => ['C:', 'D:\\srv', '\\', ''],
            'empty on Windows' => ['', 'D:\\srv', '\\', ''],
            'relative on Windows without a working directory' => ['site', false, '\\', ''],
        ];
    }

    #[DataProvider('foldersAndWhatTheyAreBoundTo')]
    public function testFolderIsBoundByTheRulesOfTheSystem(string $directory, string|false $cwd, string $separator, string $bound): void
    {
        $this->assertSame($bound, AppFolder::bind($directory, $cwd, $separator));
    }

    public function testRelativeFolderWithoutAWorkingDirectoryIsRefused(): void
    {
        $before = (string) getcwd();
        $gone = $this->base . '/gone';
        mkdir($gone);

        try {
            chdir($gone);
            rmdir($gone);

            if (getcwd() !== false) {
                $this->markTestSkipped('this system still reports a removed working directory');
            }

            // Resolved against nothing, 'tmp' would mean '/tmp'
            foreach (['tmp', 'etc', ltrim($this->app, '/')] as $relative) {
                try {
                    Router::create()->app('/x', $relative);
                    $this->fail('A relative folder was accepted without a working directory');
                } catch (RouterException $e) {
                    $this->assertSame('App folder is not a directory', $e->getMessage());
                }
            }
        } finally {
            chdir($before);
        }
    }

    public function testEmptyFolderNameIsNotTheWorkingDirectory(): void
    {
        $before = (string) getcwd();

        try {
            // What an unset variable makes of a path must not serve the directory the
            // process happens to run in
            chdir($this->app);

            Router::create()->app('/x', '');
            $this->fail('An empty folder name was accepted');
        } catch (RouterException $e) {
            $this->assertSame('App folder is not a directory', $e->getMessage());
            $this->assertSame('', $e->getDebugMessage());
        } finally {
            chdir($before);
        }
    }

    // ==================== several apps, base path, middleware, hooks ====================

    public function testMostSpecificPrefixDecidesAlone(): void
    {
        // Registered root first — the order of the calls does not matter
        $router = Router::create(['debug' => false])
            ->loadRoutes($this->routesFile)
            ->app('/', $this->base . '/site')
            ->app('/login', $this->app);

        $this->assertSame('<!doctype html><title>site</title>', (string) $this->get($router, '/')->getBody());
        $this->assertSame('about', (string) $this->get($router, '/about.html')->getBody());
        $this->assertSame('<!doctype html><title>site</title>', (string) $this->get($router, '/pricing')->getBody());
        $this->assertSame('<!doctype html><title>login</title>', (string) $this->get($router, '/login/reset')->getBody());
        $this->assertSame('ok', (string) $this->get($router, '/health')->getBody());

        // The root folder has login/shadow.js; '/login' belongs to the other app, which
        // has no such file: 404, no second try further up
        $this->assertSame(404, $this->get($router, '/login/shadow.js')->getStatusCode());

        // What the app at '/login' refuses is not handed to the root app either
        $this->assertSame(404, $this->get($router, '/login/.env')->getStatusCode());
    }

    public function testPrefixIsRelativeToTheBasePath(): void
    {
        $router = $this->router(['basePath' => '/auth']);

        $this->assertSame('p{}', (string) $this->get($router, '/auth/login/assets/style.css')->getBody());
        $this->assertSame(200, $this->get($router, '/auth/login/reset')->getStatusCode());

        // Outside the base path there is no app
        $this->assertSame(404, $this->get($router, '/login/assets/style.css')->getStatusCode());
        $this->assertSame(404, $this->get($router, '/login/')->getStatusCode());
    }

    public function testTrailingSlashModeIgnore(): void
    {
        $router = $this->router(['trailingSlash' => 'ignore']);

        $this->assertSame(200, $this->get($router, '/login/')->getStatusCode());
        $this->assertSame('<!doctype html><title>docs</title>', (string) $this->get($router, '/login/docs/')->getBody());

        // The mode drops slashes at the end before the folder is asked — an empty segment
        // stays one
        foreach (['/login//', '/login/reset//', '/login/docs//', '/login/assets/style.css//'] as $path) {
            $this->assertSame(404, $this->get($router, $path)->getStatusCode(), $path);
        }
    }

    public function testMiddlewareForEveryRequestRunsBefore(): void
    {
        $seen = [];
        $guard = new class ($seen) implements MiddlewareInterface {
            /** @param array<int, mixed> $seen */
            public function __construct(private array &$seen)
            {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                // For the route table an app path is a path without a route
                $this->seen[] = $request->getAttribute(RouteMatch::class)->status;

                if ($request->hasHeader('X-Blocked')) {
                    return Response::text('blocked', 403);
                }

                return $handler->handle($request)->withHeader('X-Frame-Options', 'DENY');
            }
        };

        $router = $this->router()->middleware($guard);

        $response = $this->get($router, '/login/assets/style.css');
        $this->assertSame('p{}', (string) $response->getBody());
        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));

        $blocked = $router->handle((new ServerRequest('GET', '/login/assets/style.css'))->withHeader('X-Blocked', '1'));
        $this->assertSame(403, $blocked->getStatusCode());

        $this->assertSame([RouteMatch::NOT_FOUND, RouteMatch::NOT_FOUND], $seen);
    }

    /**
     * The README's example: an application with a 404 page of its own. Answering every
     * NOT_FOUND there would get in before the folder — so it leaves the app's paths alone.
     */
    public function testMiddlewareThatAnswersNotFoundHasToLeaveTheAppsPathsAlone(): void
    {
        $ownPage = fn (bool $everywhere): MiddlewareInterface => new class ($everywhere) implements MiddlewareInterface {
            public function __construct(private readonly bool $everywhere)
            {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $match = $request->getAttribute(RouteMatch::class);
                $underAnApp = str_starts_with($match->path . '/', '/login/');

                if ($match->status === RouteMatch::NOT_FOUND && ($this->everywhere || !$underAnApp)) {
                    return Response::html('<h1>Not here</h1>', 404);
                }

                return $handler->handle($request);
            }
        };

        $careful = $this->router()->middleware($ownPage(false));
        $this->assertSame('p{}', (string) $this->get($careful, '/login/assets/style.css')->getBody());
        $this->assertSame('<!doctype html><title>login</title>', (string) $this->get($careful, '/login')->getBody());
        $this->assertSame('<h1>Not here</h1>', (string) $this->get($careful, '/nowhere')->getBody());
        $this->assertSame('<h1>Not here</h1>', (string) $this->get($careful, '/loginx')->getBody());
        // What the folder does not have is the router's 404 — the middleware stepped aside
        $this->assertStringContainsString('NOT_FOUND', (string) $this->get($careful, '/login/gone.js')->getBody());

        $blanket = $this->router()->middleware($ownPage(true));
        $this->assertSame('<h1>Not here</h1>', (string) $this->get($blanket, '/login/assets/style.css')->getBody());
    }

    public function testErrorHookAndDispatchHookStaySilentForAppFiles(): void
    {
        $fired = [];
        $router = $this->router();
        foreach (['dispatch', 'notFound', 'methodNotAllowed', 'error'] as $event) {
            $router->on($event, function () use (&$fired, $event): void {
                $fired[] = $event;
            });
        }

        $this->get($router, '/login/assets/style.css');
        $this->get($router, '/login/reset');

        $this->assertSame([], $fired);
    }

    public function testAppAddedAfterTheFirstRequestApplies(): void
    {
        $router = Router::create(['debug' => false])->loadRoutes($this->routesFile);
        $this->assertSame(404, $this->get($router, '/login/')->getStatusCode());

        $router->app('/login', $this->app);
        $this->assertSame(200, $this->get($router, '/login/')->getStatusCode());
    }

    // ==================== options ====================

    public function testOptions(): void
    {
        file_put_contents($this->app . '/start.html', 'start');
        file_put_contents($this->app . '/notes.md', '# notes');

        $router = Router::create(['debug' => false])->loadRoutes($this->routesFile)->app('/login', $this->app, [
            'index' => 'start.html',
            // Taking a PHP extension off the list is allowed: it was never on it
            'types' => ['md' => 'text/markdown; charset=utf-8', 'ico' => null, 'php' => null],
            // Matched against the requested path below the prefix
            'immutable' => '~^assets/style\.css$~',
            'cacheIndex' => 'no-store',
            'cacheImmutable' => 'public, max-age=60',
            'cacheOther' => null,
        ]);

        $start = $this->get($router, '/login/somewhere');
        $this->assertSame('start', (string) $start->getBody());
        $this->assertSame('no-store', $start->getHeaderLine('Cache-Control'));

        $this->assertSame('text/markdown; charset=utf-8', $this->get($router, '/login/notes.md')->getHeaderLine('Content-Type'));
        $this->assertSame(404, $this->get($router, '/login/favicon.ico')->getStatusCode(), 'taken off the list');
        $this->assertSame(404, $this->get($router, '/login/secret.php')->getStatusCode());
        $this->assertFalse($this->get($router, '/login/link-inside.css')->hasHeader('Cache-Control'), 'the requested path decides, not the file a link leads to');
        $this->assertSame('public, max-age=60', $this->get($router, '/login/assets/style.css')->getHeaderLine('Cache-Control'));

        $other = $this->get($router, '/login/assets/app.4f9a2b1c.js');
        $this->assertSame(200, $other->getStatusCode());
        $this->assertFalse($other->hasHeader('Cache-Control'));

        // index.html is an ordinary file now
        $this->assertFalse($this->get($router, '/login/index.html')->hasHeader('Cache-Control'));

        // immutable => null is the default: no name counts as unchanging
        $none = Router::create(['debug' => false])->loadRoutes($this->routesFile)->app('/login', $this->app, ['immutable' => null]);
        $this->assertSame('no-cache', $this->get($none, '/login/assets/app.4f9a2b1c.js')->getHeaderLine('Cache-Control'));
    }

    public function testSourceMapsAreServedOnceTheirTypeIsOnTheList(): void
    {
        $router = Router::create(['debug' => false])->loadRoutes($this->routesFile)
            ->app('/login', $this->app, ['types' => ['map' => 'application/json']]);

        $map = $this->get($router, '/login/assets/app.4f9a2b1c.js.map');

        $this->assertSame(200, $map->getStatusCode());
        $this->assertSame('application/json', $map->getHeaderLine('Content-Type'));
        $this->assertSame('no-cache', $map->getHeaderLine('Cache-Control'));
    }

    public function testExtensionsThatOnlyResembleThePhpOnesCanBePutOnTheList(): void
    {
        file_put_contents($this->app . '/notes.phpx', 'x');

        $router = Router::create(['debug' => false])->loadRoutes($this->routesFile)
            ->app('/login', $this->app, ['types' => ['phpx' => 'text/plain', 'xphp' => 'text/plain', 'incl' => 'text/plain', 'phtm' => 'text/plain']]);

        $this->assertSame(200, $this->get($router, '/login/notes.phpx')->getStatusCode());
    }

    /**
     * @return array<string, array{0: string, 1: array<mixed>, 2: string, 3: string|null}>
     */
    public static function registrationsThatAreRefused(): array
    {
        return [
            'folder that does not exist' => ['/x', [], 'App folder is not a directory', 'DIR/nowhere'],
            'file instead of a folder' => ['/x', [], 'App folder is not a directory', 'DIR/login/index.html'],
            'prefix with a parent step' => ['/a/../b', [], 'App prefix must be a plain path', '/a/../b'],
            'hidden prefix' => ['/.well-known', [], 'App prefix must be a plain path', '/.well-known'],
            'unknown option' => ['/x', ['indx' => 'start.html'], 'Unknown app option. Known options: index, types, immutable, cacheIndex, cacheImmutable, cacheOther', 'Unknown: indx'],
            'index with a path' => ['/x', ['index' => 'sub/index.html'], "App option 'index' must be a file name", null],
            'empty index' => ['/x', ['index' => ''], "App option 'index' must be a file name", null],
            'index with a colon' => ['/x', ['index' => 'in:dex.html'], "App option 'index' must be a file name", null],
            'hidden index' => ['/x', ['index' => '.index.html'], "App option 'index' must be a file name", null],
            'index that is no string' => ['/x', ['index' => true], "App option 'index' must be a file name", null],
            'types that are no map' => ['/x', ['types' => 'md'], "App option 'types' must map extensions to content types", null],
            'extension with a dot' => ['/x', ['types' => ['.md' => 'text/markdown']], "App option 'types' must map extensions (lowercase, without dot) to content types", null],
            'extension in capitals' => ['/x', ['types' => ['MD' => 'text/markdown']], "App option 'types' must map extensions (lowercase, without dot) to content types", null],
            // Found at registration, not as a 500 at the first request
            'content type with a line break' => ['/x', ['types' => ['js' => "text/javascript\r\nX-Injected: 1"]], "App option 'types' must map extensions (lowercase, without dot) to content types", null],
            'content type with a NUL' => ['/x', ['types' => ['js' => "text/javascript\0"]], "App option 'types' must map extensions (lowercase, without dot) to content types", null],
            'content type that is no string' => ['/x', ['types' => ['js' => 1]], "App option 'types' must map extensions (lowercase, without dot) to content types", null],
            'cache value with a NUL' => ['/x', ['cacheIndex' => "no-cache\0evil"], "App option 'cacheIndex' must be a Cache-Control value or null", null],
            'cache value with a DEL' => ['/x', ['cacheOther' => "no-cache\x7F"], "App option 'cacheOther' must be a Cache-Control value or null", null],
            'cache value with a tab' => ['/x', ['cacheImmutable' => "public,\tmax-age=60"], "App option 'cacheImmutable' must be a Cache-Control value or null", null],
            'empty content type' => ['/x', ['types' => ['md' => '']], "App option 'types' must map extensions (lowercase, without dot) to content types", null],
            'list of extensions' => ['/x', ['types' => ['md']], "App option 'types' must map extensions (lowercase, without dot) to content types", null],
            'extension with a line break' => ['/x', ['types' => ["md\n" => 'text/markdown']], "App option 'types' must map extensions (lowercase, without dot) to content types", null],
            'PHP source put on the list' => ['/x', ['types' => ['php' => 'text/plain']], "App option 'types' cannot put PHP sources on the list", 'php'],
            'PHP source put on the list, other extension' => ['/x', ['types' => ['phtml' => 'text/html']], "App option 'types' cannot put PHP sources on the list", 'phtml'],
            'PHP source put on the list, version in the extension' => ['/x', ['types' => ['php6' => 'text/plain']], "App option 'types' cannot put PHP sources on the list", 'php6'],
            'PHP source put on the list, two digits' => ['/x', ['types' => ['php74' => 'text/plain']], "App option 'types' cannot put PHP sources on the list", 'php74'],
            'PHP source put on the list, pht' => ['/x', ['types' => ['pht' => 'text/plain']], "App option 'types' cannot put PHP sources on the list", 'pht'],
            'PHP source put on the list, phps' => ['/x', ['types' => ['phps' => 'text/plain']], "App option 'types' cannot put PHP sources on the list", 'phps'],
            'PHP test file put on the list' => ['/x', ['types' => ['phpt' => 'text/plain']], "App option 'types' cannot put PHP sources on the list", 'phpt'],
            'PHP archive put on the list' => ['/x', ['types' => ['phar' => 'application/octet-stream']], "App option 'types' cannot put PHP sources on the list", 'phar'],
            'content type of blanks' => ['/x', ['types' => ['js' => '   ']], "App option 'types' must map extensions (lowercase, without dot) to content types", null],
            'cache value of blanks' => ['/x', ['cacheOther' => '  '], "App option 'cacheOther' must be a Cache-Control value or null", null],
            'PHP include put on the list' => ['/x', ['types' => ['inc' => 'text/plain']], "App option 'types' cannot put PHP sources on the list", 'inc'],
            'prefix whose segment ends in a dot' => ['/a./b', [], 'App prefix must be a plain path', '/a./b'],
            'index that ends in a space' => ['/x', ['index' => 'index.html '], "App option 'index' must be a file name", null],
            'broken regular expression' => ['/x', ['immutable' => '~[~'], "App option 'immutable' must be a regular expression or null", null],
            'immutable that is no string' => ['/x', ['immutable' => true], "App option 'immutable' must be a regular expression or null", null],
            'cache value with a line break' => ['/x', ['cacheIndex' => "no-cache\r\nX-Injected: 1"], "App option 'cacheIndex' must be a Cache-Control value or null", null],
            'empty cache value' => ['/x', ['cacheOther' => ''], "App option 'cacheOther' must be a Cache-Control value or null", null],
            'cache value that is no string' => ['/x', ['cacheImmutable' => 60], "App option 'cacheImmutable' must be a Cache-Control value or null", null],
        ];
    }

    /**
     * @param array<mixed> $options
     */
    #[DataProvider('registrationsThatAreRefused')]
    public function testRegistrationIsRefused(string $prefix, array $options, string $message, ?string $debugMessage): void
    {
        $directory = match (true) {
            str_starts_with((string) $debugMessage, 'DIR/') => $this->base . substr((string) $debugMessage, 3),
            default => $this->app,
        };

        try {
            /** @phpstan-ignore argument.type */
            Router::create()->app($prefix, $directory, $options);
            $this->fail('The registration was accepted');
        } catch (RouterException $e) {
            // The message names no value from the configuration; the debug message may
            $this->assertSame($message, $e->getMessage());
            $this->assertSame(
                str_starts_with((string) $debugMessage, 'DIR/') ? $directory : $debugMessage,
                $e->getDebugMessage()
            );
        }
    }

    public function testColonInThePrefixIsPartOfTheAddress(): void
    {
        // Only the path below the prefix is kept away from the file system
        $router = Router::create(['debug' => false])->loadRoutes($this->routesFile)->app('/urn:isbn', $this->app);

        $this->assertSame('p{}', (string) $this->get($router, '/urn:isbn/assets/style.css')->getBody());
        $this->assertSame(404, $this->get($router, '/urn:isbn/colon:name.js')->getStatusCode());
    }

    public function testPrefixCanBeUsedOnce(): void
    {
        $router = Router::create()->app('/login', $this->app);

        try {
            // The same prefix, spelled differently
            $router->app('login/', $this->base . '/site');
            $this->fail('The second app was accepted');
        } catch (RouterException $e) {
            $this->assertSame('App prefix is already in use', $e->getMessage());
            $this->assertSame('login/', $e->getDebugMessage());
        }
    }

    // ==================== which files count as unchanging ====================

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function namesAndWhetherTheyCountAsHashed(): array
    {
        $hashed = [
            // Vite, Rollup, esbuild: hyphen and eight characters — letters only, '_' and '-' included
            'assets/index-wVeeiXs6.js',
            'assets/index-CaIQYQta.css',
            'assets/index-Ab-_12cd.js',
            'assets/polyfills-legacy-AbCdEfGh.js',
            'assets/chunks/vendor-4XZ7KQ2M.js',
            // webpack, Create React App, older Vite: dot and 8 to 32 hexadecimal digits
            'assets/app.deadbeef.js',
            'static/js/787.a1b2c3d4.chunk.js',
            'static/media/logo.6ce24c58023cc2f8fd88.svg',
            'static/css/main.0123456789abcdef0123456789abcdef.css',
            // The price of a rule by form: eight characters behind a hyphen look like a
            // hash. That is why the rule is not the default — and why hand-written files
            // do not belong into assets/ or static/ of an app that passes it (README).
            'assets/app-settings.js',
            'assets/chacha20-poly1305.js',
            'assets/user-12345678.png',
            'assets/release-20261002.json',
        ];
        $plain = [
            // Copied from public/ as they are — a year of caching would be real damage
            'apple-touch-icon-180x180.png',
            'android-chrome-192x192.png',
            'index-B1fQx9cD.css',
            'main.a1b2c3d4.chunk.js',
            'chacha20-poly1305.js',
            'user-12345678.png',
            'release-20261002.json',
            'other/assets/index-B1fQx9cD.css',
            'assetsx/index-B1fQx9cD.css',
            // In the bundler's directory, but not in its form
            'assets/apple-touch-icon-180x180.png',
            'assets/maskable-icon-512x512.png',
            'assets/og-image-2024.png',
            'assets/hero-1920x1080.jpg',
            'assets/logo-2024-10-01.png',
            'assets/photo-1234567890.jpg',
            'assets/roboto-v30-latin-regular.woff2',
            'assets/my-component-v2.js',
            'assets/jquery-3.7.1.min.js',
            'assets/app.min.js',
            // Eight letters behind a dot that are no hexadecimal digits
            'assets/app.settings.js',
            'assets/service-worker.js',
            'assets/chunk-vendors.js',
            'assets/app.4f9a2b1.js',
            'assets/app.0123456789abcdef0123456789abcdef0.js',
            'assets/main.4F9A2B1C.js',
            'assets/index-B1fQx9cDe.css',
            'assets/index-B1fQx9c.css',
            'assets/index_B1fQx9cD.css',
            'assets/B1fQx9cD.css',
            'assets/-B1fQx9cD.css',
        ];

        $cases = [];
        foreach ($hashed as $path) {
            $cases[$path] = [$path, true];
        }
        foreach ($plain as $path) {
            $cases[$path] = [$path, false];
        }

        return $cases;
    }

    #[DataProvider('namesAndWhetherTheyCountAsHashed')]
    public function testOnlyWhatABundlerHashedIsCachedForAYear(string $path, bool $hashed): void
    {
        @mkdir(dirname($this->app . '/' . $path), 0o777, true);
        file_put_contents($this->app . '/' . $path, '//');

        $response = $this->get($this->routerThatTrustsHashedNames(), '/login/' . $path);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            $hashed ? 'public, max-age=31536000, immutable' : 'no-cache',
            $response->getHeaderLine('Cache-Control')
        );
    }
}
