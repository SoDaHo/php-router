<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Feature;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\Response;
use Sodaho\Router\RouteCollector;
use Sodaho\Router\RouteDispatcher;

/**
 * A static route wins over a dynamic one — for HEAD as for GET. With implicitHead a HEAD to
 * /users/me went to a dynamic HEAD route /users/{id} with id 'me', while the GET to the same
 * path is answered by the static GET route /users/me: HEAD and GET told two stories.
 */
class HeadStaticWinsTest extends TestCase
{
    private function dispatcher(bool $implicitHead = true): RouteDispatcher
    {
        $collector = new RouteCollector();
        $collector->get('/users/me', fn () => Response::text('me')->withHeader('X-Route', 'static GET'));
        $collector->head('/users/{id}', fn ($request, string $id) => Response::text('')->withHeader('X-Route', 'dynamic HEAD ' . $id));
        $collector->head('/files/readme', fn () => Response::text('')->withHeader('X-Route', 'static HEAD'));
        $collector->get('/files/readme', fn () => Response::text('readme')->withHeader('X-Route', 'static GET'));
        $collector->get('/docs/{name}', fn ($request, string $name) => Response::text('doc')->withHeader('X-Route', 'dynamic GET'));
        $collector->head('/docs/{name}', fn ($request, string $name) => Response::text('')->withHeader('X-Route', 'dynamic HEAD'));

        return new RouteDispatcher($collector->getData())->setImplicitHead($implicitHead);
    }

    public function testStaticGetRouteWinsOverADynamicHeadRoute(): void
    {
        $dispatcher = $this->dispatcher();

        $head = $dispatcher->handle(new ServerRequest('HEAD', '/users/me'));
        $get = $dispatcher->handle(new ServerRequest('GET', '/users/me'));

        $this->assertSame('static GET', $head->getHeaderLine('X-Route'));
        $this->assertSame('', (string) $head->getBody());
        $this->assertSame($get->getHeaderLine('X-Route'), $head->getHeaderLine('X-Route'));

        $match = $dispatcher->match(new ServerRequest('HEAD', '/users/me'));
        $this->assertTrue($match->viaGet);
        $this->assertSame('/users/me', $match->route?->pattern);
        $this->assertSame([], $match->params);
    }

    public function testHeadRouteOfItsOwnStillAnswersWhereNoStaticGetRouteTakesThePath(): void
    {
        $dispatcher = $this->dispatcher();

        $this->assertSame('dynamic HEAD 7', $dispatcher->handle(new ServerRequest('HEAD', '/users/7'))->getHeaderLine('X-Route'));
        // Static HEAD beats static GET, dynamic HEAD beats dynamic GET: the method's own route
        $this->assertSame('static HEAD', $dispatcher->handle(new ServerRequest('HEAD', '/files/readme'))->getHeaderLine('X-Route'));
        $this->assertSame('dynamic HEAD', $dispatcher->handle(new ServerRequest('HEAD', '/docs/a'))->getHeaderLine('X-Route'));
    }

    public function testWithoutImplicitHeadEveryMethodKeepsItsOwnRoutes(): void
    {
        $dispatcher = $this->dispatcher(false);

        $this->assertSame('dynamic HEAD me', $dispatcher->handle(new ServerRequest('HEAD', '/users/me'))->getHeaderLine('X-Route'));
    }
}
