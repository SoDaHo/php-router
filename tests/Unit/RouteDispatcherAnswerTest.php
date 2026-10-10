<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sodaho\Router\Response;
use Sodaho\Router\Route;
use Sodaho\Router\RouteDispatcher;

/**
 * RouteDispatcher::answer() says for the call it answers whether it cut the body for HEAD
 * — never for the response object, which a handler may return again for another request
 * (RunReusedHeadAnswerTest shows what run() sends then).
 */
class RouteDispatcherAnswerTest extends TestCase
{
    /** What the POST route answers — set by a test before it asks */
    private ?ResponseInterface $kept = null;

    private function dispatcher(): RouteDispatcher
    {
        $get = new Route(['GET'], '/page', fn () => Response::text('hello world')->withHeader('Content-Length', '11'));
        $post = new Route(['POST'], '/page', fn () => $this->kept ?? Response::text('posted'));

        return new RouteDispatcher([['GET' => ['/page' => $get], 'POST' => ['/page' => $post]], []]);
    }

    public function testAnAnswerCutForHeadIsAnOrdinaryAnswerWhenAPostReturnsItAgain(): void
    {
        $dispatcher = $this->dispatcher();

        [$cut, $cutForHead] = $dispatcher->answer(new ServerRequest('HEAD', '/page'));
        $this->assertTrue($cutForHead);
        $this->assertSame('', (string) $cut->getBody());
        $this->assertSame(['11'], $cut->getHeader('Content-Length'));

        $this->kept = $cut;
        [$again, $cutForHead] = $dispatcher->answer(new ServerRequest('POST', '/page'));
        $this->assertSame($cut, $again);
        $this->assertFalse($cutForHead);
    }

    public function testAPostAMiddlewarePassesOnAsHeadIsCut(): void
    {
        $dispatcher = $this->dispatcher()->setMiddleware([new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request->withMethod('HEAD'));
            }
        }]);

        [$response, $cutForHead] = $dispatcher->answer(new ServerRequest('POST', '/page'));

        $this->assertTrue($cutForHead);
        $this->assertSame('', (string) $response->getBody());
        $this->assertSame(['11'], $response->getHeader('Content-Length'));
    }

    public function testWithImplicitHeadOffNothingIsCut(): void
    {
        [$response, $cutForHead] = $this->dispatcher()->setImplicitHead(false)->answer(new ServerRequest('HEAD', '/page'));

        $this->assertSame(405, $response->getStatusCode());
        $this->assertFalse($cutForHead);
    }

    public function testHandleReturnsTheAnswer(): void
    {
        $response = $this->dispatcher()->handle(new ServerRequest('HEAD', '/page'));

        $this->assertSame('', (string) $response->getBody());
        $this->assertSame(['11'], $response->getHeader('Content-Length'));
    }
}
