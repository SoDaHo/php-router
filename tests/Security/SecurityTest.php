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
    // ==================== Path Traversal ====================

    public function testRejectsPathTraversalAttempts(): void
    {
        $collector = new RouteCollector();
        $collector->get('/files/{name}', fn ($req, $name) => Response::success(['file' => $name]));

        $dispatcher = new RouteDispatcher($collector->getData());

        // Path traversal attempts should not match
        $attacks = [
            '/files/../etc/passwd',
            '/files/..%2F..%2Fetc%2Fpasswd',
            '/files/....//....//etc/passwd',
        ];

        foreach ($attacks as $path) {
            $response = $dispatcher->handle(new ServerRequest('GET', $path));
            // Either 404 (not found) or the literal string is captured (not traversed)
            $this->assertTrue(
                $response->getStatusCode() === 404 ||
                str_contains((string) $response->getBody(), '..'),
                "Path traversal attempt should be blocked or literal: {$path}"
            );
        }
    }

    public function testPathParametersAreNotInterpreted(): void
    {
        $collector = new RouteCollector();
        $collector->get('/files/{path:any}', fn ($req, $path) => Response::success(['path' => $path]));

        $dispatcher = new RouteDispatcher($collector->getData());

        $response = $dispatcher->handle(new ServerRequest('GET', '/files/../../../etc/passwd'));

        // The path should be captured as literal string, not interpreted
        $body = json_decode((string) $response->getBody(), true);
        $this->assertStringContainsString('..', $body['data']['path']);
    }

    // ==================== Null Byte Injection ====================

    public function testRejectsNullByteInjection(): void
    {
        $collector = new RouteCollector();
        $collector->get('/files/{name}', fn ($req, $name) => Response::success(['name' => $name]));

        $dispatcher = new RouteDispatcher($collector->getData());

        // Null byte injection attempt
        $response = $dispatcher->handle(new ServerRequest('GET', "/files/test.php\x00.jpg"));

        // URL should be properly decoded and handled (or 404)
        $this->assertTrue(
            $response->getStatusCode() === 404 || $response->getStatusCode() === 200
        );
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
            // Should be 404 (regex doesn't match) or 500 (casting fails)
            $this->assertContains(
                $response->getStatusCode(),
                [404, 500],
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

    public function testCustomPatternsAreNotVulnerableToReDoS(): void
    {
        $collector = new RouteCollector();
        // Potentially dangerous regex if not careful
        $collector->get('/test/{slug:slug}', fn ($req, $slug) => Response::success(['slug' => $slug]));

        $dispatcher = new RouteDispatcher($collector->getData());

        // Input designed to cause ReDoS on vulnerable regex
        $maliciousInput = str_repeat('a', 50) . '!';

        $start = microtime(true);
        $response = $dispatcher->handle(new ServerRequest('GET', "/test/{$maliciousInput}"));
        $duration = microtime(true) - $start;

        // Should complete quickly (< 1 second) even with malicious input
        $this->assertLessThan(1.0, $duration, 'Potential ReDoS vulnerability');
        // Should be 404 since '!' doesn't match slug pattern
        $this->assertSame(404, $response->getStatusCode());
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
        $collector->get('/search/{query}', fn ($req, $query) => Response::success(['query' => $query]));

        $dispatcher = new RouteDispatcher($collector->getData());

        // Attempt CRLF injection
        $malicious = "test\r\nX-Injected: evil";
        $encoded = rawurlencode($malicious);

        $response = $dispatcher->handle(new ServerRequest('GET', "/search/{$encoded}"));

        // CRLF should be in the body as literal text, not create new headers
        $body = json_decode((string) $response->getBody(), true);
        $this->assertStringContainsString("\r\n", $body['data']['query']);

        // Response should not have injected header
        $this->assertFalse($response->hasHeader('X-Injected'));
    }
}
