<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\Exception\RouteNotFoundException;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\Route;
use Sodaho\Router\UrlGenerator;

class UrlGeneratorTest extends TestCase
{
    public function testGenerateSimpleUrl(): void
    {
        $routes = [
            new Route(['GET'], '/users', 'handler', [], 'users.index'),
        ];
        $generator = new UrlGenerator($routes);

        $url = $generator->url('users.index');
        $this->assertSame('/users', $url);
    }

    public function testGenerateUrlWithParameter(): void
    {
        $routes = [
            new Route(['GET'], '/users/{id}', 'handler', [], 'users.show'),
        ];
        $generator = new UrlGenerator($routes);

        $url = $generator->url('users.show', ['id' => 42]);
        $this->assertSame('/users/42', $url);
    }

    public function testGenerateUrlWithMultipleParameters(): void
    {
        $routes = [
            new Route(['GET'], '/posts/{year}/{slug}', 'handler', [], 'posts.show'),
        ];
        $generator = new UrlGenerator($routes);

        $url = $generator->url('posts.show', ['year' => 2025, 'slug' => 'hello-world']);
        $this->assertSame('/posts/2025/hello-world', $url);
    }

    public function testGenerateUrlWithConstraint(): void
    {
        $routes = [
            new Route(['GET'], '/users/{id:int}', 'handler', [], 'users.show'),
        ];
        $generator = new UrlGenerator($routes);

        $url = $generator->url('users.show', ['id' => 42]);
        $this->assertSame('/users/42', $url);
    }

    public function testGenerateUrlWithBasePath(): void
    {
        $routes = [
            new Route(['GET'], '/users', 'handler', [], 'users.index'),
        ];
        $generator = new UrlGenerator($routes);
        $generator->setBasePath('/api/v1');

        $url = $generator->url('users.index');
        $this->assertSame('/api/v1/users', $url);
    }

    public function testGenerateAbsoluteUrl(): void
    {
        $routes = [
            new Route(['GET'], '/users/{id}', 'handler', [], 'users.show'),
        ];
        $generator = new UrlGenerator($routes);
        $generator->setBaseUrl('https://api.example.com');

        $url = $generator->absoluteUrl('users.show', ['id' => 42]);
        $this->assertSame('https://api.example.com/users/42', $url);
    }

    public function testGenerateAbsoluteUrlWithBasePath(): void
    {
        $routes = [
            new Route(['GET'], '/users', 'handler', [], 'users.index'),
        ];
        $generator = new UrlGenerator($routes);
        $generator->setBasePath('/api');
        $generator->setBaseUrl('https://example.com');

        $url = $generator->absoluteUrl('users.index');
        $this->assertSame('https://example.com/api/users', $url);
    }

    public function testAbsoluteUrlThrowsWithoutBaseUrl(): void
    {
        $routes = [
            new Route(['GET'], '/users', 'handler', [], 'users.index'),
        ];
        $generator = new UrlGenerator($routes);

        $this->expectException(RouterException::class);
        $this->expectExceptionMessage('baseUrl is not configured');
        $generator->absoluteUrl('users.index');
    }

    public function testThrowsOnUnknownRoute(): void
    {
        $generator = new UrlGenerator([]);

        $this->expectException(RouteNotFoundException::class);
        $generator->url('nonexistent.route');
    }

    public function testThrowsOnMissingParameter(): void
    {
        $routes = [
            new Route(['GET'], '/users/{id}', 'handler', [], 'users.show'),
        ];
        $generator = new UrlGenerator($routes);

        $this->expectException(RouterException::class);
        $this->expectExceptionMessage('Missing parameter "id"');
        $generator->url('users.show', []);
    }

    public function testHasRoute(): void
    {
        $routes = [
            new Route(['GET'], '/users', 'handler', [], 'users.index'),
        ];
        $generator = new UrlGenerator($routes);

        $this->assertTrue($generator->hasRoute('users.index'));
        $this->assertFalse($generator->hasRoute('nonexistent'));
    }

    public function testIgnoresRoutesWithoutName(): void
    {
        $routes = [
            new Route(['GET'], '/users', 'handler'), // No name
        ];
        $generator = new UrlGenerator($routes);

        $this->assertFalse($generator->hasRoute(''));
    }

    public function testThrowsOnOptionalSegments(): void
    {
        $routes = [
            new Route(['GET'], '/users[/{id}]', 'handler', [], 'users.optional'),
        ];
        $generator = new UrlGenerator($routes);

        // Optional segments are not supported - should throw instead of silent misbehavior
        $this->expectException(RouterException::class);
        $this->expectExceptionMessage('Optional segments [] are not supported');

        $generator->url('users.optional', ['id' => 5]);
    }

    public function testSetBaseUrlTrimsTrailingSlash(): void
    {
        $routes = [
            new Route(['GET'], '/test', 'handler', [], 'test'),
        ];
        $generator = new UrlGenerator($routes);
        $generator->setBaseUrl('https://example.com/');

        $url = $generator->absoluteUrl('test');
        $this->assertSame('https://example.com/test', $url);
    }

    public function testSetBasePathTrimsTrailingSlash(): void
    {
        $routes = [
            new Route(['GET'], '/test', 'handler', [], 'test'),
        ];
        $generator = new UrlGenerator($routes);
        $generator->setBasePath('/api/');

        $url = $generator->url('test');
        $this->assertSame('/api/test', $url);
    }

    public function testSetBaseUrlToNull(): void
    {
        $routes = [
            new Route(['GET'], '/test', 'handler', [], 'test'),
        ];
        $generator = new UrlGenerator($routes);
        $generator->setBaseUrl('https://example.com');
        $generator->setBaseUrl(null);

        $this->expectException(RouterException::class);
        $generator->absoluteUrl('test');
    }

    public function testConstructorWithPatternMapping(): void
    {
        // A plain map: name => pattern
        $patternMapping = [
            'users.index' => '/users',
            'users.show' => '/users/{id}',
            'posts.show' => '/posts/{year}/{slug}',
        ];

        $generator = new UrlGenerator($patternMapping);

        $this->assertTrue($generator->hasRoute('users.index'));
        $this->assertTrue($generator->hasRoute('users.show'));
        $this->assertTrue($generator->hasRoute('posts.show'));

        $this->assertSame('/users', $generator->url('users.index'));
        $this->assertSame('/users/42', $generator->url('users.show', ['id' => 42]));
        $this->assertSame('/posts/2025/hello', $generator->url('posts.show', ['year' => 2025, 'slug' => 'hello']));
    }

    // ==================== Parameter Type Tests ====================

    public function testGenerateUrlWithIntParameter(): void
    {
        $routes = [
            new Route(['GET'], '/users/{id:int}', 'handler', [], 'users.show'),
        ];
        $generator = new UrlGenerator($routes);

        $this->assertSame('/users/42', $generator->url('users.show', ['id' => 42]));
        $this->assertSame('/users/0', $generator->url('users.show', ['id' => 0]));
        $this->assertSame('/users/-5', $generator->url('users.show', ['id' => -5]));
    }

    public function testGenerateUrlWithFloatParameter(): void
    {
        $routes = [
            new Route(['GET'], '/products/{price:float}', 'handler', [], 'products.show'),
        ];
        $generator = new UrlGenerator($routes);

        $this->assertSame('/products/19.99', $generator->url('products.show', ['price' => 19.99]));
        $this->assertSame('/products/0', $generator->url('products.show', ['price' => 0.0]));
        $this->assertSame('/products/-3.14', $generator->url('products.show', ['price' => -3.14]));
    }

    public function testGenerateUrlWithBoolParameterTrue(): void
    {
        $routes = [
            new Route(['GET'], '/users/activate/{flag:bool}', 'handler', [], 'users.activate'),
        ];
        $generator = new UrlGenerator($routes);

        $url = $generator->url('users.activate', ['flag' => true]);
        $this->assertSame('/users/activate/1', $url);
    }

    public function testGenerateUrlWithBoolParameterFalse(): void
    {
        $routes = [
            new Route(['GET'], '/users/activate/{flag:bool}', 'handler', [], 'users.activate'),
        ];
        $generator = new UrlGenerator($routes);

        $url = $generator->url('users.activate', ['flag' => false]);
        $this->assertSame('/users/activate/0', $url);
    }

    public function testThrowsOnMissingBoolParameter(): void
    {
        $routes = [
            new Route(['GET'], '/users/activate/{flag:bool}', 'handler', [], 'users.activate'),
        ];
        $generator = new UrlGenerator($routes);

        $this->expectException(RouterException::class);
        $this->expectExceptionMessage('Missing parameter "flag"');
        $generator->url('users.activate', []);
    }

    // ==================== URL Encoding Tests ====================

    public function testUrlEncodingEnabledByDefault(): void
    {
        $routes = [
            new Route(['GET'], '/users/{name}', 'handler', [], 'users.show'),
        ];
        $generator = new UrlGenerator($routes);

        // Spaces should be encoded
        $url = $generator->url('users.show', ['name' => 'John Doe']);
        $this->assertSame('/users/John%20Doe', $url);
    }

    public function testUrlEncodingWithUtf8Umlauts(): void
    {
        $routes = [
            new Route(['GET'], '/users/{name}', 'handler', [], 'users.show'),
        ];
        $generator = new UrlGenerator($routes);

        // German umlauts äöü
        $url = $generator->url('users.show', ['name' => 'Müller']);
        $this->assertSame('/users/M%C3%BCller', $url);

        $url = $generator->url('users.show', ['name' => 'Ärger']);
        $this->assertSame('/users/%C3%84rger', $url);

        $url = $generator->url('users.show', ['name' => 'Öffentlich']);
        $this->assertSame('/users/%C3%96ffentlich', $url);
    }

    public function testUrlEncodingWithEmojis(): void
    {
        $routes = [
            new Route(['GET'], '/posts/{title}', 'handler', [], 'posts.show'),
        ];
        $generator = new UrlGenerator($routes);

        // Emoji test
        $url = $generator->url('posts.show', ['title' => 'Hello 🚀 World']);
        $this->assertSame('/posts/Hello%20%F0%9F%9A%80%20World', $url);

        $url = $generator->url('posts.show', ['title' => '❤️']);
        $this->assertSame('/posts/%E2%9D%A4%EF%B8%8F', $url);
    }

    public function testUrlEncodingWithSpecialCharacters(): void
    {
        $routes = [
            new Route(['GET'], '/search/{query}', 'handler', [], 'search'),
        ];
        $generator = new UrlGenerator($routes);

        // Plus sign
        $url = $generator->url('search', ['query' => 'a+b']);
        $this->assertSame('/search/a%2Bb', $url);

        // Asterisk
        $url = $generator->url('search', ['query' => 'file*.txt']);
        $this->assertSame('/search/file%2A.txt', $url);

        // Swiss French ç
        $url = $generator->url('search', ['query' => 'français']);
        $this->assertSame('/search/fran%C3%A7ais', $url);

        // Parentheses
        $url = $generator->url('search', ['query' => '(test)']);
        $this->assertSame('/search/%28test%29', $url);

        // Mixed special characters
        $url = $generator->url('search', ['query' => 'a & b = c']);
        $this->assertSame('/search/a%20%26%20b%20%3D%20c', $url);
    }

    public function testUrlEncodingWithSlashInParameter(): void
    {
        $routes = [
            new Route(['GET'], '/files/{path}', 'handler', [], 'files.show'),
        ];
        $generator = new UrlGenerator($routes);

        // A plain placeholder is one segment: a value with a slash has no address.
        // (1.x wrote %2F — the router refuses such a request since 2.0.)
        $this->expectException(RouterException::class);
        $this->expectExceptionMessage('Parameter "path" contains a slash, which its placeholder does not accept');

        $generator->url('files.show', ['path' => 'dir/file.txt']);
    }

    public function testSlashStaysWhereThePlaceholderTakesSeveralSegments(): void
    {
        $generator = new UrlGenerator([
            new Route(['GET'], '/files/{path:any}', 'handler', [], 'files.show'),
        ]);

        // Without the collector's patterns the built-in ones apply
        $this->assertSame('/files/my%20dir/file%231.txt', $generator->url('files.show', ['path' => 'my dir/file#1.txt']));
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string>, 2: string}>
     */
    public static function valuesThatWouldNameAHost(): array
    {
        return [
            'slash in front' => ['/{path:any}', ['path' => '/evil.example/x'], '//evil.example/x'],
            'two slashes in front' => ['/{path:any}', ['path' => '//evil.example/x'], '///evil.example/x'],
            'a slash alone' => ['/{path:any}', ['path' => '/'], '//'],
            'empty value in front of a literal' => ['/{path:any}/edit', ['path' => ''], '//edit'],
            'empty value in front of another placeholder' => ['/{a:any}/{b}', ['a' => '', 'b' => 'evil.example'], '//evil.example'],
        ];
    }

    /**
     * @param array<string, string> $params
     */
    #[DataProvider('valuesThatWouldNameAHost')]
    public function testAddressNeverBeginsWithTwoSlashes(string $pattern, array $params, string $address): void
    {
        // A client reads '//evil.example/x' as another host: a redirect to what url()
        // returned would leave the site. (1.x wrote '/%2Fevil.example%2Fx'.)
        $generator = new UrlGenerator([new Route(['GET'], $pattern, 'handler', [], 'page')]);

        try {
            $generator->url('page', $params);
            $this->fail('An address was generated');
        } catch (RouterException $e) {
            $this->assertSame('The address would begin with "//", which a client reads as another host', $e->getMessage());
            $this->assertSame($address, $e->getDebugMessage());
        }

        // Behind a base path the address stays on the site — and is refused all the same
        $generator->setBasePath('/app');
        try {
            $generator->url('page', $params);
            $this->fail('An address was generated behind the base path');
        } catch (RouterException $e) {
            $this->assertSame($address, $e->getDebugMessage());
        }

        // Without URL encoding the application writes the address and answers for it
        $generator->setEncodeParams(false);
        $this->assertSame('/app' . $address, $generator->url('page', $params));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function patternsAndBasePathsThatLeaveTheSite(): array
    {
        return [
            // A client reads a backslash as a slash: '/\evil.example/x' is '//evil.example/x'
            'backslash in the route pattern' => ['/\\evil.example/x', '', '/\\evil.example/x'],
            'backslash further inside the pattern' => ['/foo\\bar', '', '/foo\\bar'],
            'base path that begins with two slashes' => ['/x', '//evil.example', '//evil.example/x'],
            'base path with a backslash' => ['/x', '/\\evil.example', '/\\evil.example/x'],
            // A client drops tab and line breaks before it reads the address
            'tab between two slashes' => ['/evil.example/x', "/\t", "/\t/evil.example/x"],
            'line feed between two slashes' => ['/evil.example/x', "/\n", "/\n/evil.example/x"],
            'carriage return between two slashes' => ['/evil.example/x', "/\r", "/\r/evil.example/x"],
            'tab inside the route pattern' => ["/\t/evil.example/x", '', "/\t/evil.example/x"],
            'NUL in the address' => ['/x', "/a\0b", "/a\0b/x"],
            'DEL in the address' => ['/x', "/a\x7Fb", "/a\x7Fb/x"],
            // Not a path at all
            'base path that names a host' => ['/x', 'https://evil.example', 'https://evil.example/x'],
            'base path without a slash in front' => ['/x', 'evil.example', 'evil.example/x'],
        ];
    }

    #[DataProvider('patternsAndBasePathsThatLeaveTheSite')]
    public function testAddressIsAPathOnThisSiteWhateverItIsMadeOf(string $pattern, string $basePath, string $address): void
    {
        $generator = new UrlGenerator([new Route(['GET'], $pattern, 'handler', [], 'page')]);
        $generator->setBasePath($basePath);
        $generator->setBaseUrl('https://good.example');

        foreach (['url', 'absoluteUrl'] as $method) {
            try {
                $generator->{$method}('page');
                $this->fail('An address was generated');
            } catch (RouterException $e) {
                $this->assertSame('The address would not be a path on this site: it has to begin with a single "/" and contain no backslash or control character', $e->getMessage());
                $this->assertSame($address, $e->getDebugMessage());
            }
        }

        // Without URL encoding the application writes the address and answers for it
        $generator->setEncodeParams(false);
        $this->assertSame($address, $generator->url('page'));
    }

    public function testOrdinaryAddressesKeepTheirForm(): void
    {
        // Blanks and non-ASCII letters of a route pattern go out as they are, as before
        $generator = new UrlGenerator([new Route(['GET'], '/über uns/{name}', 'handler', [], 'about')]);
        $generator->setBasePath('/my app');

        $this->assertSame('/my app/über uns/a%20b', $generator->url('about', ['name' => 'a b']));
    }

    public function testEmptySegmentsInsideTheAddressStay(): void
    {
        $generator = new UrlGenerator([new Route(['GET'], '/files/{path:any}', 'handler', [], 'files')]);

        $this->assertSame('/files/a//b', $generator->url('files', ['path' => 'a//b']));
        $this->assertSame('/files//a', $generator->url('files', ['path' => '/a']));
    }

    public function testPatternThatDoesNotStandOnItsOwnIsARefusalNotAWarning(): void
    {
        // A fragment that refers to another placeholder does not compile when asked alone;
        // one that looks at the text behind it does not match. Both: RouterException.
        $generator = new UrlGenerator(
            [
                new Route(['GET'], '/{a:any}/{b:same}', 'handler', [], 'same'),
                new Route(['GET'], '/x/{p:ctx}/tail', 'handler', [], 'ctx'),
            ],
            ['any' => '.*', 'same' => '(?P=a)/x', 'ctx' => 'a/b(?=/tail)'],
        );

        // An application's handler that turns every warning into an exception, and does
        // not look at error_reporting(): it must not get to see the failed attempt
        $seen = [];
        set_error_handler(static function (int $level, string $message) use (&$seen): bool {
            $seen[] = $message;

            throw new \ErrorException($message, 0, $level);
        });

        try {
            foreach ([['same', ['a' => 'foo', 'b' => 'foo/x'], 'b'], ['ctx', ['p' => 'a/b'], 'p']] as [$name, $params, $parameter]) {
                try {
                    $generator->url($name, $params);
                    $this->fail('An address was generated');
                } catch (RouterException $e) {
                    $this->assertSame(sprintf('Parameter "%s" contains a slash, which its placeholder does not accept', $parameter), $e->getMessage());
                }
            }
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $seen);

        // And the application's handler is back in place afterwards
        $mine = static fn (): bool => true;
        set_error_handler($mine);
        try {
            try {
                $generator->url('ctx', ['p' => 'a/b']);
            } catch (RouterException) {
            }
            $this->assertSame($mine, set_error_handler(null));
            restore_error_handler();
        } finally {
            restore_error_handler();
        }
    }

    public function testUrlEncodingDisabled(): void
    {
        $routes = [
            new Route(['GET'], '/users/{name}', 'handler', [], 'users.show'),
        ];
        $generator = new UrlGenerator($routes);
        $generator->setEncodeParams(false);

        // Spaces should NOT be encoded
        $url = $generator->url('users.show', ['name' => 'John Doe']);
        $this->assertSame('/users/John Doe', $url);

        // Umlauts should NOT be encoded
        $url = $generator->url('users.show', ['name' => 'Müller']);
        $this->assertSame('/users/Müller', $url);
    }

    public function testUrlEncodingCanBeToggled(): void
    {
        $routes = [
            new Route(['GET'], '/users/{name}', 'handler', [], 'users.show'),
        ];
        $generator = new UrlGenerator($routes);

        // Default: encoded
        $url = $generator->url('users.show', ['name' => 'Test User']);
        $this->assertSame('/users/Test%20User', $url);

        // Disable encoding
        $generator->setEncodeParams(false);
        $url = $generator->url('users.show', ['name' => 'Test User']);
        $this->assertSame('/users/Test User', $url);

        // Re-enable encoding
        $generator->setEncodeParams(true);
        $url = $generator->url('users.show', ['name' => 'Test User']);
        $this->assertSame('/users/Test%20User', $url);
    }

    public function testUrlEncodingPreservesAlphanumericAndSafeChars(): void
    {
        $routes = [
            new Route(['GET'], '/posts/{slug}', 'handler', [], 'posts.show'),
        ];
        $generator = new UrlGenerator($routes);

        // These should NOT be encoded (alphanumeric, hyphen, underscore, dot, tilde)
        $url = $generator->url('posts.show', ['slug' => 'hello-world_2025.test~draft']);
        $this->assertSame('/posts/hello-world_2025.test~draft', $url);
    }

    public function testFalsyParametersAreValues(): void
    {
        $generator = new UrlGenerator(['p' => '/p/{a}/{b}/{c}/{d}']);

        $this->assertSame('/p/0/0/0/', $generator->url('p', ['a' => 0, 'b' => '0', 'c' => false, 'd' => '']));
    }
}
