<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\Cache\RouteCache;
use Sodaho\Router\Exception\CacheException;

/**
 * The cache file sits on disk between requests. Whoever can write it must not be able to
 * make the router run code or load a route table that no holder of the key ever signed.
 * (A table that WAS signed with the same key — another router's, an older one — passes;
 * that is what "one key per cache file" in the README is for.)
 *
 * Up to 1.1.0 the file was PHP that load() ran through require, and the HMAC covered only
 * the text after the first "return ": code in front of it passed verification.
 */
class CacheTamperingTest extends TestCase
{
    private const KEY = 'cache-tampering-test-key';

    /** The format is a contract shared with sodaho/container — pinned here byte for byte. */
    private const GUARD = "<?php __halt_compiler(); ?>\n";
    private const CONTEXT = "sodaho/php-router route-cache v2\n";

    private string $dir;
    private string $file;
    private string $marker;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/router-tamper-' . uniqid();
        mkdir($this->dir, 0o755, true);
        $this->file = $this->dir . '/routes.cache.php';
        $this->marker = $this->dir . '/executed.marker';
    }

    protected function tearDown(): void
    {
        foreach ([$this->file, $this->marker] as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }
        rmdir($this->dir);
    }

    private function cache(string $key = self::KEY): RouteCache
    {
        return new RouteCache($this->file, $key);
    }

    /** PHP that leaves a trace when it runs. */
    private function markerCode(): string
    {
        return 'file_put_contents(' . var_export($this->marker, true) . ", 'x');";
    }

    private static function signed(string $payload, string $key = self::KEY, string $context = self::CONTEXT): string
    {
        return self::GUARD . 'HMAC-SHA256: ' . hash_hmac('sha256', $context . $payload, $key) . "\n" . $payload;
    }

    public function testWrittenFileHasTheSharedFormat(): void
    {
        $data = ['dispatchData' => [['GET' => []], []], 'namedRoutes' => ['a' => '/a']];
        $this->cache()->save($data);

        $this->assertSame(self::signed(serialize($data)), file_get_contents($this->file));
    }

    public function testFileSignedByHandInTheSharedFormatLoads(): void
    {
        $data = ['namedRoutes' => ['a' => '/a']];
        file_put_contents($this->file, self::signed(serialize($data)));

        $this->assertSame($data, $this->cache()->load());
    }

    /**
     * The 1.1.0 attack, replayed against a file in the old format: it must neither run
     * nor load, and it must not raise either — it is simply replaced on the next save().
     */
    public function testLegacyFileWithInjectedCodeIsNeverExecuted(): void
    {
        $export = var_export(['dispatchData' => [[], []], 'namedRoutes' => []], true);
        $legacy = "<?php\n// HMAC-SHA256: " . hash_hmac('sha256', $export, self::KEY) . "\n"
            . $this->markerCode() . "\nreturn {$export};";
        file_put_contents($this->file, $legacy);

        $this->assertNull($this->cache()->load());
        $this->assertFileDoesNotExist($this->marker);
    }

    public function testUntouchedLegacyFileIsAMissAndGetsReplaced(): void
    {
        $export = var_export(['old' => true], true);
        file_put_contents(
            $this->file,
            "<?php\n// HMAC-SHA256: " . hash_hmac('sha256', $export, self::KEY) . "\nreturn {$export};"
        );

        $cache = $this->cache();
        $this->assertNull($cache->load());

        $cache->save(['new' => true]);
        $this->assertSame(['new' => true], $cache->load());
        $this->assertStringStartsWith(self::GUARD, (string) file_get_contents($this->file));
    }

    /**
     * @return array<string, array{0: callable(string, string): string}>
     */
    public static function tamperings(): array
    {
        return [
            'payload byte changed' => [fn (string $c): string => str_replace('/original', '/tampered', $c)],
            'bytes appended' => [fn (string $c): string => $c . 'x'],
            'newline appended' => [fn (string $c): string => $c . "\n"],
            'last byte removed' => [fn (string $c): string => substr($c, 0, -1)],
            'payload removed' => [fn (string $c): string => substr($c, 0, strlen(self::GUARD) + 13 + 64 + 1)],
            'cut inside the signature' => [fn (string $c): string => substr($c, 0, strlen(self::GUARD) + 13 + 10)],
            'code before the signature line' => [fn (string $c, string $code): string => "<?php {$code} __halt_compiler(); ?>\n" . substr($c, strlen(self::GUARD))],
            'old exit guard' => [fn (string $c): string => "<?php exit; ?>\n" . substr($c, strlen(self::GUARD))],
            'code between signature and payload' => [fn (string $c, string $code): string => substr_replace($c, "<?php {$code} ?>\n", strlen(self::GUARD) + 13 + 64 + 1, 0)],
            'guard line removed' => [fn (string $c): string => substr($c, strlen(self::GUARD))],
            'signature in upper case' => [fn (string $c): string => self::GUARD . 'HMAC-SHA256: ' . strtoupper(substr($c, strlen(self::GUARD) + 13, 64)) . substr($c, strlen(self::GUARD) + 13 + 64)],
            'CRLF after the signature' => [fn (string $c): string => substr_replace($c, "\r\n", strlen(self::GUARD) + 13 + 64, 1)],
            // Same length as the original, so only the comparison of the fixed parts can catch them
            'one byte of the guard changed' => [fn (string $c): string => str_replace('__halt_compiler', '__HALT_compiler', $c)],
            'one byte of the signature label changed' => [fn (string $c): string => str_replace('HMAC-SHA256: ', "HMAC-SHA256:\t", $c)],
            'separator after the signature changed' => [fn (string $c): string => substr_replace($c, ' ', strlen(self::GUARD) + 13 + 64, 1)],
            'empty file' => [fn (): string => ''],
            'plain PHP without signature' => [fn (string $c, string $code): string => "<?php\n{$code}\nreturn [];"],
        ];
    }

    #[DataProvider('tamperings')]
    public function testTamperedFileIsRejectedAndNothingRuns(callable $tamper): void
    {
        $this->cache()->save(['namedRoutes' => ['a' => '/original']]);
        $original = (string) file_get_contents($this->file);

        $tampered = $tamper($original, $this->markerCode());
        $this->assertNotSame($original, $tampered, 'the fixture must actually change the file');
        file_put_contents($this->file, $tampered);

        try {
            $this->cache()->load();
            $this->fail('A tampered cache file was accepted');
        } catch (CacheException $e) {
            $this->assertSame('Cache file signature is invalid', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->marker);
    }

    /**
     * Should the file ever be executed (cache directory inside the web root), nothing may
     * happen and nothing may be printed — not even when the payload contains "<?php".
     * PHP compiles the whole file first; only __halt_compiler() keeps it from parsing the payload.
     */
    public function testExecutingTheCacheFileDirectlyDoesNothing(): void
    {
        $this->cache()->save(['namedRoutes' => ['a' => '<?php ' . $this->markerCode() . ' echo "leak"; this is not php']]);

        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=1', $this->file],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame('', $output);
        $this->assertSame(0, $exitCode);
        $this->assertFileDoesNotExist($this->marker);
    }

    public function testPayloadSignedWithAnotherKeyIsRejected(): void
    {
        file_put_contents($this->file, self::signed(serialize(['a' => 1]), 'another-key'));

        $this->expectException(CacheException::class);
        $this->cache()->load();
    }

    /**
     * Both libraries tell applications to use the same APP_KEY. Without the context in the
     * signature a validly signed container cache could be copied over the route cache.
     */
    public function testPayloadSignedForAnotherPurposeWithTheSameKeyIsRejected(): void
    {
        $payload = serialize(['a' => 1]);
        file_put_contents($this->file, self::signed($payload, self::KEY, "sodaho/container cache v2\n"));

        try {
            $this->cache()->load();
            $this->fail('A file signed for another purpose was accepted');
        } catch (CacheException) {
        }

        // ... and without any context at all (plain HMAC over the payload)
        file_put_contents($this->file, self::signed($payload, self::KEY, ''));

        $this->expectException(CacheException::class);
        $this->cache()->load();
    }

    public function testEmptyKeyIsRefused(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('signature key is required');

        new RouteCache($this->file, '');
    }

    public function testEmptyKeyIsFineWhileCachingIsOff(): void
    {
        $cache = new RouteCache($this->file, '', false);

        $this->assertNull($cache->load());
    }

    /**
     * Unserializing runs code (__wakeup, __unserialize). It may only happen after the
     * signature held — a rejected file must not have woken anything up.
     */
    public function testNothingIsUnserializedBeforeTheSignatureHolds(): void
    {
        WakesUpLoudly::$marker = $this->marker;
        $payload = serialize(['x' => new WakesUpLoudly()]);

        file_put_contents($this->file, self::signed($payload, 'another-key'));

        try {
            $this->cache()->load();
            $this->fail('A file signed with another key was accepted');
        } catch (CacheException) {
        }

        $this->assertFileDoesNotExist($this->marker);

        // Control: with the right key the same payload does wake up — the marker works
        file_put_contents($this->file, self::signed($payload));
        $this->assertIsArray($this->cache()->load());
        $this->assertFileExists($this->marker);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function payloadsWithAnUnknownClass(): array
    {
        $unknown = 'O:29:"Sodaho\Router\Tests\NoSuchOne":1:{s:1:"a";i:1;}';

        return [
            'at the top' => ['a:1:{s:1:"x";' . $unknown . '}'],
            'nested in arrays' => ['a:1:{s:1:"x";a:1:{i:0;a:1:{i:0;' . $unknown . '}}}'],
            'in a property of a known object' => ['a:1:{s:1:"x";O:8:"stdClass":1:{s:1:"p";' . $unknown . '}}'],
            'inside an SplObjectStorage' => [
                'a:1:{s:1:"x";O:16:"SplObjectStorage":2:{i:0;a:2:{i:0;' . $unknown . 'i:1;N;}i:1;a:0:{}}}',
            ],
            // What classes implementing Serializable wrote before __serialize() existed
            'in the old C: format' => ['a:1:{s:1:"x";C:29:"Sodaho\Router\Tests\NoSuchOne":0:{}}'],
            'as the class of an enum case' => ['a:1:{s:1:"x";E:34:"Sodaho\Router\Tests\NoSuchOne:Case";}'],
            'in the middleware of a route' => [
                'a:1:{s:1:"x";O:19:"Sodaho\Router\Route":5:{s:7:"methods";a:1:{i:0;s:3:"GET";}s:7:"pattern";s:2:"/x";'
                . 's:7:"handler";s:1:"h";s:10:"middleware";a:1:{i:0;' . $unknown . '}s:4:"name";N;}}',
            ],
        ];
    }

    /**
     * A class the loading process does not know comes back from unserialize() as
     * __PHP_Incomplete_Class without any error. Dispatching with that would fail in the
     * middle of a request; reporting it lets the router rebuild from the routes file.
     */
    #[DataProvider('payloadsWithAnUnknownClass')]
    public function testSignedPayloadWithAnUnknownClassIsReportedAsOutdated(string $payload): void
    {
        file_put_contents($this->file, self::signed($payload));

        try {
            $this->cache()->load();
            $this->fail('A cache referring to an unknown class was loaded');
        } catch (CacheException $e) {
            $this->assertSame('Cache file is outdated', $e->getMessage());
            $this->assertStringStartsWith('Unknown class Sodaho\Router\Tests\NoSuchOne. ', (string) $e->getDebugMessage());
        }
    }

    public function testEnumCaseThatNoLongerExistsIsReportedAsOutdated(): void
    {
        $payload = str_replace('Running', 'Removed', serialize(['state' => CachedState::Running]));
        file_put_contents($this->file, self::signed($payload));

        // The class is there, the case is not: unserialize() only raises a notice and
        // returns false. That is neither a usable cache nor a silent miss.
        try {
            $this->cache()->load();
            $this->fail('A cache with a removed enum case was loaded');
        } catch (CacheException $e) {
            $this->assertSame('Cache file is outdated', $e->getMessage());
            $this->assertStringStartsWith('The content cannot be restored. ', (string) $e->getDebugMessage());
        }

        // Control: the unchanged payload loads, and so does a plain false
        file_put_contents($this->file, self::signed(serialize(['state' => CachedState::Running])));
        $this->assertSame(['state' => CachedState::Running], $this->cache()->load());

        file_put_contents($this->file, self::signed(serialize(false)));
        $this->assertNull($this->cache()->load());
    }

    public function testFailedClassScanIsNotTakenForAllClear(): void
    {
        file_put_contents($this->file, self::signed('a:1:{s:1:"x";O:29:"Sodaho\Router\Tests\NoSuchOne":0:{}}'));

        // With PCRE out of room the scan returns false. Treating that like "no unknown
        // class" would hand an incomplete object to the dispatcher.
        $limit = ini_set('pcre.backtrack_limit', '1');
        $jit = ini_set('pcre.jit', '0');

        try {
            $this->cache()->load();
            $this->fail('A cache whose classes could not be checked was loaded');
        } catch (CacheException $e) {
            $this->assertSame('Cache file is outdated', $e->getMessage());
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
            ini_set('pcre.jit', (string) $jit);
        }
    }

    public function testKnownClassesAndEnumsLoad(): void
    {
        $storage = new \SplObjectStorage();
        $storage[new \stdClass()] = new \ArrayObject(['a' => CachedState::Running]);

        $cache = $this->cache();
        $cache->save(['storage' => $storage, 'label' => 'O:8:"stdClass":0:{} is just text here']);

        $loaded = $cache->load();
        $this->assertIsArray($loaded);
        $this->assertCount(1, $loaded['storage']);
    }

    /**
     * The check reads the serialization format, not the restored data. Text that looks
     * exactly like an object of an unknown class is taken for one — on the safe side: the
     * cache is reported and rebuilt, nothing is dispatched with it.
     */
    public function testTextThatLooksLikeAnUnknownObjectCountsAsOne(): void
    {
        $cache = $this->cache();
        $cache->save(['label' => 'O:9:"NoSuchCls":0:{}']);

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Cache file is outdated');
        $cache->load();
    }

    public function testObjectGraphWithCyclesIsWalkedOnce(): void
    {
        $a = new \stdClass();
        $b = new \stdClass();
        $a->other = $b;
        $b->other = $a;

        $cache = $this->cache();
        $cache->save(['a' => $a]);
        $loaded = $cache->load();

        $this->assertIsArray($loaded);
        $this->assertSame($loaded['a'], $loaded['a']->other->other);
    }

    public function testSignedPayloadThatIsNotAnArrayIsAMiss(): void
    {
        file_put_contents($this->file, self::signed(serialize('not an array')));

        $this->assertNull($this->cache()->load());
    }

    public function testSignedPayloadWhoseClassRefusesToWakeUpIsReportedAsOutdated(): void
    {
        file_put_contents($this->file, self::signed(serialize(['x' => new RefusesToWakeUp()])));

        try {
            $this->cache()->load();
            $this->fail('A cache that could not be restored was loaded');
        } catch (CacheException $e) {
            $this->assertSame('Cache file is outdated', $e->getMessage());
            $this->assertStringStartsWith('Shape changed since the cache was written. ', (string) $e->getDebugMessage());
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }
}

enum CachedState
{
    case Running;
}

final class WakesUpLoudly
{
    public static string $marker = '';

    public function __wakeup(): void
    {
        file_put_contents(self::$marker, 'x');
    }
}

final class RefusesToWakeUp
{
    public function __wakeup(): void
    {
        throw new \RuntimeException('shape changed since the cache was written');
    }
}
