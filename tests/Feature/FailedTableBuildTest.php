<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Feature;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sodaho\Router\Exception\DuplicateRouteException;
use Sodaho\Router\Router;

/**
 * A routes file may register middleware, apps and hooks on the router itself. When the
 * table cannot be built, the next request runs the file again — and failed at the
 * middleware key (or app prefix) the first attempt had taken, so that every report after
 * the first named that instead of why the table cannot be built. A hook the attempt
 * registered goes with it as well.
 */
class FailedTableBuildTest extends TestCase
{
    private string $routesFile;
    private string $appDir;

    protected function setUp(): void
    {
        $this->appDir = sys_get_temp_dir() . '/router_failed_build_app_' . uniqid();
        mkdir($this->appDir);
        file_put_contents($this->appDir . '/index.html', '<p>app</p>');

        $this->routesFile = sys_get_temp_dir() . '/router_failed_build_' . uniqid() . '.php';
        file_put_contents($this->routesFile, sprintf(
            <<<'PHP'
                <?php
                use Sodaho\Router\Response;
                use Sodaho\Router\Tests\Feature\PassThrough;

                return function (Sodaho\Router\RouteCollector $r) {
                    $this->middleware(['cors' => new PassThrough()]);
                    $this->app('/app', %s);
                    // Belongs to the attempt as well: were it kept, it would hear the report
                    // of the very failure that dropped it
                    $this->on('error', function (): void {
                        $GLOBALS['failed_build_error_hook'] = ($GLOBALS['failed_build_error_hook'] ?? 0) + 1;
                    });
                    $r->get('/a', fn () => Response::text('a'))->name('same');
                    $r->get('/b', fn () => Response::text('b'))->name('same');
                };
                PHP,
            var_export($this->appDir, true),
        ));
    }

    protected function tearDown(): void
    {
        unlink($this->routesFile);
        unlink($this->appDir . '/index.html');
        rmdir($this->appDir);
        unset($GLOBALS['failed_build_error_hook']);
    }

    public function testEveryAttemptReportsWhyTheTableCannotBeBuilt(): void
    {
        $router = Router::create()->loadRoutes($this->routesFile);
        $reported = [];
        $router->on('error', function (array $data) use (&$reported): void {
            $reported[] = $data['exception'];
        });

        foreach ([1, 2, 3] as $attempt) {
            $this->assertSame(500, $router->handle(new ServerRequest('GET', '/a'))->getStatusCode(), "attempt {$attempt}");
        }

        $this->assertCount(3, $reported);
        foreach ($reported as $i => $exception) {
            $this->assertInstanceOf(DuplicateRouteException::class, $exception, 'attempt ' . ($i + 1));
            $this->assertSame('same: /a and /b', $exception->getDebugMessage());
        }

        // The hook the routes file registered went with each failed attempt
        $this->assertSame(0, $GLOBALS['failed_build_error_hook'] ?? 0);
    }
}

/**
 * Middleware of the fixture that does nothing but hand the request on.
 */
final class PassThrough implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }
}
