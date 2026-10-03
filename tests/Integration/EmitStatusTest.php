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

    /**
     * @param array<string, mixed> $config
     */
    private function serve(string $method, string $uri, array $config = []): string
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

        ob_start();
        /** @phpstan-ignore argument.type */
        Router::create($config + ['debug' => false])->loadRoutes($this->routesFile)->run();

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
            // 1.x left this one to PHP, which made a 302 of it. A redirect is a 3xx status
            // (Response::redirect()); a 200 that names a Location is a 200.
            '200 with a Location' => ['/location-only', 200],
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
    public function testA200WithALocationStaysA200WhateverTheHostSetBefore(int $hostStatus): void
    {
        // 201 and 3xx are the codes a Location leaves alone: sent before the headers, the
        // host's status would survive. The response's own status goes out, last.
        http_response_code($hostStatus);

        $this->serve('GET', '/location-only');

        $this->assertSame(200, http_response_code());
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

        // run() leaves the body out itself — also where the router did not cut it: with
        // implicitHead off, the HEAD route of this path hands its body through untouched
        $this->assertSame('', $this->serve('HEAD', '/page', ['implicitHead' => false]));
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

    /**
     * @return array<string, array{0: array<string, mixed>, 1: int}>
     */
    public static function chunkSizes(): array
    {
        return [
            'default' => [[], 8192],
            'null is the default' => [['emitChunkSize' => null], 8192],
            'minimum' => [['emitChunkSize' => 1024], 1024],
            'raised' => [['emitChunkSize' => 1048576], 1048576],
            'from an env file' => [['emitChunkSize' => '65536'], 65536],
            'digits with zeros in front' => [['emitChunkSize' => '0065536'], 65536],
            'maximum' => [['emitChunkSize' => 16 * 1024 * 1024], 16 * 1024 * 1024],
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('chunkSizes')]
    public function testBodyIsPulledInChunksOfTheConfiguredSize(array $config, int $expected): void
    {
        $asked = [];
        $body = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $body->method('isReadable')->willReturn(true);
        $body->method('isSeekable')->willReturn(false);
        $body->method('eof')->willReturnOnConsecutiveCalls(false, false, true);
        $body->method('read')->willReturnCallback(function (int $length) use (&$asked): string {
            $asked[] = $length;

            return 'x';
        });

        /** @phpstan-ignore argument.type */
        $router = Router::create($config + ['debug' => false]);

        ob_start();
        $router->emit((new \Nyholm\Psr7\Response(200))->withBody($body));
        $sent = ob_get_clean();

        $this->assertSame('xx', $sent);
        $this->assertSame([$expected, $expected], $asked);
    }

    // ==================== a request that cannot be read ====================

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function requestsThePsr7ObjectsDoNotAccept(): array
    {
        return [
            // Sent by a client as it stands: curl -H 'Host: x:99999999'
            'Host with a port that is none' => [['HTTP_HOST' => 'x:99999999']],
            'header value with a control character' => [['HTTP_X_TEST' => "a\x0Bb"]],
            'header without a name' => [['HTTP_' => 'x']],
        ];
    }

    /**
     * 1.x let the exception leave run(): a 500 from PHP itself, with the stack trace on
     * the page where display_errors is on.
     *
     * @param array<string, string> $server
     */
    #[DataProvider('requestsThePsr7ObjectsDoNotAccept')]
    public function testRequestThatCannotBeReadIsAnsweredWith400(array $server): void
    {
        $_SERVER = $server + $_SERVER;
        $reports = [];

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/plain?x=1';

        ob_start();
        Router::create(['debug' => false])
            ->loadRoutes($this->routesFile)
            ->on('error', function (array $data) use (&$reports): void {
                $e = $data['exception'];
                $reports[] = [$e::class, $e->getMessage(), $e->getPrevious() !== null ? $e->getPrevious()::class : null, $data['method'], $data['path'], $data['status']];
            })
            ->run();
        $sent = (string) ob_get_clean();

        $this->assertSame(400, http_response_code());
        $this->assertSame('{"success":false,"message":"Bad Request","error":{"message":"Bad Request","code":"BAD_REQUEST"}}', $sent);
        // Reported with a message that does not repeat what the client sent; that is in
        // the exception behind it
        $this->assertSame(
            [[\Sodaho\Router\Exception\RouterException::class, 'The request could not be read', \InvalidArgumentException::class, 'GET', '/plain', 400]],
            $reports
        );
    }

    public function testRequestThatCannotBeReadIsReportedEvenWithoutMethodAndAddress(): void
    {
        $_SERVER['HTTP_HOST'] = 'x:99999999';
        // As in a script that is not run by a web server
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
        $reports = [];

        ob_start();
        Router::create(['debug' => false])
            ->on('error', function (array $data) use (&$reports): void {
                $reports[] = [$data['method'], $data['path']];
            })
            ->run();
        ob_end_clean();

        $this->assertSame(400, http_response_code());
        $this->assertSame([['', '']], $reports);
    }

    public function testRequestThatCannotBeBuiltForAnotherReasonIsA500(): void
    {
        // Not what PHP makes of an upload — but an application can rewrite $_FILES, and the
        // PSR-17 factory answers this shape with a TypeError
        $_FILES = ['f' => ['name' => ['a.txt'], 'type' => 'text/plain', 'tmp_name' => __FILE__, 'error' => 0, 'size' => 3]];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/plain';
        $reports = [];

        ob_start();
        Router::create(['debug' => false])
            ->loadRoutes($this->routesFile)
            ->on('error', function (array $data) use (&$reports): void {
                $reports[] = [$data['exception']::class, $data['method'], $data['path'], $data['status']];
            })
            ->run();
        $sent = (string) ob_get_clean();

        $this->assertSame(500, http_response_code());
        $this->assertSame('{"success":false,"message":"Internal Server Error","error":{"message":"Internal Server Error","code":"SERVER_ERROR"}}', $sent);
        $this->assertSame([[\TypeError::class, 'POST', '/plain', 500]], $reports);
    }

    public function testBodyThatFailsWhileItIsSentIsReportedAndNothingMoreGoesOut(): void
    {
        $body = $this->createStub(\Psr\Http\Message\StreamInterface::class);
        $body->method('isReadable')->willReturn(true);
        $body->method('isSeekable')->willReturn(false);
        $body->method('eof')->willReturn(false);
        $body->method('read')->willReturnCallback(function (): string {
            static $calls = 0;

            return ++$calls === 1 ? 'partial-' : throw new \RuntimeException('read failed');
        });
        $GLOBALS['emit_status_body'] = $body;

        $routes = sys_get_temp_dir() . '/router_emit_mid_' . uniqid() . '.php';
        file_put_contents($routes, '<?php return function ($r) {
            $r->get("/mid", fn () => (new Nyholm\Psr7\Response(200))->withBody($GLOBALS["emit_status_body"]));
        };');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/mid';
        $reports = [];

        ob_start();

        try {
            // 1.x let the exception out: with display_errors on, the stack trace followed the partial body
            Router::create(['debug' => false])
                ->loadRoutes($routes)
                ->on('error', function (array $data) use (&$reports): void {
                    $reports[] = [$data['exception']->getMessage(), $data['method'], $data['path']];
                })
                ->run();
        } finally {
            $sent = (string) ob_get_clean();
            unlink($routes);
            unset($GLOBALS['emit_status_body']);
        }

        $this->assertSame('partial-', $sent);
        $this->assertSame([['read failed', 'GET', '/mid']], $reports);
    }

    public function testRouterExceptionInTheMiddleOfTheBodyIsOnlyReported(): void
    {
        // What fails after the first byte is reported — whatever its class — and nothing
        // is appended: no "Internal Server Error" behind the part that went out
        $body = $this->createStub(\Psr\Http\Message\StreamInterface::class);
        $body->method('isReadable')->willReturn(true);
        $body->method('isSeekable')->willReturn(false);
        $body->method('eof')->willReturn(false);
        $body->method('read')->willReturnCallback(function (): string {
            static $calls = 0;

            return ++$calls === 1 ? 'A' : throw new \Sodaho\Router\Exception\RouterException('router exception mid-body');
        });

        [$sent, $reports] = $this->runWith(fn () => (new \Nyholm\Psr7\Response(503))->withBody($body));

        $this->assertSame('A', $sent);
        $this->assertSame(503, http_response_code());
        // The status that went out with the headers
        $this->assertSame(['router exception mid-body | 503'], $reports);
    }

    public function testHandleOfASubclassThatThrowsGivesA500(): void
    {
        // Router::handle() does not throw; one of a subclass may (Router is not final yet)
        $router = new class (['debug' => false]) extends Router {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                throw new \RuntimeException('override failed');
            }
        };
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/plain';
        $reports = [];

        ob_start();
        $router->on('error', function (array $data) use (&$reports): void {
            $reports[] = [$data['exception']->getMessage(), $data['method'], $data['path'], $data['status']];
        })->run();
        $sent = (string) ob_get_clean();

        $this->assertSame('Internal Server Error', $sent);
        $this->assertSame(500, http_response_code());
        $this->assertSame([['override failed', 'GET', '/plain', 500]], $reports);
    }

    public function testHeaderThatFailsBeforeTheStatusLineReportsTheStatusPhpSet(): void
    {
        // A header PHP refuses (a line break in the value; the PSR-7 object of an
        // application may not check), after a Location that made PHP's status a 302
        $response = new class (200) extends \Nyholm\Psr7\Response {
            public function getHeaders(): array
            {
                return ['Location' => ['/there'], 'X-Broken' => ["a\nb"]];
            }
        };

        set_error_handler(static function (int $severity, string $message): bool {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            [$sent, $reports] = $this->runWith(fn () => $response);
        } finally {
            restore_error_handler();
        }

        $this->assertSame('', $sent);
        $this->assertSame(302, http_response_code());
        $this->assertCount(1, $reports);
        $this->assertStringEndsWith(' | 302', $reports[0]);
    }

    public function testFirstHeaderThatFailsUnderTheCliReportsThe200PhpWouldSend(): void
    {
        $response = new class (503) extends \Nyholm\Psr7\Response {
            public function getHeaders(): array
            {
                return ['X-Broken' => ["a\nb"]];
            }
        };

        set_error_handler(static function (int $severity, string $message): bool {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            [$sent, $reports] = $this->runWith(fn () => $response);
        } finally {
            restore_error_handler();
        }

        // Nothing set a status yet: the CLI keeps none, a server would send its 200
        $this->assertSame('', $sent);
        $this->assertFalse(http_response_code());
        $this->assertCount(1, $reports);
        $this->assertStringEndsWith(' | 200', $reports[0]);
    }

    public function testGetterThatThrowsBeforeTheFirstByteGivesA500(): void
    {
        $response = new class () extends \Nyholm\Psr7\Response {
            public function getProtocolVersion(): string
            {
                throw new \RuntimeException('protocol failed');
            }
        };

        [$sent, $reports] = $this->runWith(fn () => $response);

        $this->assertSame('Internal Server Error', $sent);
        $this->assertSame(500, http_response_code());
        $this->assertSame(['protocol failed | 500'], $reports);
    }

    /**
     * run() for one route that answers with what $respond returns.
     *
     * @return array{0: string, 1: list<string>} What was sent, and what the error hook heard
     */
    private function runWith(\Closure $respond): array
    {
        $GLOBALS['emit_status_respond'] = $respond;
        $routes = sys_get_temp_dir() . '/router_emit_with_' . uniqid() . '.php';
        file_put_contents($routes, '<?php return function ($r) { $r->get("/with", fn () => ($GLOBALS["emit_status_respond"])()); };');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/with';
        $reports = [];

        ob_start();

        try {
            Router::create(['debug' => false])
                ->loadRoutes($routes)
                ->on('error', function (array $data) use (&$reports): void {
                    $reports[] = $data['exception']->getMessage() . ' | ' . $data['status'];
                })
                ->run();
        } finally {
            $sent = (string) ob_get_clean();
            unlink($routes);
            unset($GLOBALS['emit_status_respond']);
        }

        return [$sent, $reports];
    }

    public function testBodyWhoseReadabilityCannotBeAskedIsAnsweredWith500(): void
    {
        $body = $this->createStub(\Psr\Http\Message\StreamInterface::class);
        $body->method('isReadable')->willThrowException(new \LogicException('isReadable failed'));
        $GLOBALS['emit_status_body'] = $body;

        $routes = sys_get_temp_dir() . '/router_emit_ask_' . uniqid() . '.php';
        file_put_contents($routes, '<?php return function ($r) {
            $r->get("/ask", fn () => (new Nyholm\Psr7\Response(200))->withBody($GLOBALS["emit_status_body"]));
        };');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/ask';
        $reports = [];

        ob_start();

        try {
            Router::create(['debug' => false])
                ->loadRoutes($routes)
                ->on('error', function (array $data) use (&$reports): void {
                    $reports[] = [$data['exception']->getMessage(), $data['exception']->getPrevious()?->getMessage()];
                })
                ->run();
        } finally {
            $sent = (string) ob_get_clean();
            unlink($routes);
            unset($GLOBALS['emit_status_body']);
        }

        $this->assertSame('Internal Server Error', $sent);
        $this->assertSame(500, http_response_code());
        $this->assertSame([['Response body is not readable (closed or detached before emit)', 'isReadable failed']], $reports);
    }

    public function testRequestThatCannotBeReadIsNotEchoedInDebugModeEither(): void
    {
        $_SERVER['HTTP_HOST'] = 'x:99999999';
        $debug = [];

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/plain';

        ob_start();
        Router::create(['debug' => true])
            ->loadRoutes($this->routesFile)
            ->on('error', function (array $data) use (&$debug): void {
                $debug[] = $data['exception']->getDebugMessage();
            })
            ->run();
        $sent = (string) ob_get_clean();

        $this->assertSame(400, http_response_code());
        $this->assertSame('{"success":false,"message":"Bad Request","error":{"message":"Bad Request","code":"BAD_REQUEST"}}', $sent);
        // The reason is where the application reads it
        $this->assertCount(1, $debug);
        $this->assertStringStartsWith('Invalid port: 99999999.', (string) $debug[0]);
    }

    public function testResponseWhoseBodyWasClosedIsAnsweredWith500(): void
    {
        $routes = sys_get_temp_dir() . '/router_emit_closed_' . uniqid() . '.php';
        file_put_contents($routes, '<?php return function ($r) {
            $r->get("/closed", function () {
                $response = Sodaho\Router\Response::text("gone");
                $response->getBody()->close();

                return $response;
            });
        };');
        $reports = [];

        try {
            // HEAD: the answer loses its body before run() looks at it — there is nothing
            // left that could not be read
            foreach (['GET' => [500, 'Internal Server Error'], 'HEAD' => [200, '']] as $method => [$status, $body]) {
                $_SERVER['REQUEST_METHOD'] = $method;
                $_SERVER['REQUEST_URI'] = '/closed';

                ob_start();
                // 1.x let the exception out of run(): PHP's own answer, no report
                Router::create(['debug' => false])
                    ->loadRoutes($routes)
                    ->on('error', function (array $data) use (&$reports): void {
                        $reports[] = [$data['exception']->getMessage(), $data['method'], $data['path']];
                    })
                    ->run();

                $this->assertSame($body, (string) ob_get_clean());
                $this->assertSame($status, http_response_code());
            }
        } finally {
            unlink($routes);
        }

        $this->assertSame(
            [['Response body is not readable (closed or detached before emit)', 'GET', '/closed']],
            $reports
        );
    }

    public function testRequestThatCannotBeReadGetsNoBodyForHead(): void
    {
        $_SERVER['HTTP_HOST'] = 'x:99999999';

        $this->assertSame('', $this->serve('HEAD', '/plain'));
        $this->assertSame(400, http_response_code());
    }

    private static function responderThatFails(): void
    {
        \Sodaho\Router\Response::setResponder(new class () implements \Sodaho\Router\Contract\ResponderInterface {
            public function formatSuccess(mixed $data, ?string $message = null, ?array $meta = null): array
            {
                return ['data' => $data];
            }

            public function formatError(string $message, ?string $code = null, ?array $details = null): array
            {
                throw new \LogicException('responder failed');
            }

            public function getContentType(): string
            {
                return 'application/json';
            }

            public function getSuccessContentType(): string
            {
                return 'application/json';
            }
        });
    }

    public function testRequestThatCannotBeReadIsAnsweredWithoutTheResponderWhenThatFails(): void
    {
        $_SERVER['HTTP_HOST'] = 'x:99999999';
        self::responderThatFails();

        $reports = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/plain';

        ob_start();
        Router::create(['debug' => false])
            ->loadRoutes($this->routesFile)
            ->on('error', function (array $data) use (&$reports): void {
                $reports[] = [$data['exception']->getMessage(), $data['status']];
            })
            ->run();

        $this->assertSame('Bad Request', (string) ob_get_clean());
        $this->assertSame(400, http_response_code());
        // Both are reported: what was wrong with the request, and the responder — the answer is a 400
        $this->assertSame([['The request could not be read', 400], ['responder failed', 400]], $reports);
    }

    public function testRequestThatCannotBeBuiltForAnotherReasonStaysA500WhenTheResponderFails(): void
    {
        $_FILES = ['f' => ['name' => ['a.txt'], 'type' => 'text/plain', 'tmp_name' => __FILE__, 'error' => 0, 'size' => 3]];
        self::responderThatFails();

        $reports = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/plain';

        ob_start();
        Router::create(['debug' => false])
            ->loadRoutes($this->routesFile)
            ->on('error', function (array $data) use (&$reports): void {
                $reports[] = [$data['exception']::class, $data['status'], array_keys($data)];
            })
            ->run();

        $this->assertSame('Internal Server Error', (string) ob_get_clean());
        $this->assertSame(500, http_response_code());
        // status comes last, after the keys these reports had before
        $keys = ['exception', 'method', 'path', 'status'];
        $this->assertSame([[\TypeError::class, 500, $keys], [\LogicException::class, 500, $keys]], $reports);
    }
}
