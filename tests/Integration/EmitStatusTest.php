<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\Router;

/**
 * The status a handler returns is the status the client gets.
 *
 * PHP's header() rewrites the response code as a side effect: "WWW-Authenticate" forces 401,
 * "Location" forces 302 unless the code is 201 or 3xx. Up to 1.1.0 emit() sent the status
 * line first and the headers after it, so a 403 with a challenge left as 401 and a 202 with
 * a Location as 302. http_response_code() shows that rewriting in CLI too.
 */
#[RunTestsInSeparateProcesses]
class EmitStatusTest extends TestCase
{
    private string $routesFile;

    protected function setUp(): void
    {
        $this->routesFile = sys_get_temp_dir() . '/router_emit_status_' . uniqid() . '.php';

        file_put_contents(
            $this->routesFile,
            <<<'PHP'
                <?php
                use Sodaho\Router\RouteCollector;
                use Sodaho\Router\Response;

                return function (RouteCollector $r) {
                    $r->get('/forbidden', fn() => Response::forbidden()->withHeader('WWW-Authenticate', 'Bearer error="insufficient_scope"'));
                    $r->get('/unauthorized', fn() => Response::unauthorized()->withHeader('WWW-Authenticate', 'Bearer'));
                    $r->get('/accepted', fn() => Response::accepted(['job' => 7])->withHeader('Location', '/jobs/7'));
                    $r->get('/conflict', fn() => Response::error('exists', 409)->withHeader('Location', '/things/7'));
                    $r->get('/created', fn() => Response::created(['id' => 7], null, '/things/7'));
                    $r->get('/moved', fn() => Response::redirect('/new', 301));
                    $r->get('/found', fn() => Response::redirect('/new'));
                    $r->get('/location-only', fn() => Response::success('x')->withHeader('Location', '/new'));
                    $r->get('/plain', fn() => Response::success('x'));
                    $r->match(['GET', 'HEAD'], '/page', fn() => Response::text('BODY'));
                };
                PHP
        );
    }

    protected function tearDown(): void
    {
        if (file_exists($this->routesFile)) {
            @unlink($this->routesFile);
        }
    }

    private function serve(string $method, string $uri): string
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

        ob_start();
        Router::create(['debug' => false])->loadRoutes($this->routesFile)->run();

        return (string) ob_get_clean();
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function responsesWhoseHeadersRewriteTheStatus(): array
    {
        return [
            '403 with a challenge' => ['/forbidden', 403],
            '401 with a challenge' => ['/unauthorized', 401],
            '202 with a Location' => ['/accepted', 202],
            '409 with a Location' => ['/conflict', 409],
            '201 with a Location' => ['/created', 201],
            '301 redirect' => ['/moved', 301],
            '302 redirect' => ['/found', 302],
            'plain 200' => ['/plain', 200],
            // Never chose a status, only set a Location: PHP has always sent that as a redirect
            // and code building redirects by header alone relies on it. Stays so in 1.x.
            '200 with a Location' => ['/location-only', 302],
        ];
    }

    #[DataProvider('responsesWhoseHeadersRewriteTheStatus')]
    public function testStatusOfTheResponseIsTheStatusSent(string $uri, int $status): void
    {
        $this->serve('GET', $uri);

        $this->assertSame($status, http_response_code());
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function statusCodesALocationDoesNotRewrite(): array
    {
        return ['201' => [201], '301' => [301], '307' => [307]];
    }

    #[DataProvider('statusCodesALocationDoesNotRewrite')]
    public function testRedirectByHeaderDoesNotDependOnWhatTheHostSetBefore(int $hostStatus): void
    {
        // 201 and 3xx are the codes a Location leaves alone. Up to 1.1.0 the router's own
        // "200" came first and the redirect happened all the same.
        http_response_code($hostStatus);

        $this->serve('GET', '/location-only');

        $this->assertSame(302, http_response_code());
    }

    public function testStatusOfTheHostDoesNotSurviveTheResponse(): void
    {
        http_response_code(503);

        $this->serve('GET', '/plain');

        $this->assertSame(200, http_response_code());
    }

    public function testHeadRequestSendsNoBody(): void
    {
        $this->assertSame('BODY', $this->serve('GET', '/page'));
        $this->assertSame('', $this->serve('HEAD', '/page'));
        $this->assertSame(200, http_response_code());
    }

    public function testEmitCanBeCalledForAResponseOfOnesOwn(): void
    {
        $router = Router::create(['debug' => false]);

        ob_start();
        $router->emit(\Sodaho\Router\Response::json(['ok' => true], 202));
        $this->assertSame('{"ok":true}', ob_get_clean());
        $this->assertSame(202, http_response_code());

        ob_start();
        $router->emit(\Sodaho\Router\Response::text('never read'), withBody: false);
        $this->assertSame('', ob_get_clean());
        $this->assertSame(200, http_response_code());
    }
}
