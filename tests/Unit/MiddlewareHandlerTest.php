<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sodaho\Router\Middleware\MiddlewareHandler;
use Sodaho\Router\Response;

class MiddlewareHandlerTest extends TestCase
{
    public function testDelegatesRequestToMiddleware(): void
    {
        $middleware = new class () implements MiddlewareInterface {
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler
            ): ResponseInterface {
                return Response::success(['middleware' => 'executed']);
            }
        };

        $next = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return Response::success(['next' => 'should not reach']);
            }
        };

        $handler = new MiddlewareHandler($middleware, $next);
        $response = $handler->handle(new ServerRequest('GET', '/test'));

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('executed', $body['data']['middleware']);
    }

    public function testMiddlewareCanDelegateToNext(): void
    {
        $middleware = new class () implements MiddlewareInterface {
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler
            ): ResponseInterface {
                $request = $request->withAttribute('modified', true);
                return $handler->handle($request);
            }
        };

        $next = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return Response::success([
                    'modified' => $request->getAttribute('modified'),
                ]);
            }
        };

        $handler = new MiddlewareHandler($middleware, $next);
        $response = $handler->handle(new ServerRequest('GET', '/test'));

        $body = json_decode((string) $response->getBody(), true);
        $this->assertTrue($body['data']['modified']);
    }

    public function testMiddlewareChain(): void
    {
        $order = [];

        $middleware1 = new class ($order) implements MiddlewareInterface {
            public function __construct(private array &$order)
            {
            }
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler
            ): ResponseInterface {
                $this->order[] = 'before1';
                $response = $handler->handle($request);
                $this->order[] = 'after1';
                return $response;
            }
        };

        $middleware2 = new class ($order) implements MiddlewareInterface {
            public function __construct(private array &$order)
            {
            }
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler
            ): ResponseInterface {
                $this->order[] = 'before2';
                $response = $handler->handle($request);
                $this->order[] = 'after2';
                return $response;
            }
        };

        $finalHandler = new class ($order) implements RequestHandlerInterface {
            public function __construct(private array &$order)
            {
            }
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->order[] = 'handler';
                return Response::success([]);
            }
        };

        // Build chain: middleware1 -> middleware2 -> finalHandler
        $handler = new MiddlewareHandler(
            $middleware1,
            new MiddlewareHandler($middleware2, $finalHandler)
        );

        $handler->handle(new ServerRequest('GET', '/test'));

        $this->assertSame(['before1', 'before2', 'handler', 'after2', 'after1'], $order);
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $middleware = new class () implements MiddlewareInterface {
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler
            ): ResponseInterface {
                // Don't call $handler->handle() - short circuit
                return Response::unauthorized('Access denied');
            }
        };

        $next = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('Should not be called');
            }
        };

        $handler = new MiddlewareHandler($middleware, $next);
        $response = $handler->handle(new ServerRequest('GET', '/test'));

        $this->assertSame(401, $response->getStatusCode());
    }
}
