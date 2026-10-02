<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\Exception\CacheException;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\Router;

/**
 * Config precedence: $config > $_ENV > getenv() > default — for every option, and for
 * every way a value can arrive.
 */
class RouterConfigTest extends TestCase
{
    private const ENV_KEYS = [
        'APP_DEBUG', 'APP_ENV', 'APP_URL',
        'ROUTER_BASE_PATH', 'ROUTER_TRAILING_SLASH', 'ROUTER_CACHE_FILE', 'ROUTER_CACHE_KEY', 'ROUTER_URL_ENCODING',
    ];

    private string $routesFile;
    private string $cacheFile;

    /** @var array<string, array{env: mixed, getenv: string|false}> What the process had before the test */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        foreach (self::ENV_KEYS as $key) {
            $this->originalEnvironment[$key] = ['env' => $_ENV[$key] ?? null, 'getenv' => getenv($key)];
        }
        $this->clearEnvironment();

        $this->routesFile = sys_get_temp_dir() . '/router_config_routes_' . uniqid() . '.php';
        $this->cacheFile = sys_get_temp_dir() . '/router_config_cache_' . uniqid() . '.php';

        file_put_contents(
            $this->routesFile,
            <<<'PHP'
                <?php
                use Sodaho\Router\RouteCollector;

                return function (RouteCollector $r) {
                    $r->get('/', [Sodaho\Router\Tests\Unit\RouterConfigController::class, 'root']);
                    $r->get('/users', [Sodaho\Router\Tests\Unit\RouterConfigController::class, 'root']);
                    $r->get('/users/{id}', [Sodaho\Router\Tests\Unit\RouterConfigController::class, 'show'])->name('users.show');
                    $r->get('/boom', [Sodaho\Router\Tests\Unit\RouterConfigController::class, 'boom']);
                };
                PHP
        );
    }

    protected function tearDown(): void
    {
        $this->clearEnvironment();
        foreach ($this->originalEnvironment as $key => $original) {
            if ($original['env'] !== null) {
                $_ENV[$key] = $original['env'];
            }
            if ($original['getenv'] !== false) {
                putenv($key . '=' . $original['getenv']);
            }
        }

        foreach ([$this->routesFile, $this->cacheFile] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    private function clearEnvironment(): void
    {
        foreach (self::ENV_KEYS as $key) {
            unset($_ENV[$key]);
            putenv($key);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function router(array $config = []): Router
    {
        /** @phpstan-ignore argument.type */
        return Router::create($config)->loadRoutes($this->routesFile);
    }

    private function showsDebugDetails(Router $router): bool
    {
        $response = $router->handle(new ServerRequest('GET', '/boom'));
        $this->assertSame(500, $response->getStatusCode());

        $body = (string) $response->getBody();

        // Message, file and trace travel together — and none of them may leak when debug is off
        $leaksMessage = str_contains($body, 'secret detail');
        $leaksTrace = str_contains($body, '"trace"');
        $this->assertSame($leaksMessage, $leaksTrace);

        return $leaksMessage;
    }

    // ==================== debug ====================

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function environmentsThatTurnDebugOn(): array
    {
        return [
            'APP_ENV=local' => [['APP_ENV' => 'local']],
            'APP_ENV=dev' => [['APP_ENV' => 'dev']],
            'APP_ENV=development' => [['APP_ENV' => 'development']],
            'APP_DEBUG=true' => [['APP_DEBUG' => 'true']],
            'both' => [['APP_DEBUG' => 'true', 'APP_ENV' => 'local']],
        ];
    }

    /**
     * @param array<string, string> $env
     */
    #[DataProvider('environmentsThatTurnDebugOn')]
    public function testExplicitDebugFalseBeatsTheEnvironment(array $env): void
    {
        foreach ($env as $key => $value) {
            $_ENV[$key] = $value;
        }

        // Up to 1.1.0 `??` and `?:` in one expression let APP_ENV overrule an explicit false
        $this->assertFalse($this->showsDebugDetails($this->router(['debug' => false])));
    }

    /**
     * @param array<string, string> $env
     */
    #[DataProvider('environmentsThatTurnDebugOn')]
    public function testEnvironmentDecidesWithoutAnExplicitValue(array $env): void
    {
        foreach ($env as $key => $value) {
            $_ENV[$key] = $value;
        }

        $this->assertTrue($this->showsDebugDetails($this->router()));
        $this->assertTrue($this->showsDebugDetails($this->router(['debug' => null])), 'null means "not set"');
    }

    public function testDebugIsOffByDefault(): void
    {
        $this->assertFalse($this->showsDebugDetails($this->router()));

        $_ENV['APP_ENV'] = 'production';
        $_ENV['APP_DEBUG'] = 'false';
        $this->assertFalse($this->showsDebugDetails($this->router()));
    }

    public function testExplicitDebugTrueBeatsTheEnvironment(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_ENV['APP_DEBUG'] = 'false';

        $this->assertTrue($this->showsDebugDetails($this->router(['debug' => true])));
    }

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function debugValuesFromEnvFiles(): array
    {
        return [
            "'false'" => ['false', false],
            "'0'" => ['0', false],
            "'off'" => ['off', false],
            "''" => ['', false],
            '0' => [0, false],
            '0.0' => [0.0, false],
            '[]' => [[], false],
            "'true'" => ['true', true],
            "'1'" => ['1', true],
            "'on'" => ['on', true],
            '1' => [1, true],
        ];
    }

    /**
     * Config arrays are often filled from env files. 'false' used to end in a TypeError on
     * every request — with the trace in the response, because the string is truthy.
     */
    #[DataProvider('debugValuesFromEnvFiles')]
    public function testBooleanLikeDebugValuesAreUnderstood(mixed $value, bool $expected): void
    {
        $_ENV['APP_ENV'] = $expected ? 'production' : 'local';

        $router = $this->router(['debug' => $value]);

        $this->assertSame($expected, $this->showsDebugDetails($router));
        $this->assertSame(200, $router->handle(new ServerRequest('GET', '/users/5'))->getStatusCode());
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function debugValuesThatMeanNothing(): array
    {
        return [
            'word' => ['maybe', 'string'],
            'number' => [2, 'int'],
            'array' => [['true'], 'array'],
        ];
    }

    #[DataProvider('debugValuesThatMeanNothing')]
    public function testMeaninglessDebugValueIsRefusedAtConstruction(mixed $value, string $type): void
    {
        $this->expectException(RouterException::class);
        $this->expectExceptionMessage("Config 'debug' must be a boolean, got {$type}");

        /** @phpstan-ignore argument.type */
        Router::create(['debug' => $value]);
    }

    // ==================== basePath ====================

    /**
     * @return array<string, array{0: string}>
     */
    public static function spellingsOfTheSamePrefix(): array
    {
        return [
            '/api' => ['/api'],
            '/api/' => ['/api/'],
            'api' => ['api'],
            'api/' => ['api/'],
            '//api//' => ['//api//'],
        ];
    }

    #[DataProvider('spellingsOfTheSamePrefix')]
    public function testBasePathIsNormalizedOnEveryChannel(string $basePath): void
    {
        $_ENV['ROUTER_BASE_PATH'] = $basePath;

        $routers = [
            'config' => $this->router(['basePath' => $basePath, 'debug' => false]),
            'env' => $this->router(['debug' => false]),
            'setter' => $this->router(['basePath' => '/other', 'debug' => false])->setBasePath($basePath),
        ];

        foreach ($routers as $channel => $router) {
            $this->assertSame(200, $router->handle(new ServerRequest('GET', '/api/users/5'))->getStatusCode(), $channel);
            $this->assertSame(200, $router->handle(new ServerRequest('GET', '/api'))->getStatusCode(), "{$channel}: root route");
            $this->assertSame(404, $router->handle(new ServerRequest('GET', '/users/5'))->getStatusCode(), $channel);
            $this->assertSame(404, $router->handle(new ServerRequest('GET', '/apix/users/5'))->getStatusCode(), $channel);
            $this->assertSame('/api/users/5', $router->url('users.show', ['id' => 5]), $channel);
        }
    }

    #[DataProvider('spellingsOfNoPrefix')]
    public function testEmptyBasePathStaysEmpty(string $basePath): void
    {
        $router = $this->router(['basePath' => $basePath, 'debug' => false]);

        $this->assertSame(200, $router->handle(new ServerRequest('GET', '/users/5'))->getStatusCode());
        $this->assertSame('/users/5', $router->url('users.show', ['id' => 5]));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function spellingsOfNoPrefix(): array
    {
        return ["''" => [''], "'/'" => ['/'], "'//'" => ['//']];
    }

    public function testConfigBasePathBeatsTheEnvironment(): void
    {
        $_ENV['ROUTER_BASE_PATH'] = '/env';

        $router = $this->router(['basePath' => '/config', 'debug' => false]);

        $this->assertSame(200, $router->handle(new ServerRequest('GET', '/config/users'))->getStatusCode());
        $this->assertSame(404, $router->handle(new ServerRequest('GET', '/env/users'))->getStatusCode());
    }

    // ==================== environment lookup ====================

    public function testGetenvIsTheFallbackBehindEnv(): void
    {
        putenv('ROUTER_BASE_PATH=/from-getenv');

        $router = $this->router(['debug' => false]);
        $this->assertSame(200, $router->handle(new ServerRequest('GET', '/from-getenv/users'))->getStatusCode());

        $_ENV['ROUTER_BASE_PATH'] = '/from-env';

        $router = $this->router(['debug' => false]);
        $this->assertSame(200, $router->handle(new ServerRequest('GET', '/from-env/users'))->getStatusCode());
        $this->assertSame(404, $router->handle(new ServerRequest('GET', '/from-getenv/users'))->getStatusCode());
    }

    public function testNonScalarEnvValueIsNotAValue(): void
    {
        // $_ENV is a plain array; whatever an application parked there must not be cast to "Array"
        $_ENV['ROUTER_BASE_PATH'] = ['/api'];
        putenv('ROUTER_BASE_PATH=/from-getenv');

        $router = $this->router(['debug' => false]);

        $this->assertSame(200, $router->handle(new ServerRequest('GET', '/from-getenv/users'))->getStatusCode());
    }

    public function testTrailingSlashModeFromEnvironment(): void
    {
        $this->assertSame(404, $this->router(['debug' => false])->handle(new ServerRequest('GET', '/users/'))->getStatusCode());

        $_ENV['ROUTER_TRAILING_SLASH'] = 'ignore';
        $this->assertSame(200, $this->router(['debug' => false])->handle(new ServerRequest('GET', '/users/'))->getStatusCode());

        $this->assertSame(
            404,
            $this->router(['debug' => false, 'trailingSlash' => 'strict'])->handle(new ServerRequest('GET', '/users/'))->getStatusCode(),
            'config beats environment'
        );
    }

    public function testBaseUrlFromEnvironment(): void
    {
        $_ENV['APP_URL'] = 'https://env.example.com/';

        $this->assertSame('https://env.example.com/users/5', $this->router()->absoluteUrl('users.show', ['id' => 5]));
        $this->assertSame(
            'https://config.example.com/users/5',
            $this->router(['baseUrl' => 'https://config.example.com'])->absoluteUrl('users.show', ['id' => 5])
        );
    }

    public function testUrlEncodingFromEnvironment(): void
    {
        $this->assertSame('/users/a%20b', $this->router()->url('users.show', ['id' => 'a b']));

        $_ENV['ROUTER_URL_ENCODING'] = 'false';
        $this->assertSame('/users/a b', $this->router()->url('users.show', ['id' => 'a b']));
        $this->assertSame('/users/a%20b', $this->router(['urlEncoding' => true])->url('users.show', ['id' => 'a b']));
    }

    // ==================== cache ====================

    public function testCacheFileAndKeyFromEnvironment(): void
    {
        $_ENV['ROUTER_CACHE_FILE'] = $this->cacheFile;
        $_ENV['ROUTER_CACHE_KEY'] = 'key-from-env';

        $this->assertSame(200, $this->router(['debug' => false])->handle(new ServerRequest('GET', '/users'))->getStatusCode());
        $this->assertFileExists($this->cacheFile);
    }

    public function testEnableCacheWithoutKeyKeepsTheConfiguredKey(): void
    {
        // Up to 1.1.0 the setter overwrote the configured key with null: every request a 500
        $_ENV['ROUTER_CACHE_KEY'] = 'key-from-env';

        foreach ([['debug' => false], ['debug' => false, 'cacheSignature' => 'key-from-config']] as $config) {
            $errors = [];
            $router = $this->router($config)
                ->enableCache($this->cacheFile)
                ->on('error', function (array $data) use (&$errors): void {
                    $errors[] = $data;
                });

            $this->assertSame(200, $router->handle(new ServerRequest('GET', '/users'))->getStatusCode());
            $this->assertSame([], $errors);
            $this->assertFileExists($this->cacheFile);
            unlink($this->cacheFile);
        }
    }

    public function testEnableCacheWithKeyReplacesTheConfiguredKey(): void
    {
        $this->router(['debug' => false, 'cacheSignature' => 'first-key'])
            ->enableCache($this->cacheFile, 'second-key')
            ->handle(new ServerRequest('GET', '/users'));

        $errors = [];
        $this->router(['debug' => false])
            ->enableCache($this->cacheFile, 'first-key')
            ->on('error', function (array $data) use (&$errors): void {
                $errors[] = $data['exception'];
            })
            ->handle(new ServerRequest('GET', '/users'));

        $this->assertCount(1, $errors, 'the file was signed with the second key, not the first');
        $this->assertInstanceOf(CacheException::class, $errors[0]);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, string>}>
     */
    public static function cacheConfigurationsWithoutAUsableKey(): array
    {
        return [
            'no key at all' => [[], []],
            'empty key in the config array' => [['cacheSignature' => ''], []],
            // What an .env template with "ROUTER_CACHE_KEY=" leaves behind. With 1.1.0 that
            // signed with an empty key — anyone could have produced the same signature.
            'empty key in the environment' => [[], ['ROUTER_CACHE_KEY' => '']],
        ];
    }

    /**
     * The cache is an optimisation. Without a usable key it stays off and says so through
     * the error hook; it must not take the application down (which is what happened in
     * production only — in debug mode the cache is off anyway).
     *
     * @param array<string, mixed> $config
     * @param array<string, string> $env
     */
    #[DataProvider('cacheConfigurationsWithoutAUsableKey')]
    public function testCacheWithoutAUsableKeyStaysOffAndIsReported(array $config, array $env): void
    {
        foreach ($env as $key => $value) {
            $_ENV[$key] = $value;
        }

        $errors = [];
        $router = $this->router($config + ['debug' => false, 'cacheFile' => $this->cacheFile])
            ->on('error', function (array $data) use (&$errors): void {
                $errors[] = $data;
            });

        $this->assertSame(200, $router->handle(new ServerRequest('GET', '/users'))->getStatusCode());
        $this->assertSame('/users/5', $router->url('users.show', ['id' => 5]));

        $this->assertCount(1, $errors);
        $this->assertSame('cache', $errors[0]['type']);
        $this->assertInstanceOf(CacheException::class, $errors[0]['exception']);
        $this->assertStringContainsString('signature key is required', $errors[0]['message']);
        $this->assertFileDoesNotExist($this->cacheFile);
    }

    public function testEmptyCacheFileSwitchesTheCacheOffDespiteTheEnvironment(): void
    {
        $_ENV['ROUTER_CACHE_FILE'] = $this->cacheFile;
        $_ENV['ROUTER_CACHE_KEY'] = 'key-from-env';

        $this->assertSame(
            200,
            $this->router(['debug' => false, 'cacheFile' => '', 'cacheSignature' => ''])->handle(new ServerRequest('GET', '/users'))->getStatusCode()
        );
        $this->assertFileDoesNotExist($this->cacheFile);
    }
}

final class RouterConfigController
{
    public function root(): \Psr\Http\Message\ResponseInterface
    {
        return \Sodaho\Router\Response::success('ok');
    }

    public function show(mixed $request, string $id): \Psr\Http\Message\ResponseInterface
    {
        return \Sodaho\Router\Response::success(['id' => $id]);
    }

    public function boom(): never
    {
        throw new \RuntimeException('secret detail');
    }
}
