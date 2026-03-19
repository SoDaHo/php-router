<?php

declare(strict_types=1);

namespace Sodaho\Router\Cache;

use Sodaho\Router\Exception\CacheException;

/**
 * Handles caching of compiled route data.
 * Uses var_export() to store routes as executable PHP code, which allows
 * OPcache to cache the route data in memory for maximum performance.
 * Includes optional HMAC signature for integrity verification.
 */
class RouteCache
{
    private string $cacheFile;
    private ?string $signatureKey;
    private bool $enabled;

    /**
     * Create a new RouteCache instance.
     *
     * @param string $cacheFile The path to the cache file
     * @param string|null $signatureKey Optional key for HMAC signature (required when enabled)
     * @param bool $enabled Whether caching is enabled
     *
     * @throws CacheException If caching is enabled but no signature key is provided
     */
    public function __construct(string $cacheFile, ?string $signatureKey = null, bool $enabled = true)
    {
        // Security: Require signature key in production (when caching is enabled)
        // This prevents RCE via tampered cache files in shared hosting environments
        if ($enabled && $signatureKey === null) {
            throw CacheException::signatureKeyRequired();
        }

        $this->cacheFile = $cacheFile;
        $this->signatureKey = $signatureKey;
        $this->enabled = $enabled;
    }

    /**
     * Save dispatch data to cache.
     *
     * @param array<int|string, mixed> $data The dispatch data to cache
     *
     * @throws \LogicException If data contains Closures
     * @throws CacheException If writing fails
     */
    public function save(array $data): void
    {
        // CLOSURE-CHECK at the beginning (as per spec)
        // Must check objects too, not just arrays
        $this->assertNoClosures($data);

        if (!$this->enabled) {
            return;
        }

        // Ensure cache directory exists
        $directory = dirname($this->cacheFile);
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0o755, true) && !is_dir($directory)) {
                throw CacheException::directoryNotWritable($directory);
            }
        }

        // Export data as PHP code for OPcache optimization
        $export = var_export($data, true);

        // Build cache file content
        $content = "<?php\n";

        // Add signature comment if key is provided
        if ($this->signatureKey !== null) {
            $signature = $this->generateSignature($export);
            $content .= "// HMAC-SHA256: {$signature}\n";
        }

        $content .= "return {$export};";

        // Atomic write (prevents partial reads)
        $tempFile = $this->cacheFile . '.tmp.' . bin2hex(random_bytes(8));
        if (file_put_contents($tempFile, $content) === false) {
            @unlink($tempFile);
            throw CacheException::writeFailed($this->cacheFile);
        }

        // Atomic move
        if (!rename($tempFile, $this->cacheFile)) {
            @unlink($tempFile);
            throw CacheException::writeFailed($this->cacheFile);
        }

        // Invalidate OPcache so the new file is picked up immediately
        // @codeCoverageIgnoreStart
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->cacheFile, true);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Load dispatch data from cache.
     *
     * @throws CacheException If signature validation fails
     *
     * @return array<int|string, mixed>|null The cached dispatch data, or null if not available
     */
    public function load(): ?array
    {
        if (!$this->enabled || !file_exists($this->cacheFile) || !is_readable($this->cacheFile)) {
            return null;
        }

        $content = file_get_contents($this->cacheFile);
        if ($content === false) {
            // @codeCoverageIgnoreStart
            return null;
            // @codeCoverageIgnoreEnd
        }

        // Validate signature if key is provided
        if ($this->signatureKey !== null) {
            // Extract signature from comment: // HMAC-SHA256: [signature]
            if (!preg_match('/^<\?php\s*\n\/\/ HMAC-SHA256: ([a-f0-9]{64})\n/i', $content, $matches)) {
                throw CacheException::invalidSignature();
            }

            $signature = $matches[1];

            // Extract the var_export data (everything after the signature comment)
            $exportStart = strpos($content, 'return ');
            if ($exportStart === false) {
                throw CacheException::invalidSignature();
            }

            $export = substr($content, $exportStart);
            $export = rtrim($export, ';');
            $export = substr($export, 7); // Remove "return "

            if (!$this->verifySignature($export, $signature)) {
                throw CacheException::invalidSignature();
            }
        }

        // Load PHP file (OPcache will cache this in memory!)
        try {
            $data = require $this->cacheFile;
            return is_array($data) ? $data : null;
            // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            // Defense-in-depth: Cannot be triggered under normal circumstances
            // because signature validation ensures content integrity.
            // Kept for edge cases (TOCTOU, OPcache bugs, filesystem corruption).
            return null;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Clear the cache.
     *
     * @return bool True if cleared successfully, false if file didn't exist
     */
    public function clear(): bool
    {
        if (file_exists($this->cacheFile)) {
            return unlink($this->cacheFile);
        }
        return false;
    }

    /**
     * Check if cache exists and is fresh.
     *
     * @param int|null $maxAge Maximum age in seconds (null for no limit)
     */
    public function isFresh(?int $maxAge = null): bool
    {
        if (!$this->enabled || !file_exists($this->cacheFile)) {
            return false;
        }

        if ($maxAge === null) {
            return true;
        }

        $fileAge = time() - filemtime($this->cacheFile);
        return $fileAge <= $maxAge;
    }

    /**
     * Get cache file modification time.
     *
     * @return int|null Unix timestamp or null if file doesn't exist
     */
    public function getModificationTime(): ?int
    {
        if (!file_exists($this->cacheFile)) {
            return null;
        }
        $mtime = filemtime($this->cacheFile);
        return $mtime !== false ? $mtime : null;
    }

    private function generateSignature(string $data): string
    {
        // signatureKey is guaranteed non-null when this method is called
        assert($this->signatureKey !== null);
        return hash_hmac('sha256', $data, $this->signatureKey);
    }

    private function verifySignature(string $data, string $signature): bool
    {
        $expected = $this->generateSignature($data);
        return hash_equals($expected, $signature);
    }

    // /**
    //  * Check if caching is enabled.
    //  */
    // public function isEnabled(): bool
    // {
    //     return $this->enabled;
    // }

    // /**
    //  * Enable or disable caching.
    //  *
    //  * @param bool $enabled Enable caching
    //  */
    // public function setEnabled(bool $enabled): self
    // {
    //     $this->enabled = $enabled;
    //     return $this;
    // }

    /**
     * Get the cache file path.
     */
    public function getCacheFile(): string
    {
        return $this->cacheFile;
    }

    /**
     * @param array<int, bool> $visited Already visited object IDs
     *
     * @throws \LogicException If a Closure is found
     */
    private function assertNoClosures(mixed $data, array &$visited = []): void
    {
        if ($data instanceof \Closure) {
            throw new \LogicException(
                'Cannot cache routes with Closures. Use [Controller::class, "method"] syntax.'
            );
        }

        if (is_array($data)) {
            foreach ($data as $item) {
                $this->assertNoClosures($item, $visited);
            }
            return;
        }

        if (is_object($data)) {
            // Prevent infinite recursion on circular references
            $objectId = spl_object_id($data);
            if (isset($visited[$objectId])) {
                return;
            }
            $visited[$objectId] = true;

            // Check object properties
            foreach ((array) $data as $value) {
                $this->assertNoClosures($value, $visited);
            }
        }
    }
}
