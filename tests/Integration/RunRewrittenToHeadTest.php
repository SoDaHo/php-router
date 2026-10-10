<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Integration;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sodaho\Router\Router;

/**
 * A request that came in as a POST and that a middleware passed on as HEAD is answered as
 * HEAD (implicitHead): the dispatcher cuts the body and keeps the GET's Content-Length.
 * run() has to send that answer as one to HEAD as well — a candidate of 2.2.0 decided by
 * the method the request came in with, sent it with its body, and refused the empty body
 * under a Content-Length above 0 with a 500. What the web server sends is checked in
 * EmitOverHttpTest.
 */
#[RunTestsInSeparateProcesses]
class RunRewrittenToHeadTest extends TestCase
{
    /**
     * What run() sent for a POST to $path, with or without the header that makes the
     * middleware pass it on as HEAD, and what the error hook heard (message and status).
     *
     * @return array{string, int|bool, list<string>}
     */
    private function runPost(string $path, bool $asHead, bool $implicitHead = true): array
    {
        $routes = sys_get_temp_dir() . '/router_run_head_' . uniqid() . '.php';
        file_put_contents($routes, <<<'PHP'
            <?php return function ($r) {
                $answer = fn () => \Sodaho\Router\Response::text('hello world')->withHeader('Content-Length', '11');
                $r->get('/page', $answer);
                $r->post('/page', $answer);
                // A status no response can go out with: run() answers 500 for it
                $r->get('/no-status', fn () => new \Nyholm\Psr7\Response(600, ['Content-Length' => '11'], 'hello world'));
            };
            PHP);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
        if ($asHead) {
            $_SERVER['HTTP_X_AS_HEAD'] = '1';
        }
        $reports = [];

        ob_start();

        try {
            Router::create(['debug' => false, 'implicitHead' => $implicitHead])
                ->loadRoutes($routes)
                ->middleware(new class () implements MiddlewareInterface {
                    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                    {
                        return $handler->handle($request->hasHeader('X-As-Head') ? $request->withMethod('HEAD') : $request);
                    }
                })
                ->on('error', function (array $data) use (&$reports): void {
                    $reports[] = $data['exception']->getMessage() . ' | ' . $data['status'];
                })
                ->run();
        } finally {
            $sent = (string) ob_get_clean();
            unlink($routes);
        }

        return [$sent, http_response_code(), $reports];
    }

    public function testPostPassedOnAsHeadGoesOutWithoutItsBody(): void
    {
        $this->assertSame(['', 200, []], $this->runPost('/page', asHead: true));
    }

    public function testPostThatStaysPostGoesOutWithItsBody(): void
    {
        $this->assertSame(['hello world', 200, []], $this->runPost('/page', asHead: false));
    }

    public function testWithImplicitHeadOffTheBodyIsNotCutAndGoesOut(): void
    {
        // HEAD on a GET route is the 405 it always was; nothing cut its body, so it goes out
        [$sent, $status, $reports] = $this->runPost('/page', asHead: true, implicitHead: false);

        $this->assertSame(405, $status);
        $this->assertStringContainsString('METHOD_NOT_ALLOWED', $sent);
        $this->assertSame([], $reports);
    }

    /**
     * The 500 run() sends where the answer cannot go out is the router's answer to the
     * request as it came in — a POST: with its text, as 2.1.1 sent it.
     */
    public function testRouters500ForAPostPassedOnAsHeadHasItsText(): void
    {
        $this->assertSame(
            ['Internal Server Error', 500, ['Response status code must be from 100 to 599 | 500']],
            $this->runPost('/no-status', asHead: true)
        );
    }
}
