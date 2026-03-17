<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\Response;
use Sodaho\Router\RouteCollector;
use Sodaho\Router\RouteDispatcher;

/**
 * Tests for validated type casting of route parameters.
 * Spec: 1e3 → TypeError, overflow check, strict validation.
 */
class CastingTest extends TestCase
{
    // ==================== INT CASTING ====================

    public function testIntCastingValidValues(): void
    {
        $collector = new RouteCollector();
        $collector->get('/test/{id:int}', fn ($req, int $id) => Response::success(['id' => $id]));

        $dispatcher = new RouteDispatcher($collector->getData());

        // Valid integers
        foreach (['0', '1', '42', '999', '-1', '-42'] as $value) {
            $response = $dispatcher->handle(new ServerRequest('GET', "/test/{$value}"));
            $this->assertSame(200, $response->getStatusCode(), "Value {$value} should be valid");
        }
    }

    public function testIntCastingRejectsLeadingZero(): void
    {
        $collector = new RouteCollector();
        $collector->get('/test/{id:int}', fn ($req, int $id) => Response::success(['id' => $id]));

        $dispatcher = new RouteDispatcher($collector->getData());

        $response = $dispatcher->handle(new ServerRequest('GET', '/test/01'));
        $this->assertSame(400, $response->getStatusCode());
    }

    public function testIntCastingRejectsScientificNotation(): void
    {
        $collector = new RouteCollector();
        $collector->get('/test/{id:int}', fn ($req, int $id) => Response::success(['id' => $id]));

        $dispatcher = new RouteDispatcher($collector->getData());

        // 1e3 doesn't match int pattern (-?\d+) -> 404
        $response = $dispatcher->handle(new ServerRequest('GET', '/test/1e3'));
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testIntCastingRejectsFloat(): void
    {
        $collector = new RouteCollector();
        $collector->get('/test/{id:int}', fn ($req, int $id) => Response::success(['id' => $id]));

        $dispatcher = new RouteDispatcher($collector->getData());

        // 5.0 doesn't match int pattern (-?\d+) -> 404
        $response = $dispatcher->handle(new ServerRequest('GET', '/test/5.0'));
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testIntCastingRejectsNonNumeric(): void
    {
        $collector = new RouteCollector();
        $collector->get('/test/{id:int}', fn ($req, int $id) => Response::success(['id' => $id]));

        $dispatcher = new RouteDispatcher($collector->getData());

        // abc doesn't match int pattern (-?\d+) -> 404
        $response = $dispatcher->handle(new ServerRequest('GET', '/test/abc'));
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testIntCastingOverflowCheck(): void
    {
        $collector = new RouteCollector();
        $collector->get('/test/{id:int}', fn ($req, int $id) => Response::success(['id' => $id]));

        $dispatcher = new RouteDispatcher($collector->getData());

        // Value larger than PHP_INT_MAX
        $hugeNumber = '99999999999999999999999999999999';
        $response = $dispatcher->handle(new ServerRequest('GET', "/test/{$hugeNumber}"));
        $this->assertSame(400, $response->getStatusCode());
    }

    // ==================== FLOAT CASTING ====================

    public function testFloatCastingValidValues(): void
    {
        $collector = new RouteCollector();
        $collector->get('/test/{val:float}', fn ($req, float $val) => Response::success(['val' => $val]));

        $dispatcher = new RouteDispatcher($collector->getData());

        foreach (['0', '5', '5.5', '-3.14', '100.001'] as $value) {
            $response = $dispatcher->handle(new ServerRequest('GET', "/test/{$value}"));
            $this->assertSame(200, $response->getStatusCode(), "Value {$value} should be valid");
        }
    }

    public function testFloatCastingRejectsScientificNotation(): void
    {
        $collector = new RouteCollector();
        $collector->get('/test/{val:float}', fn ($req, float $val) => Response::success(['val' => $val]));

        $dispatcher = new RouteDispatcher($collector->getData());

        // 1e3 doesn't match float pattern -> 404
        $response = $dispatcher->handle(new ServerRequest('GET', '/test/1e3'));
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testFloatCastingRejectsTrailingDot(): void
    {
        $collector = new RouteCollector();
        $collector->get('/test/{val:float}', fn ($req, float $val) => Response::success(['val' => $val]));

        $dispatcher = new RouteDispatcher($collector->getData());

        // 5. doesn't match float pattern -> 404
        $response = $dispatcher->handle(new ServerRequest('GET', '/test/5.'));
        $this->assertSame(404, $response->getStatusCode());
    }

    // ==================== BOOL CASTING ====================

    public function testBoolCastingValidValues(): void
    {
        $collector = new RouteCollector();
        $collector->get('/test/{flag:bool}', fn ($req, bool $flag) => Response::success(['flag' => $flag]));

        $dispatcher = new RouteDispatcher($collector->getData());

        // true values
        foreach (['true', 'TRUE', 'True', '1'] as $value) {
            $response = $dispatcher->handle(new ServerRequest('GET', "/test/{$value}"));
            $this->assertSame(200, $response->getStatusCode());
            $body = json_decode((string) $response->getBody(), true);
            $this->assertTrue($body['data']['flag'], "Value {$value} should be true");
        }

        // false values
        foreach (['false', 'FALSE', 'False', '0'] as $value) {
            $response = $dispatcher->handle(new ServerRequest('GET', "/test/{$value}"));
            $this->assertSame(200, $response->getStatusCode());
            $body = json_decode((string) $response->getBody(), true);
            $this->assertFalse($body['data']['flag'], "Value {$value} should be false");
        }
    }

    public function testBoolCastingRejectsInvalidValues(): void
    {
        $collector = new RouteCollector();
        $collector->get('/test/{flag:bool}', fn ($req, bool $flag) => Response::success(['flag' => $flag]));

        $dispatcher = new RouteDispatcher($collector->getData());

        // These don't match bool pattern -> 404
        foreach (['yes', 'no', 'on', 'off', '2', 'abc'] as $value) {
            $response = $dispatcher->handle(new ServerRequest('GET', "/test/{$value}"));
            $this->assertSame(404, $response->getStatusCode(), "Value {$value} should not match bool pattern");
        }
    }

    // ==================== NO CASTING (STRING) ====================

    public function testNoCastingKeepsString(): void
    {
        $collector = new RouteCollector();
        $collector->get('/test/{name}', fn ($req, string $name) => Response::success(['name' => $name]));

        $dispatcher = new RouteDispatcher($collector->getData());

        $response = $dispatcher->handle(new ServerRequest('GET', '/test/hello-world'));
        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('hello-world', $body['data']['name']);
    }
}
