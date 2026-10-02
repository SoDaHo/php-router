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

                if (\$path === '/early') {
                    echo 'early output';
                    flush();
                }

                Sodaho\Router\Router::create(['debug' => false, 'basePath' => '', 'cacheFile' => ''])
                    ->loadRoutes(__DIR__ . '/routes.php')
                    ->on('error', function (array \$data): void {
                        file_put_contents(__DIR__ . '/error.log', (\$data['type'] ?? '-') . '|' . \$data['message'] . "\n", FILE_APPEND);
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

        foreach (['/routes.php', '/index.php', '/error.log'] as $file) {
            @unlink(self::$docroot . $file);
        }
        @rmdir(self::$docroot);
    }

    /**
     * @return array{status: string, headers: list<string>, body: string}|null null when nothing answered in time
     */
    private static function send(string $method, string $path, float $timeout): ?array
    {
        $socket = @fsockopen('127.0.0.1', self::$port, $errno, $error, $timeout);
        if (!is_resource($socket)) {
            return null;
        }

        stream_set_timeout($socket, (int) ceil($timeout), 0);
        fwrite($socket, "{$method} {$path} HTTP/1.0\r\nHost: 127.0.0.1\r\n\r\n");
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
     * @return array{status: string, headers: list<string>, body: string}
     */
    private function request(string $method, string $path): array
    {
        $response = self::send($method, $path, 5.0);
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

        // A 200 that carries a Location is the one status left to PHP (see Router::emit())
        // (PHP writes that status line itself, in the protocol version of the request)
        $this->assertStringEndsWith(' 302 Found', $response['status']);
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

    public function testListOfReplacedFieldsIsDeliberate(): void
    {
        // Every name added here makes the response overrule the host for that field. That is
        // right for fields that exist once per message (RFC 9110) — and wrong for anything
        // that is a list or protects something; see the test above.
        $list = (new \ReflectionClassConstant(\Sodaho\Router\Router::class, 'SINGLETON_HEADERS'))->getValue();

        $this->assertSame(
            [
                'content-type', 'content-length', 'content-range', 'content-location', 'content-disposition',
                'location', 'etag', 'last-modified', 'date', 'expires', 'age', 'retry-after',
                'cross-origin-embedder-policy', 'cross-origin-embedder-policy-report-only',
                'cross-origin-opener-policy', 'cross-origin-opener-policy-report-only',
                'cross-origin-resource-policy', 'origin-agent-cluster',
            ],
            array_keys($list)
        );
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

    public function testResponseThatCanNoLongerBeSentIsReported(): void
    {
        @unlink(self::$docroot . '/error.log');

        $response = $this->request('GET', '/early');

        // Output had started before run(): status and headers are gone, the body is not appended
        $this->assertSame('early output', $response['body']);
        $this->assertSame([], self::valuesOf($response['headers'], 'X-Late'));

        // Up to 1.1.0 that was all — the response vanished without a trace
        $log = (string) file_get_contents(self::$docroot . '/error.log');
        // Whether PHP can name the place depends on output_buffering in the server's php.ini
        $this->assertMatchesRegularExpression(
            '#^emit\|Response not sent: output had already started( at .+index\.php:\d+)?\n$#',
            $log
        );
    }
}
