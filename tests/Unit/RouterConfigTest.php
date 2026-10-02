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
 * is read by Router::fromEnv() only — $_ENV, then getenv() — and a key that is passed
 * settles the matter for its variable.
 */
class RouterConfigTest extends TestCase
{
    private const ENV_KEYS = [
        'APP_DEBUG', 'APP_ENV', 'APP_URL',
        'ROUTER_BASE_PATH', 'ROUTER_TRAILING_SLASH', 'ROUTER_URL_ENCODING',
        // no longer read; still set by a test that proves it
        'ROUTER_CACHE_FILE', 'ROUTER_CACHE_KEY',
        'ROUTER_IMPLICIT_HEAD',
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
        /** @phpstan-ignore argument.type */
        return Router::create($config)->loadRoutes($this->routesFile);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function routerFromEnv(array $config = []): Router
    {
        /** @phpstan-ignore argument.type */
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
        $_ENV['ROUTER_URL_ENCODING'] = 'false';

        $router = $this->routerFromEnv();

        $this->assertTrue($router->isDebug());
        $this->assertSame(200, $router->handle(new ServerRequest('GET', '/env/users/'))->getStatusCode());
        $this->assertSame('/env/users/a b', $router->url('users.show', ['id' => 'a b']));
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
        $key = $variable === 'APP_DEBUG' ? 'debug' : 'urlEncoding';
        foreach ([false, null] as $passed) {
            $router = $this->routerFromEnv([$key => $passed]);

            $this->assertFalse($router->isDebug());
            // false switches the encoding off, null is its default (on)
            $this->assertSame(
                $key === 'urlEncoding' && $passed === false ? '/users/a b' : '/users/a%20b',
                $router->url('users.show', ['id' => 'a b'])
            );
        }
    }

    public function testEmptyFlagVariableIsOff(): void
    {
        $_ENV['APP_DEBUG'] = '';
        $_ENV['ROUTER_URL_ENCODING'] = '';

        $router = $this->routerFromEnv();

        $this->assertFalse($router->isDebug());
        $this->assertSame('/users/a b', $router->url('users.show', ['id' => 'a b']));
    }

    // ==================== what is left of the cache ====================

    /**
     * 1.x applications pass 'cacheFile' => '' to keep the cache off against ROUTER_CACHE_*,
     * and some still carry a real cache configuration. None of it may stop the router from
     * starting, and none of it has an effect. (Config keys the router does not know are
     * ignored as in 1.x; a later 2.0 beta will refuse them.)
     */
    public function testKeysAndVariablesOfTheRemovedCacheHaveNoEffect(): void
    {
        $cacheFile = sys_get_temp_dir() . '/router_no_cache_' . uniqid() . '.php';
        $_ENV['ROUTER_CACHE_FILE'] = $cacheFile;
        $_ENV['ROUTER_CACHE_KEY'] = 'key-from-env';
        putenv('ROUTER_CACHE_FILE=' . $cacheFile);
        putenv('ROUTER_CACHE_KEY=key-from-env');

        $routers = [
            'the 1.x way to keep the cache off' => $this->router(['debug' => false, 'cacheFile' => '', 'cacheSignature' => '']),
            'a cache configuration' => $this->router(['debug' => false, 'cacheFile' => $cacheFile, 'cacheSignature' => 'key']),
            'the variables alone' => $this->routerFromEnv(['debug' => false]),
            'other keys the router does not know' => $this->router(['debug' => false, 0 => 'stray', 'DEBUG' => true, 'basepath' => '/typo']),
        ];

        foreach ($routers as $label => $router) {
            $this->assertSame(200, $router->handle(new ServerRequest('GET', '/users'))->getStatusCode(), $label);
            $this->assertSame(200, $router->handle(new ServerRequest('GET', '/users'))->getStatusCode(), "{$label}: second request");
            $this->assertFalse($router->isDebug(), $label);
        }

        $this->assertFileDoesNotExist($cacheFile);
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

        $_ENV['ROUTER_URL_ENCODING'] = 'false';
        $this->assertSame('/users/a b', $this->routerFromEnv()->url('users.show', ['id' => 'a b']));
        $this->assertSame('/users/a%20b', $this->routerFromEnv(['urlEncoding' => true])->url('users.show', ['id' => 'a b']));
    }

    public function testUrlEncodingTakesBooleanLikeValuesAndRefusesTheRest(): void
    {
        $this->assertSame('/users/a b', $this->router(['urlEncoding' => 'false'])->url('users.show', ['id' => 'a b']));
        $this->assertSame('/users/a b', $this->router(['urlEncoding' => 0])->url('users.show', ['id' => 'a b']));
        $this->assertSame('/users/a%20b', $this->router(['urlEncoding' => '1'])->url('users.show', ['id' => 'a b']));

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
        $_ENV['ROUTER_IMPLICIT_HEAD'] = 'true';
        putenv('ROUTER_IMPLICIT_HEAD=true');

        $this->assertSame(405, $this->router(['debug' => false])->handle(new ServerRequest('HEAD', '/users'))->getStatusCode());
        $this->assertSame(
            200,
            $this->router(['debug' => false, 'implicitHead' => true])->handle(new ServerRequest('HEAD', '/users'))->getStatusCode()
        );
    }

    public function testImplicitHeadTakesBooleanLikeValuesAndRefusesTheRest(): void
    {
        $status = fn (mixed $value): int => $this->router(['debug' => false, 'implicitHead' => $value])
            ->handle(new ServerRequest('HEAD', '/users'))
            ->getStatusCode();

        foreach ([true, 'true', '1', 1, 'on'] as $on) {
            $this->assertSame(200, $status($on), var_export($on, true));
        }

        // 'false' from an env file is not "a non-empty string, so on"
        foreach ([false, 'false', '0', 0, 'off', '', null, []] as $off) {
            $this->assertSame(405, $status($off), var_export($off, true));
        }

        $this->expectException(RouterException::class);
        $this->expectExceptionMessage("Config 'implicitHead' must be a boolean, got string");
        $status('maybe');
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
