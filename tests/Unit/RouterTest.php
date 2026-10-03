<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Sodaho\Router\Router;

class RouterTest extends TestCase
{
    private string $routesFile;

    protected function setUp(): void
    {
        $this->routesFile = sys_get_temp_dir() . '/router_test_routes_' . uniqid() . '.php';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->routesFile)) {
            unlink($this->routesFile);
        }
    }

    private function createRoutesFile(string $content): void
    {
        file_put_contents($this->routesFile, $content);
    }

    public function testCreateFactory(): void
    {
        $router = Router::create(['debug' => true]);
        $this->assertInstanceOf(Router::class, $router);
    }

    public function testSetContainer(): void
    {
        $container = $this->createMock(ContainerInterface::class);

        $this->createRoutesFile(
            <<<'PHP'
                <?php
                use Sodaho\Router\RouteCollector;
                use Sodaho\Router\Response;

                return function (RouteCollector $r) {
                    $r->get('/', fn($req) => Response::success(['ok' => true]));
                };
                PHP
        );

        $router = Router::create()
            ->setContainer($container)
            ->loadRoutes($this->routesFile);

        $response = $router->handle(new ServerRequest('GET', '/'));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testSetDebug(): void
    {
        $this->createRoutesFile(
            <<<'PHP'
                <?php
                use Sodaho\Router\RouteCollector;

                return function (RouteCollector $r) {
                    $r->get('/error', fn($req) => throw new \RuntimeException('Test error'));
                };
                PHP
        );

        // Debug mode shows error details
        $router = Router::create()
            ->setDebug(true)
            ->loadRoutes($this->routesFile);

        $response = $router->handle(new ServerRequest('GET', '/error'));
        $body = json_decode((string) $response->getBody(), true);
        $this->assertStringContainsString('Test error', $body['message']);

        // Non-debug mode hides details
        $this->createRoutesFile(
            <<<'PHP'
                <?php
                use Sodaho\Router\RouteCollector;

                return function (RouteCollector $r) {
                    $r->get('/error', fn($req) => throw new \RuntimeException('Secret error'));
                };
                PHP
        );

        $router2 = Router::create()
            ->setDebug(false)
            ->loadRoutes($this->routesFile);

        $response2 = $router2->handle(new ServerRequest('GET', '/error'));
        $body2 = json_decode((string) $response2->getBody(), true);
        $this->assertSame('Internal Server Error', $body2['message']);
    }

    public function testSetBasePath(): void
    {
        $this->createRoutesFile(
            <<<'PHP'
                <?php
                use Sodaho\Router\RouteCollector;
                use Sodaho\Router\Response;

                return function (RouteCollector $r) {
                    $r->get('/users', fn($req) => Response::success(['users' => []]));
                };
                PHP
        );

        $router = Router::create()
            ->setBasePath('/api/v1/')  // Trailing slash should be trimmed
            ->loadRoutes($this->routesFile);

        // With basePath
        $response = $router->handle(new ServerRequest('GET', '/api/v1/users'));
        $this->assertSame(200, $response->getStatusCode());

        // Without basePath -> 404
        $response2 = $router->handle(new ServerRequest('GET', '/users'));
        $this->assertSame(404, $response2->getStatusCode());
    }

    public function testUrlGeneration(): void
    {
        $this->createRoutesFile(
            <<<'PHP'
                <?php
                use Sodaho\Router\RouteCollector;
                use Sodaho\Router\Response;

                return function (RouteCollector $r) {
                    $r->get('/users/{id}', fn($req) => Response::success([]))->name('users.show');
                };
                PHP
        );

        $router = Router::create([
            'basePath' => '/api',
            'baseUrl' => 'https://example.com',
        ])->loadRoutes($this->routesFile);

        // Initialize routes
        $router->handle(new ServerRequest('GET', '/api/users/1'));

        $this->assertSame('/api/users/42', $router->url('users.show', ['id' => 42]));
        $this->assertSame(
            'https://example.com/api/users/42',
            $router->absoluteUrl('users.show', ['id' => 42])
        );
    }

    public function testHooksOnRouter(): void
    {
        $this->createRoutesFile(
            <<<'PHP'
                <?php
                use Sodaho\Router\RouteCollector;
                use Sodaho\Router\Response;

                return function (RouteCollector $r) {
                    $r->get('/test', fn($req) => Response::success(['ok' => true]));
                };
                PHP
        );

        $dispatched = false;
        $router = Router::create()
            ->loadRoutes($this->routesFile)
            ->on('dispatch', function ($data) use (&$dispatched) {
                $dispatched = true;
            });

        $router->handle(new ServerRequest('GET', '/test'));
        $this->assertTrue($dispatched);
    }

    public function testConfigFromEnvironment(): void
    {
        // Set environment variables
        $_ENV['APP_DEBUG'] = 'true';
        $_ENV['APP_URL'] = 'https://env.example.com';

        $this->createRoutesFile(
            <<<'PHP'
                <?php
                use Sodaho\Router\RouteCollector;
                use Sodaho\Router\Response;

                return function (RouteCollector $r) {
                    $r->get('/error', fn($req) => throw new \Exception('Test'));
                };
                PHP
        );

        $router = Router::fromEnv()->loadRoutes($this->routesFile);
        $response = $router->handle(new ServerRequest('GET', '/error'));

        // Should show debug info due to APP_DEBUG=true
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('exception', $body['error']['details']['debug']);

        // Clean up
        unset($_ENV['APP_DEBUG'], $_ENV['APP_URL']);
    }

    public function testThrowsWithoutRoutes(): void
    {
        $router = Router::create(['debug' => true]);

        // Without routes, should return 500 with error message
        $response = $router->handle(new ServerRequest('GET', '/'));
        $this->assertSame(500, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertStringContainsString('No routes loaded', $body['message']);
    }

    public function testErrorHookTriggered(): void
    {
        $this->createRoutesFile(
            <<<'PHP'
                <?php
                use Sodaho\Router\RouteCollector;

                return function (RouteCollector $r) {
                    $r->get('/error', fn($req) => throw new \RuntimeException('Boom'));
                };
                PHP
        );

        $errorData = null;
        $router = Router::create(['debug' => true])
            ->loadRoutes($this->routesFile)
            ->on('error', function ($data) use (&$errorData) {
                $errorData = $data;
            });

        $router->handle(new ServerRequest('GET', '/error'));

        $this->assertNotNull($errorData);
        $this->assertInstanceOf(\RuntimeException::class, $errorData['exception']);
    }

    public function testStrictTrailingSlashDistinguishesRoutes(): void
    {
        $this->createRoutesFile(
            <<<'PHP'
                <?php
                use Sodaho\Router\RouteCollector;
                use Sodaho\Router\Response;

                return function (RouteCollector $r) {
                    $r->get('/api/', fn($req) => Response::success(['route' => 'with_slash']));
                    $r->get('/api', fn($req) => Response::success(['route' => 'without_slash']));
                };
                PHP
        );

        $router = Router::create(['trailingSlash' => 'strict'])
            ->loadRoutes($this->routesFile);

        // Request WITH trailing slash matches /api/
        $response1 = $router->handle(new ServerRequest('GET', '/api/'));
        $this->assertSame(200, $response1->getStatusCode());
        $body1 = json_decode((string) $response1->getBody(), true);
        $this->assertSame('with_slash', $body1['data']['route']);

        // Request WITHOUT trailing slash matches /api
        $response2 = $router->handle(new ServerRequest('GET', '/api'));
        $this->assertSame(200, $response2->getStatusCode());
        $body2 = json_decode((string) $response2->getBody(), true);
        $this->assertSame('without_slash', $body2['data']['route']);
    }

    public function testIgnoreTrailingSlashNormalizesRoutes(): void
    {
        $this->createRoutesFile(
            <<<'PHP'
                <?php
                use Sodaho\Router\RouteCollector;
                use Sodaho\Router\Response;

                return function (RouteCollector $r) {
                    // Even if defined with slash, ignore mode normalizes to /api
                    $r->get('/api/', fn($req) => Response::success(['matched' => true]));
                };
                PHP
        );

        $router = Router::create(['trailingSlash' => 'ignore'])
            ->loadRoutes($this->routesFile);

        // Both requests match (normalized)
        $response1 = $router->handle(new ServerRequest('GET', '/api/'));
        $this->assertSame(200, $response1->getStatusCode());

        $response2 = $router->handle(new ServerRequest('GET', '/api'));
        $this->assertSame(200, $response2->getStatusCode());
    }

    public function testRouterCanBeWrappedThroughItsInterface(): void
    {
        $this->createRoutesFile('<?php return function ($r) { $r->get("/users/{id}", fn ($request, string $id) => Sodaho\Router\Response::text("user " . $id))->name("users.show"); $r->get("/price/{p}", fn () => Sodaho\Router\Response::text("p"))->name("price"); };');
        $inner = Router::create(['baseUrl' => 'https://example.org'])->loadRoutes($this->routesFile);

        // A decorator: what an application adds around the router, without extending it
        $wrapped = new class ($inner) implements \Sodaho\Router\Contract\RouterInterface {
            /** @var list<string> */
            public array $seen = [];

            public function __construct(private \Sodaho\Router\Contract\RouterInterface $inner)
            {
            }

            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->seen[] = $request->getUri()->getPath();

                return $this->inner->handle($request);
            }

            public function match(\Psr\Http\Message\ServerRequestInterface $request): \Sodaho\Router\RouteMatch
            {
                return $this->inner->match($request);
            }

            public function url(string $name, array $params = []): string
            {
                return $this->inner->url($name, $params);
            }

            public function absoluteUrl(string $name, array $params = []): string
            {
                return $this->inner->absoluteUrl($name, $params);
            }
        };

        $this->assertSame('user 5', (string) $wrapped->handle(new ServerRequest('GET', '/users/5'))->getBody());
        $this->assertSame(['/users/5'], $wrapped->seen);
        $this->assertSame(\Sodaho\Router\RouteMatch::FOUND, $wrapped->match(new ServerRequest('GET', '/users/5'))->status);
        $this->assertSame('/users/5', $wrapped->url('users.show', ['id' => 5]));
        $this->assertSame('https://example.org/users/5', $wrapped->absoluteUrl('users.show', ['id' => 5]));
        // Floats and booleans go in as the router writes them
        $this->assertSame('/price/1.5', $wrapped->url('price', ['p' => 1.5]));
        $this->assertSame('/price/1', $wrapped->url('price', ['p' => true]));
    }

    public function testEveryClassButTheExceptionsIsFinal(): void
    {
        $open = [];
        $finalExceptions = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            $name = 'Sodaho\\Router\\' . str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen(dirname(__DIR__, 2) . '/src/')));
            $class = new \ReflectionClass($name);
            if ($class->isInterface() || $class->isTrait()) {
                continue;
            }
            if ($class->isSubclassOf(\Throwable::class)) {
                // The exceptions stay open: an application may build its own on them
                if ($class->isFinal()) {
                    $finalExceptions[] = $name;
                }
            } elseif (!$class->isFinal()) {
                $open[] = $name;
            }
        }

        $this->assertSame([], $open);
        $this->assertSame([], $finalExceptions);
    }

    public function testHooksRegisteredAfterTheFirstRequestStillFire(): void
    {
        $this->createRoutesFile(
            <<<'ROUTES'
                <?php
                use Sodaho\Router\RouteCollector;
                use Sodaho\Router\Response;

                return function (RouteCollector $r) {
                    $r->get('/users/{id:int}', fn($req, $id) => Response::success(['id' => $id]));
                };
                ROUTES
        );

        $router = Router::create(['debug' => false])->loadRoutes($this->routesFile);

        $early = [];
        $router->on('dispatch', function (array $data) use (&$early): void {
            $early[] = 'dispatch';
        });

        // The first request builds the dispatcher, which copies the hooks registered so far.
        // A long-running worker registering a metrics hook later used to be ignored silently.
        $router->handle(new ServerRequest('GET', '/users/1'));

        $late = [];
        foreach (['dispatch', 'notFound', 'methodNotAllowed', 'error'] as $event) {
            $returned = $router->on($event, function (array $data) use (&$late, $event): void {
                $late[] = $event;
            });
            $this->assertSame($router, $returned);
        }

        $router->handle(new ServerRequest('GET', '/users/2'));
        $router->handle(new ServerRequest('GET', '/nowhere'));
        $router->handle(new ServerRequest('POST', '/users/2'));
        $router->handle(new ServerRequest('GET', '/users/' . str_repeat('9', 30)));

        $this->assertSame(['dispatch', 'notFound', 'methodNotAllowed', 'error'], $late);
        $this->assertSame(['dispatch', 'dispatch'], $early, 'each hook fires once per event, not once per copy');
    }
}
