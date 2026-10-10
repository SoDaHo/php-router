<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Security;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\Router;

/**
 * Two placeholders that take slashes in one route (/{a:any}/{b:any}) leave PCRE every way
 * to split the path between them: the work grows with the square of its segments, where one
 * such placeholder grows with their number. A long path reaches PCRE's limit — answered
 * with 500 and reported, never as "no match" (see MatchFailureTest). Documented in the
 * README ("Custom Patterns", "Limitations").
 */
class TwoAnyPlaceholdersTest extends TestCase
{
    private string $routesFile;
    private string $backtrackLimit;

    protected function setUp(): void
    {
        // A fixed limit, so that the outcome depends on the growth and not on php.ini
        $this->backtrackLimit = (string) ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '100000');

        $this->routesFile = sys_get_temp_dir() . '/router_two_any_' . uniqid() . '.php';
        file_put_contents(
            $this->routesFile,
            <<<'PHP'
                <?php
                use Sodaho\Router\Response;

                return function (Sodaho\Router\RouteCollector $r) {
                    $r->get('/one/{a:any}/end', fn ($req, string $a) => Response::text('one'));
                    $r->get('/two/{a:any}/{b:any}/end', fn ($req, string $a, string $b) => Response::text('two'));
                };
                PHP
        );
    }

    protected function tearDown(): void
    {
        ini_set('pcre.backtrack_limit', $this->backtrackLimit);
        unlink($this->routesFile);
    }

    /**
     * A path that contains '/end', but not at its end: PCRE has to try the splits.
     */
    private static function path(string $route, int $segments): string
    {
        return '/' . $route . '/' . implode('/', array_fill(0, $segments, 's')) . '/end/x';
    }

    public function testOnePlaceholderThatTakesSlashesStaysWithinTheLimit(): void
    {
        $router = Router::create()->loadRoutes($this->routesFile);

        $this->assertSame(404, $router->handle(new ServerRequest('GET', self::path('one', 400)))->getStatusCode());
    }

    public function testTwoOfThemReachTheLimitWithALongPathAndAnswer500(): void
    {
        $router = Router::create()->loadRoutes($this->routesFile);
        $reported = [];
        $router->on('error', function (array $data) use (&$reported): void {
            $reported[] = $data;
        });

        // A short path is decided ...
        $this->assertSame(404, $router->handle(new ServerRequest('GET', self::path('two', 100)))->getStatusCode());
        $this->assertSame([], $reported);

        // ... four times as many segments take about sixteen times the work
        $response = $router->handle(new ServerRequest('GET', self::path('two', 400)));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertCount(1, $reported);
        $this->assertInstanceOf(RouterException::class, $reported[0]['exception']);
        $this->assertSame('Route pattern could not be matched: Backtrack limit exhausted', $reported[0]['exception']->getMessage());
        $this->assertSame('/two/{a:any}/{b:any}/end', $reported[0]['exception']->getDebugMessage());
    }
}
