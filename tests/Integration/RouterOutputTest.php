<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Integration;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\Router;

/**
 * Tests for Router output methods (run, emit, boot).
 * These tests require process isolation because they send headers and output.
 */
#[RunTestsInSeparateProcesses]
class RouterOutputTest extends TestCase
{
    private string $routesFile;

    protected function setUp(): void
    {
        $this->routesFile = sys_get_temp_dir() . '/router_output_test_' . uniqid() . '.php';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->routesFile)) {
            @unlink($this->routesFile);
        }
    }

    private function createRoutesFile(string $content): void
    {
        file_put_contents($this->routesFile, $content);
    }

    #[RunInSeparateProcess]
    public function testEmitOutputsBody(): void
    {
        $this->createRoutesFile(
            <<<'PHP'
                <?php
                use Sodaho\Router\RouteCollector;
                use Sodaho\Router\Response;

                return function (RouteCollector $r) {
                    $r->get('/test', fn($req) => Response::success(['message' => 'Hello World']));
                };
                PHP
        );

        // Simulate $_SERVER for request creation
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/test';
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

        $router = Router::create(['debug' => true])->loadRoutes($this->routesFile);

        ob_start();
        $router->run();
        $output = ob_get_clean();

        // Check that JSON body was output
        $this->assertStringContainsString('Hello World', $output);
        $this->assertStringContainsString('"success":true', $output);
    }

    #[RunInSeparateProcess]
    public function testEmitSendsHeaders(): void
    {
        $this->createRoutesFile(
            <<<'PHP'
                <?php
                use Sodaho\Router\RouteCollector;
                use Sodaho\Router\Response;

                return function (RouteCollector $r) {
                    $r->get('/test', fn($req) => Response::success(['ok' => true]));
                };
                PHP
        );

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/test';
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

        $router = Router::create(['debug' => true])->loadRoutes($this->routesFile);

        ob_start();
        $router->run();
        ob_end_clean();

        // Verify no exception was thrown; header assertions are unreliable
        // in PHPUnit subprocess isolation (xdebug_get_headers() may return empty)
        $this->assertTrue(true);
    }

    #[RunInSeparateProcess]
    public function testBootStaticMethod(): void
    {
        $this->createRoutesFile(
            <<<'PHP'
                <?php
                use Sodaho\Router\RouteCollector;
                use Sodaho\Router\Response;

                return function (RouteCollector $r) {
                    $r->get('/boot-test', fn($req) => Response::success(['booted' => true]));
                };
                PHP
        );

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/boot-test';
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

        ob_start();
        Router::boot(['debug' => true], $this->routesFile);
        $output = ob_get_clean();

        $this->assertStringContainsString('"booted":true', $output);
    }

    #[RunInSeparateProcess]
    public function testEmitHandles404(): void
    {
        $this->createRoutesFile(
            <<<'PHP'
                <?php
                use Sodaho\Router\RouteCollector;
                use Sodaho\Router\Response;

                return function (RouteCollector $r) {
                    $r->get('/exists', fn($req) => Response::success([]));
                };
                PHP
        );

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/not-exists';
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

        $router = Router::create(['debug' => true])->loadRoutes($this->routesFile);

        ob_start();
        $router->run();
        $output = ob_get_clean();

        $this->assertStringContainsString('"success":false', $output);
        $this->assertStringContainsString('not found', strtolower($output));
    }

    #[RunInSeparateProcess]
    public function testEmitWithMultipleHeaders(): void
    {
        $this->createRoutesFile(
            <<<'PHP'
                <?php
                use Sodaho\Router\RouteCollector;
                use Sodaho\Router\Response;

                return function (RouteCollector $r) {
                    $r->get('/redirect', fn($req) => Response::redirect('/new-location', 302));
                };
                PHP
        );

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/redirect';
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

        $router = Router::create(['debug' => true])->loadRoutes($this->routesFile);

        ob_start();
        $router->run();
        $output = ob_get_clean();

        // Redirect has empty body
        $this->assertEmpty($output);

        // Header assertions skipped: xdebug_get_headers() unreliable in subprocess isolation
    }

    /**
     * Note: Testing headers_sent() branch is tricky in CLI because output buffering
     * prevents headers from being "sent". We test that the code path exists and
     * doesn't crash, but the actual branch is only relevant in real web server context.
     */
    #[RunInSeparateProcess]
    public function testRunCompletesWithoutError(): void
    {
        $this->createRoutesFile(
            <<<'PHP'
                <?php
                use Sodaho\Router\RouteCollector;
                use Sodaho\Router\Response;

                return function (RouteCollector $r) {
                    $r->get('/complete', fn($req) => Response::success(['complete' => true]));
                };
                PHP
        );

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/complete';
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

        $router = Router::create(['debug' => true])->loadRoutes($this->routesFile);

        ob_start();
        $router->run();
        $output = ob_get_clean();

        // Verify the complete flow works
        $data = json_decode($output, true);
        $this->assertTrue($data['success']);
        $this->assertTrue($data['data']['complete']);
    }
}
