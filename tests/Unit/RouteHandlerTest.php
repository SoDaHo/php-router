<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\Middleware\RouteHandler;
use Sodaho\Router\Response;

class RouteHandlerTest extends TestCase
{
    public function testHandlesClosure(): void
    {
        $handler = new RouteHandler(fn ($req) => Response::success(['ok' => true]));

        $response = $handler->handle(new ServerRequest('GET', '/test'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testHandlesClosureWithParameters(): void
    {
        $handler = new RouteHandler(fn ($req, $id, $name) => Response::success([
            'id' => $id,
            'name' => $name,
        ]));

        // Route params must be in _route_params attribute
        $request = (new ServerRequest('GET', '/test'))
            ->withAttribute('_route_params', ['id' => 42, 'name' => 'John']);

        $response = $handler->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(42, $body['data']['id']);
        $this->assertSame('John', $body['data']['name']);
    }

    public function testHandlesControllerArray(): void
    {
        $handler = new RouteHandler([TestController::class, 'index']);

        $response = $handler->handle(new ServerRequest('GET', '/test'));

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('index', $body['data']['action']);
    }

    public function testHandlesControllerWithContainer(): void
    {
        $controller = new TestController();
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with(TestController::class)->willReturn(true);
        $container->method('get')->with(TestController::class)->willReturn($controller);

        $handler = new RouteHandler([TestController::class, 'index'], $container);
        $response = $handler->handle(new ServerRequest('GET', '/test'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testHandlesPsr15RequestHandler(): void
    {
        $psr15Handler = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return Response::success(['psr15' => true]);
            }
        };

        $handler = new RouteHandler($psr15Handler);
        $response = $handler->handle(new ServerRequest('GET', '/test'));

        $body = json_decode((string) $response->getBody(), true);
        $this->assertTrue($body['data']['psr15']);
    }

    public function testThrowsOnInvalidHandler(): void
    {
        $handler = new RouteHandler('invalid-string-handler');

        $this->expectException(RouterException::class);
        $this->expectExceptionMessage('Invalid route handler');
        $handler->handle(new ServerRequest('GET', '/test'));
    }

    public function testThrowsOnNonResponseReturn(): void
    {
        $handler = new RouteHandler(fn ($req) => ['array' => 'not allowed']);

        $this->expectException(RouterException::class);
        $this->expectExceptionMessage('must return ResponseInterface');
        $handler->handle(new ServerRequest('GET', '/test'));
    }

    public function testControllerWithoutContainer(): void
    {
        // Controller is instantiated directly without container
        $handler = new RouteHandler([TestController::class, 'show']);

        // Route params must be in _route_params attribute
        $request = (new ServerRequest('GET', '/test'))
            ->withAttribute('_route_params', ['id' => 99]);
        $response = $handler->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(99, $body['data']['id']);
    }

    public function testContainerWithoutHas(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        // Should fall back to direct instantiation
        $handler = new RouteHandler([TestController::class, 'index'], $container);
        $response = $handler->handle(new ServerRequest('GET', '/test'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testThrowsWhenControllerRequiresConstructorParams(): void
    {
        // Controller with required constructor parameter and no container
        $handler = new RouteHandler([ControllerWithRequiredParams::class, 'index']);

        $this->expectException(RouterException::class);
        $this->expectExceptionMessage('requires constructor parameters');
        $this->expectExceptionMessage('ControllerWithRequiredParams');

        $handler->handle(new ServerRequest('GET', '/test'));
    }

    public function testControllerWithOptionalParamsWorksWithoutContainer(): void
    {
        // Controller with only optional constructor parameters should work
        $handler = new RouteHandler([ControllerWithOptionalParams::class, 'index']);

        $response = $handler->handle(new ServerRequest('GET', '/test'));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('default', $body['data']['value']);
    }
}

class TestController
{
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return Response::success(['action' => 'index']);
    }

    public function show(ServerRequestInterface $request, int $id): ResponseInterface
    {
        return Response::success(['action' => 'show', 'id' => $id]);
    }
}

class ControllerWithRequiredParams
{
    public function __construct(private readonly string $requiredDependency)
    {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return Response::success(['dependency' => $this->requiredDependency]);
    }
}

class ControllerWithOptionalParams
{
    public function __construct(private readonly string $value = 'default')
    {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return Response::success(['value' => $this->value]);
    }
}
