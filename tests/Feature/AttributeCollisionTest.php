<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Feature;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\Router;

/**
 * A route parameter never takes the place of an attribute the request carries already:
 * an auth middleware for every request sets user_id from a token, and /users/{user_id}
 * must not hand the route the value the client wrote into the path under that name.
 */
class AttributeCollisionTest extends TestCase
{
    private string $routesFile;

    /** @var list<string> What ran behind the lookup, in order */
    public static array $ran = [];

    protected function setUp(): void
    {
        self::$ran = [];
        $this->routesFile = sys_get_temp_dir() . '/router_attribute_collision_' . uniqid() . '.php';

        file_put_contents(
            $this->routesFile,
            <<<'PHP'
                <?php
                use Sodaho\Router\Response;
                use Sodaho\Router\Tests\Feature\{AttributeCollisionTest, CollisionWitness};

                return function (Sodaho\Router\RouteCollector $r) {
                    $r->delete('/users/{user_id}/sessions', function ($req, string $user_id) {
                        AttributeCollisionTest::$ran[] = 'handler';

                        return Response::text('revoked ' . $user_id);
                    })->middleware(new CollisionWitness());
                    $r->get('/orders/{id:int}', fn ($req, int $id) => Response::json([
                        'id' => $id,
                        'attribute' => $req->getAttribute('id'),
                        'user' => $req->getAttribute('user_id'),
                        'params' => $req->getAttribute('_route_params'),
                    ]));
                };
                PHP
        );
    }

    protected function tearDown(): void
    {
        unlink($this->routesFile);
    }

    /**
     * @param array<string, mixed> $attributes What the middleware for every request sets
     * @param list<array<string, mixed>> $reported
     */
    private function router(array $attributes, array &$reported): Router
    {
        $router = Router::create()->loadRoutes($this->routesFile);
        $router->middleware(new class ($attributes) implements MiddlewareInterface {
            /** @param array<string, mixed> $attributes */
            public function __construct(private readonly array $attributes)
            {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                foreach ($this->attributes as $name => $value) {
                    $request = $request->withAttribute($name, $value);
                }

                return $handler->handle($request);
            }
        });
        $router->on('error', function (array $data) use (&$reported): void {
            $reported[] = $data;
        });

        return $router;
    }

    public function testPlaceholderNamedLikeAnAttributeOfTheTokenIsA500AndNothingOfTheRouteRuns(): void
    {
        $reported = [];
        $response = $this->router(['user_id' => 'alice'], $reported)
            ->handle(new ServerRequest('DELETE', '/users/bob/sessions'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('bob', (string) $response->getBody());
        $this->assertSame([], self::$ran, 'neither the route middleware nor the handler ran');

        $this->assertCount(1, $reported);
        $exception = $reported[0]['exception'];
        $this->assertInstanceOf(RouterException::class, $exception);
        $this->assertSame(
            'Route parameter has the name of an attribute the request already carries: rename the placeholder or the attribute',
            $exception->getMessage(),
        );
        $this->assertSame('{user_id} in /users/{user_id}/sessions', $exception->getDebugMessage());
        $this->assertSame(500, $reported[0]['status']);
    }

    public function testAnAttributeThatIsNullIsThereAllTheSame(): void
    {
        $reported = [];
        $response = $this->router(['user_id' => null], $reported)
            ->handle(new ServerRequest('DELETE', '/users/bob/sessions'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame([], self::$ran);
        $this->assertCount(1, $reported);
    }

    public function testAnAttributeTheRequestBroughtAlongCountsAsWell(): void
    {
        $reported = [];
        $request = new ServerRequest('DELETE', '/users/bob/sessions')->withAttribute('user_id', 'alice');

        $response = $this->router([], $reported)->handle($request);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame([], self::$ran);
    }

    public function testWithoutACollisionEveryParameterIsAnAttributeAndInRouteParams(): void
    {
        $reported = [];
        $router = $this->router(['App\\Auth\\Identity' => 'alice', 'tenant' => 't1'], $reported);

        $response = $router->handle(new ServerRequest('DELETE', '/users/bob/sessions'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('revoked bob', (string) $response->getBody());
        $this->assertSame(['witness: bob, identity alice', 'handler'], self::$ran);
        $this->assertSame([], $reported);
    }

    public function testCastValueIsTheAttributeAndTheParameterListIsComplete(): void
    {
        $reported = [];
        $response = $this->router(['user_id' => 'alice'], $reported)
            ->handle(new ServerRequest('GET', '/orders/7'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['id' => 7, 'attribute' => 7, 'user' => 'alice', 'params' => ['id' => 7]],
            json_decode((string) $response->getBody(), true),
        );
    }

    public function testLookingTheRouteUpIsNotTouched(): void
    {
        $reported = [];
        $request = new ServerRequest('DELETE', '/users/bob/sessions')->withAttribute('user_id', 'alice');

        $match = $this->router([], $reported)->match($request);

        $this->assertTrue($match->isFound());
        $this->assertSame(['user_id' => 'bob'], $match->params);
    }
}

/**
 * Route middleware of the fixture: records what it sees.
 */
final class CollisionWitness implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $identity = $request->getAttribute('App\\Auth\\Identity');
        AttributeCollisionTest::$ran[] = sprintf(
            'witness: %s, identity %s',
            (string) $request->getAttribute('user_id'),
            is_string($identity) ? $identity : '-',
        );

        return $handler->handle($request);
    }
}
