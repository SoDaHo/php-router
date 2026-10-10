<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\Router;

/**
 * Configuration: what the config array says counts, and nothing else. The environment
 * is read by Router::fromEnv() only — $_ENV, then the environment of the process — and
 * a key that is passed settles the matter for its variable.
 *
 * The environment of the process is getenv($name, true). That it leaves out what PHP-FPM
 * receives with the request cannot be shown here: on the command line both forms of
 * getenv() see the same.
 */
class RouterConfigTest extends TestCase
{
    private const ENV_KEYS = [
        'APP_DEBUG', 'APP_ENV', 'APP_URL',
        'ROUTER_BASE_PATH', 'ROUTER_TRAILING_SLASH', 'ROUTER_URL_ENCODING',
        // no longer read; still set by a test that proves it
        'ROUTER_CACHE_FILE', 'ROUTER_CACHE_KEY',
        'ROUTER_IMPLICIT_HEAD', 'ROUTER_EMIT_CHUNK_SIZE',
    ];

    private string $routesFile;

    /** @var array<string, array{env: mixed, getenv: string|false}> What the process had before the test */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        foreach (self::ENV_KEYS as $key) {
            $this->originalEnvironment[$key] = ['env' => $_ENV[$key] ?? null, 'getenv' => getenv($key)];
        }
        $this->clearEnvironment();

        $this->routesFile = sys_get_temp_dir() . '/router_config_routes_' . uniqid() . '.php';

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

        foreach ([$this->routesFile] as $file) {
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
        return Router::create($config)->loadRoutes($this->routesFile);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @phpstan-impure It reads the environment, which a test changes between two calls
     */
    private function routerFromEnv(array $config = []): Router
    {
        return Router::fromEnv($config)->loadRoutes($this->routesFile);
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

    // ==================== the environment is read by fromEnv() only ====================

    public function testConstructorAndCreateDoNotLookAtTheEnvironment(): void
    {
        $_ENV['APP_DEBUG'] = 'true';
        $_ENV['APP_ENV'] = 'local';
        $_ENV['APP_URL'] = 'https://env.example.com';
        $_ENV['ROUTER_BASE_PATH'] = '/env';
        $_ENV['ROUTER_TRAILING_SLASH'] = 'ignore';
        $_ENV['ROUTER_URL_ENCODING'] = 'false';
        putenv('APP_DEBUG=true');
        putenv('ROUTER_BASE_PATH=/env');

        foreach ([Router::create(), new Router(), Router::create([])] as $router) {
            $router->loadRoutes($this->routesFile);

            $this->assertFalse($router->isDebug());
            $this->assertFalse($this->showsDebugDetails($router));
            $this->assertSame(200, $router->handle(new ServerRequest('GET', '/users'))->getStatusCode());
            $this->assertSame(404, $router->handle(new ServerRequest('GET', '/env/users'))->getStatusCode());
            $this->assertSame(404, $router->handle(new ServerRequest('GET', '/users/'))->getStatusCode());
            $this->assertSame('/users/a%20b', $router->url('users.show', ['id' => 'a b']));

            try {
                $router->absoluteUrl('users.show', ['id' => 5]);
                $this->fail('A base URL came from somewhere');
            } catch (RouterException $e) {
                $this->assertStringContainsString('baseUrl is not configured', $e->getMessage());
            }
        }
    }

    public function testFromEnvReadsEveryVariableItNames(): void
    {
        $_ENV['APP_DEBUG'] = 'true';
        $_ENV['APP_URL'] = 'https://env.example.com/';
        $_ENV['ROUTER_BASE_PATH'] = 'env/';
        $_ENV['ROUTER_TRAILING_SLASH'] = 'ignore';
        $_ENV['ROUTER_URL_ENCODING'] = 'true';

        $router = $this->routerFromEnv();

        $this->assertTrue($router->isDebug());
        $this->assertSame(200, $router->handle(new ServerRequest('GET', '/env/users/'))->getStatusCode());
        $this->assertSame('/env/users/a%20b', $router->url('users.show', ['id' => 'a b']));
        $this->assertSame('https://env.example.com/env/users/5', $router->absoluteUrl('users.show', ['id' => 5]));
    }

    public function testAppEnvMeansNothingToTheRouter(): void
    {
        foreach (['local', 'dev', 'development'] as $appEnv) {
            $_ENV['APP_ENV'] = $appEnv;
            putenv("APP_ENV={$appEnv}");

            $this->assertFalse($this->routerFromEnv()->isDebug(), $appEnv);
            $this->assertFalse($this->showsDebugDetails($this->routerFromEnv()), $appEnv);
        }
    }

    /**
     * What $config contains wins — whatever the value. null is "the default", not "ask the
     * environment": the key is there, so the variable is not consulted.
     */
    public function testEveryKeyInTheConfigBeatsItsVariable(): void
    {
        $_ENV['APP_DEBUG'] = 'true';
        $_ENV['APP_URL'] = 'https://env.example.com';
        $_ENV['ROUTER_BASE_PATH'] = '/env';
        $_ENV['ROUTER_TRAILING_SLASH'] = 'ignore';
        $_ENV['ROUTER_URL_ENCODING'] = 'false';

        foreach ([
            'null' => ['debug' => null, 'baseUrl' => null, 'basePath' => null, 'trailingSlash' => null, 'urlEncoding' => null],
            'the defaults, spelled out' => ['debug' => false, 'baseUrl' => null, 'basePath' => '', 'trailingSlash' => 'strict', 'urlEncoding' => true],
        ] as $label => $config) {
            $router = $this->routerFromEnv($config);

            $this->assertFalse($router->isDebug(), $label);
            $this->assertSame(200, $router->handle(new ServerRequest('GET', '/users'))->getStatusCode(), $label);
            $this->assertSame(404, $router->handle(new ServerRequest('GET', '/users/'))->getStatusCode(), $label);
            $this->assertSame('/users/a%20b', $router->url('users.show', ['id' => 'a b']), $label);

            try {
                $router->absoluteUrl('users.show', ['id' => 5]);
                $this->fail("{$label}: the base URL came from the environment");
            } catch (RouterException) {
            }
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function flagVariablesWithValuesThatMeanNothing(): array
    {
        return [
            'APP_DEBUG=maybe' => ['APP_DEBUG', 'maybe'],
            'APP_DEBUG=2' => ['APP_DEBUG', '2'],
            'APP_DEBUG with a comment the env file left in' => ['APP_DEBUG', 'true # on for now'],
            'ROUTER_URL_ENCODING=sometimes' => ['ROUTER_URL_ENCODING', 'sometimes'],
        ];
    }

    /**
     * 1.x read such a value as "false" and said nothing. The message names the variable —
     * not the config key it feeds, and never the value.
     */
    #[DataProvider('flagVariablesWithValuesThatMeanNothing')]
    public function testVariableThatIsNotBooleanLikeIsRefused(string $variable, string $value): void
    {
        $_ENV[$variable] = $value;

        try {
            Router::fromEnv();
            $this->fail('The value was accepted');
        } catch (RouterException $e) {
            $this->assertSame(
                "Environment variable {$variable} must be boolean-like (true/false, 1/0, on/off, yes/no or empty)",
                $e->getMessage()
            );
        }

        // A key that is passed settles it: the variable is not looked at, so nothing throws
        // (urlEncoding takes nothing that means off: true, or null for its default)
        $key = $variable === 'APP_DEBUG' ? 'debug' : 'urlEncoding';
        foreach ([$key === 'debug' ? false : true, null] as $passed) {
            $router = $this->routerFromEnv([$key => $passed]);

            $this->assertFalse($router->isDebug());
            $this->assertSame('/users/a%20b', $router->url('users.show', ['id' => 'a b']));
        }
    }

    public function testEmptyDebugVariableIsOff(): void
    {
        $_ENV['APP_DEBUG'] = '';

        $router = $this->routerFromEnv();

        $this->assertFalse($router->isDebug());
        $this->assertSame('/users/a%20b', $router->url('users.show', ['id' => 'a b']));
    }

    /**
     * URL encoding cannot be turned off since 2.2.0 — off took every check of url() along.
     * Empty meant off as well; the message names the variable, never the value.
     *
     * @return array<int|string, array{0: string|int|bool}>
     */
    public static function urlEncodingVariablesThatMeanOff(): array
    {
        return [
            'false' => ['false'],
            'off' => ['off'],
            'no' => ['no'],
            '0' => ['0'],
            'empty' => [''],
            'the integer 0' => [0],
            'the boolean false' => [false],
        ];
    }

    #[DataProvider('urlEncodingVariablesThatMeanOff')]
    public function testUrlEncodingVariableThatMeansOffIsRefused(string|int|bool $value): void
    {
        $_ENV['ROUTER_URL_ENCODING'] = $value;

        try {
            Router::fromEnv();
            $this->fail('URL encoding was turned off');
        } catch (RouterException $e) {
            $this->assertSame(
                'Environment variable ROUTER_URL_ENCODING cannot turn URL encoding off: url() always encodes values and checks the address',
                $e->getMessage()
            );
        }

        // The key in the config settles it without asking the variable
        $this->assertSame('/users/a%20b', $this->routerFromEnv(['urlEncoding' => true])->url('users.show', ['id' => 'a b']));
    }

    // ==================== what is left of the cache ====================

    /**
     * 1.x read ROUTER_CACHE_FILE and ROUTER_CACHE_KEY. Set, they have no effect now. (The
     * config keys of the cache are refused like every key the router does not know, see
     * testUnknownConfigKeyIsRefused.)
     */
    public function testVariablesOfTheRemovedCacheHaveNoEffect(): void
    {
        $cacheFile = sys_get_temp_dir() . '/router_no_cache_' . uniqid() . '.php';
        $_ENV['ROUTER_CACHE_FILE'] = $cacheFile;
        $_ENV['ROUTER_CACHE_KEY'] = 'key-from-env';
        putenv('ROUTER_CACHE_FILE=' . $cacheFile);
        putenv('ROUTER_CACHE_KEY=key-from-env');

        // The config keys of the cache are refused (testUnknownConfigKeyIsRefused); the
        // variables are not read
        $routers = [
            'the variables, create()' => $this->router(['debug' => false]),
            'the variables, fromEnv()' => $this->routerFromEnv(['debug' => false]),
        ];

        foreach ($routers as $label => $router) {
            $this->assertSame(200, $router->handle(new ServerRequest('GET', '/users'))->getStatusCode(), $label);
            $this->assertSame(200, $router->handle(new ServerRequest('GET', '/users'))->getStatusCode(), "{$label}: second request");
            $this->assertFalse($router->isDebug(), $label);
        }

        $this->assertFileDoesNotExist($cacheFile);
    }

    // ==================== unknown keys ====================

    /**
     * @return array<string, array{0: array<int|string, mixed>, 1: string}>
     */
    public static function configsWithKeysTheRouterDoesNotKnow(): array
    {
        return [
            'a key of the removed cache' => [['cacheFile' => '/var/cache/routes.php', 'debug' => false], 'Unknown: cacheFile'],
            'a typo' => [['basepath' => '/api'], 'Unknown: basepath'],
            'several' => [['cacheFile' => '', 'cacheSignature' => 'secret-key'], 'Unknown: cacheFile, cacheSignature'],
            'a list instead of a map' => [['/api'], 'Unknown: 0'],
        ];
    }

    /**
     * @param array<int|string, mixed> $config
     */
    #[DataProvider('configsWithKeysTheRouterDoesNotKnow')]
    public function testUnknownConfigKeyIsRefused(array $config, string $debugMessage): void
    {
        foreach (['create', 'fromEnv'] as $factory) {
            try {
                Router::$factory($config);
                $this->fail("{$factory}() accepted it");
            } catch (RouterException $e) { // @phpstan-ignore catch.neverThrown (a variable static call PHPStan does not follow)
                // The message names what is allowed — never a value, and the keys only in the debug message
                $this->assertSame('Unknown config key. Known keys: debug, basePath, baseUrl, trailingSlash, routesFile, urlEncoding, implicitHead, emitChunkSize, emitIdleTimeout', $e->getMessage());
                $this->assertSame($debugMessage, $e->getDebugMessage());
                $this->assertStringNotContainsString('secret-key', $e->getMessage() . $e->getDebugMessage());
            }
        }
    }

    public function testEveryKnownKeyIsTaken(): void
    {
        $router = Router::create([
            'debug' => false, 'basePath' => '/api', 'baseUrl' => 'https://example.org', 'trailingSlash' => 'ignore',
            'routesFile' => null, 'urlEncoding' => true, 'implicitHead' => true, 'emitChunkSize' => 4096,
        ]);

        $this->assertFalse($router->isDebug());
    }

    // ==================== debug ====================

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function environmentsThatTurnDebugOn(): array
    {
        return [
            'APP_DEBUG=true' => [['APP_DEBUG' => 'true']],
            'APP_DEBUG=1' => [['APP_DEBUG' => '1']],
            'APP_DEBUG=on' => [['APP_DEBUG' => 'on']],
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

        $this->assertFalse($this->showsDebugDetails($this->routerFromEnv(['debug' => false])));
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

        $this->assertTrue($this->showsDebugDetails($this->routerFromEnv()));
    }

    public function testDebugIsOffByDefault(): void
    {
        $this->assertFalse($this->showsDebugDetails($this->router()));
        $this->assertFalse($this->showsDebugDetails($this->routerFromEnv()));

        $_ENV['APP_DEBUG'] = 'false';
        $this->assertFalse($this->showsDebugDetails($this->routerFromEnv()));

        $_ENV['APP_DEBUG'] = '';
        $this->assertFalse($this->showsDebugDetails($this->routerFromEnv()));
    }

    public function testExplicitDebugTrueBeatsTheEnvironment(): void
    {
        $_ENV['APP_DEBUG'] = 'false';

        $this->assertTrue($this->showsDebugDetails($this->routerFromEnv(['debug' => true])));
    }

    /**
     * @return array<int|string, array{0: mixed, 1: bool}>
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
            // PHP 8.5 warns when these are turned into a string; the router must get there first
            'NAN' => [NAN, 'float'],
            'INF' => [INF, 'float'],
            '-INF' => [-INF, 'float'],
        ];
    }

    #[DataProvider('debugValuesThatMeanNothing')]
    public function testMeaninglessDebugValueIsRefusedAtConstruction(mixed $value, string $type): void
    {
        $this->expectException(RouterException::class);
        $this->expectExceptionMessage("Config 'debug' must be a boolean, got {$type}");

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
            'env' => $this->routerFromEnv(['debug' => false]),
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

        $router = $this->routerFromEnv(['basePath' => '/config', 'debug' => false]);

        $this->assertSame(200, $router->handle(new ServerRequest('GET', '/config/users'))->getStatusCode());
        $this->assertSame(404, $router->handle(new ServerRequest('GET', '/env/users'))->getStatusCode());
    }

    // ==================== environment lookup ====================

    public function testGetenvIsTheFallbackBehindEnv(): void
    {
        putenv('ROUTER_BASE_PATH=/from-getenv');

        $router = $this->routerFromEnv(['debug' => false]);
        $this->assertSame(200, $router->handle(new ServerRequest('GET', '/from-getenv/users'))->getStatusCode());

        $_ENV['ROUTER_BASE_PATH'] = '/from-env';

        $router = $this->routerFromEnv(['debug' => false]);
        $this->assertSame(200, $router->handle(new ServerRequest('GET', '/from-env/users'))->getStatusCode());
        $this->assertSame(404, $router->handle(new ServerRequest('GET', '/from-getenv/users'))->getStatusCode());
    }

    public function testNonFiniteFloatInEnvIsNotAValueEither(): void
    {
        // PHP 8.5 warns when NAN is turned into a string — the suite fails on warnings
        foreach ([NAN, INF, -INF] as $float) {
            $_ENV['APP_DEBUG'] = $float;
            $_ENV['ROUTER_URL_ENCODING'] = $float;
            $_ENV['ROUTER_BASE_PATH'] = $float;
            putenv('ROUTER_BASE_PATH=/from-getenv');

            $router = $this->routerFromEnv();

            $this->assertFalse($router->isDebug());
            $this->assertSame('/from-getenv/users/a%20b', $router->url('users.show', ['id' => 'a b']));
        }
    }

    public function testScalarsInEnvCountWhateverTheirType(): void
    {
        // $_ENV is a plain array: an application (or a loader that casts) may put more than strings there
        $_ENV['APP_DEBUG'] = true;
        $_ENV['ROUTER_URL_ENCODING'] = true;

        $router = $this->routerFromEnv();

        $this->assertTrue($router->isDebug());
        $this->assertSame('/users/a%20b', $router->url('users.show', ['id' => 'a b']));

        $_ENV['APP_DEBUG'] = false;
        $_ENV['ROUTER_URL_ENCODING'] = 1;

        $router = $this->routerFromEnv();

        $this->assertFalse($router->isDebug());
        $this->assertSame('/users/a%20b', $router->url('users.show', ['id' => 'a b']));
    }

    public function testNonScalarEnvValueIsNotAValue(): void
    {
        // $_ENV is a plain array; whatever an application parked there must not be cast to "Array"
        $_ENV['ROUTER_BASE_PATH'] = ['/api'];
        putenv('ROUTER_BASE_PATH=/from-getenv');

        $router = $this->routerFromEnv(['debug' => false]);

        $this->assertSame(200, $router->handle(new ServerRequest('GET', '/from-getenv/users'))->getStatusCode());
    }

    public function testTrailingSlashModeFromEnvironment(): void
    {
        $this->assertSame(404, $this->routerFromEnv(['debug' => false])->handle(new ServerRequest('GET', '/users/'))->getStatusCode());

        $_ENV['ROUTER_TRAILING_SLASH'] = 'ignore';
        $this->assertSame(200, $this->routerFromEnv(['debug' => false])->handle(new ServerRequest('GET', '/users/'))->getStatusCode());

        $this->assertSame(
            404,
            $this->routerFromEnv(['debug' => false, 'trailingSlash' => 'strict'])->handle(new ServerRequest('GET', '/users/'))->getStatusCode(),
            'config beats environment'
        );
    }

    public function testBaseUrlFromEnvironment(): void
    {
        $_ENV['APP_URL'] = 'https://env.example.com/';

        $this->assertSame('https://env.example.com/users/5', $this->routerFromEnv()->absoluteUrl('users.show', ['id' => 5]));
        $this->assertSame(
            'https://config.example.com/users/5',
            $this->routerFromEnv(['baseUrl' => 'https://config.example.com'])->absoluteUrl('users.show', ['id' => 5])
        );
    }

    public function testUrlEncodingFromEnvironment(): void
    {
        $this->assertSame('/users/a%20b', $this->routerFromEnv()->url('users.show', ['id' => 'a b']));

        $_ENV['ROUTER_URL_ENCODING'] = 'on';
        $this->assertSame('/users/a%20b', $this->routerFromEnv()->url('users.show', ['id' => 'a b']));
    }

    /**
     * @return array<int|string, array{0: mixed}>
     */
    public static function urlEncodingValuesThatMeanOff(): array
    {
        return [
            'false' => [false],
            "'false'" => ['false'],
            "'off'" => ['off'],
            '0' => [0],
            "'0'" => ['0'],
            "'' (empty meant off)" => [''],
        ];
    }

    #[DataProvider('urlEncodingValuesThatMeanOff')]
    public function testUrlEncodingCannotBeTurnedOff(mixed $value): void
    {
        try {
            $this->router(['urlEncoding' => $value]);
            $this->fail('URL encoding was turned off');
        } catch (RouterException $e) {
            $this->assertSame("Config 'urlEncoding' cannot be turned off: url() always encodes values and checks the address", $e->getMessage());
        }
    }

    public function testUrlEncodingTakesValuesThatMeanOnAndRefusesTheRest(): void
    {
        $this->assertSame('/users/a%20b', $this->router(['urlEncoding' => true])->url('users.show', ['id' => 'a b']));
        $this->assertSame('/users/a%20b', $this->router(['urlEncoding' => '1'])->url('users.show', ['id' => 'a b']));
        $this->assertSame('/users/a%20b', $this->router(['urlEncoding' => 'yes'])->url('users.show', ['id' => 'a b']));
        $this->assertSame('/users/a%20b', $this->router(['urlEncoding' => null])->url('users.show', ['id' => 'a b']));

        $this->expectException(RouterException::class);
        $this->expectExceptionMessage("Config 'urlEncoding' must be a boolean, got string");
        $this->router(['urlEncoding' => 'sometimes']);
    }

    // ==================== 1.2 ====================

    public function testIsDebugTellsWhatTheRouterDecided(): void
    {
        $this->assertFalse($this->router()->isDebug());
        $this->assertTrue($this->router(['debug' => true])->isDebug());
        $this->assertTrue($this->router()->setDebug(true)->isDebug());

        // The same answer the 500 response follows — nobody has to rebuild it outside
        $_ENV['APP_DEBUG'] = 'true';
        $this->assertTrue($this->routerFromEnv()->isDebug());
        $this->assertFalse($this->routerFromEnv(['debug' => false])->isDebug());
        $this->assertFalse($this->router()->isDebug());
    }

    public function testFromEnvReadsTheEnvironmentAndLetsPassedValuesWin(): void
    {
        $_ENV['APP_DEBUG'] = 'true';
        $_ENV['ROUTER_BASE_PATH'] = '/env';
        $_ENV['APP_URL'] = 'https://env.example.com';

        $router = Router::fromEnv()->loadRoutes($this->routesFile);
        $this->assertTrue($router->isDebug());
        $this->assertSame(200, $router->handle(new ServerRequest('GET', '/env/users'))->getStatusCode());
        $this->assertSame('https://env.example.com/env/users/5', $router->absoluteUrl('users.show', ['id' => 5]));

        $router = Router::fromEnv(['debug' => false, 'basePath' => '/config'])->loadRoutes($this->routesFile);
        $this->assertFalse($router->isDebug());
        $this->assertSame(200, $router->handle(new ServerRequest('GET', '/config/users'))->getStatusCode());
        $this->assertSame('https://env.example.com/config/users/5', $router->absoluteUrl('users.show', ['id' => 5]));
    }

    public function testImplicitHeadIsAConfigValueAndNotReadFromTheEnvironment(): void
    {
        $_ENV['ROUTER_IMPLICIT_HEAD'] = 'false';
        putenv('ROUTER_IMPLICIT_HEAD=false');

        // On by default, and no variable switches it off — fromEnv() does not read one for it
        $this->assertSame(200, $this->router(['debug' => false])->handle(new ServerRequest('HEAD', '/users'))->getStatusCode());
        $this->assertSame(200, $this->routerFromEnv(['debug' => false])->handle(new ServerRequest('HEAD', '/users'))->getStatusCode());
        $this->assertSame(
            405,
            $this->routerFromEnv(['debug' => false, 'implicitHead' => false])->handle(new ServerRequest('HEAD', '/users'))->getStatusCode()
        );
    }

    public function testImplicitHeadTakesBooleanLikeValuesAndRefusesTheRest(): void
    {
        $status = fn (mixed $value): int => $this->router(['debug' => false, 'implicitHead' => $value])
            ->handle(new ServerRequest('HEAD', '/users'))
            ->getStatusCode();

        // null is "not set": the default, which is on
        foreach ([true, 'true', '1', 1, 'on', null] as $on) {
            $this->assertSame(200, $status($on), var_export($on, true));
        }

        // 'false' from an env file is not "a non-empty string, so on"
        foreach ([false, 'false', '0', 0, 'off', '', []] as $off) {
            $this->assertSame(405, $status($off), var_export($off, true));
        }

        $this->expectException(RouterException::class);
        $this->expectExceptionMessage("Config 'implicitHead' must be a boolean, got string");
        $status('maybe');
    }

    // ==================== trailingSlash ====================

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function trailingSlashValuesThatAreRefused(): array
    {
        return [
            // 1.x took any other value: routes were registered as in 'ignore', requests
            // compared as in 'strict' — /users/ was a 404 in both spellings
            'capital letter' => ['Strict'],
            'another word' => ['redirect'],
            'true' => [true],
            'a number' => [1],
            'blank behind it' => ['ignore '],
        ];
    }

    #[DataProvider('trailingSlashValuesThatAreRefused')]
    public function testTrailingSlashModeHasToBeOneOfTheTwo(mixed $value): void
    {
        $this->expectException(RouterException::class);
        $this->expectExceptionMessage("Config 'trailingSlash' must be 'strict' or 'ignore'");

        Router::create(['trailingSlash' => $value]);
    }

    public function testTrailingSlashModeFromTheEnvironmentIsCheckedAsWell(): void
    {
        foreach (['loose', 'Strict', ' ', 'ignore '] as $value) {
            $_ENV['ROUTER_TRAILING_SLASH'] = $value;

            try {
                Router::fromEnv();
                $this->fail('The router was created');
            } catch (RouterException $e) {
                // Names the variable — the config key would send the reader to the wrong place
                $this->assertSame("Environment variable ROUTER_TRAILING_SLASH must be 'strict', 'ignore' or empty", $e->getMessage());
            }
        }
    }

    public function testEmptyTrailingSlashVariableMeansTheDefault(): void
    {
        // A line 'ROUTER_TRAILING_SLASH=' in a .env file: the default, as for the boolean variables
        $_ENV['ROUTER_TRAILING_SLASH'] = '';

        $router = Router::fromEnv()->loadRoutes($this->routesFile);

        $this->assertSame(200, $router->handle(new ServerRequest('GET', '/users'))->getStatusCode());
        $this->assertSame(404, $router->handle(new ServerRequest('GET', '/users/'))->getStatusCode());

        // … and so does an empty value in the config array, like null
        foreach ([Router::fromEnv(['trailingSlash' => '']), Router::create(['trailingSlash' => ''])] as $router) {
            $router->loadRoutes($this->routesFile);

            $this->assertSame(200, $router->handle(new ServerRequest('GET', '/users'))->getStatusCode());
            $this->assertSame(404, $router->handle(new ServerRequest('GET', '/users/'))->getStatusCode());
        }
    }

    public function testTrailingSlashVariableIsNotLookedAtWhenTheKeyIsPassed(): void
    {
        $_ENV['ROUTER_TRAILING_SLASH'] = 'loose';
        $_ENV['ROUTER_BASE_PATH'] = '/a/../b';

        $router = Router::fromEnv(['trailingSlash' => 'ignore', 'basePath' => '/api'])->loadRoutes($this->routesFile);

        $this->assertSame(200, $router->handle(new ServerRequest('GET', '/api/users/'))->getStatusCode());
    }

    public function testTrailingSlashModesThatAreAllowed(): void
    {
        $this->assertSame(404, $this->router(['trailingSlash' => null])->handle(new ServerRequest('GET', '/users/'))->getStatusCode());
        $this->assertSame(404, $this->router(['trailingSlash' => 'strict'])->handle(new ServerRequest('GET', '/users/'))->getStatusCode());
        $this->assertSame(200, $this->router(['trailingSlash' => 'ignore'])->handle(new ServerRequest('GET', '/users/'))->getStatusCode());
    }

    // ==================== basePath ====================

    /**
     * @return array<string, array{0: string}>
     */
    public static function basePathsThatAreRefused(): array
    {
        return [
            // The router has no route for such a request path, a client resolves or cuts
            // it — and url() would write an address that leaves the site
            'backslash' => ['\\evil.example'],
            'backslash inside' => ['/api\\v1'],
            'tab' => ["\t"],
            'line break' => ["/api\n"],
            'NUL' => ["/a\0b"],
            'DEL' => ["/a\x7Fb"],
            'encoded slash' => ['/a%2Fb'],
            'encoded backslash' => ['/a%5cb'],
            'query' => ['/api?x=1'],
            'fragment' => ['/api#top'],
            'parent segment' => ['/api/../admin'],
            'parent segment alone' => ['..'],
            'current segment' => ['/api/./v1'],
            'current segment at the end' => ['/api/.'],
            // Compared with the decoded path, '/my%20app' would wait for the request '/my%2520app'
            'percent-encoded blank' => ['/my%20app'],
            'percent-encoded letter, lower case' => ['/caf%c3%a9'],
        ];
    }

    #[DataProvider('basePathsThatAreRefused')]
    public function testBasePathHasToBeAPlainPath(string $basePath): void
    {
        $rule = ' must be a plain path, written decoded: no backslash, control character, percent-encoded character (%20), "?", "#" or dot segment';
        $message = "Config 'basePath'" . $rule;

        foreach ([fn () => Router::create(['basePath' => $basePath]), fn () => Router::create()->setBasePath($basePath)] as $configure) {
            try {
                $configure();
                $this->fail('The base path was accepted');
            } catch (RouterException $e) {
                // The message does not repeat the value
                $this->assertSame($message, $e->getMessage());
            }
        }

        // From the environment the message names the variable
        $_ENV['ROUTER_BASE_PATH'] = $basePath;
        $this->expectException(RouterException::class);
        $this->expectExceptionMessage('Environment variable ROUTER_BASE_PATH' . $rule);
        Router::fromEnv();
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function configValuesThatAreNoString(): array
    {
        return [
            // Up to 2.1.1 basePath was cast: true became '1', an array 'Array' with a warning
            'basePath true' => ['basePath', true],
            'basePath 123' => ['basePath', 123],
            'basePath array' => ['basePath', ['/api']],
            // … and a routesFile that is an array failed only when the table was built
            'routesFile array' => ['routesFile', ['routes.php']],
            'routesFile false' => ['routesFile', false],
            'routesFile 0' => ['routesFile', 0],
        ];
    }

    #[DataProvider('configValuesThatAreNoString')]
    public function testConfigValueThatMustBeAStringIsRefusedOtherwise(string $key, mixed $value): void
    {
        try {
            Router::create([$key => $value]);
            $this->fail('The router was created');
        } catch (RouterException $e) {
            $this->assertSame(sprintf("Config '%s' must be a string, got %s", $key, get_debug_type($value)), $e->getMessage());
        }
    }

    public function testBasePathAndRoutesFileTakeNullForTheirDefault(): void
    {
        $router = Router::create(['basePath' => null, 'routesFile' => null])->loadRoutes($this->routesFile);

        $this->assertSame(200, $router->handle(new ServerRequest('GET', '/users'))->getStatusCode());

        // As for baseUrl: false is what getenv() gives without the variable — no base path
        $router = Router::create(['basePath' => false])->loadRoutes($this->routesFile);
        $this->assertSame(200, $router->handle(new ServerRequest('GET', '/users'))->getStatusCode());
        $this->assertSame(200, Router::create(['routesFile' => $this->routesFile])->handle(new ServerRequest('GET', '/users'))->getStatusCode());
    }

    public function testBasePathsThatStayAllowed(): void
    {
        foreach (['' => '/users', '/' => '/users', '/api/' => '/api/users', 'api' => '/api/users', '/my app' => '/my app/users', '/über' => '/über/users', '/v1.2' => '/v1.2/users', '/a..b/.well-known' => '/a..b/.well-known/users', '/100%' => '/100%/users', '/5%2' => '/5%2/users', '//' => '/users', '//api//' => '/api/users', '/api//v1' => '/api//v1/users'] as $basePath => $path) {
            $router = Router::create(['basePath' => (string) $basePath])->loadRoutes($this->routesFile);

            $this->assertSame(200, $router->handle(new ServerRequest('GET', str_replace(['%', ' '], ['%25', '%20'], $path)))->getStatusCode(), (string) $basePath);
        }
    }

    // ==================== what cannot be changed once the table is built ====================

    /**
     * @return array<string, array{0: string, 1: \Closure(Router): mixed}>
     */
    public static function waysToUseTheRouter(): array
    {
        return [
            'a request' => ['handle', fn (Router $router) => $router->handle(new ServerRequest('GET', '/users'))],
            'a lookup' => ['match', fn (Router $router) => $router->match(new ServerRequest('GET', '/users'))],
            'an address' => ['url', fn (Router $router) => $router->url('users.show', ['id' => 5])],
            'an absolute address' => ['absoluteUrl', fn (Router $router) => $router->absoluteUrl('users.show', ['id' => 5])],
        ];
    }

    #[DataProvider('waysToUseTheRouter')]
    public function testSettingsOfTheTableAreRefusedAfterTheFirstUse(string $how, \Closure $use): void
    {
        $router = $this->router();
        // Before the first use everything can still be set
        $router->setDebug(false)->setBasePath('')->setBaseUrl('https://example.org')->loadRoutes($this->routesFile);
        $use($router);

        $late = [
            'setBasePath' => fn () => $router->setBasePath('/api'),
            'setDebug' => fn () => $router->setDebug(true),
            'setBaseUrl' => fn () => $router->setBaseUrl('https://late.example'),
            'loadRoutes' => fn () => $router->loadRoutes($this->routesFile),
        ];

        foreach ($late as $method => $call) {
            try {
                // 1.x accepted the call: no effect at all, or half of one
                $call();
                $this->fail($method . '() was accepted after ' . $how);
            } catch (RouterException $e) {
                $this->assertSame(
                    $method . '() has to be called before the routing table is built: the first request, match(), url() or absoluteUrl() built it',
                    $e->getMessage()
                );
            }
        }

        // … and nothing changed
        $this->assertFalse($router->isDebug());
        $this->assertSame(200, $router->handle(new ServerRequest('GET', '/users'))->getStatusCode());
        $this->assertSame(404, $router->handle(new ServerRequest('GET', '/api/users'))->getStatusCode());
    }

    public function testBaseUrlCanBeSetAfterTheRouterWasCreated(): void
    {
        // An application that reads its .env only after the router was built
        $router = $this->router()->loadRoutes($this->routesFile)->setBaseUrl('https://example.org');
        $this->assertSame('https://example.org/users/5', $router->absoluteUrl('users.show', ['id' => 5]));

        // Empty takes it away again, as for the config key
        foreach ([null, '', '0'] as $none) {
            $router = $this->router(['baseUrl' => 'https://example.org'])->loadRoutes($this->routesFile)->setBaseUrl($none);
            $this->assertSame('/users/5', $router->url('users.show', ['id' => 5]));
            try {
                $router->absoluteUrl('users.show', ['id' => 5]);
                $this->fail('An absolute address without a base URL');
            } catch (RouterException $e) {
                $this->assertStringContainsString('call setBaseUrl()', $e->getMessage());
            }
        }
    }

    public function testEmptyBaseUrlMeansNoneAsIn1x(): void
    {
        // 'baseUrl' => getenv('APP_URL') without the variable is false
        foreach ([null, false, '', '0', 0] as $none) {
            $router = $this->router(['baseUrl' => $none])->loadRoutes($this->routesFile);
            $label = var_export($none, true);
            $this->assertSame('/users/5', $router->url('users.show', ['id' => 5]), $label);
            try {
                $router->absoluteUrl('users.show', ['id' => 5]);
                $this->fail("An absolute address for {$label}");
            } catch (RouterException $e) {
                $this->assertStringContainsString('baseUrl is not configured', $e->getMessage(), $label);
            }
        }
    }

    public function testBaseUrlOfAnotherTypeIsRefused(): void
    {
        foreach ([['https://example.org'], 5, true, 1.5] as $value) {
            try {
                /** @phpstan-ignore argument.type (a type the config refuses, on purpose) */
                Router::create(['baseUrl' => $value]);
                $this->fail('Accepted ' . var_export($value, true));
            } catch (RouterException $e) {
                $this->assertSame("Config 'baseUrl' must be a string, or empty for none", $e->getMessage());
            }
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function baseUrlsNoAddressCanBeginWith(): array
    {
        return [
            'line break' => ["https://x.example\r\nX-Evil: 1"],
            'line feed at the end' => ["https://x.example\n"],
            'NUL' => ["https://x.example\0"],
            'DEL' => ["https://x.\x7Fexample"],
            'tab' => ["https://x.example\t"],
            'blank in front' => [' https://x.example'],
            'blank inside' => ['https://x example'],
        ];
    }

    /**
     * Put in front of every absolute address as it is: a line break in it made each a
     * Location header the response refuses, a blank an address that is none
     */
    #[DataProvider('baseUrlsNoAddressCanBeginWith')]
    public function testBaseUrlWithAControlCharacterOrABlankIsRefused(string $value): void
    {
        $message = "Config 'baseUrl' must not contain a control character or a blank";

        // From the environment, the message names the variable
        $_ENV['APP_URL'] = $value;
        try {
            Router::fromEnv();
            $this->fail('Accepted through APP_URL');
        } catch (RouterException $e) {
            $this->assertSame('Environment variable APP_URL must not contain a control character or a blank', $e->getMessage());
        } finally {
            unset($_ENV['APP_URL']);
        }

        foreach ([
            'config' => fn () => Router::create(['baseUrl' => $value]),
            'setBaseUrl' => fn () => Router::create()->setBaseUrl($value),
            'config through fromEnv()' => fn () => Router::fromEnv(['baseUrl' => $value]),
        ] as $way => $create) {
            try {
                $create();
                $this->fail('Accepted through ' . $way);
            } catch (RouterException $e) {
                $this->assertSame($message, $e->getMessage(), $way);
            }
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function baseUrlsThatAreNoAddressOfAHost(): array
    {
        return [
            // Up to 2.1.1 put in front of every address as they are
            'no scheme' => ['example.com'],
            'scheme-relative' => ['//evil.example'],
            'another scheme' => ['javascript:alert(1)'],
            'ftp' => ['ftp://files.example'],
            'no host' => ['https://'],
            'no host, a path' => ['https:///path'],
            'a query' => ['https://app.example?x=1'],
            'a query behind a path' => ['https://app.example/base?x=1'],
            'a fragment' => ['https://app.example/#top'],
            // An authority that is no host, or names another one for a browser (WHATWG URL)
            'a port and no host' => ['https://:443'],
            'user information and no host' => ['https://user@/base'],
            'an IPv6 host that is not closed' => ['https://[::1'],
            'an at sign alone' => ['https://@'],
            'a port that is no number' => ['https://example.com:bad'],
            'a port beyond 65535' => ['https://example.com:65536'],
            'port 0' => ['https://example.com:0'],
            // The parser takes an empty port as none — written, it is no port of digits
            'an empty port' => ['https://example.com:'],
            'an empty port in front of a path' => ['https://example.com:/base'],
            'an empty port behind an IPv6 host' => ['https://[::1]:'],
            'a backslash: the host is evil for a browser' => ['https://evil\\@trusted.example/base'],
            'a backslash in front of the path' => ['https://\\/login'],
            'a backslash in the path' => ['https://app.example/a\\b'],
            'user information' => ['https://user:secret@app.example'],
        ];
    }

    /**
     * An absolute address begins with the base URL: one that is no http(s) address of a
     * host made every address relative ('example.com/users/5'), another host's or a script
     * ('javascript:alert(1)/users/5'), or put the path behind a query or a fragment
     */
    #[DataProvider('baseUrlsThatAreNoAddressOfAHost')]
    public function testBaseUrlThatIsNoAddressOfAHostIsRefused(string $value): void
    {
        $_ENV['APP_URL'] = $value;
        try {
            Router::fromEnv();
            $this->fail('Accepted through APP_URL');
        } catch (RouterException $e) {
            $this->assertSame('Environment variable APP_URL must be an address of a host: http:// or https://, the host, a port and a path at most — no user information, query or fragment', $e->getMessage());
            $this->assertSame($value, $e->getDebugMessage());
        } finally {
            unset($_ENV['APP_URL']);
        }

        foreach ([
            'config' => fn () => Router::create(['baseUrl' => $value]),
            'setBaseUrl' => fn () => Router::create()->setBaseUrl($value),
        ] as $way => $create) {
            try {
                $create();
                $this->fail('Accepted through ' . $way);
            } catch (RouterException $e) {
                $this->assertSame("Config 'baseUrl' must be an address of a host: http:// or https://, the host, a port and a path at most — no user information, query or fragment", $e->getMessage(), $way);
            }
        }
    }

    public function testBaseUrlOfAHostIsTaken(): void
    {
        foreach ([
            'https://app.example' => 'https://app.example/users/5',
            'HTTP://app.example:8080/' => 'HTTP://app.example:8080/users/5',
            'https://app.example/base/' => 'https://app.example/base/users/5',
            'https://[::1]:8443' => 'https://[::1]:8443/users/5',
            'https://bücher.example' => 'https://bücher.example/users/5',
            'https://xn--bcher-kva.example' => 'https://xn--bcher-kva.example/users/5',
            'https://a.example./' => 'https://a.example./users/5',
            'https://app.example:65535' => 'https://app.example:65535/users/5',
            // Written otherwise than the parser writes them, and taken as in 2.1.1: for a
            // browser they name the host they are written with
            'https://Bücher.example' => 'https://Bücher.example/users/5',
            'https://[0:0:0:0:0:0:0:1]' => 'https://[0:0:0:0:0:0:0:1]/users/5',
            'https://[::ffff:192.0.2.128]' => 'https://[::ffff:192.0.2.128]/users/5',
            'https://127.1' => 'https://127.1/users/5',
        ] as $baseUrl => $address) {
            $this->assertSame($address, $this->router(['baseUrl' => $baseUrl])->absoluteUrl('users.show', ['id' => 5]), $baseUrl);
        }
    }

    // ==================== emitChunkSize ====================

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function chunkSizesThatAreRefused(): array
    {
        return [
            'zero' => [0],
            'negative' => [-8192],
            'below the minimum' => [1023],
            'above the maximum' => [16 * 1024 * 1024 + 1],
            'a float' => [8192.5],
            'a float that looks whole' => [8192.0],
            'a word' => ['big'],
            'a number with a unit' => ['64K'],
            'an exponent' => ['1e5'],
            'true' => [true],
            'an array' => [[8192]],
            'an empty string' => [''],
            // An env file makes '65536' of the number — and nothing else counts as one
            'a space in front' => [' 65536'],
            'a sign' => ['+65536'],
            'a line break behind' => ["65536\n"],
            'more digits than an integer holds' => ['99999999999999999999999'],
        ];
    }

    #[DataProvider('chunkSizesThatAreRefused')]
    public function testChunkSizeOutsideItsRangeIsRefusedAtConstruction(mixed $value): void
    {
        $this->expectException(RouterException::class);
        $this->expectExceptionMessage("Config 'emitChunkSize' must be an integer between 1024 and 16777216");

        Router::create(['emitChunkSize' => $value]);
    }

    public function testChunkSizeIsNotReadFromTheEnvironment(): void
    {
        $_ENV['ROUTER_EMIT_CHUNK_SIZE'] = 'big';
        putenv('ROUTER_EMIT_CHUNK_SIZE=big');

        try {
            $this->assertInstanceOf(Router::class, Router::fromEnv());
        } finally {
            unset($_ENV['ROUTER_EMIT_CHUNK_SIZE']);
            putenv('ROUTER_EMIT_CHUNK_SIZE');
        }
    }

    // ==================== emitIdleTimeout ====================

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function idleTimeoutsThatAreRefused(): array
    {
        return [
            // 0 gave up at the first empty read, which a stream gives while bytes are on their way
            'zero' => [0],
            'zero as a float' => [0.0],
            'zero as a string' => ['0'],
            'negative' => [-1],
            'above an hour' => [3601],
            'just above an hour' => [3600.5],
            'a microsecond above an hour' => ['3600.000001'],
            // A string with more decimals than the microsecond would be rounded before the
            // comparison — this one is the float 3600.0
            'above an hour in decimals a float does not hold' => ['3600.0000000000000000000000000001'],
            'more decimals than microseconds' => ['0.0000001'],
            'a point and no decimals' => ['30.'],
            'infinite' => [INF],
            'not a number' => [NAN],
            'a word' => ['long'],
            'a number with a unit' => ['30s'],
            'an exponent' => ['1e3'],
            'a space in front' => [' 30'],
            'a sign' => ['+30'],
            'an empty string' => [''],
            'true' => [true],
            'an array' => [[30]],
        ];
    }

    #[DataProvider('idleTimeoutsThatAreRefused')]
    public function testIdleTimeoutOutsideItsRangeIsRefusedAtConstruction(mixed $value): void
    {
        $this->expectException(RouterException::class);
        $this->expectExceptionMessage("Config 'emitIdleTimeout' must be a number of seconds above 0 and at most 3600");

        Router::create(['emitIdleTimeout' => $value]);
    }

    public function testIdleTimeoutIsANumberOfSecondsUpToAnHour(): void
    {
        foreach ([30, 0.5, '0.5', '30', 3600, '3600.000000', '0.000001', null] as $value) {
            $this->assertInstanceOf(Router::class, Router::create(['emitIdleTimeout' => $value]), var_export($value, true));
        }
    }

    public function testIdleTimeoutIsNotReadFromTheEnvironment(): void
    {
        $_ENV['ROUTER_EMIT_IDLE_TIMEOUT'] = 'never';
        putenv('ROUTER_EMIT_IDLE_TIMEOUT=never');

        try {
            $this->assertInstanceOf(Router::class, Router::fromEnv());
        } finally {
            unset($_ENV['ROUTER_EMIT_IDLE_TIMEOUT']);
            putenv('ROUTER_EMIT_IDLE_TIMEOUT');
        }
    }
}

/** A controller for the routes files of these tests: an answer, a parameter, an exception. */
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
