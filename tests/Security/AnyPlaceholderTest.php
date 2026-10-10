<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Security;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\Response;
use Sodaho\Router\RouteCollector;
use Sodaho\Router\RouteDispatcher;
use Sodaho\Router\UrlGenerator;

/**
 * {path:any} takes slashes, but no empty segment. '/files//etc/passwd' gave the value
 * '/etc/passwd' — an absolute path for every helper that takes one as such
 * (Path::makeAbsolute('/etc/passwd', '/srv/files') is /etc/passwd): the dot-segment rule of
 * 2.1.1 kept '..' out, not this.
 */
class AnyPlaceholderTest extends TestCase
{
    private function dispatcher(string $trailingSlash = 'strict'): RouteDispatcher
    {
        $collector = new RouteCollector();
        $collector->setPreserveTrailingSlash($trailingSlash === 'strict');
        $collector->get('/files/{path:any}', fn ($request, string $path) => Response::text('files: ' . $path));
        $collector->get('/edit/{path:any}/meta', fn ($request, string $path) => Response::text('meta: ' . $path));

        return new RouteDispatcher($collector->getData(), trailingSlash: $trailingSlash);
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function pathsWithAnEmptySegmentInTheValue(): array
    {
        $both = ['strict', 'ignore'];

        return [
            'slash in front: an absolute path' => ['/files//etc/passwd', $both],
            'two slashes in front' => ['/files///etc/passwd', $both],
            'two slashes inside' => ['/files/a//b', $both],
            // (the mode 'ignore' drops slashes at the end of a path before it asks: '/files/a')
            'two slashes at the end' => ['/files/a//', ['strict']],
            'a slash alone' => ['/files//', $both],
            'slash at the end in front of a literal' => ['/edit/a//meta', $both],
            'slash in front, in front of a literal' => ['/edit//etc/meta', $both],
        ];
    }

    /**
     * @param list<string> $modes
     */
    #[DataProvider('pathsWithAnEmptySegmentInTheValue')]
    public function testValueWithAnEmptySegmentHasNoRoute(string $path, array $modes): void
    {
        foreach ($modes as $mode) {
            $response = $this->dispatcher($mode)->handle(new ServerRequest('GET', $path));

            $this->assertSame(404, $response->getStatusCode(), "{$mode}: {$path}");
            $this->assertStringNotContainsString('etc', (string) $response->getBody(), "{$mode}: {$path}");
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function pathsThatReachTheRoute(): array
    {
        return [
            'one segment' => ['/files/a.txt', 'files: a.txt'],
            'segments' => ['/files/docs/a/b.txt', 'files: docs/a/b.txt'],
            'empty value' => ['/files/', 'files: '],
            'slash at the end of the path' => ['/files/docs/', 'files: docs/'],
            'in front of a literal' => ['/edit/docs/a/meta', 'meta: docs/a'],
            // An empty value is no empty segment inside the value: in the middle of a pattern
            // it makes one in the path, and that path reaches the route
            'empty value in the middle of a pattern' => ['/edit//meta', 'meta: '],
        ];
    }

    public function testEmptyValueInTheMiddleOfAPatternIsWrittenAndMatchedAsAnEmptySegment(): void
    {
        $collector = new RouteCollector();
        $collector->get('/edit/{path:any}/meta', 'handler')->name('meta');

        // url() writes it as the path that reaches the route …
        $address = new UrlGenerator($collector->getRoutes(), $collector->getPatterns())->url('meta', ['path' => '']);
        $this->assertSame('/edit//meta', $address);

        // … and match() finds the route there with the value ''
        $match = new RouteDispatcher($collector->getData())->match(new ServerRequest('GET', $address));
        $this->assertTrue($match->isFound());
        $this->assertSame(['path' => ''], $match->params);
    }

    #[DataProvider('pathsThatReachTheRoute')]
    public function testValueWithoutAnEmptySegmentReachesTheRoute(string $path, string $body): void
    {
        $response = $this->dispatcher()->handle(new ServerRequest('GET', $path));

        $this->assertSame(200, $response->getStatusCode(), $path);
        $this->assertSame($body, (string) $response->getBody());
    }
}
