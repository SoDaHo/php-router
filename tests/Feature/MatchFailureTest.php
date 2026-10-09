<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Feature;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\Router;

/**
 * A route expression that PCRE gives up on (the backtrack limit, the JIT stack) is a
 * failure, not "no match": the router answers 500 and reports it instead of handing the
 * request to the next route that matches (a catch-all), and url() says so.
 */
class MatchFailureTest extends TestCase
{
    private string $routesFile;
    private string $backtrackLimit;

    /** Nested quantifiers: a run of letters without the 'b' tries every way to split it */
    private const BOMB = '(?:a+)+b|[a-z]*c';

    private const INPUT = 'aaaaaaaaaaaaaaaaaaaac';

    protected function setUp(): void
    {
        // Low, so that the input stays short and the failure does not depend on php.ini
        $this->backtrackLimit = (string) ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1000');

        $this->routesFile = sys_get_temp_dir() . '/router_match_failure_' . uniqid() . '.php';
        file_put_contents(
            $this->routesFile,
            <<<'PHP'
                <?php
                use Sodaho\Router\Response;

                return function (Sodaho\Router\RouteCollector $r) {
                    $r->addPattern('bomb', '(?:a+)+b|[a-z]*c');
                    $r->get('/r/{x:bomb}', fn ($req, string $x) => Response::text('bomb: ' . $x))->name('bomb');
                    $r->get('/{path:any}', fn ($req, string $path) => Response::text('catch-all'));
                };
                PHP
        );
    }

    protected function tearDown(): void
    {
        ini_set('pcre.backtrack_limit', $this->backtrackLimit);
        unlink($this->routesFile);
    }

    private function router(): Router
    {
        return Router::create()->loadRoutes($this->routesFile);
    }

    public function testFixtureReallyExhaustsTheLimit(): void
    {
        $this->assertFalse(@preg_match('#^/r/(?P<x>' . self::BOMB . ')\z#', '/r/' . self::INPUT));
        $this->assertSame(PREG_BACKTRACK_LIMIT_ERROR, preg_last_error());
    }

    public function testRouteThatPcreGivesUpOnIsA500AndNotTheNextRoute(): void
    {
        $router = $this->router();
        $reported = [];
        $router->on('error', function (array $data) use (&$reported): void {
            $reported[] = $data;
        });

        $response = $router->handle(new ServerRequest('GET', '/r/' . self::INPUT));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('catch-all', (string) $response->getBody());
        // Once: the last resort that looks the request up again for the error handler gets
        // the same failure, not a second one
        $this->assertCount(1, $reported);
        $this->assertInstanceOf(RouterException::class, $reported[0]['exception']);
        $this->assertSame('Route pattern could not be matched: Backtrack limit exhausted', $reported[0]['exception']->getMessage());
        $this->assertSame('/r/{x:bomb}', $reported[0]['exception']->getDebugMessage());
        $this->assertSame(500, $reported[0]['status']);

        // A path the expression decides quickly is not touched
        $this->assertSame('bomb: aac', (string) $router->handle(new ServerRequest('GET', '/r/aac'))->getBody());
        $this->assertSame('catch-all', (string) $router->handle(new ServerRequest('GET', '/elsewhere'))->getBody());
    }

    public function testListOfAllowedMethodsDoesNotSkipARouteThatPcreGivesUpOn(): void
    {
        // No POST route: the 405 list asks every GET route — the bomb, then the catch-all
        $response = $this->router()->handle(new ServerRequest('POST', '/r/' . self::INPUT));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Allow'));
    }

    public function testUrlNamesTheFailureInsteadOfSayingTheValuesDoNotFit(): void
    {
        $this->expectException(RouterException::class);
        $this->expectExceptionMessage('The parameters could not be checked against the pattern of route "bomb": Backtrack limit exhausted');

        $this->router()->url('bomb', ['x' => self::INPUT]);
    }
}
