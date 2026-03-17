<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Integration;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\Router;

class FullStackTest extends TestCase
{
    private string $routesFile;

    protected function setUp(): void
    {
        $this->routesFile = sys_get_temp_dir() . '/test-routes-' . uniqid() . '.php';
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

    public function testFullRequestCycle(): void
    {
        $this->createRoutesFile(
            <<<'PHP'
                <?php
                use Sodaho\Router\RouteCollector;
                use Sodaho\Router\Response;

                return function (RouteCollector $r) {
                    $r->get('/users', fn($req) => Response::success(['users' => []]));
                    $r->get('/users/{id:int}', fn($req, int $id) => Response::success(['id' => $id]));
                    $r->post('/users', fn($req) => Response::created(['id' => 1]));
                };
                PHP
        );

        $router = Router::create(['debug' => true])->loadRoutes($this->routesFile);

        // GET /users
        $response = $router->handle(new ServerRequest('GET', '/users'));
        $this->assertSame(200, $response->getStatusCode());

        // GET /users/42
        $response = $router->handle(new ServerRequest('GET', '/users/42'));
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(42, $body['data']['id']);

        // POST /users
        $response = $router->handle(new ServerRequest('POST', '/users'));
        $this->assertSame(201, $response->getStatusCode());

        // GET /nonexistent -> 404
        $response = $router->handle(new ServerRequest('GET', '/nonexistent'));
        $this->assertSame(404, $response->getStatusCode());

        // DELETE /users -> 405
        $response = $router->handle(new ServerRequest('DELETE', '/users'));
        $this->assertSame(405, $response->getStatusCode());
    }

    public function testMiddlewareExecution(): void
    {
        // Use anonymous class via Closure to avoid class redefinition
        $this->createRoutesFile(
            <<<'PHP'
                <?php
                use Sodaho\Router\RouteCollector;
                use Sodaho\Router\Response;
                use Psr\Http\Message\ResponseInterface;
                use Psr\Http\Message\ServerRequestInterface;
                use Psr\Http\Server\MiddlewareInterface;
                use Psr\Http\Server\RequestHandlerInterface;

                return function (RouteCollector $r) {
                    $authMiddleware = new class implements MiddlewareInterface {
                        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                        {
                            $auth = $request->getHeaderLine('Authorization');
                            if ($auth !== 'Bearer valid-token') {
                                return Response::unauthorized();
                            }
                            $request = $request->withAttribute('user', 'authenticated-user');
                            return $handler->handle($request);
                        }
                    };

                    $r->get('/public', fn($req) => Response::success(['public' => true]));
                    $r->get('/protected', fn($req) => Response::success([
                        'user' => $req->getAttribute('user')
                    ]))->middleware($authMiddleware);
                };
                PHP
        );

        $router = Router::create(['debug' => true])->loadRoutes($this->routesFile);

        // Public route works
        $response = $router->handle(new ServerRequest('GET', '/public'));
        $this->assertSame(200, $response->getStatusCode());

        // Protected route without token -> 401
        $response = $router->handle(new ServerRequest('GET', '/protected'));
        $this->assertSame(401, $response->getStatusCode());

        // Protected route with token -> 200
        $request = (new ServerRequest('GET', '/protected'))
            ->withHeader('Authorization', 'Bearer valid-token');
        $response = $router->handle($request);
        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('authenticated-user', $body['data']['user']);
    }

    public function testRouteParametersAvailableInMiddleware(): void
    {
        $this->createRoutesFile(
            <<<'PHP'
                <?php
                use Sodaho\Router\RouteCollector;
                use Sodaho\Router\Response;
                use Psr\Http\Message\ResponseInterface;
                use Psr\Http\Message\ServerRequestInterface;
                use Psr\Http\Server\MiddlewareInterface;
                use Psr\Http\Server\RequestHandlerInterface;

                return function (RouteCollector $r) {
                    $paramMiddleware = new class implements MiddlewareInterface {
                        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                        {
                            // Verify route params are available BEFORE handler
                            $id = $request->getAttribute('id');
                            $response = $handler->handle($request);

                            // Modify response to prove middleware saw the ID
                            $body = json_decode((string) $response->getBody(), true);
                            $body['data']['middleware_saw_id'] = ($id === 42);
                            return Response::success($body['data']);
                        }
                    };

                    $r->get('/items/{id:int}', fn($req, int $id) => Response::success(['id' => $id]))
                        ->middleware($paramMiddleware);
                };
                PHP
        );

        $router = Router::create(['debug' => true])->loadRoutes($this->routesFile);

        $response = $router->handle(new ServerRequest('GET', '/items/42'));
        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(42, $body['data']['id']);
        $this->assertTrue($body['data']['middleware_saw_id']);
    }

    public function testNamedRoutesAndUrlGeneration(): void
    {
        $this->createRoutesFile(
            <<<'PHP'
                <?php
                use Sodaho\Router\RouteCollector;
                use Sodaho\Router\Response;

                return function (RouteCollector $r) {
                    $r->get('/users/{id}', fn($req) => Response::success([]))->name('users.show');
                    $r->get('/posts/{year}/{slug}', fn($req) => Response::success([]))->name('posts.show');
                };
                PHP
        );

        $router = Router::create([
            'debug' => true,
            'basePath' => '/api',
            'baseUrl' => 'https://example.com',
        ])->loadRoutes($this->routesFile);

        // Initialize routes by handling a request
        $router->handle(new ServerRequest('GET', '/api/users/1'));

        $this->assertSame('/api/users/42', $router->url('users.show', ['id' => 42]));
        $this->assertSame('/api/posts/2025/hello', $router->url('posts.show', [
            'year' => 2025,
            'slug' => 'hello',
        ]));
        $this->assertSame(
            'https://example.com/api/users/42',
            $router->absoluteUrl('users.show', ['id' => 42])
        );
    }

    public function testGroupsAndMiddlewareGroups(): void
    {
        $this->createRoutesFile(
            <<<'PHP'
                <?php
                use Sodaho\Router\RouteCollector;
                use Sodaho\Router\Response;
                use Psr\Http\Message\ResponseInterface;
                use Psr\Http\Message\ServerRequestInterface;
                use Psr\Http\Server\MiddlewareInterface;
                use Psr\Http\Server\RequestHandlerInterface;

                return function (RouteCollector $r) {
                    $authMiddleware = new class implements MiddlewareInterface {
                        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                        {
                            $auth = $request->getHeaderLine('Authorization');
                            if ($auth !== 'Bearer valid-token') {
                                return Response::unauthorized();
                            }
                            return $handler->handle($request);
                        }
                    };

                    $r->group('/api', function (RouteCollector $r) use ($authMiddleware) {
                        $r->group('/v1', function (RouteCollector $r) use ($authMiddleware) {
                            $r->get('/public', fn($req) => Response::success(['version' => 'v1']));

                            $r->middlewareGroup($authMiddleware, function (RouteCollector $r) {
                                $r->get('/private', fn($req) => Response::success(['private' => true]));
                            });
                        });
                    });
                };
                PHP
        );

        $router = Router::create(['debug' => true])->loadRoutes($this->routesFile);

        // Public endpoint
        $response = $router->handle(new ServerRequest('GET', '/api/v1/public'));
        $this->assertSame(200, $response->getStatusCode());

        // Private endpoint without auth
        $response = $router->handle(new ServerRequest('GET', '/api/v1/private'));
        $this->assertSame(401, $response->getStatusCode());

        // Private endpoint with auth
        $request = (new ServerRequest('GET', '/api/v1/private'))
            ->withHeader('Authorization', 'Bearer valid-token');
        $response = $router->handle($request);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testErrorHandlingInDebugMode(): void
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

        $router = Router::create(['debug' => true])->loadRoutes($this->routesFile);
        $response = $router->handle(new ServerRequest('GET', '/error'));

        $this->assertSame(500, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertStringContainsString('Test error', $body['message']);
        $this->assertArrayHasKey('debug', $body['error']['details']);
    }

    public function testErrorHandlingInProductionMode(): void
    {
        $this->createRoutesFile(
            <<<'PHP'
                <?php
                use Sodaho\Router\RouteCollector;

                return function (RouteCollector $r) {
                    $r->get('/error', fn($req) => throw new \RuntimeException('Sensitive error info'));
                };
                PHP
        );

        $router = Router::create(['debug' => false])->loadRoutes($this->routesFile);
        $response = $router->handle(new ServerRequest('GET', '/error'));

        $this->assertSame(500, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('Internal Server Error', $body['message']);
        $this->assertArrayNotHasKey('details', $body['error']);
    }

    public function testHooksReceiveCorrectData(): void
    {
        $this->createRoutesFile(
            <<<'PHP'
                <?php
                use Sodaho\Router\RouteCollector;
                use Sodaho\Router\Response;

                return function (RouteCollector $r) {
                    $r->get('/test/{id}', fn($req, $id) => Response::success(['id' => $id]));
                };
                PHP
        );

        $dispatchData = null;
        $router = Router::create(['debug' => true])
            ->loadRoutes($this->routesFile)
            ->on('dispatch', function (array $data) use (&$dispatchData) {
                $dispatchData = $data;
            });

        $router->handle(new ServerRequest('GET', '/test/42'));

        $this->assertNotNull($dispatchData);
        $this->assertSame('GET', $dispatchData['method']);
        $this->assertSame('/test/42', $dispatchData['path']);
        $this->assertArrayHasKey('duration', $dispatchData);
    }

    public function testBasePathHandling(): void
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

        $router = Router::create([
            'debug' => true,
            'basePath' => '/api/v1',
        ])->loadRoutes($this->routesFile);

        // With basePath prefix -> 200
        $response = $router->handle(new ServerRequest('GET', '/api/v1/users'));
        $this->assertSame(200, $response->getStatusCode());

        // Without basePath prefix -> 404 (route is /users but requested path after stripping basePath doesn't exist)
        $response = $router->handle(new ServerRequest('GET', '/users'));
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testThrowsWhenNoRoutesLoaded(): void
    {
        $router = Router::create(['debug' => true]);

        // Without error handling, this would throw. With error handling, it returns 500
        $response = $router->handle(new ServerRequest('GET', '/test'));
        $this->assertSame(500, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertStringContainsString('No routes loaded', $body['message']);
    }

    public function testThrowsOnInvalidRoutesFile(): void
    {
        $this->createRoutesFile(
            <<<'PHP'
                <?php
                // Returns string instead of callable
                return 'not a callable';
                PHP
        );

        $router = Router::create(['debug' => true])->loadRoutes($this->routesFile);

        // With error handling enabled, returns 500 instead of throwing
        $response = $router->handle(new ServerRequest('GET', '/test'));
        $this->assertSame(500, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertStringContainsString('must return callable', $body['message']);
    }
}
