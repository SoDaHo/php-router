<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Integration;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sodaho\Router\Cache\RouteCache;
use Sodaho\Router\Exception\CacheException;
use Sodaho\Router\Response;
use Sodaho\Router\Router;

/**
 * What a route carries must be the same whether it comes from the routes file or from the
 * cache. A cache that drops middleware removes the authentication of every cached route —
 * and nothing else would notice.
 */
class CacheRoundTripTest extends TestCase
{
    private const KEY = 'round-trip-key';

    private string $routesFile;
    private string $cacheFile;

    protected function setUp(): void
    {
        $this->routesFile = sys_get_temp_dir() . '/router_roundtrip_routes_' . uniqid() . '.php';
        $this->cacheFile = sys_get_temp_dir() . '/router_roundtrip_cache_' . uniqid() . '.php';
    }

    protected function tearDown(): void
    {
        foreach ([$this->routesFile, $this->cacheFile] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    private function writeRoutes(string $body): void
    {
        file_put_contents(
            $this->routesFile,
            "<?php\nuse Sodaho\\Router\\Tests\\Integration\\{RoundTripController, RoundTripGate, RoundTripTag};\n"
            . "return function (Sodaho\\Router\\RouteCollector \$r) {\n{$body}\n};"
        );
    }

    /**
     * @param list<array<string, mixed>> $errors
     */
    private function router(array &$errors = []): Router
    {
        return Router::create(['debug' => false])
            ->enableCache($this->cacheFile, self::KEY)
            ->loadRoutes($this->routesFile)
            ->on('error', function (array $data) use (&$errors): void {
                $errors[] = $data;
            });
    }

    /**
     * Warms the cache, then removes the routes file: whatever answers afterwards came from the cache.
     */
    private function routerFromCacheOnly(): Router
    {
        $errors = [];
        $this->router($errors)->handle(new ServerRequest('GET', '/warm-up'));
        $this->assertSame([], $errors);
        $this->assertFileExists($this->cacheFile);

        unlink($this->routesFile);

        return $this->router();
    }

    public function testMiddlewareNameCastsAndRedirectsSurviveTheCache(): void
    {
        $this->writeRoutes(
            <<<'PHP'
                    $r->middlewareGroup(RoundTripGate::class, function ($r) {
                        $r->get('/private/{id:int}', [RoundTripController::class, 'show'])->name('private.show');
                    });
                    $r->get('/public/{id:int}', [RoundTripController::class, 'show'])->middleware(new RoundTripTag('tagged'));
                    $r->get('/static', [RoundTripController::class, 'show'])->middleware(RoundTripGate::class);
                    $r->redirect('/old/{id}', '/public/{id}', 301);
                    $r->attributeGroup(['format' => 'oauth'], function ($r) {
                        $r->get('/tagged/{id}', [RoundTripController::class, 'show'])->attribute('cors', true);
                    });
                PHP
        );

        $router = $this->routerFromCacheOnly();

        // class-string middleware on a dynamic and on a static route: still in front of the handler
        foreach (['/private/5', '/static'] as $path) {
            $denied = $router->handle(new ServerRequest('GET', $path));
            $this->assertSame(401, $denied->getStatusCode(), $path);

            $allowed = $router->handle((new ServerRequest('GET', $path))->withHeader('X-Pass', '1'));
            $this->assertSame(200, $allowed->getStatusCode(), $path);
        }

        // middleware instance (no __set_state): state intact
        $tagged = $router->handle(new ServerRequest('GET', '/public/7'));
        $this->assertSame(200, $tagged->getStatusCode());
        $this->assertSame('tagged', $tagged->getHeaderLine('X-Tag'));

        // casts
        $this->assertSame(['id' => 7, 'type' => 'int'], json_decode((string) $tagged->getBody(), true)['data']);
        $this->assertSame(400, $router->handle(new ServerRequest('GET', '/public/' . str_repeat('9', 30)))->getStatusCode());

        // redirect handler
        $redirect = $router->handle(new ServerRequest('GET', '/old/a b'));
        $this->assertSame(301, $redirect->getStatusCode());
        $this->assertSame('/public/a%20b', $redirect->getHeaderLine('Location'));

        // names
        $this->assertSame('/private/9', $router->url('private.show', ['id' => 9]));

        // attributes
        $this->assertSame(
            ['format' => 'oauth', 'cors' => true],
            $router->match(new ServerRequest('GET', '/tagged/1'))->route?->attributes
        );

        // method table
        $notAllowed = $router->handle(new ServerRequest('POST', '/static'));
        $this->assertSame(405, $notAllowed->getStatusCode());
        $this->assertSame('GET', $notAllowed->getHeaderLine('Allow'));
    }

    public function testCacheFromAnEarlierVersionIsReplacedWithoutNoise(): void
    {
        $this->writeRoutes("    \$r->get('/users', [RoundTripController::class, 'show']);");

        // What 1.1.0 would have written for a different route table
        $export = var_export(['dispatchData' => [[], []], 'namedRoutes' => []], true);
        file_put_contents(
            $this->cacheFile,
            "<?php\n// HMAC-SHA256: " . hash_hmac('sha256', $export, self::KEY) . "\nreturn {$export};"
        );

        $errors = [];
        $response = $this->router($errors)->handle(new ServerRequest('GET', '/users'));

        $this->assertSame(200, $response->getStatusCode(), 'routes come from the routes file, not from the old cache');
        $this->assertSame([], $errors);
        $this->assertStringStartsWith('<?php __halt_compiler(); ?>', (string) file_get_contents($this->cacheFile));
        $this->assertIsArray((new RouteCache($this->cacheFile, self::KEY))->load());
    }

    public function testTamperedCacheIsReportedAndRebuilt(): void
    {
        $this->writeRoutes("    \$r->get('/users', [RoundTripController::class, 'show']);");
        $this->router()->handle(new ServerRequest('GET', '/users'));

        $valid = (string) file_get_contents($this->cacheFile);
        file_put_contents($this->cacheFile, str_replace('/users', '/admin', $valid));

        $errors = [];
        $router = $this->router($errors);

        $this->assertSame(200, $router->handle(new ServerRequest('GET', '/users'))->getStatusCode());
        $this->assertSame(404, $router->handle(new ServerRequest('GET', '/admin'))->getStatusCode());

        $this->assertCount(1, $errors);
        $this->assertSame('cache', $errors[0]['type']);
        $this->assertInstanceOf(CacheException::class, $errors[0]['exception']);
        $this->assertSame($valid, file_get_contents($this->cacheFile), 'the rebuilt file replaces the tampered one');
    }

    /**
     * Two workers, two processes. A class declared inside the routes file exists in the
     * process that wrote the cache and nowhere else: the second worker never runs the
     * routes file when the cache hits. It must fall back to the routes file, not dispatch
     * with an object of an unknown class — and say that this cache can never be used.
     */
    public function testClassDeclaredInTheRoutesFileDoesNotBreakTheNextWorker(): void
    {
        file_put_contents(
            $this->routesFile,
            <<<'ROUTES'
                <?php
                use Psr\Http\Message\ResponseInterface;
                use Psr\Http\Message\ServerRequestInterface;
                use Psr\Http\Server\MiddlewareInterface;
                use Psr\Http\Server\RequestHandlerInterface;

                final class DeclaredInRoutesFile implements MiddlewareInterface
                {
                    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                    {
                        return $handler->handle($request)->withHeader('X-Local', 'yes');
                    }
                }

                final class DeclaredInRoutesFileController
                {
                    public function show(): ResponseInterface
                    {
                        return Sodaho\Router\Response::success('ok');
                    }
                }

                return function (Sodaho\Router\RouteCollector $r) {
                    $r->get('/local', [DeclaredInRoutesFileController::class, 'show'])->middleware(new DeclaredInRoutesFile());
                };
                ROUTES
        );

        $worker = sprintf(
            'require %s;'
            . '$errors = "";'
            . '$response = Sodaho\Router\Router::create(["debug" => false])'
            . '->enableCache(%s, %s)->loadRoutes(%s)'
            . '->on("error", function (array $data) use (&$errors) { $errors .= $data["message"]; })'
            . '->handle(new Nyholm\Psr7\ServerRequest("GET", "/local"));'
            . 'echo $response->getStatusCode(), "|", $response->getHeaderLine("X-Local"), "|", $errors;',
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            var_export($this->cacheFile, true),
            var_export(self::KEY, true),
            var_export($this->routesFile, true)
        );

        $first = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($worker));
        $this->assertSame('200|yes|', $first);
        $this->assertFileExists($this->cacheFile);

        $second = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($worker));
        $this->assertSame('200|yes|Cache file is outdated', $second);
    }

    public function testCacheThatCannotBeWrittenIsReportedAndTheRequestServed(): void
    {
        $this->writeRoutes("    \$r->get('/users', [RoundTripController::class, 'show']);");

        // A path below a regular file: no directory can be created there, on any platform, as any user
        $this->cacheFile = $this->routesFile . '/cache/routes.php';

        // Up to 1.1.0 a failing write was a 500 on every request. After an upgrade with a
        // read-only cache directory that is exactly what would happen: the old file is a
        // miss and has to be rewritten.
        $errors = [];
        $router = $this->router($errors);

        $this->assertSame(200, $router->handle(new ServerRequest('GET', '/users'))->getStatusCode());
        $this->assertCount(1, $errors);
        $this->assertSame('cache', $errors[0]['type']);
        $this->assertInstanceOf(CacheException::class, $errors[0]['exception']);
        $this->assertStringContainsString('not writable', $errors[0]['message']);
    }

    /**
     * Anything can throw on the way to the cache — here an autoloader, asked for a class
     * the cache names. Whatever it is, the request is served from the routes file.
     */
    public function testExceptionOfTheApplicationWhileLoadingTheCacheDoesNotBreakTheRequest(): void
    {
        $this->writeRoutes("    \$r->get('/users', [RoundTripController::class, 'show']);");
        $this->router()->handle(new ServerRequest('GET', '/users'));

        // Same file, now naming a class only a (failing) autoloader could provide
        $payload = serialize(['dispatchData' => [[], []], 'namedRoutes' => [], 'x' => new \ArrayObject()]);
        $payload = str_replace('O:11:"ArrayObject"', 'O:11:"GoneForGood"', $payload);
        file_put_contents(
            $this->cacheFile,
            "<?php __halt_compiler(); ?>\nHMAC-SHA256: "
            . hash_hmac('sha256', "sodaho/php-router route-cache v2\n" . $payload, self::KEY) . "\n" . $payload
        );

        $autoloader = static function (string $class): void {
            if ($class === 'GoneForGood') {
                throw new \DomainException('autoloader gave up');
            }
        };
        spl_autoload_register($autoloader);

        try {
            $errors = [];
            $response = $this->router($errors)->handle(new ServerRequest('GET', '/users'));
        } finally {
            spl_autoload_unregister($autoloader);
        }

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $errors);
        $this->assertSame('cache', $errors[0]['type']);
        $this->assertInstanceOf(\DomainException::class, $errors[0]['exception']);
    }

    public function testExceptionOfTheApplicationWhileSavingTheCacheDoesNotBreakTheRequest(): void
    {
        // A stream wrapper that throws instead of failing: is_dir() on the cache directory
        // already ends in an exception that is neither Logic- nor CacheException.
        stream_wrapper_register('roundtrip-throws', ThrowingStreamWrapper::class);
        $this->writeRoutes("    \$r->get('/users', [RoundTripController::class, 'show']);");
        $localCacheFile = $this->cacheFile;
        $this->cacheFile = 'roundtrip-throws://cache/routes.php';

        try {
            $errors = [];
            $response = $this->router($errors)->handle(new ServerRequest('GET', '/users'));
        } finally {
            stream_wrapper_unregister('roundtrip-throws');
            $this->cacheFile = $localCacheFile;
        }

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $errors);
        $this->assertSame('cache', $errors[0]['type']);
        $this->assertSame('storage is gone', $errors[0]['message']);
    }

    public function testRoutesThatCannotBeSerializedAreServedUncached(): void
    {
        $this->writeRoutes("    \$r->get('/users', [RoundTripController::class, 'show'])->middleware(new class () extends RoundTripTag {});");

        $errors = [];
        $response = $this->router($errors)->handle(new ServerRequest('GET', '/users'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFileDoesNotExist($this->cacheFile);
        $this->assertCount(1, $errors);
        $this->assertSame('cache', $errors[0]['type']);
        $this->assertInstanceOf(\LogicException::class, $errors[0]['exception']);
        $this->assertStringContainsString('Cannot cache routes', $errors[0]['message']);
    }

    /**
     * The upgrade case: a cache directory the process may not write to (an image, another
     * deploy user), and an application whose error handler throws on warnings.
     */
    public function testReadOnlyCacheDirectoryDoesNotBreakTheRequestEvenWhenWarningsThrow(): void
    {
        $this->writeRoutes("    \$r->get('/users', [RoundTripController::class, 'show']);");

        $root = \org\bovigo\vfs\vfsStream::setup('deploy');
        \org\bovigo\vfs\vfsStream::newDirectory('cache', 0o000)->at($root);
        $this->cacheFile = \org\bovigo\vfs\vfsStream::url('deploy/cache/routes.php');

        // The blunt kind of handler: throws on every warning, silenced with @ or not
        $reporting = error_reporting(E_ALL);
        set_error_handler(static function (int $severity, string $message): bool {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            $errors = [];
            $response = $this->router($errors)->handle(new ServerRequest('GET', '/users'));
        } finally {
            restore_error_handler();
            error_reporting($reporting);
        }

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $errors);
        $this->assertSame('cache', $errors[0]['type']);
        $this->assertStringContainsString('Failed to write cache file', $errors[0]['message']);
    }
}

final class RoundTripController
{
    public function show(ServerRequestInterface $request, mixed $id = null): ResponseInterface
    {
        return Response::success(['id' => $id, 'type' => get_debug_type($id)]);
    }
}

final class RoundTripGate implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $request->hasHeader('X-Pass') ? $handler->handle($request) : Response::unauthorized();
    }
}

class RoundTripTag implements MiddlewareInterface
{
    public function __construct(private readonly string $tag = 'anonymous')
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)->withHeader('X-Tag', $this->tag);
    }
}

final class ThrowingStreamWrapper
{
    /** @var resource|null */
    public $context;

    /** @return array<string, int>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        // file_exists() on the cache file answers "no"; everything else gives up loudly
        if (str_ends_with($path, 'routes.php')) {
            return false;
        }

        // Not a LogicException: the router used to catch only those and CacheException here
        throw new \RuntimeException('storage is gone');
    }
}
