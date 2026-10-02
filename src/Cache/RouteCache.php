<?php

declare(strict_types=1);

namespace Sodaho\Router\Cache;

use Sodaho\Router\Exception\CacheException;

/**
 * Handles caching of compiled route data.
 *
 * The cache is a signed data file, never an executable one: load() verifies an
 * HMAC-SHA256 over every byte it goes on to use and only then unserializes them.
 *
 * What the signature proves: a holder of the key wrote this file as a route cache. It does
 * not prove that the file belongs to this router or is the latest one — a file signed with
 * the same key for another router, or an older one, passes. One key per cache file.
 */
class RouteCache
{
    /**
     * First line of the file, should a web server ever hand the cache file to PHP. Not
     * "exit": PHP compiles a whole file before running it, so a "<?php" inside the payload
     * would end in a parse error that prints the cache path. After __halt_compiler() nothing
     * is compiled at all.
     */
    private const GUARD = "<?php __halt_compiler(); ?>\n";

    private const SIGNATURE_PREFIX = 'HMAC-SHA256: ';

    /** Hex length of a SHA-256 HMAC. */
    private const SIGNATURE_LENGTH = 64;

    /**
     * Signed together with the payload, so a file signed for another purpose with the
     * same key (e.g. a container cache under a shared APP_KEY) is not accepted here.
     */
    private const SIGNATURE_CONTEXT = "sodaho/php-router route-cache v2\n";

    /** How files written by 1.0/1.1 start. They were executable PHP; they are never run, only replaced. */
    private const LEGACY_PREFIX = "<?php\n// HMAC-SHA256: ";

    private string $cacheFile;
    private ?string $signatureKey;
    private bool $enabled;

    /**
     * Create a new RouteCache instance.
     *
     * @param string $cacheFile The path to the cache file
     * @param string|null $signatureKey Key for the HMAC signature (required when enabled)
     * @param bool $enabled Whether caching is enabled
     *
     * @throws CacheException If caching is enabled but no signature key is provided
     */
    public function __construct(string $cacheFile, ?string $signatureKey = null, bool $enabled = true)
    {
        // An empty key signs nothing: anyone can compute the same HMAC.
        if ($enabled && ($signatureKey === null || $signatureKey === '')) {
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
     * @throws \LogicException If data contains Closures, resources or other values that cannot be serialized
     * @throws CacheException If writing fails
     */
    public function save(array $data): void
    {
        // CLOSURE-CHECK at the beginning (as per spec), also while the cache is off: routes
        // with Closures are reported in debug mode already, not only in production.
        // Must check objects too, not just arrays
        if (!$this->enabled) {
            $this->assertNoClosures($data);

            return;
        }

        // From here on the application's own __serialize()/__sleep() run — in the check, which
        // looks at what will be written, and in serialize() itself. Whatever they throw means
        // the same thing: these routes cannot be cached.
        try {
            $this->assertCacheable($data);
            $payload = serialize($data);
        } catch (\LogicException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \LogicException('Cannot cache routes: ' . $e->getMessage(), 0, $e);
        }

        // Ensure cache directory exists. With several cold workers at once only one mkdir()
        // wins, the others would warn "File exists" — the is_dir() check is what decides.
        $directory = dirname($this->cacheFile);
        if (!is_dir($directory) && !self::quietly(static fn () => mkdir($directory, 0o755, true)) && !is_dir($directory)) {
            throw CacheException::directoryNotWritable($directory);
        }

        $content = self::GUARD . self::SIGNATURE_PREFIX . $this->sign($payload) . "\n" . $payload;

        // Atomic write (prevents partial reads)
        $tempFile = $this->cacheFile . '.tmp.' . bin2hex(random_bytes(8));
        // The return value decides (false also for a short write, e.g. disk full)
        if (self::quietly(static fn () => file_put_contents($tempFile, $content)) === false) {
            self::quietly(static fn () => unlink($tempFile));
            throw CacheException::writeFailed($this->cacheFile);
        }

        // Atomic move
        $cacheFile = $this->cacheFile;
        if (!self::quietly(static fn () => rename($tempFile, $cacheFile))) {
            self::quietly(static fn () => unlink($tempFile));
            throw CacheException::writeFailed($this->cacheFile);
        }
    }

    /**
     * Runs a filesystem call whose return value says whether it worked.
     *
     * Its warning must not travel: applications commonly turn warnings into exceptions, and
     * a cache that cannot be read or written must not take the request down. @ covers the
     * handlers that respect it, the catch those that throw regardless.
     */
    private static function quietly(callable $operation): mixed
    {
        try {
            return @$operation();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Load dispatch data from cache.
     *
     * @throws CacheException If the file is not a cache file this key signed, or its content
     *                        no longer fits the classes of the application
     *
     * @return array<int|string, mixed>|null The cached dispatch data, or null if not available
     */
    public function load(): ?array
    {
        if (!$this->enabled || !file_exists($this->cacheFile) || !is_readable($this->cacheFile)) {
            return null;
        }

        $cacheFile = $this->cacheFile;
        $content = self::quietly(static fn () => file_get_contents($cacheFile));
        if (!is_string($content)) {
            return null;
        }

        // Left over from 1.0/1.1: a miss, so the next save() replaces it.
        if (str_starts_with($content, self::LEGACY_PREFIX)) {
            return null;
        }

        // No searching: fixed head, fixed-length signature, the rest is the payload. Every
        // byte of the file is therefore either fixed or signed.
        $head = self::GUARD . self::SIGNATURE_PREFIX;
        $payloadStart = strlen($head) + self::SIGNATURE_LENGTH + 1;
        if (!str_starts_with($content, $head) || strlen($content) < $payloadStart || $content[$payloadStart - 1] !== "\n") {
            throw CacheException::invalidSignature();
        }

        $signature = substr($content, strlen($head), self::SIGNATURE_LENGTH);
        $payload = substr($content, $payloadStart);

        if (!hash_equals($this->sign($payload), $signature)) {
            throw CacheException::invalidSignature();
        }

        // The payload is exactly what save() signed, so the classes in it are the ones the
        // application put into its routes (Route, RedirectHandler, middleware instances).
        //
        // A class this process does not know (declared inside the routes file, renamed since
        // the cache was written) would come back as __PHP_Incomplete_Class without any error,
        // and dispatching with it would fail in the middle of a request. Look first.
        $unknown = $this->findUnknownClass($payload);
        if ($unknown !== null) {
            throw CacheException::outdated(sprintf('unknown class %s', $unknown));
        }

        try {
            // @: a notice (an enum case that no longer exists) must not become an exception
            // of its own in applications whose error handler throws
            $data = @unserialize($payload);
        } catch (\Throwable $e) {
            // A class refused to wake up: it changed since the cache was written.
            throw CacheException::outdated($e->getMessage(), $e);
        }

        if ($data === false && $payload !== serialize(false)) {
            throw CacheException::outdated('the content cannot be restored');
        }

        return is_array($data) ? $data : null;
    }

    /**
     * Names every class the payload would instantiate and returns the first one that does
     * not exist (after autoloading).
     *
     * Reads the serialization format instead of walking the restored data: that walk cost
     * more than unserialize() itself. A string value that merely looks like an object token
     * is checked too — the worst that follows is a cache reported as outdated.
     *
     * @throws CacheException If the payload cannot be scanned
     */
    private function findUnknownClass(string $payload): ?string
    {
        $found = preg_match_all('/\b[OC]:\d+:"([^"]+)":\d+:\{|\bE:\d+:"([^":]+):[^"]+";/', $payload, $matches);

        // The scan itself failed (PCRE limits): "could not check" must not pass for "all known"
        if ($found === false) {
            throw CacheException::outdated('the classes in the cache could not be checked');
        }

        if ($found === 0) {
            return null;
        }

        foreach (array_unique(array_merge($matches[1], $matches[2])) as $class) {
            // class_exists() is true for enums as well
            if ($class !== '' && !class_exists($class)) {
                return $class;
            }
        }

        return null;
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

    private function sign(string $payload): string
    {
        // signatureKey is guaranteed non-null when this method is called
        assert($this->signatureKey !== null);
        return hash_hmac('sha256', self::SIGNATURE_CONTEXT . $payload, $this->signatureKey);
    }

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

    /**
     * What is about to be serialized must survive it: no Closure, no resource.
     *
     * Unlike assertNoClosures() this follows what serialize() will write, not everything an
     * object holds — and for that it has to ask the object (see serializedState()).
     *
     * @param array<int, bool> $visited Already visited object IDs
     *
     * @throws \LogicException If a Closure or a resource would be written
     */
    private function assertCacheable(mixed $data, array &$visited = []): void
    {
        if ($data instanceof \Closure) {
            throw new \LogicException(
                'Cannot cache routes with Closures. Use [Controller::class, "method"] syntax.'
            );
        }

        // serialize() does not refuse a resource, it writes int(0) in its place. gettype():
        // is_resource() is false for a closed one, which serializes the same way.
        if (str_starts_with(gettype($data), 'resource')) {
            throw new \LogicException(
                'Cannot cache routes with resources (open files, sockets): they do not survive serialization.'
            );
        }

        if (is_array($data)) {
            foreach ($data as $item) {
                $this->assertCacheable($item, $visited);
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

            foreach ($this->serializedState($data) as $value) {
                $this->assertCacheable($value, $visited);
            }
        }
    }

    /**
     * The part of an object that serialize() writes.
     *
     * A logger that leaves its open file out of __serialize()/__sleep() and reopens it is
     * fine to cache; a collection whose (array) cast shows nothing (SplObjectStorage) may
     * still carry a resource into the payload. Asking the object is the only reliable way.
     *
     * @return array<int|string, mixed>
     */
    private function serializedState(object $object): array
    {
        if (method_exists($object, '__serialize')) {
            return $object->__serialize();
        }

        $properties = (array) $object;

        if (!method_exists($object, '__sleep')) {
            return $properties;
        }

        // __sleep() names properties, plainly or (for a private one of a parent class) in the
        // form the (array) cast uses as key: "\0Class\0name".
        $kept = array_flip($object->__sleep());

        return array_filter(
            $properties,
            static fn (int|string $key): bool => isset($kept[$key])
                || isset($kept[substr((string) $key, (int) strrpos("\0" . $key, "\0"))]),
            ARRAY_FILTER_USE_KEY
        );
    }
}
