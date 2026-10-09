<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Feature;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sodaho\Router\Dispatcher;
use Sodaho\Router\Response;
use Sodaho\Router\RouteCollector;
use Sodaho\Router\RouteDispatcher;
use Sodaho\Router\RouteMatch;
use Sodaho\Router\Router;

/**
 * implicitHead: a HEAD request without a HEAD route of its own runs through the GET route
 * (RFC 9110: HEAD is GET without the content). On by default since 2.0; switched off, a
 * HEAD on a GET route is the 405 it was in 1.x.
 */
class ImplicitHeadTest extends TestCase
{
    private string $routesFile;

    protected function setUp(): void
    {
        $this->routesFile = sys_get_temp_dir() . '/router_head_routes_' . uniqid() . '.php';

        file_put_contents(
            $this->routesFile,
            <<<'PHP'
                <?php
                use Sodaho\Router\Response;

                return function (Sodaho\Router\RouteCollector $r) {
                    $r->get('/page', fn ($request) => Response::text('BODY of ' . $request->getMethod())
                        ->withHeader('Content-Length', '11')
                        ->withAddedHeader('Vary', 'Accept')
                        ->withAddedHeader('Vary', 'Origin'))->name('page');
                    $r->patch('/page', fn () => Response::success('patched'));
                    $r->post('/only-post', fn () => Response::success('posted'));
                    $r->get('/both', fn () => Response::text('from GET'))->name('both.get');
                    $r->head('/both', fn () => Response::text('from HEAD')->withHeader('X-Route', 'head'))->name('both.head');
                    $r->get('/users/{id:int}', fn ($request, int $id) => Response::success($id));
                    $r->get('/boom', fn () => throw new \RuntimeException('failed'));
                };
                PHP
        );
    }

    protected function tearDown(): void
    {
        if (file_exists($this->routesFile)) {
            unlink($this->routesFile);
        }
    }

    private function router(bool $implicitHead): Router
    {
        return Router::create(['debug' => false, 'implicitHead' => $implicitHead])
            ->loadRoutes($this->routesFile);
    }

    public function testOnByDefault(): void
    {
        $router = Router::create(['debug' => false])->loadRoutes($this->routesFile);

        $response = $router->handle(new ServerRequest('HEAD', '/page'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
        $this->assertSame(['GET', 'HEAD', 'PATCH'], $router->match(new ServerRequest('HEAD', '/page'))->allowedMethods());
    }

    public function testSwitchedOffHeadOnAGetRouteIsThe405ItWasIn1x(): void
    {
        $router = $this->router(false);

        $response = $router->handle(new ServerRequest('HEAD', '/page'));

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('GET, PATCH', $response->getHeaderLine('Allow'));
        $this->assertStringContainsString('"allowed":["GET","PATCH"]', (string) $response->getBody());
        $this->assertSame(['GET', 'PATCH'], $router->match(new ServerRequest('HEAD', '/page'))->allowedMethods());

        // An explicit HEAD route keeps its body as well: nothing is touched while the switch is off
        $this->assertSame('from HEAD', (string) $router->handle(new ServerRequest('HEAD', '/both'))->getBody());
    }

    public function testHeadIsGetWithoutTheBody(): void
    {
        $router = $this->router(true);
        $dispatched = [];
        $router->on('dispatch', function (array $data) use (&$dispatched): void {
            $dispatched[] = [$data['method'], $data['route']];
        });

        $get = $router->handle(new ServerRequest('GET', '/page'));
        $head = $router->handle(new ServerRequest('HEAD', '/page'));

        $this->assertSame(200, $head->getStatusCode());
        $this->assertSame('', (string) $head->getBody());
        $this->assertSame('BODY of GET', (string) $get->getBody());

        // Same headers, same order — Content-Length included, it describes what GET would send
        $this->assertSame($get->getHeaders(), $head->getHeaders());
        $this->assertSame('11', $head->getHeaderLine('Content-Length'));

        // The request stays what it is: handler and hooks see HEAD
        $this->assertSame([['GET', '/page'], ['HEAD', '/page']], $dispatched);
    }

    public function testMatchSaysSo(): void
    {
        $match = $this->router(true)->match(new ServerRequest('HEAD', '/page'));

        $this->assertTrue($match->isFound());
        $this->assertSame('HEAD', $match->method);
        $this->assertSame('page', $match->route->name);
        $this->assertTrue($match->viaGet);
        $this->assertSame(['GET', 'HEAD', 'PATCH'], $match->allowedMethods());

        $this->assertFalse($this->router(true)->match(new ServerRequest('GET', '/page'))->viaGet);
    }

    public function testParametersOfTheGetRouteAreCast(): void
    {
        $router = $this->router(true);

        $this->assertSame(200, $router->handle(new ServerRequest('HEAD', '/users/5'))->getStatusCode());
        $this->assertSame(400, $router->handle(new ServerRequest('HEAD', '/users/99999999999999999999'))->getStatusCode());
        $this->assertSame(404, $router->handle(new ServerRequest('HEAD', '/users/abc'))->getStatusCode());
    }

    public function testHeadRouteOfItsOwnWins(): void
    {
        $router = $this->router(true);

        $response = $router->handle(new ServerRequest('HEAD', '/both'));
        $this->assertSame('head', $response->getHeaderLine('X-Route'));
        $this->assertSame('', (string) $response->getBody(), 'no HEAD response carries a body while the switch is on');

        $match = $router->match(new ServerRequest('HEAD', '/both'));
        $this->assertSame('both.head', $match->route->name);
        $this->assertFalse($match->viaGet);
        $this->assertSame(['GET', 'HEAD'], $match->allowedMethods(), 'HEAD is not listed twice');
    }

    public function testAllowListsNameHeadRightBehindGet(): void
    {
        $router = $this->router(true);
        $hook = null;
        $router->on('methodNotAllowed', function (array $data) use (&$hook): void {
            $hook = $data['allowed_methods'];
        });

        $response = $router->handle(new ServerRequest('DELETE', '/page'));

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('GET, HEAD, PATCH', $response->getHeaderLine('Allow'));
        $this->assertStringContainsString('"allowed":["GET","HEAD","PATCH"]', (string) $response->getBody());
        $this->assertSame(['GET', 'HEAD', 'PATCH'], $hook);
        $this->assertSame(['GET', 'HEAD', 'PATCH'], $router->match(new ServerRequest('OPTIONS', '/page'))->allowedMethods());
    }

    public function testNoGetNoHead(): void
    {
        $router = $this->router(true);

        $response = $router->handle(new ServerRequest('HEAD', '/only-post'));
        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('POST', $response->getHeaderLine('Allow'));
        $this->assertSame('', (string) $response->getBody());

        $this->assertSame(404, $router->handle(new ServerRequest('HEAD', '/nowhere'))->getStatusCode());
        $this->assertSame(RouteMatch::NOT_FOUND, $router->match(new ServerRequest('HEAD', '/nowhere'))->status);
    }

    /**
     * The body goes at the very end — whoever wrote it. A 404 page from a middleware for
     * every request, the error handler's response and the last resort are HEAD answers too.
     */
    public function testNoAnswerToHeadCarriesABody(): void
    {
        $page = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                if ($request->getAttribute(RouteMatch::class)->status === RouteMatch::NOT_FOUND) {
                    return Response::html('<h1>Not here</h1>', 404);
                }
                if ($request->hasHeader('X-Throw-Outside')) {
                    throw new \RuntimeException('outside the boundary');
                }

                return $handler->handle($request);
            }
        };

        $router = $this->router(true)
            ->middleware($page)
            ->setErrorHandler(fn (\Throwable $e): ResponseInterface => Response::text($e->getMessage(), 500)->withHeader('X-Error', '1'));

        foreach ([
            'page of a middleware' => [new ServerRequest('HEAD', '/nowhere'), 404, 'text/html; charset=utf-8'],
            'error handler' => [new ServerRequest('HEAD', '/boom'), 500, 'text/plain; charset=utf-8'],
            'last resort' => [new ServerRequest('HEAD', '/page')->withHeader('X-Throw-Outside', '1'), 500, 'text/plain; charset=utf-8'],
        ] as $label => [$request, $status, $contentType]) {
            $head = $router->handle($request);
            $get = $router->handle($request->withMethod('GET'));

            $this->assertSame($status, $head->getStatusCode(), $label);
            $this->assertSame('', (string) $head->getBody(), $label);
            $this->assertNotSame('', (string) $get->getBody(), $label);
            $this->assertSame($get->getHeaders(), $head->getHeaders(), $label);
            $this->assertSame($contentType, $head->getHeaderLine('Content-Type'), $label);
        }
    }

    public function testLowLevelDispatcherStaysRaw(): void
    {
        $collector = new RouteCollector();
        $collector->get('/page', 'handler');
        [$static, $dynamic] = $collector->getData();
        $dispatcher = new Dispatcher($static, $dynamic);

        // implicitHead is a matter of the request handling, not of the table
        $this->assertSame([Dispatcher::METHOD_NOT_ALLOWED, ['GET'], [], []], $dispatcher->dispatch('HEAD', '/page'));
        $this->assertSame(['GET'], $dispatcher->allowedMethods('/page'));
    }

    public function testLastResortWithoutRoutesHasNoBodyEither(): void
    {
        $router = Router::create(['debug' => false, 'implicitHead' => true]);

        $head = $router->handle(new ServerRequest('HEAD', '/page'));
        $get = $router->handle(new ServerRequest('GET', '/page'));

        $this->assertSame(500, $head->getStatusCode());
        $this->assertSame('', (string) $head->getBody());
        $this->assertStringContainsString('SERVER_ERROR', (string) $get->getBody());

        // ... and with the switch off the body stays, as it always has
        $off = Router::create(['debug' => false, 'implicitHead' => false])->handle(new ServerRequest('HEAD', '/page'));
        $this->assertStringContainsString('SERVER_ERROR', (string) $off->getBody());
    }

    public function testHeadStandsBehindGetAlsoWhereItIsRegistered(): void
    {
        $collector = new RouteCollector();
        $collector->any('/any', fn () => Response::text('any'));
        $collector->head('/head-first', fn () => Response::text('head'));
        $collector->post('/head-first', fn () => Response::text('post'));
        $collector->get('/head-first', fn () => Response::text('get'));

        $off = new RouteDispatcher($collector->getData())->setImplicitHead(false);
        $on = new RouteDispatcher($collector->getData());

        // As registered while the switch is off ...
        $this->assertSame(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], $off->match(new ServerRequest('GET', '/any'))->allowedMethods());
        $this->assertSame(['GET', 'POST', 'HEAD'], $off->match(new ServerRequest('PUT', '/head-first'))->allowedMethods());

        // ... one rule with it: HEAD right behind GET
        $this->assertSame(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], $on->match(new ServerRequest('GET', '/any'))->allowedMethods());
        $this->assertSame(['GET', 'HEAD', 'POST'], $on->match(new ServerRequest('PUT', '/head-first'))->allowedMethods());
        $this->assertSame('GET, HEAD, POST', $on->handle(new ServerRequest('PUT', '/head-first'))->getHeaderLine('Allow'));
    }

    public function testRequestThatBecomesHeadOnTheWayInLosesItsBodyToo(): void
    {
        $toHead = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request->withMethod('HEAD'));
            }
        };

        $response = $this->router(true)->middleware($toHead)->handle(new ServerRequest('POST', '/page'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('11', $response->getHeaderLine('Content-Length'));
        $this->assertSame('', (string) $response->getBody());

        // Off: the 405 it always was, body and all
        $response = $this->router(false)->middleware($toHead)->handle(new ServerRequest('POST', '/page'));
        $this->assertSame(405, $response->getStatusCode());
        $this->assertNotSame('', (string) $response->getBody());
    }

    public function testLookupFromBeforeTheSwitchIsNotTakenOver(): void
    {
        $collector = new RouteCollector();
        $collector->get('/page', fn () => Response::text('page'));
        $dispatcher = new RouteDispatcher($collector->getData())->setImplicitHead(false);
        $request = new ServerRequest('HEAD', '/page');

        $before = $dispatcher->match($request);
        $this->assertSame(RouteMatch::METHOD_NOT_ALLOWED, $before->status);

        $dispatcher->setImplicitHead(true);

        $this->assertSame(200, $dispatcher->handle($request->withAttribute(RouteMatch::class, $before))->getStatusCode());
    }

    /**
     * The body goes once, at the very end. On its way out through the middleware for every
     * request the response still has it: an ETag or a Content-Length derived there is the
     * one GET gets (RFC 9110: the same header fields).
     */
    public function testMiddlewareSeesTheBodyGetWouldSend(): void
    {
        $etag = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $response = $handler->handle($request);
                $body = (string) $response->getBody();

                return $response
                    ->withHeader('ETag', '"' . md5($body) . '"')
                    ->withHeader('X-Length', (string) strlen($body));
            }
        };
        $toHead = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request->hasHeader('X-As-Head') ? $request->withMethod('HEAD') : $request);
            }
        };

        $router = $this->router(true)->middleware([$etag, $toHead]);

        $get = $router->handle(new ServerRequest('GET', '/users/5'));
        $head = $router->handle(new ServerRequest('HEAD', '/users/5'));
        $madeHead = $router->handle(new ServerRequest('POST', '/users/5')->withHeader('X-As-Head', '1'));

        $this->assertNotSame('"' . md5('') . '"', $get->getHeaderLine('ETag'));
        foreach (['HEAD' => $head, 'made HEAD on the way in' => $madeHead] as $label => $response) {
            $this->assertSame($get->getHeaders(), $response->getHeaders(), $label);
            $this->assertSame('', (string) $response->getBody(), $label);
        }
    }

    /**
     * HEAD made on the way in, and the answer comes from a middleware further in — one that
     * answers itself, or one that puts a body back on its way out. Still no body.
     */
    public function testHeadMadeOnTheWayInCountsForEveryoneFurtherIn(): void
    {
        $toHead = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request->withMethod('HEAD'));
            }
        };
        $answers = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return Response::text('early answer to ' . $request->getMethod(), 418);
            }
        };
        $putsBodyBack = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request)->withBody(\Nyholm\Psr7\Stream::create('body again'));
            }
        };

        $early = $this->router(true)->middleware([$toHead, $answers])->handle(new ServerRequest('POST', '/page'));
        $this->assertSame(418, $early->getStatusCode());
        $this->assertSame('', (string) $early->getBody());

        $late = $this->router(true)->middleware([$toHead, $putsBodyBack])->handle(new ServerRequest('POST', '/page'));
        $this->assertSame(200, $late->getStatusCode());
        $this->assertSame('', (string) $late->getBody());

        // Off, and for a request that never was HEAD: bodies stay
        $this->assertSame('early answer to HEAD', (string) $this->router(false)->middleware([$toHead, $answers])->handle(new ServerRequest('POST', '/page'))->getBody());
        $this->assertSame('early answer to POST', (string) $this->router(true)->middleware($answers)->handle(new ServerRequest('POST', '/page'))->getBody());
    }

    public function testAllowedMethodsOfAMatchAreThoseOfTheMomentItWasMade(): void
    {
        $collector = new RouteCollector();
        $collector->get('/page', fn () => Response::text('page'));
        $collector->post('/page', fn () => Response::text('posted'));
        $dispatcher = new RouteDispatcher($collector->getData())->setImplicitHead(false);

        $before = $dispatcher->match(new ServerRequest('GET', '/page'));
        $dispatcher->setImplicitHead(true);
        $after = $dispatcher->match(new ServerRequest('GET', '/page'));

        // Asked only now — the list is built on demand, but not with a later setting
        $this->assertSame(['GET', 'POST'], $before->allowedMethods());
        $this->assertSame(['GET', 'HEAD', 'POST'], $after->allowedMethods());
    }

    /**
     * The other direction: an application that turns HEAD into GET itself on the way in.
     * The client asked with HEAD, so the answer has no body.
     */
    public function testHeadTurnedIntoGetOnTheWayInStillHasNoBody(): void
    {
        $toGet = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request->getMethod() === 'HEAD' ? $request->withMethod('GET') : $request);
            }
        };

        $response = $this->router(true)->middleware($toGet)->handle(new ServerRequest('HEAD', '/page'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
    }

    /**
     * Known limit, on purpose: "HEAD" is a request that was HEAD at any step on its way in.
     * A middleware that delegates as HEAD, throws that answer away and delegates again as
     * POST still gets a bodiless answer — the body is cut once, at the very end, and what
     * the middleware did in between is not tracked.
     */
    public function testOnceHeadAlwaysWithoutBody(): void
    {
        $probesFirst = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $handler->handle($request->withMethod('HEAD'));

                return $handler->handle($request);
            }
        };

        $response = $this->router(true)->middleware($probesFirst)->handle(new ServerRequest('PATCH', '/page'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
    }
}
