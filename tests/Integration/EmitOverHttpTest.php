<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Router::run() behind a real web server (PHP's built-in one), asked over a socket.
 *
 * In CLI header() sends nothing and headers_list() stays empty, so what actually leaves the
 * process — which header replaces which, what survives next to the host's own headers, what
 * a HEAD gets — can only be seen from the outside.
 */
class EmitOverHttpTest extends TestCase
{
    /** The server answers /ping with this, so a foreign service on the port is not mistaken for ours. */
    private const PING = 'router-emit-test';

    /**
     * Fields that exist once per message: here the response replaces what the host set.
     * Every name added to the router's list makes the response overrule the host for that
     * field — right for these (RFC 9110), wrong for anything that is a list or protects
     * something.
     */
    private const REPLACED = [
        'Content-Type', 'Content-Length', 'Content-Range', 'Content-Location', 'Content-Disposition',
        'Location', 'ETag', 'Last-Modified', 'Date', 'Expires', 'Age', 'Retry-After',
        'Cross-Origin-Embedder-Policy', 'Cross-Origin-Embedder-Policy-Report-Only',
        'Cross-Origin-Opener-Policy', 'Cross-Origin-Opener-Policy-Report-Only',
        'Cross-Origin-Resource-Policy', 'Origin-Agent-Cluster',
    ];

    /** Fields whose lines add up — lists, and security fields a route must not weaken */
    private const ADDED = [
        'Vary', 'Cache-Control', 'Link', 'Content-Security-Policy', 'Content-Language', 'X-Custom',
        'X-Frame-Options', 'Strict-Transport-Security', 'Access-Control-Allow-Origin',
    ];

    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;
    private static string $docroot = '';

    public static function setUpBeforeClass(): void
    {
        self::$docroot = sys_get_temp_dir() . '/router_emit_http_' . uniqid();
        mkdir(self::$docroot, 0o755, true);

        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $ping = var_export(self::PING, true);
        $fields = var_export([...self::REPLACED, ...self::ADDED], true);

        // Not periodic: an offset that is off by a whole period would not show
        file_put_contents(self::$docroot . '/payload.bin', self::payload());
        $payload = var_export(self::$docroot . '/payload.bin', true);

        file_put_contents(
            self::$docroot . '/routes.php',
            <<<PHP
                <?php
                use Sodaho\Router\RouteCollector;
                use Sodaho\Router\Response;

                return function (RouteCollector \$r) {
                    \$r->get('/ping', fn() => Response::text({$ping}));
                    \$r->get('/forbidden', fn() => Response::forbidden()->withHeader('WWW-Authenticate', 'Bearer error="insufficient_scope"'));
                    \$r->get('/accepted', fn() => Response::accepted(['job' => 7])->withHeader('Location', '/jobs/7'));
                    \$r->get('/headers', fn() => Response::success('x')
                        ->withHeader('X-Test', 'lib')
                        ->withHeader('Cache-Control', 'public, max-age=60')
                        ->withHeader('Location', '/from-response')
                        ->withHeader('X-Frame-Options', 'SAMEORIGIN')
                        ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
                        ->withHeader('Strict-Transport-Security', 'max-age=0')
                        ->withHeader('Access-Control-Allow-Origin', '*')
                        ->withAddedHeader('Vary', 'Accept')
                        ->withAddedHeader('Vary', 'Origin')
                        ->withAddedHeader('Set-Cookie', 'a=1')
                        ->withAddedHeader('Set-Cookie', 'b=2'));
                    \$r->match(['GET', 'HEAD'], '/page', fn() => Response::text('BODY')->withHeader('Content-Length', '4'));
                    \$r->get('/early', fn() => Response::text('never sent')->withHeader('X-Late', '1'));
                    \$r->get('/media', fn(\$req) => Response::file({$payload}, 'clip.bin', 'application/octet-stream', true, \$req->getHeaderLine('Range') ?: null));
                    \$r->get('/fields', function () {
                        \$response = Response::text('BODY');
                        foreach ({$fields} as \$name) {
                            \$response = \$response->withHeader(\$name, \$name === 'Content-Length' ? '4' : 'response-' . strtolower(\$name));
                        }

                        return \$response;
                    });
                    \$r->get('/phrase-line-break', fn() => Response::forbidden()->withStatus(403, "Forbidden\\r\\nX-Injected: 1"));
                    \$r->get('/phrase-control', fn() => Response::text('teapot')->withStatus(418, "Bad\\x01Phrase"));
                    \$r->get('/phrase-beyond-ascii', fn() => Response::accepted(['job' => 7])->withStatus(202, 'Akzeptiert ä'));
                    \$r->get('/phrase-tab', fn() => Response::text('tab')->withStatus(200, "All\\tright"));
                    \$r->get('/early-broken', fn() => new class extends \\Nyholm\\Psr7\\Response {
                        public function getProtocolVersion(): string { throw new \\RuntimeException('protocol failed'); }
                    });
                };
                PHP
        );

        // What a host application does before it hands over to the router
        file_put_contents(
            self::$docroot . '/index.php',
            <<<PHP
                <?php
                require {$autoload};

                \$path = (string) parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH);

                if (\$path === '/headers') {
                    header('Content-Type: text/html; charset=utf-8');
                    header('Location: /from-host');
                    header('X-Test: host');
                    header('X-Host-Only: kept');
                    header('Cache-Control: no-store');
                    header('Vary: Cookie');
                    header('X-Frame-Options: DENY');
                    header('Cross-Origin-Opener-Policy: same-origin');
                    header('Strict-Transport-Security: max-age=63072000');
                    header('Access-Control-Allow-Origin: https://app.example');
                    header('Set-Cookie: sess=1');
                }

                if (\$path === '/fields') {
                    foreach ({$fields} as \$name) {
                        header(\$name . ': host-' . strtolower(\$name), false);
                    }
                }

                if (\$path === '/early' || \$path === '/early-broken' || \$path === '/early-emit') {
                    // A status the host set before it printed — what went out with the output
                    if (\$path === '/early-emit') {
                        http_response_code(418);
                    }
                    echo 'early output';
                    flush();
                }

                if (\$path === '/early-emit') {
                    // emit() with a response it cannot read: once output has started, it is not read at all
                    Sodaho\Router\Router::create()
                        ->on('error', function (array \$data): void {
                            file_put_contents(__DIR__ . '/error.log', (\$data['type'] ?? '-') . '|' . \$data['exception']->getMessage() . '|' . \$data['status'] . "\n", FILE_APPEND);
                        })
                        ->emit(new class extends \\Nyholm\\Psr7\\Response {
                            public function getProtocolVersion(): string { throw new \\RuntimeException('protocol failed'); }
                        });

                    return;
                }

                Sodaho\Router\Router::create(['debug' => false, 'basePath' => ''])
                    ->loadRoutes(__DIR__ . '/routes.php')
                    ->on('error', function (array \$data): void {
                        // Any exception, not only the router's own: what has no debug message logs ''
                        \$debug = \$data['exception'] instanceof Sodaho\Router\Exception\RouterException ? \$data['exception']->getDebugMessage() : '';
                        file_put_contents(__DIR__ . '/error.log', (\$data['type'] ?? '-') . '|' . \$data['exception']->getMessage() . '|' . \$debug . '|' . \$data['status'] . "\n", FILE_APPEND);
                        if (isset(\$data['type'])) {
                            file_put_contents(__DIR__ . '/keys.log', implode(',', array_keys(\$data)));
                        }
                    })
                    ->run();
                PHP
        );

        // The port is free when we ask and may be taken when the server binds it. Try again
        // with a new one instead of failing on that race.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            if (self::startServer()) {
                return;
            }
            self::stopServer();
        }

        self::fail('The built-in web server did not come up');
    }

    private static function startServer(): bool
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        if (!is_resource($probe)) {
            return false;
        }
        self::$port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, self::$docroot . '/index.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            self::$docroot
        );
        if (!is_resource($server)) {
            return false;
        }
        self::$server = $server;

        for ($i = 0; $i < 100; $i++) {
            if (!proc_get_status($server)['running']) {
                return false;
            }

            $response = self::send('GET', '/ping', 0.2);
            if ($response !== null && $response['body'] === self::PING) {
                return true;
            }
            usleep(50_000);
        }

        return false;
    }

    private static function stopServer(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        self::$server = null;
    }

    public static function tearDownAfterClass(): void
    {
        self::stopServer();

        foreach (['/routes.php', '/index.php', '/error.log', '/keys.log', '/payload.bin'] as $file) {
            @unlink(self::$docroot . $file);
        }
        @rmdir(self::$docroot);
    }

    /** 40,000 bytes, more than four chunks of the emitter, none of them like another */
    private static function payload(): string
    {
        $payload = '';
        for ($i = 0; $i < 1250; $i++) {
            $payload .= hash('sha256', (string) $i, true);
        }

        return $payload;
    }

    /**
     * @param list<string> $headers Further request header lines
     *
     * @return array{status: string, headers: list<string>, body: string}|null null when nothing answered in time
     */
    private static function send(string $method, string $path, float $timeout, array $headers = []): ?array
    {
        $socket = @fsockopen('127.0.0.1', self::$port, $errno, $error, $timeout);
        if (!is_resource($socket)) {
            return null;
        }

        stream_set_timeout($socket, (int) ceil($timeout), 0);
        fwrite($socket, "{$method} {$path} HTTP/1.0\r\nHost: 127.0.0.1\r\n" . implode('', array_map(static fn (string $line): string => $line . "\r\n", $headers)) . "\r\n");
        $raw = (string) stream_get_contents($socket);
        fclose($socket);

        if (!str_contains($raw, "\r\n\r\n")) {
            return null;
        }

        [$head, $body] = explode("\r\n\r\n", $raw, 2);
        $lines = explode("\r\n", $head);

        return ['status' => (string) array_shift($lines), 'headers' => $lines, 'body' => $body];
    }

    /**
     * @param list<string> $headers Further request header lines
     *
     * @return array{status: string, headers: list<string>, body: string}
     */
    private function request(string $method, string $path, array $headers = []): array
    {
        $response = self::send($method, $path, 5.0, $headers);
        $this->assertNotNull($response, "No answer for {$method} {$path}");

        return $response;
    }

    /**
     * @param list<string> $headers
     *
     * @return list<string>
     */
    private static function valuesOf(array $headers, string $name): array
    {
        $values = [];
        foreach ($headers as $line) {
            [$key, $value] = explode(':', $line, 2) + [1 => ''];
            if (strcasecmp($key, $name) === 0) {
                $values[] = trim($value);
            }
        }

        return $values;
    }

    public function testChallengeDoesNotTurnA403IntoA401(): void
    {
        $response = $this->request('GET', '/forbidden');

        $this->assertSame('HTTP/1.1 403 Forbidden', $response['status']);
        $this->assertSame(['Bearer error="insufficient_scope"'], self::valuesOf($response['headers'], 'WWW-Authenticate'));
    }

    public function testLocationDoesNotTurnA202IntoARedirect(): void
    {
        $response = $this->request('GET', '/accepted');

        $this->assertSame('HTTP/1.1 202 Accepted', $response['status']);
        $this->assertSame(['/jobs/7'], self::valuesOf($response['headers'], 'Location'));
        $this->assertSame('{"success":true,"data":{"job":7}}', $response['body']);
    }

    public function testFieldsThatExistOncePerMessageAreReplaced(): void
    {
        $response = $this->request('GET', '/headers');

        // Two Content-Type or two Location lines are not a valid response: the response's value wins.
        $this->assertSame(['application/json'], self::valuesOf($response['headers'], 'Content-Type'));
        $this->assertSame(['/from-response'], self::valuesOf($response['headers'], 'Location'));

        // The status is the response's: a 200 with a Location is no redirect (1.x: 302)
        $this->assertStringEndsWith(' 200 OK', $response['status']);
    }

    public function testListFieldsOfHostAndResponseAddUp(): void
    {
        $headers = $this->request('GET', '/headers')['headers'];

        // The host's "Vary: Cookie" must not disappear because the response adds its own —
        // a shared cache would hand one user's page to the next.
        $this->assertSame(['Cookie', 'Accept', 'Origin'], self::valuesOf($headers, 'Vary'));

        // A session's "no-store" stays next to whatever the response says; caches obey the stricter one.
        $this->assertSame(['no-store', 'public, max-age=60'], self::valuesOf($headers, 'Cache-Control'));

        // Unknown fields are lists as far as the router can tell
        $this->assertSame(['host', 'lib'], self::valuesOf($headers, 'X-Test'));
        $this->assertSame(['kept'], self::valuesOf($headers, 'X-Host-Only'));
    }

    /**
     * Single-valued, and still not replaced: with two conflicting values a browser takes the
     * safe side (no framing, the first HSTS policy, no CORS access). Replacing them would let
     * one route quietly weaken what the host set for the whole application.
     */
    public function testSecurityFieldsOfTheHostAreNotReplaced(): void
    {
        $headers = $this->request('GET', '/headers')['headers'];

        $this->assertSame(['DENY', 'SAMEORIGIN'], self::valuesOf($headers, 'X-Frame-Options'));
        $this->assertSame(['max-age=63072000', 'max-age=0'], self::valuesOf($headers, 'Strict-Transport-Security'));
        $this->assertSame(['https://app.example', '*'], self::valuesOf($headers, 'Access-Control-Allow-Origin'));

        // The other kind: two lines of a Cross-Origin-* policy are no valid value at all, and
        // a browser then applies none. Here one line has to remain.
        $this->assertSame(['same-origin'], self::valuesOf($headers, 'Cross-Origin-Opener-Policy'));
    }

    /**
     * What goes out, not how the router keeps its list: the host set every field, then the
     * response set it again
     */
    public function testWhichFieldsTheResponseReplacesIsDeliberate(): void
    {
        $response = $this->request('GET', '/fields');

        $this->assertSame('HTTP/1.1 200 OK', $response['status']);
        $this->assertSame('BODY', $response['body']);

        foreach (self::REPLACED as $name) {
            $expected = $name === 'Content-Length' ? '4' : 'response-' . strtolower($name);
            $this->assertSame([$expected], self::valuesOf($response['headers'], $name), $name);
        }

        foreach (self::ADDED as $name) {
            $this->assertSame(['host-' . strtolower($name), 'response-' . strtolower($name)], self::valuesOf($response['headers'], $name), $name);
        }
    }

    public function testCookiesOfTheHostSurviveNextToThoseOfTheResponse(): void
    {
        $headers = $this->request('GET', '/headers')['headers'];

        $this->assertSame(['sess=1', 'a=1', 'b=2'], self::valuesOf($headers, 'Set-Cookie'));
    }

    public function testHeadGetsTheHeadersWithoutTheBody(): void
    {
        $get = $this->request('GET', '/page');
        $head = $this->request('HEAD', '/page');

        $this->assertSame('BODY', $get['body']);
        $this->assertSame('HTTP/1.1 200 OK', $head['status']);
        $this->assertSame(['4'], self::valuesOf($head['headers'], 'Content-Length'));
        $this->assertSame('', $head['body']);
    }

    /**
     * PHP drops a status line with a line break and sends its own 200 instead: a 403 went
     * out as a 200, with a warning in the body and no report. Such a reason phrase is
     * refused before anything is sent — a 500 can still go out.
     */
    public function testReasonPhraseWithAControlCharacterIsA500(): void
    {
        $phrases = [
            '/phrase-line-break' => ['"Forbidden\r\nX-Injected: 1"', 'X-Injected'],
            '/phrase-control' => ['"Bad\u0001Phrase"', null],
        ];

        foreach ($phrases as $path => [$debug, $injected]) {
            @unlink(self::$docroot . '/error.log');

            $response = $this->request('GET', $path);

            $this->assertSame('HTTP/1.1 500 Internal Server Error', $response['status'], $path);
            $this->assertSame('Internal Server Error', $response['body'], $path);
            if ($injected !== null) {
                $this->assertSame([], self::valuesOf($response['headers'], $injected));
            }
            $this->assertSame(
                "-|Response reason phrase must not contain a control character other than a tab|{$debug}|500\n",
                (string) file_get_contents(self::$docroot . '/error.log'),
                $path
            );
        }
    }

    /**
     * RFC 9112: reason-phrase = *( HTAB / SP / VCHAR / obs-text ) — text beyond ASCII and a
     * tab go out as they are
     */
    public function testReasonPhraseOfVisibleTextGoesOutAsItIs(): void
    {
        $this->assertSame('HTTP/1.1 202 Akzeptiert ä', $this->request('GET', '/phrase-beyond-ascii')['status']);
        $this->assertSame("HTTP/1.1 200 All\tright", $this->request('GET', '/phrase-tab')['status']);
    }

    /**
     * Response::file() with a Range, as it leaves the process: status line, Content-Range
     * and Content-Length on the wire, and a body that is exactly the slice — across several
     * chunks of the emitter
     */
    public function testRangeGoesOutAs206WithExactlyTheSlice(): void
    {
        $payload = self::payload();

        $response = $this->request('GET', '/media', ['Range: bytes=1000-30999']);
        $this->assertSame('HTTP/1.1 206 Partial Content', $response['status']);
        $this->assertSame(['bytes 1000-30999/40000'], self::valuesOf($response['headers'], 'Content-Range'));
        $this->assertSame(['30000'], self::valuesOf($response['headers'], 'Content-Length'));
        $this->assertSame(['bytes'], self::valuesOf($response['headers'], 'Accept-Ranges'));
        $this->assertSame(substr($payload, 1000, 30000), $response['body']);

        // The last bytes of the file
        $suffix = $this->request('GET', '/media', ['Range: bytes=-100']);
        $this->assertSame('HTTP/1.1 206 Partial Content', $suffix['status']);
        $this->assertSame(['bytes 39900-39999/40000'], self::valuesOf($suffix['headers'], 'Content-Range'));
        $this->assertSame(substr($payload, -100), $suffix['body']);

        // Without a Range: the whole file
        $whole = $this->request('GET', '/media');
        $this->assertSame('HTTP/1.1 200 OK', $whole['status']);
        $this->assertSame(['40000'], self::valuesOf($whole['headers'], 'Content-Length'));
        $this->assertSame([], self::valuesOf($whole['headers'], 'Content-Range'));
        $this->assertSame($payload, $whole['body']);
    }

    public function testRangeBeyondTheFileGoesOutAs416(): void
    {
        $response = $this->request('GET', '/media', ['Range: bytes=40000-']);

        // (The reason phrase is the PSR-7 implementation's)
        $this->assertStringStartsWith('HTTP/1.1 416 ', $response['status']);
        $this->assertSame(['bytes */40000'], self::valuesOf($response['headers'], 'Content-Range'));
        $this->assertSame(['0'], self::valuesOf($response['headers'], 'Content-Length'));
        $this->assertSame([], self::valuesOf($response['headers'], 'Content-Disposition'));
        $this->assertSame('', $response['body']);
    }

    public function testResponseThatCanNoLongerBeSentIsReported(): void
    {
        @unlink(self::$docroot . '/error.log');
        @unlink(self::$docroot . '/keys.log');

        $response = $this->request('GET', '/early');

        // Output had started before run(): status and headers are gone, the body is not appended
        $this->assertSame('early output', $response['body']);
        $this->assertSame([], self::valuesOf($response['headers'], 'X-Late'));

        // Up to 1.1.0 that was all — the response vanished without a trace
        $log = (string) file_get_contents(self::$docroot . '/error.log');
        // The message does not name the place; where PHP knows it (that depends on
        // output_buffering in the server's php.ini) it is in the debug message
        $this->assertMatchesRegularExpression(
            '#^emit\|Response not sent: output had already started\|(.+index\.php:\d+)?\|200\n$#',
            $log
        );
        // status came last, after the keys an emit report had before
        $this->assertSame('type,message,exception,status', (string) file_get_contents(self::$docroot . '/keys.log'));
    }

    /**
     * Output had started, and the response cannot even be read: it is not read at all —
     * one report of type 'emit', no exception from emit() and no second report from run()
     * (a response that was read anyway let a throwing getter out of emit()).
     */
    public function testResponseIsNotReadOnceOutputHasStarted(): void
    {
        // The status of the report is what went out with the earlier output
        foreach (['/early-broken' => 200, '/early-emit' => 418] as $path => $status) {
            @unlink(self::$docroot . '/error.log');

            $response = $this->request('GET', $path);

            $this->assertSame('early output', $response['body'], $path);
            $this->assertMatchesRegularExpression('#^HTTP/1\.[01] ' . $status . ' #', $response['status'], $path);
            $log = (string) file_get_contents(self::$docroot . '/error.log');
            $this->assertMatchesRegularExpression('#^emit\|Response not sent: output had already started(\|[^|\n]*)?\|' . $status . '\n$#', $log, $path);
        }
    }
}
