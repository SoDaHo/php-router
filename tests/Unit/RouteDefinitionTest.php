<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\RouteCollector;

/**
 * What is wrong with a route as it is written is said where it is written — or, where
 * patterns may still be added, when the table is built — and not found out by a request.
 */
class RouteDefinitionTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string|null}>
     */
    public static function patternsThatAreRefused(): array
    {
        $shape = 'Route pattern has a placeholder that is not of the form {name} or {name:type}. A regular expression of your own goes into addPattern().';
        $optional = 'Optional segments [] are not supported in a route pattern. Define separate routes instead.';
        $name = 'Placeholder name must begin with an ASCII letter or underscore, have at most 32 characters and not be _route_params';
        $never = 'Route pattern must be a plain path, written decoded: no backslash, control character, percent-encoded character (%20), "?", "#" or dot segment';

        return [
            // 1.x read these as literal text: the route never matched what was meant
            'optional segment' => ['/users[/{id}]', $optional, '/users[/{id}]'],
            'closing bracket alone' => ['/a]', $optional, '/a]'],
            // What is most often meant differently is named for what it is — not for the
            // backslash or the bracket it happens to contain
            'regular expression in the placeholder' => ['/users/{id:\d+}', $shape, '/users/{id:\d+}'],
            'regular expression without a backslash' => ['/users/{id:[0-9]+}', $shape, '/users/{id:[0-9]+}'],
            'regular expression with a quantifier in braces' => ['/users/{id:\d{2}}', $shape, '/users/{id:\d{2}}'],
            'two types' => ['/users/{id:int:x}', $shape, '/users/{id:int:x}'],
            'empty placeholder' => ['/users/{}', $shape, '/users/{}'],
            'placeholder with a blank' => ['/users/{my id}', $shape, '/users/{my id}'],
            'brace that is not closed' => ['/users/{id', $shape, '/users/{id'],
            'brace that is not opened' => ['/users/id}', $shape, '/users/id}'],
            'group as type' => ['/x/{bad:(a+)+}', $shape, '/x/{bad:(a+)+}'],
            // The regular expression could not carry these names
            'name that begins with a digit' => ['/users/{1}', $name, '{1} in /users/{1}'],
            'name with 33 characters' => ['/users/{' . str_repeat('a', 33) . '}', $name, '{' . str_repeat('a', 33) . '} in /users/{' . str_repeat('a', 33) . '}'],
            // The request attribute the handler's arguments travel in
            'reserved name' => ['/users/{_route_params}', $name, '{_route_params} in /users/{_route_params}'],
            'the same name twice' => ['/a/{id}/b/{id}', 'Placeholder "id" is used twice in one route pattern', '/a/{id}/b/{id}'],
            'the same name twice, typed' => ['/a/{id:int}/{id:slug}', 'Placeholder "id" is used twice in one route pattern', '/a/{id:int}/{id:slug}'],
            // A pattern is compared with the decoded request path, and it is a path. (1.x
            // took all of these as literal text; some could be reached — '/a%2Fb' by the
            // request '/a%252Fb', a tab by '%09' — none by the address url() wrote for them.)
            'backslash' => ['/a\\b', $never, '/a\\b'],
            'percent-encoded blank' => ['/a%20b', $never, '/a%20b'],
            'percent-encoded letter' => ['/caf%C3%A9', $never, '/caf%C3%A9'],
            'query' => ['/search?q={q}', $never, '/search?q={q}'],
            'fragment' => ['/doc#top', $never, '/doc#top'],
            'parent segment' => ['/a/../b', $never, '/a/../b'],
            'current segment in front of a placeholder' => ['/files/./{name}', $never, '/files/./{name}'],
            'parent segment at the end' => ['/x/..', $never, '/x/..'],
            'line break at the end' => ["/a\n", $never, "/a\n"],
            'tab in front' => ["\t/a", $never, "/\t/a"],
            'encoded slash' => ['/a%2Fb', $never, '/a%2Fb'],
            'encoded slash, lower case' => ['/a%2fb', $never, '/a%2fb'],
            'encoded backslash' => ['/a%5Cb', $never, '/a%5Cb'],
            'tab' => ["/a\tb", $never, "/a\tb"],
            'line break' => ["/a\nb", $never, "/a\nb"],
            'DEL' => ["/a\x7Fb", $never, "/a\x7Fb"],
        ];
    }

    #[DataProvider('patternsThatAreRefused')]
    public function testPatternIsRefusedWhereItIsWritten(string $pattern, string $message, ?string $debug): void
    {
        $collector = new RouteCollector();

        foreach (['get', 'post', 'any'] as $method) {
            try {
                $collector->{$method}($pattern, 'handler');
                $this->fail('The route was registered');
            } catch (RouterException $e) {
                $this->assertSame($message, $e->getMessage());
                $this->assertSame($debug, $e->getDebugMessage());
            }
        }

        $this->assertSame([], $collector->getRoutes());
    }

    public function testPlaceholderNameIsReadTheSameUnderEveryLocale(): void
    {
        // Under a locale, \w takes bytes beyond ASCII for word characters — the table would
        // then refuse the name with a warning at every request
        $before = (string) setlocale(LC_CTYPE, '0');

        if (setlocale(LC_CTYPE, 'de_DE.ISO8859-1', 'en_US.ISO8859-1', 'de_DE.ISO8859-15') === false) {
            $this->markTestSkipped('No Latin-1 locale on this machine');
        }

        try {
            // Under this locale \w takes the byte: the library does not read names with \w
            $this->assertSame(1, preg_match('/^\w+$/', "n\xE4me"), 'the locale is not in effect');
            $latin1 = $this->readsOfANameWithAnUmlaut();
        } finally {
            setlocale(LC_CTYPE, $before);
        }

        // … and under the C locale it is read alike
        $this->assertSame($latin1, $this->readsOfANameWithAnUmlaut());
        $this->assertSame(
            [
                'route' => 'Route pattern has a placeholder that is not of the form {name} or {name:type}. A regular expression of your own goes into addPattern().',
                'pattern' => 'Pattern name must consist of ASCII letters, digits and underscores',
                'parts' => [null, null],
                'url' => "/x/{n\xE4me}",
            ],
            $latin1
        );
    }

    /**
     * @return array{route: string, pattern: string, parts: list<string|null>, url: string}
     */
    private function readsOfANameWithAnUmlaut(): array
    {
        $reads = ['route' => '', 'pattern' => ''];

        try {
            new RouteCollector()->get("/x/{n\xE4me}", 'handler');
        } catch (RouterException $e) {
            $reads['route'] = $e->getMessage();
        }

        try {
            new RouteCollector()->addPattern("d\xE4te", '\d+');
        } catch (RouterException $e) {
            $reads['pattern'] = $e->getMessage();
        }

        // Literal text for the URL generator as well, not a placeholder (decoded: the
        // address itself has it encoded)
        $reads['parts'] = array_column(RouteCollector::parts("/x/{n\xE4me}"), 'name');
        $reads['url'] = rawurldecode(new \Sodaho\Router\UrlGenerator([new \Sodaho\Router\Route(['GET'], "/x/{n\xE4me}", 'handler', [], 'n')])->url('n'));

        return $reads;
    }

    public function testControlCharacterAtTheEdgeDoesNotVanishWithTheBlanks(): void
    {
        // The collector of a router in the trailing slash mode 'strict' trims blanks at
        // the edges of a pattern — and with them it trimmed line breaks and tabs
        $collector = new RouteCollector();
        $collector->setPreserveTrailingSlash(true);

        foreach (["/a\n", "\t/a", " /a \n ", "/a\0", "\r\n/a"] as $pattern) {
            try {
                $collector->get($pattern, 'handler');
                $this->fail('The route was registered');
            } catch (RouterException $e) {
                $this->assertSame('Route pattern must be a plain path, written decoded: no backslash, control character, percent-encoded character (%20), "?", "#" or dot segment', $e->getMessage());
                $this->assertSame($pattern, $e->getDebugMessage());
            }
        }

        // Blanks are trimmed as before
        $this->assertSame('/a', $collector->get('  /a ', 'handler')->pattern);
        $this->assertSame('/b/', $collector->get('/b/ ', 'handler')->pattern);
    }

    public function testPrefixOfAGroupIsCheckedWithTheRoute(): void
    {
        $collector = new RouteCollector();

        $this->expectException(RouterException::class);
        $this->expectExceptionMessage('Placeholder "id" is used twice in one route pattern');

        $collector->group('/users/{id}', function (RouteCollector $r): void {
            $r->get('/posts/{id}', 'handler');
        });
    }

    public function testPatternsThatStayAllowed(): void
    {
        $collector = new RouteCollector();

        foreach (['/', '/users/{id}', '/a/{_x}/{Y9:int}', '/v1.0/{name}.json', '/ab/{a}-{b}', '/über/{x}', '/a b', '/p/100%', '/5%2', '/.well-known/x', '/a..b/..c/{x}..', '/v1:batch/@{user}', '/{' . str_repeat('a', 32) . '}'] as $pattern) {
            $this->assertSame($pattern, $collector->get($pattern, 'handler')->pattern);
        }

        // … and the table is built with them: the longest name is one the regular expression takes
        $regex = array_column($collector->getData()[1]['GET'], 'regex');
        $this->assertSame(1, preg_match(end($regex), '/value'));
    }

    // ==================== pattern types ====================

    public function testUnknownPatternTypeIsRefusedWhenTheTableIsBuilt(): void
    {
        $collector = new RouteCollector();
        // 1.x took this for "one segment": /users/abc matched
        $collector->get('/users/{id:integer}', 'handler');

        try {
            $collector->getData();
            $this->fail('The table was built');
        } catch (RouterException $e) {
            $this->assertSame(
                'Route pattern uses a pattern type that is not defined. Built in: int, float, bool, alpha, alphanum, slug, uuid, ulid, any',
                $e->getMessage()
            );
            $this->assertSame('{id:integer} in /users/{id:integer}', $e->getDebugMessage());
        }
    }

    public function testPatternMayBeAddedAfterTheRouteThatUsesIt(): void
    {
        $collector = new RouteCollector();
        $collector->get('/events/{day:date}', 'handler');
        $collector->addPattern('date', '\d{4}-\d{2}-\d{2}');

        $regex = $collector->getData()[1]['GET'][0]['regex'];

        $this->assertSame(1, preg_match($regex, '/events/2026-10-02'));
        $this->assertSame(0, preg_match($regex, '/events/tomorrow'));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function patternShortcutsThatAreRefused(): array
    {
        $delimiter = "Pattern fragment must not contain an unescaped '#' (write \\#)";
        $name = 'Pattern name must consist of ASCII letters, digits and underscores';

        return [
            // 1.x: a warning for every request that passed the route, and no match, ever
            'delimiter in a character class' => ['hex', '[0-9A-Fa-f#]+', $delimiter],
            'delimiter alone' => ['tag', '#[a-z]+', $delimiter],
            'delimiter behind an escaped backslash' => ['tag', '\\\\#', $delimiter],
            'delimiter at the end' => ['tag', '[a-z]+#', $delimiter],
            'name with a hyphen' => ['my-date', '\d+', $name],
            'empty name' => ['', '\d+', $name],
            'name with a line break' => ["date\n", '\d+', $name],
        ];
    }

    #[DataProvider('patternShortcutsThatAreRefused')]
    public function testPatternShortcutIsRefused(string $name, string $regex, string $message): void
    {
        $collector = new RouteCollector();

        try {
            $collector->addPattern($name, $regex);
            $this->fail('The pattern was added');
        } catch (RouterException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertSame($name, $e->getDebugMessage());
        }

        $this->assertArrayNotHasKey($name, $collector->getPatterns());
    }

    public function testEscapedDelimiterIsAllowed(): void
    {
        $collector = new RouteCollector();
        $collector->addPatterns(['tag' => '\#[a-z]+', 'both' => '\\\\\#x', 'class' => '[\#a-z]+']);

        $this->assertSame('\#[a-z]+', $collector->getPatterns()['tag']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function fragmentsThatDoNotCompile(): array
    {
        return [
            'group that is not closed' => ['broken', '(\d+'],
            'one parenthesis too many on either side' => ['broken', 'a))|((.*'],
            'quantifier without anything to repeat' => ['broken', '+\d'],
            'reference to a placeholder the route does not have' => ['broken', '(?P=other)'],
        ];
    }

    #[DataProvider('fragmentsThatDoNotCompile')]
    public function testOwnPatternThatDoesNotCompileIsRefusedWhenTheTableIsBuilt(string $name, string $regex): void
    {
        $collector = new RouteCollector();
        $collector->addPattern($name, $regex);
        $collector->get('/ok/{id:int}', 'handler');
        $collector->get('/x/{v:' . $name . '}', 'handler');

        try {
            $collector->getData();
            $this->fail('The table was built');
        } catch (RouterException $e) {
            // 1.x: a warning for every request that passed the route, and no match, ever
            $this->assertSame('Route pattern does not compile with its own pattern types', $e->getMessage());
            $this->assertStringStartsWith('/x/{v:' . $name . '}: ', (string) $e->getDebugMessage());
        }
    }

    public function testOwnPatternMayReferToAnotherPlaceholderOfItsRoute(): void
    {
        $collector = new RouteCollector();
        $collector->addPattern('same', '(?P=a)');
        $collector->get('/{a}/{b:same}', 'handler');

        $regex = $collector->getData()[1]['GET'][0]['regex'];

        $this->assertSame(1, preg_match($regex, '/x/x'));
        $this->assertSame(0, preg_match($regex, '/x/y'));
    }

    public function testBuiltInPatternThatWasReplacedIsTriedOutLikeAnOwnOne(): void
    {
        $collector = new RouteCollector();
        $collector->addPattern('int', '(\d+');
        $collector->get('/x/{id:int}', 'handler');

        $this->expectException(RouterException::class);
        $this->expectExceptionMessage('Route pattern does not compile with its own pattern types');

        $collector->getData();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function fragmentsThatBreakOutOfTheirGroup(): array
    {
        return [
            'closes the group and starts an alternative' => ['a)|(.*'],
            'closes it and starts a group that does not capture' => ['a)|(?:.*'],
            'closes it and opens another' => ['\d+)(\w*'],
        ];
    }

    /**
     * Wrapped in its group, such a fragment compiles — and takes the rest of the route's
     * expression into an alternative of its own: '/x/{v}' with 'a)|(.*' matched every path
     * of every dynamic route registered after it
     */
    #[DataProvider('fragmentsThatBreakOutOfTheirGroup')]
    public function testOwnPatternThatBreaksOutOfItsGroupIsRefusedWhenTheTableIsBuilt(string $regex): void
    {
        $collector = new RouteCollector();
        $collector->addPattern('loose', $regex);
        $collector->get('/x/{v:loose}', 'handler');
        $collector->get('/other/{id:int}', 'handler');

        try {
            $collector->getData();
            $this->fail('The table was built');
        } catch (RouterException $e) {
            $this->assertSame('Route pattern uses a pattern type that is no regular expression of its own (its parentheses do not pair up)', $e->getMessage());
            $this->assertStringStartsWith('/x/{v:loose}: ', (string) $e->getDebugMessage());
        }
    }

    public function testOwnPatternsThatStayWithinTheirGroupAreAllowed(): void
    {
        $collector = new RouteCollector();
        $collector->addPatterns([
            'either' => 'a|b',
            'same' => '(?P=a)x',
            // A pattern that calls its own group: the placeholder's name is known to it as well
            'nested' => '\((?:[^()]|(?P>n))*\)',
        ]);
        $collector->get('/e/{v:either}', 'handler');
        $collector->get('/s/{a}/{b:same}', 'handler');
        $collector->get('/n/{n:nested}', 'handler');

        $dynamic = $collector->getData()[1]['GET'];

        $this->assertSame(1, preg_match($dynamic[0]['regex'], '/e/b'));
        $this->assertSame(0, preg_match($dynamic[0]['regex'], '/e/c'));
        $this->assertSame(1, preg_match($dynamic[1]['regex'], '/s/q/qx'));
        $this->assertSame(1, preg_match($dynamic[2]['regex'], '/n/(a(b)c)'));
        $this->assertSame(0, preg_match($dynamic[2]['regex'], '/n/(a(b c)'));
    }

    // ==================== redirects ====================

    public function testRedirectTargetMayOnlyUseThePlaceholdersOfItsSource(): void
    {
        $collector = new RouteCollector();

        try {
            // 1.x sent Location: /new/{slug} — braces and all
            $collector->redirect('/old/{id}', '/new/{slug}/{id}/{page}');
            $this->fail('The redirect was registered');
        } catch (RouterException $e) {
            $this->assertSame('Redirect target has a placeholder that its source pattern does not have', $e->getMessage());
            $this->assertSame('{slug}, {page} in /new/{slug}/{id}/{page}', $e->getDebugMessage());
        }

        // The target takes {name} and nothing else in braces: 1.x sent Location: /new/{id:int}
        foreach (['/new/{id:int}', '/new/{}', '/new/{id', '/new/id}', '/new/{id}/{a b}'] as $target) {
            try {
                $collector->redirect('/old/{id:int}', $target);
                $this->fail('The redirect was registered');
            } catch (RouterException $e) {
                $this->assertSame('Redirect target has a placeholder that is not of the form {name}', $e->getMessage());
                $this->assertSame($target, $e->getDebugMessage());
            }
        }

        // Nothing was registered by the attempts
        $this->assertSame([], $collector->getRoutes());

        // The prefix of a group counts as source
        $collector->group('/t/{tenant}', function (RouteCollector $r): void {
            $r->redirect('/old', '/t/{tenant}/new');
        });
        $this->assertSame('/t/{tenant}/old', $collector->getRoutes()[0]->pattern);

        $route = $collector->redirect('/from/{id}/{name}', 'https://example.org/to/{name}?id={id}&x={id}');
        $this->assertSame('/from/{id}/{name}', $route->pattern);
        $this->assertSame('/plain', $collector->redirect('/plain', '/target')->pattern);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function targetsNoResponseCanCarry(): array
    {
        return [
            'line break' => ["/new\r\nX-Evil: 1"],
            'line feed alone' => ["/new\nx"],
            'NUL' => ["/new\0"],
            'DEL' => ["/new\x7F"],
            'escape' => ["/new\x1B[0m"],
        ];
    }

    /**
     * Such a target used to be registered, and every request to the route ended in a 500:
     * the response object refuses the Location header
     */
    #[DataProvider('targetsNoResponseCanCarry')]
    public function testRedirectTargetWithAControlCharacterIsRefusedWhereItIsWritten(string $target): void
    {
        $collector = new RouteCollector();

        try {
            $collector->redirect('/old', $target);
            $this->fail('The redirect was registered');
        } catch (RouterException $e) {
            $this->assertSame('Redirect target must not contain a control character', $e->getMessage());
        }

        $this->assertSame([], $collector->getRoutes());
    }

    public function testRedirectStatusIsA3xxStatus(): void
    {
        $collector = new RouteCollector();

        foreach ([200, 201, 299, 400, 0, 1000] as $status) {
            try {
                $collector->redirect('/old', '/new', $status);
                $this->fail('The redirect was registered with ' . $status);
            } catch (RouterException $e) {
                $this->assertSame('Redirect status must be a 3xx status', $e->getMessage());
                $this->assertSame((string) $status, $e->getDebugMessage());
            }
        }
        $this->assertSame([], $collector->getRoutes());

        foreach ([300, 301, 302, 303, 307, 308, 399] as $status) {
            $handler = $collector->redirect('/old/' . $status, '/new', $status)->handler;
            $this->assertInstanceOf(\Sodaho\Router\Middleware\RedirectHandler::class, $handler);
            $this->assertSame($status, $handler->getStatus());
        }

        // A tab is no line break: a response carries it, as before
        $this->assertSame('/tab', $collector->redirect('/tab', "/new\tpage")->pattern);
    }
}
