<?php

declare(strict_types=1);

namespace Sodaho\Router\Cache;

/**
 * Namespace mock for rename() function.
 * When $GLOBALS['mock_rename_failure'] is true, rename() returns false.
 */
function rename(string $from, string $to): bool
{
    if ($GLOBALS['mock_rename_failure'] ?? false) {
        return false;
    }
    return \rename($from, $to);
}

namespace Sodaho\Router\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\Router\Cache\RouteCache;
use Sodaho\Router\Exception\CacheException;

/**
 * Tests for RouteCache rename() failure scenario.
 * Uses namespace mocking to simulate rename() failure.
 */
class RouteCacheRenameFailTest extends TestCase
{
    private const TEST_KEY = 'test-signature-key-for-unit-tests';

    private string $cacheDir;
    private string $cacheFile;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/router-rename-test-' . uniqid();
        mkdir($this->cacheDir, 0o755, true);
        $this->cacheFile = $this->cacheDir . '/routes.cache.php';

        // Reset the mock flag
        $GLOBALS['mock_rename_failure'] = false;
    }

    protected function tearDown(): void
    {
        // Reset the mock flag
        $GLOBALS['mock_rename_failure'] = false;

        // Clean up
        if (file_exists($this->cacheFile)) {
            @unlink($this->cacheFile);
        }
        // Clean up any temp files
        foreach (glob($this->cacheDir . '/*.tmp.*') as $tmpFile) {
            @unlink($tmpFile);
        }
        if (is_dir($this->cacheDir)) {
            @rmdir($this->cacheDir);
        }
    }

    public function testSaveThrowsWhenRenameFails(): void
    {
        $cache = new RouteCache($this->cacheFile, self::TEST_KEY);

        // Enable the rename mock to return false
        $GLOBALS['mock_rename_failure'] = true;

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('write');

        $cache->save(['test' => true]);
    }

    public function testSaveCleansTempFileWhenRenameFails(): void
    {
        $cache = new RouteCache($this->cacheFile, self::TEST_KEY);

        // Enable the rename mock
        $GLOBALS['mock_rename_failure'] = true;

        try {
            $cache->save(['test' => true]);
        } catch (CacheException $e) {
            // Expected
        }

        // Verify temp file was cleaned up
        $tempFiles = glob($this->cacheDir . '/*.tmp.*');
        $this->assertEmpty($tempFiles, 'Temp file should be cleaned up after rename failure');
    }
}
