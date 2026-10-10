<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\Middleware\RouteHandler;
use Sodaho\Router\Response;

/**
 * [$object, 'method'] is a callable, and is called on that object. It was taken for
 * [class name, method]: with a container a TypeError (has() takes a string), without one a
 * TypeError as well — a 500 either way.
 */
class ObjectMethodHandlerTest extends TestCase
{
    public function testMethodOfAnObjectIsCalledOnThatObject(): void
    {
        $controller = new StatefulController('configured');
        $request = new ServerRequest('GET', '/users/5')->withAttribute('_route_params', ['id' => 5]);

        foreach ([null, $this->emptyContainer()] as $container) {
            $response = new RouteHandler([$controller, 'show'], $container)->handle($request);

            $this->assertSame('configured: 5', (string) $response->getBody());
        }
    }

    public function testMethodThatTheObjectDoesNotHaveIsAnInvalidHandler(): void
    {
        $this->expectException(RouterException::class);
        $this->expectExceptionMessage('Invalid route handler.');

        new RouteHandler([new StatefulController('x'), 'missing'])->handle(new ServerRequest('GET', '/'));
    }

    private function emptyContainer(): ContainerInterface
    {
        return new class () implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \LogicException('not asked');
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }
}

/**
 * Controller of the fixture: what the application built it with shows in the answer.
 */
final class StatefulController
{
    public function __construct(private readonly string $state)
    {
    }

    public function show(ServerRequestInterface $request, int $id): ResponseInterface
    {
        return Response::text($this->state . ': ' . $id);
    }
}
