<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Integration;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\Router;

/**
 * Whether run() sends an answer without its body is decided for the one call that made it:
 * a response the dispatcher once cut for HEAD is an ordinary answer when a POST handler
 * returns it again. A candidate of 2.2.0 marked the cut response object for good — a POST
 * handler that returned it (an earlier HEAD answer kept, a HEAD sub-request's answer) had
 * it sent without a body, and its empty body under a Content-Length above 0 went out as a
 * 200 instead of being refused with a 500. RunRewrittenToHeadTest pins the POST a
 * middleware passes on as HEAD, which is still sent without its body.
 */
#[RunTestsInSeparateProcesses]
class RunReusedHeadAnswerTest extends TestCase
{
    /**
     * What run() sent for a POST to $path, and what the error hook heard (message and
     * status). The GET route answers 'hello world' with its Content-Length; before run(),
     * the router answers a HEAD to it once, and the POST route '/kept' returns that answer.
     * The POST route '/sub' returns the answer of a HEAD sub-request to the same router.
     *
     * @return array{string, int|bool, list<string>}
     */
    private function runPost(string $path): array
    {
        $routes = sys_get_temp_dir() . '/router_run_reused_' . uniqid() . '.php';
        file_put_contents($routes, <<<'PHP'
            <?php return function ($r) {
                $r->get('/page', fn () => \Sodaho\Router\Response::text('hello world')->withHeader('Content-Length', '11'));
                $r->post('/kept', fn () => $GLOBALS['router_test_kept']);
                $r->post('/sub', fn () => $GLOBALS['router_test_router']->handle(new \Nyholm\Psr7\ServerRequest('HEAD', '/page')));
            };
            PHP);
        $reports = [];

        ob_start();

        try {
            $router = Router::create(['debug' => false])
                ->loadRoutes($routes)
                ->on('error', function (array $data) use (&$reports): void {
                    $reports[] = $data['exception']->getMessage() . ' | ' . $data['status'];
                });
            $GLOBALS['router_test_router'] = $router;

            // What handle() returned for HEAD — the copy with the body cut, not what the
            // GET route made
            $kept = $router->handle(new ServerRequest('HEAD', '/page'));
            $this->assertSame(['11'], $kept->getHeader('Content-Length'));
            $this->assertSame('', (string) $kept->getBody());
            $GLOBALS['router_test_kept'] = $kept;

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['REQUEST_URI'] = $path;
            $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
            $router->run();
        } finally {
            $sent = (string) ob_get_clean();
            unlink($routes);
        }

        return [$sent, http_response_code(), $reports];
    }

    public function testAnEarlierHeadAnswerReturnedForAPostIsRefused(): void
    {
        $this->assertSame(
            ['Internal Server Error', 500, ['Response body is empty, but its Content-Length is not (an answer to HEAD is emitted with withBody false) | 500']],
            $this->runPost('/kept')
        );
    }

    public function testAHeadSubRequestsAnswerReturnedForAPostIsRefused(): void
    {
        $this->assertSame(
            ['Internal Server Error', 500, ['Response body is empty, but its Content-Length is not (an answer to HEAD is emitted with withBody false) | 500']],
            $this->runPost('/sub')
        );
    }
}
