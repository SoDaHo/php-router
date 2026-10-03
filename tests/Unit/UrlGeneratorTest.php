<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\Exception\RouteNotFoundException;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\Route;
use Sodaho\Router\RouteCollector;
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

        // Optional segments are not supported - should throw instead of silent misbehavior.
        // (A RouteCollector refuses such a pattern where it is written; a generator can
        // be given routes that never passed one.)
        try {
            $generator->url('users.optional', ['id' => 5]);
            $this->fail('An address was generated');
        } catch (RouterException $e) {
            $this->assertSame('Optional segments [] are not supported in a route pattern. Define separate routes instead.', $e->getMessage());
            $this->assertSame('/users[/{id}]', $e->getDebugMessage());
        }
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
        $this->expectExceptionMessage('The parameters do not fit the pattern of route "files.show"');

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
                $this->assertSame('The address would not be a path on this site: it has to begin with a single "/" and contain no backslash', $e->getMessage());
                $this->assertSame($address, $e->getDebugMessage());
            }
        }

        // Without URL encoding the application writes the address and answers for it
        $generator->setEncodeParams(false);
        $this->assertSame($address, $generator->url('page'));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, string>, 3: string}>
     */
    public static function literalTextThatIsEncoded(): array
    {
        return [
            // 1.x wrote '/my app/über uns/a%20b': the value encoded, the text around it not
            'blanks and non-ASCII letters' => ['/über uns/{name}', '/my app', ['name' => 'a b'], '/my%20app/%C3%BCber%20uns/a%20b'],
            // A percent sign that is no encoding: as it stood, the address was no URI
            'percent sign' => ['/100%/{name}', '/50%', ['name' => 'x'], '/50%25/100%25/x'],
            // A client drops tab and line breaks before it reads an address: '/<tab>/host'
            // would be '//host'. Encoded, there is nothing to drop.
            'tab between two slashes' => ['/evil.example/x', "/\t", [], '/%09/evil.example/x'],
            'line feed between two slashes' => ['/evil.example/x', "/\n", [], '/%0A/evil.example/x'],
            'carriage return between two slashes' => ['/evil.example/x', "/\r", [], '/%0D/evil.example/x'],
            'tab inside the route pattern' => ["/\t/evil.example/x", '', [], '/%09/evil.example/x'],
            'NUL' => ['/x', "/a\0b", [], '/a%00b/x'],
            'DEL' => ['/x', "/a\x7Fb", [], '/a%7Fb/x'],
            // What would end the path
            'question mark and number sign' => ['/a?b#c/{x}', '', ['x' => '1'], '/a%3Fb%23c/1'],
            'quote, angle brackets, pipe' => ['/a"b<c>d|e', '', [], '/a%22b%3Cc%3Ed%7Ce'],
            // What a path may contain as it is stays as it is
            'colon, at sign, sub-delimiters' => ['/v1:batch/@{user}/a,b;c=d+e(f)!*\'$&', '', ['user' => 'x'], '/v1:batch/@x/a,b;c=d+e(f)!*\'$&'],
            'unreserved characters' => ['/A-z_0.9~/{x}', '/b-A_s.e~', ['x' => '1'], '/b-A_s.e~/A-z_0.9~/1'],
        ];
    }

    /**
     * @param array<string, string> $params
     */
    #[DataProvider('literalTextThatIsEncoded')]
    public function testLiteralTextOfPatternAndBasePathIsEncoded(string $pattern, string $basePath, array $params, string $address): void
    {
        $generator = new UrlGenerator([new Route(['GET'], $pattern, 'handler', [], 'page')]);
        $generator->setBasePath($basePath);

        $this->assertSame($address, $generator->url('page', $params));

        // Decoded it is what the router compares — and what goes out without URL encoding,
        // where the application writes the address and answers for it
        $generator->setEncodeParams(false);
        $this->assertSame(rawurldecode($address), $generator->url('page', $params));
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function valuesTheRouterDoesNotHandOn(): array
    {
        return [
            // The pattern takes them, the cast does not: the request would end in 400
            'integer with a leading zero' => ['/n/{id:int}', '01'],
            'minus zero' => ['/n/{id:int}', '-0'],
            'integer too large' => ['/n/{id:int}', '99999999999999999999'],
            'decimal too large' => ['/f/{x:float}', '1' . str_repeat('0', 400)],
        ];
    }

    #[DataProvider('valuesTheRouterDoesNotHandOn')]
    public function testValueTheCastRefusesGivesNoAddress(string $pattern, mixed $value): void
    {
        $generator = new UrlGenerator([new Route(['GET'], $pattern, 'handler', [], 'r')]);
        $name = array_column(RouteCollector::parts($pattern), 'name')[1];

        try {
            /** @phpstan-ignore argument.type */
            $generator->url('r', [$name => $value]);
            $this->fail('An address was generated');
        } catch (RouterException $e) {
            $this->assertSame('The parameters do not fit the pattern of route "r": the address would not lead back to it', $e->getMessage());
        }

        // What the cast takes goes through, in every type a value can come in
        $fine = new UrlGenerator([new Route(['GET'], '/n/{id:int}/{x:float}/{flag:bool}', 'handler', [], 'r')]);
        $this->assertSame('/n/-5/2.5/1', $fine->url('r', ['id' => -5, 'x' => 2.5, 'flag' => true]));
        $this->assertSame('/n/0/10/FALSE', $fine->url('r', ['id' => '0', 'x' => '10', 'flag' => 'FALSE']));
    }

    public function testEmptySegmentsInsideTheAddressStay(): void
    {
        $generator = new UrlGenerator([new Route(['GET'], '/files/{path:any}', 'handler', [], 'files')]);

        $this->assertSame('/files/a//b', $generator->url('files', ['path' => 'a//b']));
        $this->assertSame('/files//a', $generator->url('files', ['path' => '/a']));
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
        $generator = new UrlGenerator(['p' => '/p/{a}/{b}/{c}']);

        $this->assertSame('/p/0/0/0', $generator->url('p', ['a' => 0, 'b' => '0', 'c' => false]));
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string>|null, 2: array<string, mixed>, 3: string}>
     */
    public static function valuesThatFitTheirRoute(): array
    {
        return [
            'integer' => ['/docs/{id:int}', null, ['id' => 12], '/docs/12'],
            'negative integer as text' => ['/docs/{id:int}', null, ['id' => '-5'], '/docs/-5'],
            'float' => ['/price/{v:float}', null, ['v' => 1.5], '/price/1.5'],
            'true' => ['/flag/{on:bool}', null, ['on' => true], '/flag/1'],
            'false' => ['/flag/{on:bool}', null, ['on' => false], '/flag/0'],
            'slug' => ['/posts/{slug:slug}', null, ['slug' => 'my-post-2'], '/posts/my-post-2'],
            'text with blanks and marks' => ['/tags/{tag}', null, ['tag' => 'a b?#%'], '/tags/a%20b%3F%23%25'],
            'two placeholders in a segment' => ['/ab/{a}-{b}', null, ['a' => 'x', 'b' => 'y'], '/ab/x-y'],
            'separator of two placeholders inside the first value' => ['/ab/{a}-{b}', null, ['a' => 'x-y', 'b' => 'z'], '/ab/x-y-z'],
            'slashes where the placeholder takes them' => ['/files/{path:any}', null, ['path' => 'my dir/a b.txt'], '/files/my%20dir/a%20b.txt'],
            'two placeholders that take slashes, split as the route splits them' => ['/{a:any}/{b:any}', null, ['a' => 'x/y', 'b' => 'z'], '/x/y/z'],
            // Own patterns that look at the text around them or at another placeholder:
            // the whole route is asked, so they work
            'own pattern with a look at what follows' => ['/x/{p:ctx}/tail', ['ctx' => 'a/b(?=/tail)'], ['p' => 'a/b'], '/x/a/b/tail'],
            'own pattern that refers to another placeholder' => ['/{a:any}/{b:same}', ['any' => '.*', 'same' => '(?P=a)/x'], ['a' => 'foo', 'b' => 'foo/x'], '/foo/foo/x'],
        ];
    }

    /**
     * @param array<string, string>|null $patterns
     * @param array<string, mixed> $params
     */
    #[DataProvider('valuesThatFitTheirRoute')]
    public function testAddressOfValuesThatFit(string $pattern, ?array $patterns, array $params, string $address): void
    {
        $generator = new UrlGenerator([new Route(['GET'], $pattern, 'handler', [], 'r')], $patterns);

        /** @phpstan-ignore argument.type */
        $this->assertSame($address, $generator->url('r', $params));
    }

    public function testUnknownPatternTypeGivesNoAddress(): void
    {
        // 1.x took {x:nope} for "one segment" — the route table refuses it now, and a
        // generator built without the collector's patterns says what it is missing
        $generator = new UrlGenerator([new Route(['GET'], '/odd/{x:nope}', 'handler', [], 'odd')]);

        try {
            $generator->url('odd', ['x' => 'a.b']);
            $this->fail('An address was generated');
        } catch (RouterException $e) {
            $this->assertSame('The URL generator does not know the pattern type "nope": pass the patterns of the route collector (getPatterns()) to its constructor', $e->getMessage());
            $this->assertSame('/odd/{x:nope}', $e->getDebugMessage());
        }

        // With them it knows the type — Router::url() passes them
        $collector = new RouteCollector();
        $collector->addPattern('date', '\d{4}-\d{2}-\d{2}');
        $collector->get('/report/{day:date}', 'handler')->name('report');

        $this->assertSame('/report/2024-01-31', (new UrlGenerator($collector->getRoutes(), $collector->getPatterns()))->url('report', ['day' => '2024-01-31']));

        // Without URL encoding nothing is checked, as before
        $plain = new UrlGenerator($collector->getRoutes());
        $plain->setEncodeParams(false);
        $this->assertSame('/report/2024-01-31', $plain->url('report', ['day' => '2024-01-31']));
    }

    public function testInTheModeIgnoreTheRootPathKeepsItsSlash(): void
    {
        $generator = new UrlGenerator([new Route(['GET'], '/{path:any}', 'handler', [], 'page')]);
        $generator->setIgnoreTrailingSlash(true);

        // '/' is the one path whose slash the router leaves alone: the empty value comes back
        $this->assertSame('/', $generator->url('page', ['path' => '']));
        $this->assertSame('/a', $generator->url('page', ['path' => 'a']));

        // … any other slash at the end is dropped before the route is looked up
        $this->expectException(RouterException::class);
        $this->expectExceptionMessage('The parameters do not fit the pattern of route "page"');
        $generator->url('page', ['path' => 'a/']);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
     */
    public static function valuesThatDoNotFitTheirRoute(): array
    {
        return [
            // 1.x returned these addresses; they ended in 404
            'letters for an integer' => ['/docs/{id:int}', ['id' => '12a'], '/docs/12a'],
            'an integer with a blank' => ['/docs/{id:int}', ['id' => '12 '], '/docs/12 '],
            'capitals for a slug' => ['/posts/{slug:slug}', ['slug' => 'My-Post'], '/posts/My-Post'],
            'an empty value' => ['/tags/{tag}', ['tag' => ''], '/tags/'],
            'an empty value between others' => ['/p/{a}/{b}/{c}', ['a' => 'x', 'b' => '', 'c' => 'z'], '/p/x//z'],
            'a slash for one segment' => ['/tags/{tag}', ['tag' => 'a/b'], '/tags/a/b'],
            'a line break at the end' => ['/docs/{id:int}', ['id' => "5\n"], "/docs/5\n"],
            'true for an integer is fine, a word is not' => ['/flag/{on:bool}', ['on' => 'yes'], '/flag/yes'],
            // The route would hand over other values than these
            'two placeholders that take slashes, split otherwise' => ['/{a:any}/{b:any}', ['a' => 'x', 'b' => 'y/z'], '/x/y/z'],
            'separator of two placeholders inside the second value' => ['/ab/{a}-{b}', ['a' => 'x', 'b' => 'y-z'], '/ab/x-y-z'],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('valuesThatDoNotFitTheirRoute')]
    public function testValuesThatDoNotLeadBackToTheRouteAreRefused(string $pattern, array $params, string $candidate): void
    {
        $generator = new UrlGenerator([new Route(['GET'], $pattern, 'handler', [], 'r')]);

        try {
            /** @phpstan-ignore argument.type */
            $generator->url('r', $params);
            $this->fail('An address was generated');
        } catch (RouterException $e) {
            // The message names the route, the values only show in the debug message
            $this->assertSame('The parameters do not fit the pattern of route "r": the address would not lead back to it', $e->getMessage());
            $this->assertSame($candidate, $e->getDebugMessage());
        }

        // Without URL encoding the application writes the address and answers for it
        $generator->setEncodeParams(false);
        /** @phpstan-ignore argument.type */
        $this->assertSame($candidate, $generator->url('r', $params));
    }

    public function testNullIsNoValue(): void
    {
        $generator = new UrlGenerator(['p' => '/p/{a}/{b}']);

        foreach ([true, false] as $encode) {
            $generator->setEncodeParams($encode);

            try {
                /** @phpstan-ignore argument.type */
                $generator->url('p', ['a' => 'x', 'b' => null]);
                $this->fail('An address was generated');
            } catch (RouterException $e) {
                $this->assertSame('Parameter "b" for URL generation is null', $e->getMessage());
            }
        }
    }
}
