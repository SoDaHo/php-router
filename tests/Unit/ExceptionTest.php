<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\Router\Exception\CacheException;
use Sodaho\Router\Exception\DuplicateRouteException;
use Sodaho\Router\Exception\MethodNotAllowedException;
use Sodaho\Router\Exception\NotFoundException;
use Sodaho\Router\Exception\RouteNotFoundException;
use Sodaho\Router\Exception\RouterException;

class ExceptionTest extends TestCase
{
    // ==================== RouterException ====================

    public function testRouterExceptionDefaults(): void
    {
        $e = new RouterException();

        $this->assertSame('Router error', $e->getMessage());
        $this->assertSame(0, $e->getCode());
        $this->assertNull($e->getPrevious());
        $this->assertNull($e->getDebugMessage());
    }

    public function testRouterExceptionWithAllParameters(): void
    {
        $previous = new \Exception('Previous');
        $e = new RouterException('Custom message', 42, $previous, 'Debug info');

        $this->assertSame('Custom message', $e->getMessage());
        $this->assertSame(42, $e->getCode());
        $this->assertSame($previous, $e->getPrevious());
        $this->assertSame('Debug info', $e->getDebugMessage());
    }

    public function testAllExceptionsExtendRouterException(): void
    {
        $this->assertInstanceOf(RouterException::class, new NotFoundException());
        $this->assertInstanceOf(RouterException::class, new MethodNotAllowedException());
        $this->assertInstanceOf(RouterException::class, new RouteNotFoundException());
        $this->assertInstanceOf(RouterException::class, new DuplicateRouteException());
        $this->assertInstanceOf(RouterException::class, new CacheException());
    }

    // ==================== MethodNotAllowedException ====================

    public function testMethodNotAllowedException(): void
    {
        $e = new MethodNotAllowedException(
            'Method not allowed',
            0,
            null,
            null,
            ['GET', 'POST']
        );

        $this->assertSame(['GET', 'POST'], $e->getAllowedMethods());
    }

    public function testMethodNotAllowedExceptionDefaults(): void
    {
        $e = new MethodNotAllowedException();

        $this->assertSame('Method not allowed', $e->getMessage());
        $this->assertSame([], $e->getAllowedMethods());
    }

    // ==================== CacheException Factory Methods ====================

    public function testCacheExceptionDirectoryNotWritable(): void
    {
        $e = CacheException::directoryNotWritable('/path/to/dir');

        $this->assertStringContainsString('/path/to/dir', $e->getMessage());
        $this->assertStringContainsString('write permissions', $e->getDebugMessage());
    }

    public function testCacheExceptionWriteFailed(): void
    {
        $e = CacheException::writeFailed('/path/to/file.php');

        $this->assertStringContainsString('/path/to/file.php', $e->getMessage());
        $this->assertStringContainsString('permissions', $e->getDebugMessage());
    }

    public function testCacheExceptionInvalidSignature(): void
    {
        $e = CacheException::invalidSignature();

        $this->assertStringContainsString('signature', strtolower($e->getMessage()));
        $this->assertStringContainsString('tampered', strtolower($e->getDebugMessage()));
    }

    // ==================== Exception Inheritance ====================

    public function testCanCatchAllRouterExceptions(): void
    {
        $exceptions = [
            new NotFoundException('Not found'),
            new MethodNotAllowedException('Method not allowed'),
            new RouteNotFoundException('Route not found'),
            new DuplicateRouteException('Duplicate route'),
            new CacheException('Cache error'),
        ];

        foreach ($exceptions as $e) {
            try {
                throw $e;
            } catch (RouterException $caught) {
                $this->assertSame($e, $caught);
            }
        }
    }

    public function testExceptionsAreThrowable(): void
    {
        $exceptions = [
            new RouterException(),
            new NotFoundException(),
            new MethodNotAllowedException(),
            new RouteNotFoundException(),
            new DuplicateRouteException(),
            new CacheException(),
        ];

        foreach ($exceptions as $e) {
            $this->assertInstanceOf(\Throwable::class, $e);
            $this->assertInstanceOf(\Exception::class, $e);
        }
    }
}
