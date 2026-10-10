<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Feature;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sodaho\Router\Contract\ResponderInterface;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\Response;
use Sodaho\Router\Router;

/**
 * handle() answers, whatever fails and whatever fails next: the handler, a middleware, the
 * routes file, the error handler, the application's responder, an error hook, the request
 * or a response object. The error hook gets every exception once.
 */
class HandleNeverThrowsTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        Response::reset();

        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    private function routes(string $body): string
    {
        $file = sys_get_temp_dir() . '/router_never_' . uniqid() . '.php';
        file_put_contents($file, '<?php ' . $body);

        return $this->files[] = $file;
    }

    /**
     * @return array{0: Router, 1: string} The router, and how the first report begins
     */
    private function failing(string $source): array
    {
        $working = 'return function ($r) {
            $r->get("/x", fn () => throw new \RuntimeException("handler failed"));
            $r->get("/text", fn () => "no response");
        };';
        $throwing = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                throw new \RuntimeException('middleware failed');
            }
        };

        return match ($source) {
            'handler' => [Router::create()->loadRoutes($this->routes($working)), 'handler failed'],
            'handler that returns no response' => [Router::create()->loadRoutes($this->routes(str_replace(['"/x"', '"/text"'], ['"/y"', '"/x"'], $working))), 'Handler must return ResponseInterface'],
            'middleware for every request' => [Router::create()->loadRoutes($this->routes($working))->middleware($throwing), 'middleware failed'],
            default => throw new \LogicException('Unknown source: ' . $source),
            'no routes file' => [Router::create(), 'No routes loaded'],
            'routes file that throws' => [Router::create()->loadRoutes($this->routes('throw new \RuntimeException("routes failed");')), 'routes failed'],
            // An \Error, not an \Exception
            'routes file with a syntax error' => [Router::create()->loadRoutes($this->routes('return function ($r) { $r->get( };')), "Unclosed '('"],
            'routes file that returns nothing to call' => [Router::create()->loadRoutes($this->routes('return 5;')), 'Route file must return callable'],
            'route table that cannot be built' => [Router::create()->loadRoutes($this->routes('return function ($r) { $r->get("/x/{id:integer}", "h"); };')), 'Route pattern uses a pattern type that is not defined'],
            'route that cannot be registered' => [Router::create()->loadRoutes($this->routes('return function ($r) { $r->get("/x/{id}/{id}", "h"); };')), 'Placeholder is used twice'],
        };
    }

    private static function failingResponder(): ResponderInterface
    {
        return new class () implements ResponderInterface {
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
        };
    }

    /**
     * @return \Generator<string, array{0: string, 1: string, 2: bool, 3: bool, 4: string}>
     */
    public static function everythingThatCanFail(): \Generator
    {
        $sources = [
            'handler', 'handler that returns no response', 'middleware for every request', 'no routes file',
            'routes file that throws', 'routes file with a syntax error', 'routes file that returns nothing to call',
            'route table that cannot be built',
            'route that cannot be registered',
        ];
        $errorHandlers = ['none', 'answers', 'declines', 'throws', 'throws what it was given', 'returns no response'];

        foreach ($sources as $source) {
            foreach ($errorHandlers as $errorHandler) {
                foreach ([false, true] as $responderFails) {
                    foreach ([false, true] as $hookFails) {
                        foreach (['GET', 'HEAD'] as $method) {
                            $label = sprintf(
                                '%s %s, error handler %s%s%s',
                                $method,
                                $source,
                                $errorHandler,
                                $responderFails ? ', responder fails' : '',
                                $hookFails ? ', hook fails' : ''
                            );

                            yield $label => [$source, $errorHandler, $responderFails, $hookFails, $method];
                        }
                    }
                }
            }
        }
    }

    #[DataProvider('everythingThatCanFail')]
    public function testHandleAnswers(string $source, string $errorHandler, bool $responderFails, bool $hookFails, string $method): void
    {
        [$router, $first] = $this->failing($source);

        $reports = [];
        $router->on('error', function (array $data) use (&$reports): void {
            $reports[] = [$data['exception']->getMessage(), $data['method'], $data['path'], $data['exception']];
        });

        $hookErrors = 0;
        if ($hookFails) {
            $router->on('error', fn () => throw new \LogicException('hook failed'));
            // Without this the trait writes a line to stderr for each one
            $router->on('hookError', function () use (&$hookErrors): void {
                $hookErrors++;
            });
        }

        $asked = 0;
        if ($errorHandler !== 'none') {
            /** @phpstan-ignore argument.type (one case returns no response on purpose, see 'returns no response') */
            $router->setErrorHandler(function (\Throwable $e) use ($errorHandler, &$asked) {
                $asked++;

                return match ($errorHandler) {
                    'answers' => Response::text('custom', 503),
                    'declines' => null,
                    'throws' => throw new \LogicException('error handler failed'),
                    'throws what it was given' => throw $e,
                    // A value an error handler must not return: the router reports the TypeError
                    'returns no response' => 'text',
                    default => throw new \LogicException('Unknown error handler: ' . $errorHandler),
                };
            });
        }

        if ($responderFails) {
            Response::setResponder(self::failingResponder());
        }

        // No try: an exception here is the failure of this test
        $response = $router->handle(new ServerRequest($method, '/x'));

        // What was reported, in order: the failure, then what failed while it was answered —
        // each by how its message begins, or (where PHP words it) by its type
        $expected = [$first];
        if ($errorHandler === 'throws') {
            $expected[] = 'error handler failed';
        } elseif ($errorHandler === 'returns no response') {
            // The router's return type refuses the string: a TypeError of its own, whatever
            // PHP's message and the router's inner method say
            $expected[] = \TypeError::class;
        }
        if ($responderFails && $errorHandler !== 'answers') {
            $expected[] = 'responder failed';
        }

        $this->assertCount(count($expected), $reports, implode(' | ', array_column($reports, 0)));
        foreach ($expected as $i => $begin) {
            if ($begin === \TypeError::class) {
                $this->assertInstanceOf(\TypeError::class, $reports[$i][3]);
                $this->assertNull($reports[$i][3]->getPrevious());
            } else {
                // @phpstan-ignore argument.type (each beginning is written out above, none is empty)
                $this->assertStringStartsWith($begin, $reports[$i][0]);
            }
            $this->assertSame([$method, '/x'], [$reports[$i][1], $reports[$i][2]]);
        }

        // A hook that fails changes nothing — and the application hears of each failure
        $this->assertSame($hookFails ? count($expected) : 0, $hookErrors);

        // The error handler is asked once for the one exception there is
        $this->assertSame($errorHandler === 'none' ? 0 : 1, $asked);

        if ($errorHandler === 'answers') {
            $this->assertSame(503, $response->getStatusCode());
            $body = 'custom';
        } elseif ($responderFails) {
            // The answer that depends on nothing the application can replace
            $this->assertSame(500, $response->getStatusCode());
            $this->assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
            $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
            $body = 'Internal Server Error';
        } else {
            $this->assertSame(500, $response->getStatusCode());
            $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
            $body = '{"success":false,"message":"Internal Server Error","error":{"message":"Internal Server Error","code":"SERVER_ERROR"}}';
        }

        // No answer to HEAD carries a body
        $this->assertSame($method === 'HEAD' ? '' : $body, (string) $response->getBody());
    }

    /**
     * A routes file that declares a function cannot be required twice ("Cannot redeclare",
     * which nothing catches). 1.x and the betas before required it again with every
     * handle() whose table could not be built.
     */
    public function testRoutesFileIsLoadedOnceAlsoWhenTheTableCannotBeBuilt(): void
    {
        $id = uniqid();
        $GLOBALS['never_' . $id] = ['loaded' => 0, 'called' => 0];

        $routes = $this->routes(sprintf('
            function routes_helper_%1$s(): void {}
            $GLOBALS["never_%1$s"]["loaded"]++;

            return function ($r) {
                $GLOBALS["never_%1$s"]["called"]++;
                $r->get("/x/{id:integer}", "handler");
            };', $id));

        $reports = [];
        $router = Router::create()->loadRoutes($routes)->on('error', function (array $data) use (&$reports): void {
            $reports[] = $data['exception']->getMessage();
        });

        foreach ([1, 2, 3] as $call) {
            $this->assertSame(500, $router->handle(new ServerRequest('GET', '/x/5'))->getStatusCode());
            // The file is loaded once; its callable is tried again
            $this->assertSame(['loaded' => 1, 'called' => $call], $GLOBALS['never_' . $id]);
        }

        $this->assertCount(3, $reports);
        $this->assertStringStartsWith('Route pattern uses a pattern type that is not defined', $reports[2]);

        unset($GLOBALS['never_' . $id]);
    }

    /**
     * The file is looked for until it was loaded, and not afterwards: a relative path
     * stays this router's file when the working directory changes.
     */
    public function testRoutesFileIsLookedForUntilItWasLoaded(): void
    {
        $routes = $this->routes('return function ($r) { $r->get("/x/{id:integer}", "handler"); };');

        $cwd = (string) getcwd();
        chdir(dirname($routes));

        try {
            $relative = Router::create()->loadRoutes(basename($routes));
            $reports = [];
            $relative->on('error', function (array $data) use (&$reports): void {
                $reports[] = $data['exception']->getMessage();
            });
            $relative->handle(new ServerRequest('GET', '/x/5'));
            chdir('/');
            $relative->handle(new ServerRequest('GET', '/x/5'));
        } finally {
            chdir($cwd);
        }

        $this->assertCount(2, $reports);
        $this->assertStringStartsWith('Route pattern uses a pattern type that is not defined', $reports[1]);

        // A file that is not there yet is looked for again
        $later = sys_get_temp_dir() . '/router_never_later_' . uniqid() . '.php';
        $this->files[] = $later;
        $waiting = Router::create()->loadRoutes($later);
        $this->assertSame(500, $waiting->handle(new ServerRequest('GET', '/x'))->getStatusCode());
        file_put_contents($later, '<?php return function ($r) { $r->get("/x", fn () => Sodaho\Router\Response::text("ok")); };');
        $this->assertSame('ok', (string) $waiting->handle(new ServerRequest('GET', '/x'))->getBody());
    }



    /**
     * The error handler throws F in one delegation, and its answer refuses to lose its
     * body with that very F in another: the router reports F, its dispatcher comes across
     * it again — one report, the ledger is shared.
     */
    public function testExceptionReportedByTheRouterIsNotReportedAgainByItsDispatcher(): void
    {
        $f = new \RuntimeException('F');
        $twice = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $handler->handle($request->withUri($request->getUri()->withPath('/one')));

                return $handler->handle($request->withUri($request->getUri()->withPath('/two')));
            }
        };

        $reports = [];
        $response = Router::create()
            ->loadRoutes($this->routes('return function ($r) {
                $r->get("/one", fn () => throw new \RuntimeException("E1"));
                $r->get("/two", fn () => throw new \RuntimeException("E2"));
            };'))
            ->middleware($twice)
            ->setErrorHandler(function (\Throwable $e) use ($f): ResponseInterface {
                if ($e->getMessage() === 'E1') {
                    throw $f;
                }

                $stubborn = $this->createStub(ResponseInterface::class);
                $stubborn->method('withBody')->willThrowException($f);

                return $stubborn;
            })
            ->on('error', function (array $data) use (&$reports): void {
                $reports[] = $data['exception']->getMessage();
            })
            ->handle(new ServerRequest('HEAD', '/x'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(['E1', 'F', 'E2'], $reports);

        // The ledger lives for one call: the same object in the next request is reported anew
        $again = Router::create()->loadRoutes($this->routes('return function ($r) { $r->get("/x", fn () => throw $GLOBALS["never_f"]); };'));
        $GLOBALS['never_f'] = $f;
        $seen = [];
        $again->on('error', function (array $data) use (&$seen): void {
            $seen[] = $data['exception']->getMessage();
        });
        $again->handle(new ServerRequest('GET', '/x'));
        $again->handle(new ServerRequest('GET', '/x'));
        unset($GLOBALS['never_f']);

        $this->assertSame(['F', 'F'], $seen);
    }

    /**
     * A router is cloned before its first use: the clone is a router of its own. Once the
     * table is built — or was tried — a clone would share it (1.x: a hook added to the
     * clone fired for the original) or run the routes file a second time: refused.
     */
    public function testRouterIsClonedBeforeItsFirstUseOnly(): void
    {
        $id = uniqid();
        $GLOBALS['never_' . $id] = 0;
        $routes = $this->routes(sprintf('
            $GLOBALS["never_%1$s"]++;

            return function ($r) {
                $name = $this->isDebug() ? "debug" : "no debug";
                $r->get("/x", fn () => Sodaho\Router\Response::text($name));
            };', $id));

        // Before the first use: two routers of their own
        $template = Router::create(['debug' => true])->loadRoutes($routes);
        $clone = clone $template;
        $clone->setDebug(false);

        $seen = [];
        $clone->on('notFound', function () use (&$seen): void {
            $seen[] = 'clone hook';
        });

        $this->assertSame('debug', (string) $template->handle(new ServerRequest('GET', '/x'))->getBody());
        $this->assertSame('no debug', (string) $clone->handle(new ServerRequest('GET', '/x'))->getBody());
        $template->handle(new ServerRequest('GET', '/nowhere'));
        $this->assertSame([], $seen);
        $this->assertSame(2, $GLOBALS['never_' . $id]);

        // After a request, match(), url(), a table that could not be built, a request
        // without a routes file — the router is in use as soon as it is asked
        $failed = Router::create()->loadRoutes($this->routes('return function ($r) { $r->get("/x/{id:integer}", "handler"); };'));
        $failed->handle(new ServerRequest('GET', '/x/1'));
        $withoutRoutes = Router::create();
        $withoutRoutes->handle(new ServerRequest('GET', '/x'));
        $uses = [
            'a request' => $template,
            'match()' => (clone Router::create()->loadRoutes($routes)),
            'url()' => Router::create()->loadRoutes($this->routes('return function ($r) { $r->get("/x", "handler")->name("x"); };')),
            'a table that could not be built' => $failed,
            'a request without a routes file' => $withoutRoutes,
        ];
        $uses['match()']->match(new ServerRequest('GET', '/x'));
        $uses['url()']->url('x');

        foreach ($uses as $use => $router) {
            try {
                $copy = clone $router;
                $this->fail('A clone was made after ' . $use);
            } catch (RouterException $e) { // @phpstan-ignore catch.neverThrown (Router::__clone() throws it, a clone expression PHPStan does not follow)
                $this->assertSame('A router can be cloned before its first use only: it was already used (a request, match() or url())', $e->getMessage());
            }
        }

        // @phpstan-ignore deadCode.unreachable (reached: the catch above is, see there)
        unset($GLOBALS['never_' . $id]);

        // A router whose table could not be built, given another routes file: still used
        $failed->loadRoutes($routes);
        try {
            $copy = clone $failed;
            $this->fail('A clone was made after loadRoutes()');
        } catch (RouterException $e) {
            $this->assertStringStartsWith('A router can be cloned before its first use only', $e->getMessage());
        }

        // The routes file itself cannot clone the router it is loaded into — not even while
        // it is required, before it returns its closure
        $reports = [];
        $response = Router::create()
            ->loadRoutes($this->routes('$GLOBALS["never_clone"] = clone $this; return function ($r) { $r->get("/x", "handler"); };'))
            ->on('error', function (array $data) use (&$reports): void {
                $reports[] = $data['exception']->getMessage();
            })
            ->handle(new ServerRequest('GET', '/x'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(['A router can be cloned before its first use only: it was already used (a request, match() or url())'], $reports);
        $this->assertArrayNotHasKey('never_clone', $GLOBALS);
    }

    public function testRoutesFileOfAStreamWrapperIsLoaded(): void
    {
        // A path without a real path: phar://, a wrapper of your own. 1.x took it, and so
        // does the loader — by the path as it is written.
        $file = $this->routes('return function ($r) { $r->get("/x", fn () => Sodaho\Router\Response::text("hit")); };');

        foreach (['file://' . $file, 'router-never-test://' . basename($file)] as $path) {
            if (str_starts_with($path, 'router-never-test://') && !in_array('router-never-test', stream_get_wrappers(), true)) {
                stream_wrapper_register('router-never-test', RouterNeverTestWrapper::class);
            }
            RouterNeverTestWrapper::$root = dirname($file);

            $this->assertSame('hit', (string) Router::create()->loadRoutes($path)->handle(new ServerRequest('GET', '/x'))->getBody(), $path);
        }
    }

    /**
     * The closure of a routes file has the router as $this, because the file is required
     * inside the router — as in 1.x. Each router loads the file itself.
     */
    public function testRoutesClosureSeesItsRouterAsThis(): void
    {
        $id = uniqid();
        $GLOBALS['never_' . $id] = 0;
        $routes = $this->routes(sprintf('
            $GLOBALS["never_%1$s"]++;

            return function ($r) {
                $debug = $this->isDebug() ? "debug" : "no debug";
                $r->get("/x", fn () => Sodaho\Router\Response::text($debug));
            };', $id));

        $this->assertSame('debug', (string) Router::create(['debug' => true])->loadRoutes($routes)->handle(new ServerRequest('GET', '/x'))->getBody());
        $this->assertSame('no debug', (string) Router::create(['debug' => false])->loadRoutes($routes)->handle(new ServerRequest('GET', '/x'))->getBody());
        $this->assertSame(2, $GLOBALS['never_' . $id]);
        unset($GLOBALS['never_' . $id]);

        // A static closure has no $this, and a callable that is no closure keeps its own
        $static = $this->routes('return static function ($r) { $r->get("/x", fn () => Sodaho\Router\Response::text("static")); };');
        $this->assertSame('static', (string) Router::create()->loadRoutes($static)->handle(new ServerRequest('GET', '/x'))->getBody());

        $id = uniqid();
        $named = $this->routes(sprintf('function never_routes_%1$s($r): void { $r->get("/x", fn () => Sodaho\Router\Response::text("named")); } return "never_routes_%1$s";', $id));
        $this->assertSame('named', (string) Router::create()->loadRoutes($named)->handle(new ServerRequest('GET', '/x'))->getBody());
    }

    public function testRoutesFileThatThrewIsNotLoadedAgain(): void
    {
        $id = uniqid();
        $GLOBALS['never_' . $id] = 0;

        $throwing = $this->routes(sprintf('
            function routes_helper_%1$s(): void {}
            $GLOBALS["never_%1$s"]++;

            throw new \RuntimeException("routes failed, load " . $GLOBALS["never_%1$s"]);', $id));

        $reports = [];
        $router = Router::create()->loadRoutes($throwing)->on('error', function (array $data) use (&$reports): void {
            $reports[] = $data['exception']->getMessage();
        });

        $router->handle(new ServerRequest('GET', '/x'));
        $router->handle(new ServerRequest('GET', '/x'));

        // What it threw is thrown again
        $this->assertSame(['routes failed, load 1', 'routes failed, load 1'], $reports);
        $this->assertSame(1, $GLOBALS['never_' . $id]);

        // loadRoutes() with another file starts over
        $router->loadRoutes($this->routes('return function ($r) { $r->get("/x", fn () => Sodaho\Router\Response::text("ok")); };'));

        $this->assertSame('ok', (string) $router->handle(new ServerRequest('GET', '/x'))->getBody());

        unset($GLOBALS['never_' . $id]);
    }

    public function testSameExceptionObjectIsReportedOnce(): void
    {
        // The error handler throws it, and the responder throws the very same object
        $shared = new \LogicException('shared failure');
        Response::setResponder(new class ($shared) implements ResponderInterface {
            public function __construct(private readonly \Throwable $failure)
            {
            }

            public function formatSuccess(mixed $data, ?string $message = null, ?array $meta = null): array
            {
                return ['data' => $data];
            }

            public function formatError(string $message, ?string $code = null, ?array $details = null): array
            {
                throw $this->failure;
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

        foreach (['handler', 'no routes file'] as $source) {
            [$router, $first] = $this->failing($source);

            $reports = [];
            $router->on('error', function (array $data) use (&$reports): void {
                $reports[] = $data['exception']->getMessage();
            });
            $router->setErrorHandler(fn () => throw $shared);

            $response = $router->handle(new ServerRequest('GET', '/x'));

            $this->assertSame('Internal Server Error', (string) $response->getBody());
            $this->assertCount(2, $reports, $source);
            // @phpstan-ignore argument.type (each beginning is written out in the provider, none is empty)
            $this->assertStringStartsWith($first, $reports[0]);
            $this->assertSame('shared failure', $reports[1]);
        }
    }

    /**
     * The handler throws, the error handler leaves the answer to the router, and a
     * middleware for every request throws when it sees the 500: two exceptions, the error
     * handler is asked about each, each is reported once.
     */
    public function testErrorHandlerIsAskedOnceForEachException(): void
    {
        $onServerError = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $response = $handler->handle($request);

                return $response->getStatusCode() >= 500 ? throw new \RuntimeException('middleware failed on the 500') : $response;
            }
        };

        [$router] = $this->failing('handler');
        $router->middleware($onServerError);

        $reports = [];
        $asked = [];
        $router->on('error', function (array $data) use (&$reports): void {
            $reports[] = $data['exception']->getMessage();
        });
        $router->setErrorHandler(function (\Throwable $e) use (&$asked): ?ResponseInterface {
            $asked[] = $e->getMessage();

            return null;
        });

        $response = $router->handle(new ServerRequest('GET', '/x'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(['handler failed', 'middleware failed on the 500'], $asked);
        $this->assertSame(['handler failed', 'middleware failed on the 500'], $reports);
    }

    public function testRequestObjectThatFails(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getMethod')->willThrowException(new \RuntimeException('request failed'));
        $request->method('getUri')->willReturn(new Uri('/x'));

        [$router] = $this->failing('handler');

        $reports = [];
        $seen = null;
        $router->on('error', function (array $data) use (&$reports): void {
            $reports[] = [$data['exception']->getMessage(), $data['method'], $data['path']];
        });
        $router->setErrorHandler(function (\Throwable $e, ServerRequestInterface $given) use (&$seen): ?ResponseInterface {
            $seen = $given;

            return null;
        });

        $response = $router->handle($request);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('"code":"SERVER_ERROR"', (string) $response->getBody());
        // The request object does not say its method: the report goes out without
        $this->assertSame([['request failed', '', '/x']], $reports);
        $this->assertSame($request, $seen);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function methods(): array
    {
        return ['GET' => ['GET', 'Internal Server Error'], 'HEAD' => ['HEAD', '']];
    }

    #[DataProvider('methods')]
    public function testRequestObjectThatFailsWhileTheResponderFailsToo(string $method, string $body): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getUri')->willThrowException(new \RuntimeException('request failed'));

        [$router] = $this->failing('handler');

        $reports = [];
        $router->on('error', function (array $data) use (&$reports): void {
            $reports[] = [$data['exception']->getMessage(), $data['method'], $data['path']];
        });
        Response::setResponder(self::failingResponder());

        $response = $router->handle($request);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame($body, (string) $response->getBody());
        // What the request object does say is reported — and counts for the body
        $this->assertSame([['request failed', $method, ''], ['responder failed', $method, '']], $reports);
    }

    public function testResponseObjectThatDoesNotTakeAnotherBody(): void
    {
        $stubborn = $this->createStub(ResponseInterface::class);
        $stubborn->method('withBody')->willThrowException(new \RuntimeException('withBody failed'));

        $router = Router::create()->loadRoutes($this->routes('return function ($r) { $r->get("/x", fn ($request) => $request->getAttribute("response")); };'));

        $reports = [];
        $router->on('error', function (array $data) use (&$reports): void {
            $reports[] = [$data['exception']->getMessage(), $data['method'], $data['path']];
        });

        // GET: nobody touches the body
        $this->assertSame($stubborn, $router->handle(new ServerRequest('GET', '/x')->withAttribute('response', $stubborn)));
        $this->assertSame([], $reports);

        // HEAD: the body is to be cut, and the response object refuses
        $response = $router->handle(new ServerRequest('HEAD', '/x')->withAttribute('response', $stubborn));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('', (string) $response->getBody());
        $this->assertSame([['withBody failed', 'HEAD', '/x']], $reports);
    }

    public function testErrorHandlersResponseThatDoesNotTakeAnotherBody(): void
    {
        $stubborn = $this->createStub(ResponseInterface::class);
        $stubborn->method('withBody')->willThrowException(new \RuntimeException('withBody failed'));

        $reports = [];
        $router = Router::create()
            ->setErrorHandler(fn () => $stubborn)
            ->on('error', function (array $data) use (&$reports): void {
                $reports[] = [$data['exception']->getMessage(), $data['method'], $data['path']];
            });

        $this->assertSame($stubborn, $router->handle(new ServerRequest('GET', '/x')));

        $response = $router->handle(new ServerRequest('HEAD', '/x'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame('', (string) $response->getBody());
        $this->assertSame(
            [
                ['No routes loaded. Use loadRoutes() first.', 'GET', '/x'],
                ['No routes loaded. Use loadRoutes() first.', 'HEAD', '/x'],
                ['withBody failed', 'HEAD', '/x'],
            ],
            $reports
        );

        // With implicitHead off nobody touches the body of an answer to HEAD
        $router = Router::create(['implicitHead' => false])->setErrorHandler(fn () => $stubborn);
        $this->assertSame($stubborn, $router->handle(new ServerRequest('HEAD', '/x')));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function failuresTheErrorHandlerAnswers(): array
    {
        return ['handler' => ['handler', 'handler failed'], 'middleware for every request' => ['middleware for every request', 'middleware failed']];
    }

    /**
     * The error handler answered, and its answer refuses to lose its body for HEAD: that is
     * reported and answered without it — it is not asked about its own answer.
     */
    #[DataProvider('failuresTheErrorHandlerAnswers')]
    public function testErrorHandlerIsNotAskedAboutItsOwnAnswer(string $source, string $first): void
    {
        $stubborn = $this->createStub(ResponseInterface::class);
        $stubborn->method('withBody')->willThrowException(new \RuntimeException('withBody failed'));

        [$router] = $this->failing($source);

        $reports = [];
        $asked = [];
        $router->on('error', function (array $data) use (&$reports): void {
            $reports[] = $data['exception']->getMessage();
        });
        $router->setErrorHandler(function (\Throwable $e) use (&$asked, $stubborn): ResponseInterface {
            $asked[] = $e->getMessage();

            return $stubborn;
        });

        $this->assertSame($stubborn, $router->handle(new ServerRequest('GET', '/x')));
        $this->assertSame([[$first], [$first]], [$asked, $reports]);

        $response = $router->handle(new ServerRequest('HEAD', '/x'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame('', (string) $response->getBody());
        $this->assertSame([$first, $first], $asked);
        $this->assertSame([$first, $first, 'withBody failed'], $reports);
    }

    /**
     * A middleware delegates twice: the first time the handler throws and the error handler
     * answers (an answer the middleware drops), the second time a response comes back that
     * refuses to lose its body. That response is not the error handler's own: it is asked.
     */
    public function testErrorHandlerIsAskedAboutAResponseThatIsNotItsOwn(): void
    {
        $stubborn = $this->createStub(ResponseInterface::class);
        $stubborn->method('withBody')->willThrowException(new \RuntimeException('withBody failed'));

        $twice = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $handler->handle($request->withUri($request->getUri()->withPath('/boom')));

                return $handler->handle($request->withUri($request->getUri()->withPath('/stubborn')));
            }
        };

        $asked = [];
        $reports = [];
        $router = Router::create()
            ->loadRoutes($this->routes('return function ($r) {
                $r->get("/boom", fn () => throw new \RuntimeException("handler failed"));
                $r->get("/stubborn", fn ($request) => $request->getAttribute("response"));
            };'))
            ->middleware($twice)
            ->setErrorHandler(function (\Throwable $e) use (&$asked): ResponseInterface {
                $asked[] = $e->getMessage();

                return Response::text('custom', 503);
            })
            ->on('error', function (array $data) use (&$reports): void {
                $reports[] = $data['exception']->getMessage();
            });

        $response = $router->handle(new ServerRequest('HEAD', '/x')->withAttribute('response', $stubborn));

        $this->assertSame([503, ''], [$response->getStatusCode(), (string) $response->getBody()]);
        $this->assertSame(['handler failed', 'withBody failed'], $asked);
        $this->assertSame(['handler failed', 'withBody failed'], $reports);
    }

    /**
     * A middleware keeps the error handler's first answer, delegates again and returns the
     * kept one: it is still the error handler's own — it is not asked about it.
     */
    public function testErrorHandlerIsNotAskedAboutAnEarlierAnswerOfItsOwn(): void
    {
        $keep = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $first = $handler->handle($request->withUri($request->getUri()->withPath('/one')));
                $handler->handle($request->withUri($request->getUri()->withPath('/two')));

                return $first;
            }
        };

        $asked = [];
        $reports = [];
        $router = Router::create()
            ->loadRoutes($this->routes('return function ($r) {
                $r->get("/one", fn () => throw new \RuntimeException("first handler"));
                $r->get("/two", fn () => throw new \RuntimeException("second handler"));
            };'))
            ->middleware($keep)
            ->setErrorHandler(function (\Throwable $e) use (&$asked): ResponseInterface {
                $asked[] = $e->getMessage();
                $stubborn = $this->createStub(ResponseInterface::class);
                $stubborn->method('withBody')->willThrowException(new \RuntimeException('answer ' . count($asked) . ' refuses'));

                return $stubborn;
            })
            ->on('error', function (array $data) use (&$reports): void {
                $reports[] = $data['exception']->getMessage();
            });

        $response = $router->handle(new ServerRequest('HEAD', '/x'));

        $this->assertSame([500, 'text/plain; charset=utf-8', ''], [$response->getStatusCode(), $response->getHeaderLine('Content-Type'), (string) $response->getBody()]);
        $this->assertSame(['first handler', 'second handler'], $asked);
        $this->assertSame(['first handler', 'second handler', 'answer 1 refuses'], $reports);
    }

    /**
     * The handler throws an exception, the error handler gets it (and reports it) — and its
     * answer refuses to lose its body with that very exception: one report.
     */
    public function testExceptionHandedToTheErrorHandlerIsNotReportedAgain(): void
    {
        $shared = new \RuntimeException('shared failure');
        $GLOBALS['never_shared'] = $shared;

        $asked = 0;
        $reports = [];
        $response = Router::create()
            ->loadRoutes($this->routes('return function ($r) { $r->get("/x", fn () => throw $GLOBALS["never_shared"]); };'))
            ->setErrorHandler(function (\Throwable $e) use (&$asked): ResponseInterface {
                $asked++;
                $stubborn = $this->createStub(ResponseInterface::class);
                $stubborn->method('withBody')->willThrowException($e);

                return $stubborn;
            })
            ->on('error', function (array $data) use (&$reports): void {
                $reports[] = $data['exception'];
            })
            ->handle(new ServerRequest('HEAD', '/x'));

        unset($GLOBALS['never_shared']);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(1, $asked);
        $this->assertSame([$shared], $reports);
    }

    public function testSameExceptionFromTwoResponsesThatRefuseIsReportedOnce(): void
    {
        $shared = new \RuntimeException('shared withBody failure');
        $first = $this->createStub(ResponseInterface::class);
        $first->method('withBody')->willThrowException($shared);
        $second = $this->createStub(ResponseInterface::class);
        $second->method('withBody')->willThrowException($shared);

        $reports = [];
        $response = Router::create()
            ->loadRoutes($this->routes('return function ($r) { $r->get("/x", fn ($request) => $request->getAttribute("response")); };'))
            ->setErrorHandler(fn () => $second)
            ->on('error', function (array $data) use (&$reports): void {
                $reports[] = $data['exception']->getMessage();
            })
            ->handle(new ServerRequest('HEAD', '/x')->withAttribute('response', $first));

        $this->assertSame([500, ''], [$response->getStatusCode(), (string) $response->getBody()]);
        $this->assertSame(['shared withBody failure'], $reports);
    }

    public function testErrorHandlerIsAskedOnceAboutAHandlersResponseThatDoesNotTakeAnotherBody(): void
    {
        $stubborn = $this->createStub(ResponseInterface::class);
        $stubborn->method('withBody')->willThrowException(new \RuntimeException('withBody failed'));
        $second = $this->createStub(ResponseInterface::class);
        $second->method('withBody')->willThrowException(new \RuntimeException('withBody failed again'));

        $routes = $this->routes('return function ($r) { $r->get("/x", fn ($request) => $request->getAttribute("response")); };');
        $request = new ServerRequest('HEAD', '/x')->withAttribute('response', $stubborn);

        // Its answer takes the empty body: that is the response
        $asked = [];
        $response = Router::create()->loadRoutes($routes)
            ->setErrorHandler(function (\Throwable $e) use (&$asked): ResponseInterface {
                $asked[] = $e->getMessage();

                return Response::text('custom', 503);
            })
            ->handle($request);

        $this->assertSame([503, ''], [$response->getStatusCode(), (string) $response->getBody()]);
        $this->assertSame(['withBody failed'], $asked);

        // Its answer refuses as well: asked once, both reported, answered without it
        $asked = [];
        $reports = [];
        $response = Router::create()->loadRoutes($routes)
            ->setErrorHandler(function (\Throwable $e) use (&$asked, $second): ResponseInterface {
                $asked[] = $e->getMessage();

                return $second;
            })
            ->on('error', function (array $data) use (&$reports): void {
                $reports[] = $data['exception']->getMessage();
            })
            ->handle($request);

        $this->assertSame([500, 'text/plain; charset=utf-8', ''], [$response->getStatusCode(), $response->getHeaderLine('Content-Type'), (string) $response->getBody()]);
        $this->assertSame(['withBody failed'], $asked);
        $this->assertSame(['withBody failed', 'withBody failed again'], $reports);
    }

    /**
     * A middleware for every request throws, and the request object fails when the router
     * prepares it for the error handler: the first exception is answered and reported, the
     * second reported behind it — none is lost.
     */
    public function testRequestObjectThatFailsInTheLastResort(): void
    {
        $broken = false;
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getUri')->willReturn(new Uri('/x'));
        $request->method('withAttribute')->willReturnSelf();
        $request->method('withoutAttribute')->willReturnSelf();
        $request->method('getAttribute')->willReturnCallback(function () use (&$broken) {
            // @phpstan-ignore ternary.alwaysFalse (the middleware sets it through the reference)
            return $broken ? throw new \RuntimeException('request failed later') : null;
        });

        $breaks = new class ($broken) implements MiddlewareInterface {
            public function __construct(private bool &$broken) // @phpstan-ignore property.onlyWritten (written through the reference, read by the test)
            {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->broken = true;

                throw new \RuntimeException('middleware failed');
            }
        };

        $reports = [];
        $asked = [];
        $response = Router::create()
            ->loadRoutes($this->routes('return function ($r) { $r->get("/x", fn () => Sodaho\Router\Response::text("ok")); };'))
            ->middleware($breaks)
            ->on('error', function (array $data) use (&$reports): void {
                $reports[] = [$data['exception']->getMessage(), $data['method'], $data['path']];
            })
            ->setErrorHandler(function (\Throwable $e, ServerRequestInterface $given) use (&$asked, $request): ?ResponseInterface {
                $asked[] = [$e->getMessage(), $given === $request];

                return null;
            })
            ->handle($request);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame([['middleware failed', true]], $asked);
        $this->assertSame([['middleware failed', 'GET', '/x'], ['request failed later', 'GET', '/x']], $reports);
    }
}

/**
 * A stream wrapper over a directory, without a real path: what realpath() cannot resolve.
 */
final class RouterNeverTestWrapper
{
    public static string $root = '';

    /** @var resource|null */
    public $context;

    /** @var resource|null */
    private $handle;

    public function stream_open(string $path, string $mode): bool
    {
        $handle = fopen(self::$root . '/' . substr($path, strlen('router-never-test://')), 'rb');

        if ($handle === false) {
            return false;
        }

        $this->handle = $handle;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        return $this->handle !== null && $count > 0 ? fread($this->handle, $count) : false;
    }

    public function stream_eof(): bool
    {
        return $this->handle === null || feof($this->handle);
    }

    /**
     * @return array<int|string, int>|false
     */
    public function stream_stat(): array|false
    {
        return $this->handle !== null ? fstat($this->handle) : false;
    }

    /**
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        return @stat(self::$root . '/' . substr($path, strlen('router-never-test://')));
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    public function stream_close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
        }
    }
}
