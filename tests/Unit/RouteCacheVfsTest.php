<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\Cache\RouteCache;
use Sodaho\Router\Exception\CacheException;

/**
 * Tests for RouteCache filesystem error scenarios using vfsStream.
 */
class RouteCacheVfsTest extends TestCase
{
    private const TEST_KEY = 'test-signature-key-for-unit-tests';

    private vfsStreamDirectory $root;

    protected function setUp(): void
    {
        $this->root = vfsStream::setup('cache');
    }

    public function testSaveThrowsWhenDirectoryNotWritable(): void
    {
        // Create a read-only directory that cannot have subdirs created
        $dir = vfsStream::newDirectory('readonly', 0o444)->at($this->root);

        $cache = new RouteCache(vfsStream::url('cache/readonly/subdir/routes.php'), self::TEST_KEY);

        $this->expectException(CacheException::class);
        // Could be either "not writable" or "write" depending on which check fails
        $cache->save(['test' => true]);
    }

    public function testSaveThrowsWhenFileWriteFails(): void
    {
        // Create directory but make it non-writable after creation
        $dir = vfsStream::newDirectory('writable', 0o755)->at($this->root);

        // Create the cache file
        $cache = new RouteCache(vfsStream::url('cache/writable/routes.php'), self::TEST_KEY);
        $cache->save(['initial' => true]);

        // Make directory read-only so temp file creation fails
        $dir->chmod(0o000);

        $this->expectException(CacheException::class);

        // @ suppresses expected warning from file_put_contents
        @$cache->save(['updated' => true]);
    }

    public function testSaveCreatesNestedDirectory(): void
    {
        $cache = new RouteCache(vfsStream::url('cache/deep/nested/path/routes.php'), self::TEST_KEY);

        $cache->save(['test' => true]);

        $this->assertTrue($this->root->hasChild('deep/nested/path/routes.php'));
    }

    public function testLoadReturnsNullWhenFileUnreadable(): void
    {
        // Create a readable file first
        $file = vfsStream::newFile('unreadable.php', 0o644)
            ->withContent("<?php\nreturn ['test'];")
            ->at($this->root);

        // Make it unreadable
        $file->chmod(0o000);

        $cache = new RouteCache(vfsStream::url('cache/unreadable.php'), self::TEST_KEY);

        // is_readable() check prevents file_get_contents warning
        $result = $cache->load();

        $this->assertNull($result);
    }

    public function testSaveWithSignatureInVfs(): void
    {
        $cache = new RouteCache(
            vfsStream::url('cache/signed.php'),
            'my-secret-key'
        );

        $cache->save(['signed' => 'data']);

        $content = file_get_contents(vfsStream::url('cache/signed.php'));
        $this->assertStringContainsString('HMAC-SHA256:', $content);
    }

    public function testLoadWithSignatureFromVfs(): void
    {
        $cache = new RouteCache(
            vfsStream::url('cache/signed.php'),
            'my-secret-key'
        );

        $data = ['test' => 'data'];
        $cache->save($data);

        $loaded = $cache->load();

        $this->assertSame($data, $loaded);
    }

    public function testClearInVfs(): void
    {
        $file = vfsStream::newFile('routes.php')
            ->withContent("<?php\nreturn ['test'];")
            ->at($this->root);

        $cache = new RouteCache(vfsStream::url('cache/routes.php'), self::TEST_KEY);

        $this->assertTrue($cache->clear());
        $this->assertFalse($this->root->hasChild('routes.php'));
    }

    public function testIsFreshWithVfsFile(): void
    {
        $file = vfsStream::newFile('routes.php')
            ->withContent("<?php\nreturn ['test'];")
            ->at($this->root);

        $cache = new RouteCache(vfsStream::url('cache/routes.php'), self::TEST_KEY);

        $this->assertTrue($cache->isFresh());
        $this->assertTrue($cache->isFresh(3600)); // Within 1 hour
    }

    public function testAtomicWriteWithVfs(): void
    {
        $cache = new RouteCache(vfsStream::url('cache/atomic.php'), self::TEST_KEY);

        // Save multiple times to verify atomic write
        $cache->save(['version' => 1]);
        $cache->save(['version' => 2]);
        $cache->save(['version' => 3]);

        $loaded = $cache->load();
        $this->assertSame(['version' => 3], $loaded);
    }

    public function testSaveWithExistingDirectoryWorks(): void
    {
        // Pre-create the directory
        vfsStream::newDirectory('existing', 0o755)->at($this->root);

        $cache = new RouteCache(vfsStream::url('cache/existing/routes.php'), self::TEST_KEY);
        $cache->save(['test' => true]);

        $this->assertTrue($this->root->hasChild('existing/routes.php'));
    }

    /**
     * Applications commonly turn warnings into exceptions. A cache that cannot be written
     * has to stay a CacheException then — the router reports those and carries on — and
     * must not surface as an ErrorException from file_put_contents().
     */
    public function testWriteFailureStaysACacheExceptionUnderAThrowingErrorHandler(): void
    {
        $dir = vfsStream::newDirectory('locked', 0o755)->at($this->root);
        $cache = new RouteCache(vfsStream::url('cache/locked/routes.php'), self::TEST_KEY);
        $cache->save(['initial' => true]);
        $dir->chmod(0o000);

        // The blunt kind of handler: throws on every warning, silenced with @ or not
        $reporting = error_reporting(E_ALL);
        set_error_handler(static function (int $severity, string $message): bool {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            $cache->save(['updated' => true]);
            $this->fail('Writing into a locked directory succeeded');
        } catch (CacheException $e) {
            $this->assertStringContainsString('Failed to write cache file', $e->getMessage());
        } finally {
            restore_error_handler();
            error_reporting($reporting);
        }
    }

    public function testShortWriteIsAFailureAndLeavesTheOldCacheInPlace(): void
    {
        $cache = new RouteCache(vfsStream::url('cache/routes.php'), self::TEST_KEY);
        $cache->save(['version' => 1]);
        $old = file_get_contents(vfsStream::url('cache/routes.php'));

        // Disk full while the new file is written. Half a cache must never be renamed into
        // place — PHP reports the short write as a failure, and save() has to act on it.
        vfsStream::setQuota(strlen((string) $old) + 20);

        try {
            @$cache->save(['version' => 2, 'padding' => str_repeat('x', 500)]);
            $this->fail('A short write was taken for a complete one');
        } catch (CacheException $e) {
            $this->assertStringContainsString('Failed to write cache file', $e->getMessage());
        } finally {
            vfsStream::setQuota(-1);
        }

        $this->assertSame($old, file_get_contents(vfsStream::url('cache/routes.php')));
        $this->assertSame(['routes.php'], array_map(fn ($child) => $child->getName(), $this->root->getChildren()));
    }

    public function testUnreadableContentIsAMissUnderAThrowingErrorHandler(): void
    {
        // Passes is_readable() and then cannot be read: a directory at the cache path
        vfsStream::newDirectory('routes.php', 0o755)->at($this->root);
        $cache = new RouteCache(vfsStream::url('cache/routes.php'), self::TEST_KEY);

        // The blunt kind of handler: throws on every warning, silenced with @ or not
        $reporting = error_reporting(E_ALL);
        set_error_handler(static function (int $severity, string $message): bool {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            $this->assertNull($cache->load());
        } finally {
            restore_error_handler();
            error_reporting($reporting);
        }
    }
}
