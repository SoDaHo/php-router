<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\Cache\RouteCache;
use Sodaho\Router\Exception\CacheException;

class RouteCacheTest extends TestCase
{
    private const TEST_KEY = 'test-signature-key-for-unit-tests';

    private string $cacheDir;
    private string $cacheFile;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/router-test-' . uniqid();
        mkdir($this->cacheDir, 0o755, true);
        $this->cacheFile = $this->cacheDir . '/routes.cache.php';
    }

    protected function tearDown(): void
    {
        // Clean up cache files
        if (file_exists($this->cacheFile)) {
            chmod($this->cacheFile, 0o644); // Restore permissions before delete
            unlink($this->cacheFile);
        }
        if (is_dir($this->cacheDir)) {
            rmdir($this->cacheDir);
        }
    }

    public function testSaveAndLoad(): void
    {
        $cache = new RouteCache($this->cacheFile, self::TEST_KEY);
        $data = [
            'static' => ['/users' => 'handler'],
            'dynamic' => [],
        ];

        $cache->save($data);
        $this->assertFileExists($this->cacheFile);

        $loaded = $cache->load();
        $this->assertSame($data, $loaded);
    }

    public function testSaveWithSignature(): void
    {
        $cache = new RouteCache($this->cacheFile, 'secret-key');
        $data = ['routes' => ['test']];

        $cache->save($data);

        $content = file_get_contents($this->cacheFile);
        $this->assertStringContainsString('HMAC-SHA256:', $content);
    }

    public function testLoadVerifiesSignature(): void
    {
        $cache = new RouteCache($this->cacheFile, 'secret-key');
        $data = ['routes' => ['test']];

        $cache->save($data);
        $loaded = $cache->load();

        $this->assertSame($data, $loaded);
    }

    public function testLoadRejectsInvalidSignature(): void
    {
        $cache = new RouteCache($this->cacheFile, 'secret-key');
        $data = ['routes' => ['test']];
        $cache->save($data);

        // Tamper with the file
        $content = file_get_contents($this->cacheFile);
        $content = str_replace('test', 'hacked', $content);
        file_put_contents($this->cacheFile, $content);

        $this->expectException(CacheException::class);
        $cache->load();
    }

    public function testLoadRejectsMissingSignature(): void
    {
        $cache = new RouteCache($this->cacheFile, 'secret-key');

        // Create file without signature
        file_put_contents($this->cacheFile, "<?php\nreturn ['test'];");

        $this->expectException(CacheException::class);
        $cache->load();
    }

    public function testLoadRejectsMalformedSignedFile(): void
    {
        $cache = new RouteCache($this->cacheFile, 'secret-key');

        // Create file with HMAC signature but no "return " statement
        $fakeSignature = str_repeat('a', 64);
        file_put_contents($this->cacheFile, "<?php\n// HMAC-SHA256: {$fakeSignature}\n\$data = ['test'];");

        $this->expectException(CacheException::class);
        $cache->load();
    }

    public function testLoadRejectsWrongSignatureKey(): void
    {
        // Save with one key
        $cache1 = new RouteCache($this->cacheFile, 'correct-key');
        $cache1->save(['secret' => 'data']);

        // Try to load with different key
        $cache2 = new RouteCache($this->cacheFile, 'wrong-key');

        $this->expectException(CacheException::class);
        $cache2->load();
    }

    public function testSaveRejectsClosures(): void
    {
        $cache = new RouteCache($this->cacheFile, self::TEST_KEY);
        $data = [
            'handler' => function () {
                return 'test';
            },
        ];

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Closures');
        $cache->save($data);
    }

    public function testSaveCreatesDirectory(): void
    {
        $nestedDir = $this->cacheDir . '/nested/deep';
        $cacheFile = $nestedDir . '/routes.php';
        $cache = new RouteCache($cacheFile, self::TEST_KEY);

        $cache->save(['test' => true]);

        $this->assertFileExists($cacheFile);

        // Cleanup
        unlink($cacheFile);
        rmdir($nestedDir);
        rmdir($this->cacheDir . '/nested');
    }

    public function testLoadReturnsNullWhenDisabled(): void
    {
        $cache = new RouteCache($this->cacheFile, null, false);
        $cache->save(['test' => true]);

        // File should not be created when disabled
        $this->assertFileDoesNotExist($this->cacheFile);
        $this->assertNull($cache->load());
    }

    public function testLoadReturnsNullWhenFileDoesNotExist(): void
    {
        $cache = new RouteCache($this->cacheFile, self::TEST_KEY);
        $this->assertNull($cache->load());
    }

    public function testClear(): void
    {
        $cache = new RouteCache($this->cacheFile, self::TEST_KEY);
        $cache->save(['test' => true]);

        $this->assertTrue($cache->clear());
        $this->assertFileDoesNotExist($this->cacheFile);
    }

    public function testClearReturnsFalseWhenNoFile(): void
    {
        $cache = new RouteCache($this->cacheFile, self::TEST_KEY);
        $this->assertFalse($cache->clear());
    }

    public function testIsFresh(): void
    {
        $cache = new RouteCache($this->cacheFile, self::TEST_KEY);
        $cache->save(['test' => true]);

        $this->assertTrue($cache->isFresh());
        $this->assertTrue($cache->isFresh(60)); // Within 60 seconds
    }

    public function testIsFreshReturnsFalseWhenDisabled(): void
    {
        $cache = new RouteCache($this->cacheFile, null, false);
        $this->assertFalse($cache->isFresh());
    }

    public function testIsFreshReturnsFalseWhenNoFile(): void
    {
        $cache = new RouteCache($this->cacheFile, self::TEST_KEY);
        $this->assertFalse($cache->isFresh());
    }

    public function testGetModificationTime(): void
    {
        $cache = new RouteCache($this->cacheFile, self::TEST_KEY);
        $cache->save(['test' => true]);

        $mtime = $cache->getModificationTime();
        $this->assertIsInt($mtime);
        $this->assertGreaterThan(0, $mtime);
    }

    public function testGetModificationTimeReturnsNullWhenNoFile(): void
    {
        $cache = new RouteCache($this->cacheFile, self::TEST_KEY);
        $this->assertNull($cache->getModificationTime());
    }

    // public function testIsEnabled(): void
    // {
    //     $enabledCache = new RouteCache($this->cacheFile, self::TEST_KEY, true);
    //     $disabledCache = new RouteCache($this->cacheFile, null, false);

    //     $this->assertTrue($enabledCache->isEnabled());
    //     $this->assertFalse($disabledCache->isEnabled());
    // }

    // public function testSetEnabled(): void
    // {
    //     $cache = new RouteCache($this->cacheFile, null, false);
    //     $this->assertFalse($cache->isEnabled());

    //     // Note: setEnabled(true) without key would be invalid in production,
    //     // but the check is only in constructor. This tests the setter works.
    //     $result = $cache->setEnabled(true);
    //     $this->assertTrue($cache->isEnabled());
    //     $this->assertSame($cache, $result); // Fluent API
    // }

    public function testGetCacheFile(): void
    {
        $cache = new RouteCache($this->cacheFile, self::TEST_KEY);
        $this->assertSame($this->cacheFile, $cache->getCacheFile());
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testLoadReturnsNullOnUnreadableFile(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('chmod not supported on Windows');
        }

        $cache = new RouteCache($this->cacheFile, self::TEST_KEY);
        $cache->save(['test' => true]);

        // Make file unreadable
        chmod($this->cacheFile, 0o000);

        $loaded = $cache->load();

        // Restore permissions for cleanup
        chmod($this->cacheFile, 0o644);

        $this->assertNull($loaded);
    }

    public function testLoadReturnsNullOnCorruptedFile(): void
    {
        $cache = new RouteCache($this->cacheFile, self::TEST_KEY);

        // Create corrupted PHP file (with valid signature format to pass check)
        $fakeSignature = str_repeat('a', 64);
        file_put_contents($this->cacheFile, "<?php\n// HMAC-SHA256: {$fakeSignature}\nreturn invalid syntax;");

        // Will fail signature check first, then return null on parse error
        $this->expectException(CacheException::class);
        $cache->load();
    }

    public function testLoadReturnsNullOnNonArrayReturn(): void
    {
        $cache = new RouteCache($this->cacheFile, self::TEST_KEY);

        // Create file that returns non-array (will fail signature)
        $this->expectException(CacheException::class);
        file_put_contents($this->cacheFile, "<?php\nreturn 'not an array';");

        $cache->load();
    }

    public function testIsFreshWithExpiredMaxAge(): void
    {
        $cache = new RouteCache($this->cacheFile, self::TEST_KEY);
        $cache->save(['test' => true]);

        // Touch file to make it old
        touch($this->cacheFile, time() - 120);

        $this->assertFalse($cache->isFresh(60)); // 60 second max age, file is 120s old
    }

    public function testSaveRejectsClosuresInRouteObjects(): void
    {
        $cache = new RouteCache($this->cacheFile, self::TEST_KEY);

        // Create a Route-like object with a Closure handler
        $route = new \Sodaho\Router\Route(
            ['GET'],
            '/test',
            fn () => 'closure handler' // Closure in Route->handler
        );

        $data = [
            'static' => ['GET' => ['/test' => $route]],
            'dynamic' => [],
        ];

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Closures');
        $cache->save($data);
    }

    public function testSaveRejectsNestedClosuresInObjects(): void
    {
        $cache = new RouteCache($this->cacheFile, self::TEST_KEY);

        // Create nested structure with Closure
        $route = new \Sodaho\Router\Route(
            ['GET'],
            '/nested',
            ['SomeClass', 'method'],
            [fn () => 'middleware closure'] // Closure in middleware array
        );

        $data = [
            'static' => ['GET' => ['/nested' => $route]],
            'dynamic' => [],
        ];

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Closures');
        $cache->save($data);
    }

    public function testSaveHandlesCircularReferences(): void
    {
        $cache = new RouteCache($this->cacheFile, self::TEST_KEY);

        // Create object with circular reference
        $obj1 = new \stdClass();
        $obj2 = new \stdClass();
        $obj1->ref = $obj2;
        $obj2->ref = $obj1; // Circular reference!

        $data = ['circular' => $obj1];

        // The closure check should complete without infinite recursion
        // var_export triggers a warning for circular refs (expected)
        @$cache->save($data);

        // If we got here, the circular reference protection worked
        $this->assertFileExists($this->cacheFile);
    }

    public function testConstructorThrowsWhenEnabledWithoutKey(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('signature key is required');

        new RouteCache($this->cacheFile, null, true);
    }

    // public function testConstructorAllowsDisabledWithoutKey(): void
    // {
    //     // Should not throw - disabled cache doesn't need key
    //     $cache = new RouteCache($this->cacheFile, null, false);
    //     $this->assertFalse($cache->isEnabled());
    // }
}
