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
}
