<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\RouteCollector;

/**
 * A string key names one middleware. Up to 2.1.1 a second one under the same key took the
 * place of the first — the 'auth' of a route quietly replaced the 'auth' of its group, and
 * a check the route's own middleware relied on no longer ran. Refused where it is written.
 */
class MiddlewareKeyTest extends TestCase
{
    private const TAKEN = 'Middleware key is taken already: a string key names one middleware, give the other one a key of its own';

    public function testInnerGroupCannotGiveAKeyOfAnOuterOneAgain(): void
    {
        $collector = new RouteCollector();
        $ran = false;

        $collector->middlewareGroup(['auth' => 'RequireLogin', 'log' => 'Log'], function (RouteCollector $r) use (&$ran): void {
            try {
                $r->middlewareGroup(['auth' => 'RequireAdmin'], function () use (&$ran): void {
                    $ran = true;
                });
                $this->fail('The key was given twice');
            } catch (RouterException $e) {
                $this->assertSame(self::TAKEN, $e->getMessage());
                $this->assertSame('auth', $e->getDebugMessage());
            }

            // The outer group's middleware holds for what follows
            $r->get('/after', 'handler');
        });

        $this->assertFalse($ran, 'the inner group ran');
        $this->assertSame(['auth' => 'RequireLogin', 'log' => 'Log'], $collector->getRoutes()[0]->middleware);
    }

    public function testRouteCannotGiveAKeyOfItsGroupAgain(): void
    {
        $collector = new RouteCollector();

        $collector->middlewareGroup(['auth' => 'RequireLogin'], function (RouteCollector $r): void {
            $route = $r->get('/key', 'handler');

            try {
                $route->middleware(['auth' => 'RequireAdmin']);
                $this->fail('The key was given twice');
            } catch (RouterException $e) {
                $this->assertSame(self::TAKEN, $e->getMessage());
            }

            $this->assertSame(['auth' => 'RequireLogin'], $route->middleware);
        });
    }

    public function testKeysOfTheirOwnAndNumberedEntriesAddUp(): void
    {
        $collector = new RouteCollector();

        $collector->middlewareGroup(['auth' => 'RequireLogin', 'First'], function (RouteCollector $r): void {
            $r->middlewareGroup(['admin' => 'RequireAdmin', 'Second'], function (RouteCollector $r): void {
                $r->get('/key', 'handler')->middleware(['audit' => 'Audit', 'Third']);
            });
        });

        $this->assertSame(
            ['auth' => 'RequireLogin', 0 => 'First', 'admin' => 'RequireAdmin', 1 => 'Second', 'audit' => 'Audit', 2 => 'Third'],
            $collector->getRoutes()[0]->middleware,
        );
    }
}
