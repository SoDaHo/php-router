<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\Exception\DuplicateRouteException;
use Sodaho\Router\Route;
use Sodaho\Router\RouteCollector;
use Sodaho\Router\Router;
use Sodaho\Router\UrlGenerator;

/**
 * A name belongs to one route. Two routes under one name gave url() the address of the
 * last — 'oauth.callback' could lead to a debug route defined further down.
 */
class RouteNameTest extends TestCase
{
    private const TAKEN = 'Route name is already taken: a name belongs to one route';

    public function testTwoRoutesUnderOneNameAreRefusedWhenTheTableIsBuilt(): void
    {
        $collector = new RouteCollector();
        $collector->get('/oauth/callback', 'handler')->name('oauth.callback');
        $collector->get('/debug/echo', 'handler')->name('oauth.callback');

        try {
            $collector->getData();
            $this->fail('The table was built');
        } catch (DuplicateRouteException $e) {
            $this->assertSame(self::TAKEN, $e->getMessage());
            $this->assertSame('oauth.callback: /oauth/callback and /debug/echo', $e->getDebugMessage());
        }
    }

    public function testRouterAnswers500AndUrlThrows(): void
    {
        $file = sys_get_temp_dir() . '/router_route_name_' . uniqid() . '.php';
        file_put_contents($file, <<<'PHP'
            <?php
            return function (Sodaho\Router\RouteCollector $r) {
                $r->get('/a', fn () => Sodaho\Router\Response::text('a'))->name('same');
                $r->post('/b', fn () => Sodaho\Router\Response::text('b'))->name('same');
            };
            PHP);

        try {
            $router = Router::create()->loadRoutes($file);
            $reported = [];
            $router->on('error', function (array $data) use (&$reported): void {
                $reported[] = $data;
            });

            $this->assertSame(500, $router->handle(new ServerRequest('GET', '/a'))->getStatusCode());
            $this->assertInstanceOf(DuplicateRouteException::class, $reported[0]['exception']);

            $this->expectException(DuplicateRouteException::class);
            $router->url('same');
        } finally {
            unlink($file);
        }
    }

    public function testNamesOfTheirOwnAndARouteRenamedAreFine(): void
    {
        $collector = new RouteCollector();
        $collector->get('/a', 'handler')->name('first')->name('a');
        $collector->get('/b', 'handler')->name('b');
        $collector->match(['GET', 'POST'], '/c', 'handler')->name('c');
        $collector->get('/d', 'handler');
        $collector->get('/e', 'handler');

        $collector->getData();

        $generator = new UrlGenerator($collector->getRoutes());
        $this->assertSame('/a', $generator->url('a'));
        $this->assertFalse($generator->hasRoute('first'));
    }

    /**
     * The same name on the same pattern (a form: GET shows it, POST takes it) names one
     * address — url() is not in doubt, nothing to refuse
     */
    public function testRoutesOfOnePatternMayShareAName(): void
    {
        $collector = new RouteCollector();
        $collector->get('/login', 'handler')->name('login');
        $collector->post('/login', 'handler')->name('login');

        $collector->getData();

        $this->assertSame('/login', new UrlGenerator($collector->getRoutes())->url('login'));
    }

    public function testUrlGeneratorRefusesTwoRouteObjectsUnderOneName(): void
    {
        $this->expectException(DuplicateRouteException::class);
        $this->expectExceptionMessage(self::TAKEN);

        new UrlGenerator([
            new Route(['GET'], '/a', 'handler', [], 'same'),
            new Route(['GET'], '/b', 'handler', [], 'same'),
        ]);
    }
}
