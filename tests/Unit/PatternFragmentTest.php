<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\Response;
use Sodaho\Router\RouteCollector;
use Sodaho\Router\RouteDispatcher;

/**
 * A fragment of addPattern() acts within the group of its placeholder and nowhere else: a
 * named group of its own would be a parameter of every route that uses it, a verb
 * ((*ACCEPT)) would end or steer the match of the whole route. Both are refused where the
 * pattern is added; what the fragment may still do is read as PCRE reads it.
 */
class PatternFragmentTest extends TestCase
{
    private const NAMED = 'Pattern fragment must not contain a named group: it may refer to the placeholders of its route by name ((?P=other)), not name one itself';
    private const VERB = 'Pattern fragment must not contain a (*...) construct: a verb such as (*ACCEPT) ends or steers the match of the whole route';

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function fragmentsThatActBeyondTheirGroup(): array
    {
        return [
            // Each name used to reach the handler as an argument (and break a typed one)
            'named group, Python style' => ['(?P<year>\d{4})-\d{2}', self::NAMED],
            'named group, Perl style' => ['(?<year>\d{4})-\d{2}', self::NAMED],
            'named group in quotes' => ["(?'year'\\d{4})-\\d{2}", self::NAMED],
            // … this one replaced the parameter list of the route itself (a TypeError, a 500)
            'the router\'s own name' => ['(?P<_route_params>.+)', self::NAMED],
            'named group further inside' => ['a(?:b(?<n>c))', self::NAMED],
            'duplicate names allowed and used' => ['(?J)(?<a>x)', self::NAMED],
            // 'a(*ACCEPT)' matched /files/abcd with the value 'a'
            'accept' => ['a(*ACCEPT)', self::VERB],
            'commit' => ['(*COMMIT)\d+', self::VERB],
            'skip and fail' => ['x(*SKIP)(*F)|y', self::VERB],
            'lookahead spelled as a verb' => ['(*pla:a)a', self::VERB],
            'option that belongs at the start of a pattern' => ['(*UTF)a', self::VERB],
            // '\c[' is one character (ESC), not an escape and the start of a class: read as
            // two, the '[' hid what follows from the check
            'named group behind \c[' => ['(?:\c[)?(?<extra>[a-z]+)', self::NAMED],
            'verb behind \c[' => ['(?:\c[)?a(*ACCEPT)', self::VERB],
        ];
    }

    #[DataProvider('fragmentsThatActBeyondTheirGroup')]
    public function testFragmentThatActsBeyondItsGroupIsRefusedWhereItIsAdded(string $fragment, string $message): void
    {
        $collector = new RouteCollector();

        try {
            $collector->addPattern('own', $fragment);
            $this->fail('The pattern was added');
        } catch (RouterException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertSame('own', $e->getDebugMessage());
        }

        $this->assertArrayNotHasKey('own', $collector->getPatterns());
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function fragmentsThatStayWithinTheirGroup(): array
    {
        return [
            'lookbehind' => ['[a-z](?<=a)b', '/x/ab', '/x/cb'],
            'negative lookbehind' => ['a(?<!b)c', '/x/ac', '/x/bc'],
            'group without a name' => ['(\d{4})-(\d{2})', '/x/2024-01', '/x/2024-1'],
            'group that does not capture' => ['(?:a|b)+', '/x/abba', '/x/abc'],
            'escaped parenthesis in front of letters' => ['\(?P<x>', '/x/(P<x>', '/x/P<y>'],
            'parenthesis in a character class' => ['[(?P<x>*]+', '/x/(P*', '/x/a'],
            'POSIX class inside a character class' => ['[[:digit:](*]+', '/x/1(*', '/x/a'],
            'quoted text' => ['\Q(*ACCEPT)\E', '/x/(*ACCEPT)', '/x/a'],
            'reference to a placeholder by name' => ['(?P=a)', '/x/x', '/x/y'],
            // '\c]' in a class is one character: the class goes on to the next ']'
            'parenthesis in a class behind \c]' => ['[\c](?<n>x)]+', '/x/(n', '/x/a'],
        ];
    }

    #[DataProvider('fragmentsThatStayWithinTheirGroup')]
    public function testFragmentThatStaysWithinItsGroupIsAllowed(string $fragment, string $hit, string $miss): void
    {
        $collector = new RouteCollector();
        $collector->addPattern('own', $fragment);
        $collector->get('/{a}/{v:own}', 'handler');

        $regex = $collector->getData()[1]['GET'][0]['regex'];

        $this->assertSame(1, preg_match($regex, $hit), $hit);
        $this->assertSame(0, preg_match($regex, $miss), $miss);
    }

    /**
     * Numbers never reach the parameters: what a group without a name captures is not an
     * argument of the handler, not in '_route_params' and not in RouteMatch::$params.
     */
    public function testGroupWithoutANameNeverBecomesAParameterOrAnArgument(): void
    {
        $collector = new RouteCollector();
        $collector->addPattern('month', '(\d{4})-(\d{2})');
        $collector->get('/m/{m:month}', fn ($request, string $m, mixed ...$more) => Response::json([
            'm' => $m,
            'more' => $more,
            'params' => $request->getAttribute('_route_params'),
        ]));

        $dispatcher = new RouteDispatcher($collector->getData());
        $request = new ServerRequest('GET', '/m/2024-01');

        $this->assertSame(['m' => '2024-01'], $dispatcher->match($request)->params);

        $response = $dispatcher->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['m' => '2024-01', 'more' => [], 'params' => ['m' => '2024-01']],
            json_decode((string) $response->getBody(), true),
        );
    }
}
