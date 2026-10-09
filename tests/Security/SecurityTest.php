<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Security;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\Response;
use Sodaho\Router\RouteCollector;
use Sodaho\Router\RouteDispatcher;

/**
 * Security tests for the router.
 * Tests path traversal, parameter injection, and other attack vectors.
 */
class SecurityTest extends TestCase
{
    private string $backtrackLimit;

    protected function setUp(): void
    {
        $this->backtrackLimit = (string) ini_get('pcre.backtrack_limit');
    }

    protected function tearDown(): void
    {
        ini_set('pcre.backtrack_limit', $this->backtrackLimit);
    }

    // ==================== Path Traversal ====================

    public function testRejectsPathTraversalAttempts(): void
    {
        $collector = new RouteCollector();
        $collector->get('/files/{name}', fn ($req, $name) => Response::success(['file' => $name]));

        $dispatcher = new RouteDispatcher($collector->getData());

        // No handler gets a value with a '..' segment: such a path has no route
        $attacks = [
            '/files/..',
            '/files/.',
            '/files/../etc/passwd',
            '/files/%2E%2E',
            '/files/.%2e',
            '/files/..%2F..%2Fetc%2Fpasswd',
            '/files/....//....//etc/passwd',
        ];

        foreach ($attacks as $path) {
            $response = $dispatcher->handle(new ServerRequest('GET', $path));
            $this->assertSame(404, $response->getStatusCode(), $path);
        }

        // Dots that are no segment of their own are a name like any other
        $response = $dispatcher->handle(new ServerRequest('GET', '/files/...'));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('...', json_decode((string) $response->getBody(), true)['data']['file']);
    }

    public function testPathParametersThatTakeSlashesGetNoDotSegment(): void
    {
        $collector = new RouteCollector();
        $reached = false;
        $collector->get('/files/{path:any}', function ($req, $path) use (&$reached) {
            $reached = true;

            return Response::success(['path' => $path]);
        });

        $dispatcher = new RouteDispatcher($collector->getData());

        foreach (['/files/../../../etc/passwd', '/files/%2e%2e/%2E%2E/etc/passwd', '/files/a/%2E%2E/y', '/files/a/.%2e/b', '/files/a/./b'] as $path) {
            $this->assertSame(404, $dispatcher->handle(new ServerRequest('GET', $path))->getStatusCode(), $path);
        }
        $this->assertFalse($reached);
    }

    // ==================== Null Byte Injection ====================

    public function testRejectsNullByteInjection(): void
    {
        $collector = new RouteCollector();
        $collector->get('/files/{name}', fn ($req, $name) => Response::success(['name' => $name]));

        $dispatcher = new RouteDispatcher($collector->getData());

        // Null byte injection attempt
        $response = $dispatcher->handle(new ServerRequest('GET', "/files/test.php\x00.jpg"));

        // A raw NUL never reaches the router: PHP's URL parsing replaces control characters
        // with "_" before PSR-7 hands the path over. The name arrives whole, not cut at the NUL.
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('test.php_.jpg', json_decode((string) $response->getBody(), true)['data']['name']);
    }

    // ==================== Parameter Type Injection ====================

    public function testIntParameterRejectsNonIntegers(): void
    {
        $collector = new RouteCollector();
        $collector->get('/users/{id:int}', fn ($req, int $id) => Response::success(['id' => $id]));

        $dispatcher = new RouteDispatcher($collector->getData());

        $attacks = [
            '/users/1; DROP TABLE users',
            '/users/1 OR 1=1',
            '/users/<script>alert(1)</script>',
            '/users/1e10',
            '/users/0x1A',
        ];

        foreach ($attacks as $path) {
            $response = $dispatcher->handle(new ServerRequest('GET', $path));
            // Should be 404 (regex doesn't match) or 400 (casting fails)
            $this->assertContains(
                $response->getStatusCode(),
                [400, 404],
                "SQL/XSS injection should be rejected: {$path}"
            );
        }
    }

    // ==================== HTTP Method Spoofing ====================

    public function testMethodIsCaseSensitive(): void
    {
        $collector = new RouteCollector();
        $collector->get('/test', fn ($req) => Response::success([]));

        $dispatcher = new RouteDispatcher($collector->getData());

        // Lowercase 'get' should not match
        $response = $dispatcher->handle(new ServerRequest('get', '/test'));
        $this->assertSame(405, $response->getStatusCode());
    }

    // ==================== URL Encoding Attacks ====================

    public function testHandlesDoubleEncoding(): void
    {
        $collector = new RouteCollector();
        $collector->get('/test/{param}', fn ($req, $param) => Response::success(['param' => $param]));

        $dispatcher = new RouteDispatcher($collector->getData());

        // Double-encoded slash: %252F -> %2F -> /
        $response = $dispatcher->handle(new ServerRequest('GET', '/test/%252Fetc%252Fpasswd'));

        // Should be treated as literal string
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        // rawurldecode is applied once, so %25 becomes %
        $this->assertSame('%2Fetc%2Fpasswd', $body['data']['param']);
    }

    // ==================== Header Injection ====================

    public function testRedirectHeaderInjection(): void
    {
        $collector = new RouteCollector();
        $collector->redirect('/old', '/new');

        $dispatcher = new RouteDispatcher($collector->getData());

        $response = $dispatcher->handle(new ServerRequest('GET', '/old'));

        // Location header should be exactly what we specified
        $this->assertSame('/new', $response->getHeaderLine('Location'));

        // No CRLF injection possible since target is fixed
        $this->assertStringNotContainsString("\r", $response->getHeaderLine('Location'));
        $this->assertStringNotContainsString("\n", $response->getHeaderLine('Location'));
    }

    // ==================== Large Input ====================

    public function testHandlesLargeRouteParameters(): void
    {
        $collector = new RouteCollector();
        $collector->get('/search/{query}', fn ($req, $query) => Response::success(['len' => strlen($query)]));

        $dispatcher = new RouteDispatcher($collector->getData());

        // Very long parameter
        $longParam = str_repeat('a', 10000);
        $response = $dispatcher->handle(new ServerRequest('GET', "/search/{$longParam}"));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(10000, $body['data']['len']);
    }

    // ==================== Unicode Handling ====================

    public function testHandlesUnicodeParameters(): void
    {
        $collector = new RouteCollector();
        $collector->get('/users/{name}', fn ($req, $name) => Response::success(['name' => $name]));

        $dispatcher = new RouteDispatcher($collector->getData());

        $unicodeTests = [
            '日本語',           // Japanese
            'مرحبا',            // Arabic
            '🚀🎉',             // Emoji
            'Ñoño',            // Spanish
            'Müller',          // German umlaut
        ];

        foreach ($unicodeTests as $name) {
            $encoded = rawurlencode($name);
            $response = $dispatcher->handle(new ServerRequest('GET', "/users/{$encoded}"));

            $this->assertSame(200, $response->getStatusCode());
            $body = json_decode((string) $response->getBody(), true);
            $this->assertSame($name, $body['data']['name'], "Unicode handling failed for: {$name}");
        }
    }

    // ==================== Regex Denial of Service (ReDoS) ====================

    /**
     * Not a time limit, which depends on the machine: PCRE's own count of backtracking
     * steps. Every built-in pattern decides a path of n characters within n steps and a few
     * — what grows faster (nested quantifiers) runs into the limit, and the router throws
     * instead of answering (see MatchFailureTest).
     */
    public function testBuiltInPatternsBacktrackAtMostOnceACharacter(): void
    {
        $length = 2000;
        $collector = new RouteCollector();
        foreach (array_keys($collector->getPatterns()) as $type) {
            $collector->get("/{$type}/{v:{$type}}", fn ($req, $v) => Response::text('hit'));
        }
        $dispatcher = new RouteDispatcher($collector->getData());

        ini_set('pcre.backtrack_limit', (string) ($length + 16));

        foreach (array_keys($collector->getPatterns()) as $type) {
            foreach (['a', 'A', 'f', '1', '-'] as $character) {
                $input = str_repeat($character, $length) . '!';

                // Throws when PCRE gives up: a dispatcher on its own has no error responder
                $status = $dispatcher->handle(new ServerRequest('GET', "/{$type}/{$input}"))->getStatusCode();

                $this->assertContains($status, [200, 400, 404], "{$type} with {$character}");
            }
        }
    }

    // ==================== Integer Overflow ====================

    public function testIntegerOverflowIsDetected(): void
    {
        $collector = new RouteCollector();
        $collector->get('/users/{id:int}', fn ($req, int $id) => Response::success(['id' => $id]));

        $dispatcher = new RouteDispatcher($collector->getData());

        // Number larger than PHP_INT_MAX
        $overflow = '99999999999999999999999999999';
        $response = $dispatcher->handle(new ServerRequest('GET', "/users/{$overflow}"));

        // Should fail with 400 (overflow detected = client error)
        $this->assertSame(400, $response->getStatusCode());
    }

    // ==================== CRLF Injection in Parameters ====================

    public function testParametersCannotInjectHeaders(): void
    {
        $collector = new RouteCollector();
        $reached = false;
        $collector->get('/search/{query}', function ($req, $query) use (&$reached) {
            $reached = true;

            return Response::success(['query' => $query]);
        });

        $dispatcher = new RouteDispatcher($collector->getData());

        // Attempt CRLF injection
        $malicious = "test\r\nX-Injected: evil";
        $encoded = rawurlencode($malicious);

        $response = $dispatcher->handle(new ServerRequest('GET', "/search/{$encoded}"));

        // A path with a control character has no route (up to 2.0 the CRLF reached the
        // handler as a parameter)
        $this->assertSame(404, $response->getStatusCode());
        $this->assertFalse($reached);
        $this->assertFalse($response->hasHeader('X-Injected'));
    }
}
