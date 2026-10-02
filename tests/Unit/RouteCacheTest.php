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

        // Head and signature line are in place, but the signature belongs to nothing
        $fakeSignature = str_repeat('a', 64);
        file_put_contents($this->cacheFile, "<?php __halt_compiler(); ?>\nHMAC-SHA256: {$fakeSignature}\n" . serialize(['test']));

        $this->expectException(CacheException::class);
        $cache->load();
    }

    public function testLoadIgnoresFilesInTheFormatOfEarlierVersions(): void
    {
        $cache = new RouteCache($this->cacheFile, 'secret-key');

        // 1.0/1.1 wrote executable PHP. Whatever it contains: a miss, never an include.
        $fakeSignature = str_repeat('a', 64);
        file_put_contents($this->cacheFile, "<?php\n// HMAC-SHA256: {$fakeSignature}\nreturn invalid syntax;");

        $this->assertNull($cache->load());
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

        // The message as it is, not wrapped into a second "Cannot cache routes: ..."
        try {
            $cache->save($data);
            $this->fail('A Closure was cached');
        } catch (\LogicException $e) {
            $this->assertSame('Cannot cache routes with Closures. Use [Controller::class, "method"] syntax.', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
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

        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('Permission checks are bypassed by root; covered by RouteCacheVfsTest.');
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
        $cache->save($data);

        $loaded = $cache->load();
        $this->assertIsArray($loaded);
        $this->assertSame($loaded['circular'], $loaded['circular']->ref->ref);
    }

    public function testObjectsSurviveTheRoundTripWithoutSetState(): void
    {
        $cache = new RouteCache($this->cacheFile, self::TEST_KEY);

        // stdClass has no __set_state(): the old var_export() format wrote a file for it that
        // could never be loaded again, and the cache was silently rebuilt on every request.
        $marker = new \stdClass();
        $marker->format = 'oauth';
        $route = new \Sodaho\Router\Route(['GET'], '/test', ['SomeClass', 'method'], [$marker], 'test.route');

        $cache->save(['static' => ['GET' => ['/test' => $route]]]);
        $loaded = $cache->load();

        $this->assertIsArray($loaded);
        $this->assertEquals($route, $loaded['static']['GET']['/test']);
    }

    public function testSaveRejectsValuesThatCannotBeSerialized(): void
    {
        $cache = new RouteCache($this->cacheFile, self::TEST_KEY);

        // An anonymous class is as uncacheable as a Closure — and must be just as loud.
        $route = new \Sodaho\Router\Route(['GET'], '/test', new class () {});

        try {
            $cache->save(['static' => ['GET' => ['/test' => $route]]]);
            $this->fail('An unserializable handler was cached');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('Cannot cache routes', $e->getMessage());
            $this->assertInstanceOf(\Throwable::class, $e->getPrevious());
        }

        $this->assertFileDoesNotExist($this->cacheFile);
    }

    public function testConstructorThrowsWhenEnabledWithoutKey(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('signature key is required');

        new RouteCache($this->cacheFile, null, true);
    }

    public function testSaveRejectsResources(): void
    {
        $cache = new RouteCache($this->cacheFile, self::TEST_KEY);

        // serialize() does not refuse a resource: it writes int(0), and the next request
        // would get a middleware whose stream has turned into a number.
        $open = fopen('php://memory', 'r');
        $closed = fopen('php://memory', 'r');
        fclose($closed);

        foreach (['open' => $open, 'closed' => $closed] as $label => $resource) {
            $holder = new \stdClass();
            $holder->stream = $resource;
            $route = new \Sodaho\Router\Route(['GET'], '/test', ['SomeClass', 'method'], [$holder]);

            try {
                $cache->save(['static' => ['GET' => ['/test' => $route]]]);
                $this->fail("A route holding a resource ({$label}) was cached");
            } catch (\LogicException $e) {
                $this->assertStringContainsString('Cannot cache routes with resources', $e->getMessage());
            }
        }

        fclose($open);
        $this->assertFileDoesNotExist($this->cacheFile);
    }

    public function testFailingRenameStaysACacheExceptionUnderAThrowingErrorHandler(): void
    {
        // A directory sits where the cache file should go: the temp file can be written, the
        // rename onto it cannot — and warns. Applications whose error handler throws on
        // warnings must still get the CacheException the router knows how to report.
        mkdir($this->cacheFile);
        $cache = new RouteCache($this->cacheFile, self::TEST_KEY);

        // What frameworks install: throw on everything that is not silenced with @.
        // (PHPUnit lowers error_reporting() while a test runs; raise it so the handler
        // sees what it would see in an application.)
        $reporting = error_reporting(E_ALL);
        set_error_handler(static function (int $severity, string $message): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new \ErrorException($message, 0, $severity);
        });

        try {
            $cache->save(['test' => true]);
            $this->fail('Renaming onto a directory succeeded');
        } catch (CacheException $e) {
            $this->assertStringContainsString('Failed to write cache file', $e->getMessage());
        } finally {
            restore_error_handler();
            error_reporting($reporting);
            rmdir($this->cacheFile);
        }

        $this->assertSame([], glob($this->cacheDir . '/*.tmp.*'), 'the temp file is removed');
    }

    public function testObjectsThatLeaveTheirResourceOutOfSerializationAreCached(): void
    {
        $cache = new RouteCache($this->cacheFile, self::TEST_KEY);

        // What log handlers do: the open file is not part of the serialized state and is
        // reopened on demand. Such an object serializes cleanly and must not be refused.
        foreach ([new ReopensViaSerialize('php://memory'), new ReopensViaSleep('php://memory')] as $logger) {
            $route = new \Sodaho\Router\Route(['GET'], '/test', ['SomeClass', 'method'], [$logger]);

            $cache->save(['static' => ['GET' => ['/test' => $route]]]);
            $loaded = $cache->load();

            $this->assertIsArray($loaded);
            $restored = $loaded['static']['GET']['/test']->middleware[0];
            $this->assertSame($logger::class, $restored::class);
            $this->assertSame('php://memory', $restored->path);
            $this->assertNull($restored->handle);
        }
    }

    public function testOnlyWhatIsSerializedIsChecked(): void
    {
        $cache = new RouteCache($this->cacheFile, self::TEST_KEY);

        // A Closure in a property __sleep() leaves out never reaches the cache file ...
        $logger = new ReopensViaSleep('php://memory');
        $logger->handle = fn () => 'left out';
        $cache->save(['x' => $logger]);
        $this->assertIsArray($cache->load());

        // ... in a property that is written, it is refused as everywhere else
        $logger = new ReopensViaSleep('php://memory');
        $logger->path = fn () => 'not a path';

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Closures');
        $cache->save(['x' => $logger]);
    }

    /**
     * @return array<string, array{0: object}>
     */
    public static function objectsThatRefuseSerialization(): array
    {
        return [
            '__sleep() throws' => [new RefusesViaSleep()],
            '__serialize() throws' => [new RefusesViaSerialize()],
            '__sleep() returns no array' => [new SleepReturnsNoArray()],
        ];
    }

    /**
     * The check before serializing asks objects what they will write — it runs their code.
     * Whatever that throws has to arrive as the LogicException the router knows how to
     * report, not as an exception of the application in the middle of a request.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('objectsThatRefuseSerialization')]
    public function testObjectThatRefusesSerializationIsNotCachedAndNotFatal(object $object): void
    {
        $cache = new RouteCache($this->cacheFile, self::TEST_KEY);

        try {
            $cache->save(['x' => $object]);
            $this->fail('An object that refuses serialization was cached');
        } catch (\LogicException $e) {
            $this->assertStringStartsWith('Cannot cache routes: ', $e->getMessage());
            $this->assertNotNull($e->getPrevious());
        }

        $this->assertFileDoesNotExist($this->cacheFile);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('objectsThatRefuseSerialization')]
    public function testDisabledCacheNeverRunsSerializationCodeOfTheApplication(object $object): void
    {
        // Debug mode: nothing is written, so nothing may be asked either. Up to 1.1.0 no
        // application code ran here, and an object like this did not disturb a request.
        $cache = new RouteCache($this->cacheFile, null, false);

        $cache->save(['x' => $object]);

        $this->assertFileDoesNotExist($this->cacheFile);
    }

    public function testDisabledCacheCopesWithCircularReferences(): void
    {
        $cache = new RouteCache($this->cacheFile, null, false);

        $a = new \stdClass();
        $b = new \stdClass();
        $a->other = $b;
        $b->other = $a;

        $cache->save(['x' => $a]);

        $this->assertFileDoesNotExist($this->cacheFile);
    }

    public function testDisabledCacheStillReportsClosures(): void
    {
        $cache = new RouteCache($this->cacheFile, null, false);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Closures');
        $cache->save(['x' => new \Sodaho\Router\Route(['GET'], '/x', fn () => 'closure')]);
    }

    public function testSleepNamingAPrivatePropertyOfTheParentIsFollowed(): void
    {
        $cache = new RouteCache($this->cacheFile, self::TEST_KEY);

        // For a private property of a parent class __sleep() has to return the name in the
        // "\0Class\0name" form; serialize() then writes it — as int(0) if it is a resource.
        $resource = fopen('php://memory', 'r');

        try {
            $cache->save(['x' => new SleepsOnParentProperty($resource)]);
            $this->fail('A resource in a parent property named by __sleep() was cached');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('Cannot cache routes with resources', $e->getMessage());
        } finally {
            fclose($resource);
        }
    }

    public function testResourceHiddenInACollectionIsFound(): void
    {
        $cache = new RouteCache($this->cacheFile, self::TEST_KEY);

        // (array) on an SplObjectStorage shows nothing of its content, serialize() writes all
        // of it — the resource as int(0). Only __serialize() tells what will be written.
        $resource = fopen('php://memory', 'r');
        $storage = new \SplObjectStorage();
        $storage[new \stdClass()] = $resource;

        try {
            $cache->save(['x' => $storage]);
            $this->fail('A resource inside an SplObjectStorage was cached');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('Cannot cache routes with resources', $e->getMessage());
        } finally {
            fclose($resource);
        }

        $this->assertFileDoesNotExist($this->cacheFile);
    }

    public function testPrivateAndProtectedPropertiesNamedBySleepAreChecked(): void
    {
        $cache = new RouteCache($this->cacheFile, self::TEST_KEY);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Closures');
        $cache->save(['x' => new SleepsOnHiddenProperties(fn () => 'kept by __sleep')]);
    }

    public function testHiddenPropertiesLeftOutBySleepAreNotChecked(): void
    {
        $cache = new RouteCache($this->cacheFile, self::TEST_KEY);

        $cache->save(['x' => new SleepsOnHiddenProperties('plain', fn () => 'left out')]);

        $this->assertIsArray($cache->load());
    }
}

final class ReopensViaSerialize
{
    /** @var resource|null */
    public $handle;

    public function __construct(public mixed $path)
    {
        $this->handle = fopen($path, 'r');
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        return ['path' => $this->path];
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        $this->path = $data['path'];
        $this->handle = null;
    }
}

final class ReopensViaSleep
{
    /** @var resource|null */
    public $handle;

    public function __construct(public mixed $path)
    {
        $this->handle = fopen($path, 'r');
    }

    /** @return list<string> */
    public function __sleep(): array
    {
        return ['path'];
    }
}

final class SleepsOnHiddenProperties
{
    public function __construct(private mixed $kept, protected mixed $dropped = null)
    {
    }

    /** @return list<string> */
    public function __sleep(): array
    {
        return ['kept'];
    }
}

final class RefusesViaSleep
{
    /** @return list<string> */
    public function __sleep(): array
    {
        throw new \RuntimeException('This object must not be serialized');
    }
}

final class RefusesViaSerialize
{
    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \RuntimeException('This object must not be serialized');
    }
}

final class SleepReturnsNoArray
{
    public string $value = 'x';

    public function __sleep()
    {
        return 'value';
    }
}

class HoldsAHandle
{
    /** @param resource $handle */
    public function __construct(private mixed $handle)
    {
    }
}

final class SleepsOnParentProperty extends HoldsAHandle
{
    /** @return list<string> */
    public function __sleep(): array
    {
        return ["\0" . HoldsAHandle::class . "\0handle"];
    }
}
