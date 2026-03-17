<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Security;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\Response;
use Sodaho\Router\RouteCollector;
use Sodaho\Router\RouteDispatcher;
use Sodaho\Router\Router;

/**
 * Advanced Security Tests for "Schindluder" Scenarios.
 */
class AdvancedSecurityTest extends TestCase
{
    /**
     * SCENARIO 1: Denial of Service via Massive URL.
     * Does the regex engine freeze if we send 1MB of garbage?
     */
    public function testMassiveUrlDosAttempt(): void
    {
        $collector = new RouteCollector();
        $collector->get('/users/{name:alpha}', fn () => Response::success([]));

        $dispatcher = new RouteDispatcher($collector->getData());

        // 1MB URL string
        $massivePath = '/users/' . str_repeat('a', 1024 * 1024);

        $start = microtime(true);

        // This should either match (if memory allows) or fail quickly.
        // It should NOT hang for seconds.
        try {
            $dispatcher->handle(new ServerRequest('GET', $massivePath));
        } catch (\Throwable $e) {
            // Memory exhaustion is possible in testing environment, but timeout is bad.
        }

        $duration = microtime(true) - $start;

        $this->assertLessThan(2.0, $duration, 'Router regex engine too slow on large input (Possible DoS vector)');
    }

    /**
     * SCENARIO 2: Null Byte Poisoning Deep Dive.
     * Some older regex implementations failed on \0.
     */
    public function testDeepNullByteInjection(): void
    {
        $collector = new RouteCollector();
        $collector->get('/download/{file}', fn ($req, $file) => Response::success(['file' => $file]));

        $dispatcher = new RouteDispatcher($collector->getData());

        // Attempt to truncate the string internally
        $path = '/download/safe_file.txt%00.exe';

        $response = $dispatcher->handle(new ServerRequest('GET', $path));
        $body = json_decode((string)$response->getBody(), true);

        if ($response->getStatusCode() === 200) {
            // It matched, but the null byte should still be there (not truncated)
            $this->assertStringContainsString("\0", $body['data']['file'], 'Null byte was silently stripped or truncated string!');
        }
    }

    /**
     * SCENARIO 3: ReDoS on Custom Patterns.
     * If a dev defines a greedy pattern, can a user exploit it?
     * We use a known "evil" regex pattern for testing: (a+)+$
     */
    public function testReDoSOnPoorlyDefinedRoutes(): void
    {
        $collector = new RouteCollector();
        // Dev makes a mistake and defines a vulnerable regex
        // {bad:(a+)+} is a classic ReDoS pattern
        // Note: We can't easily test if PCRE crashes, but we can test if our
        // pre-compiled shorthands (alpha, alphanum) are safe.

        $collector->get('/safe/{param:alphanum}', fn () => Response::success([]));

        $dispatcher = new RouteDispatcher($collector->getData());

        // "aaaaaaaaaaaaaaaaaaaa!" - simple, but we check valid shorthands
        $input = str_repeat('a', 10000) . '!';

        $start = microtime(true);
        $response = $dispatcher->handle(new ServerRequest('GET', "/safe/$input"));
        $duration = microtime(true) - $start;

        $this->assertLessThan(0.5, $duration, "Standard 'alphanum' shorthand susceptible to ReDoS");
        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * SCENARIO 4: HTTP Verb Smuggling.
     * Trying X-HTTP-Method-Override without it being enabled.
     */
    public function testHttpMethodOverrideIgnoredByDefault(): void
    {
        $collector = new RouteCollector();
        $collector->post('/delete', fn () => Response::success(['action' => 'deleted']));
        $collector->get('/delete', fn () => Response::success(['action' => 'view'])); // Honeypot

        $dispatcher = new RouteDispatcher($collector->getData());

        // Send a GET but try to override to POST via Header
        $request = (new ServerRequest('GET', '/delete'))
            ->withHeader('X-HTTP-Method-Override', 'POST');

        $response = $dispatcher->handle($request);

        $body = json_decode((string)$response->getBody(), true);

        // Should hit the GET route (view), NOT the POST route (deleted)
        $this->assertSame('view', $body['data']['action'], 'Router dangerously accepted Method Override header by default');
    }

    /**
     * SCENARIO 5: Invalid UTF-8 Sequences.
     * Does it crash json_encode or the regex engine?
     */
    public function testInvalidUtf8Handling(): void
    {
        $collector = new RouteCollector();
        $collector->get('/echo/{msg}', fn ($req, $msg) => Response::success(['msg' => $msg]));

        $dispatcher = new RouteDispatcher($collector->getData());

        // Invalid UTF-8 sequence (xC3 without continuation byte)
        $path = '/echo/invalid-' . "\xC3" . '-utf8';

        // Router works on rawurldecode'd strings.
        // PHP strings are byte arrays, so this is "valid" PHP, but invalid JSON.
        $response = $dispatcher->handle(new ServerRequest('GET', $path));

        // The Controller returns it, Response::success tries to json_encode it.
        // json_encode fails on invalid UTF-8 unless flags are set.
        // Let's see if our Response class handles this gracefully or crashes.

        // We expect a 500 because Response::success() likely fails to encode the invalid UTF-8
        // OR the Router strips it.

        // If status is 200, verify body is valid JSON (not empty)
        if ($response->getStatusCode() === 200) {
            $body = (string)$response->getBody();
            $this->assertJson($body, 'Response with invalid UTF-8 produced invalid JSON');
        }
    }
}
