<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\Router\RouteCollector;

/**
 * In the trailing slash mode 'strict' /api and /api/ are two addresses. Inside
 * group('/api'), get('') is the group's own address /api now and get('/') stays /api/ —
 * up to 2.1.1 both were /api/ (the second a duplicate), and /api could not be registered in
 * the group.
 */
class GroupOwnAddressTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function patterns(RouteCollector $collector): array
    {
        return array_values(array_map(static fn ($route): string => $route->pattern, $collector->getRoutes()));
    }

    public function testEmptyPatternIsTheGroupsOwnAddressInTheModeStrict(): void
    {
        $collector = new RouteCollector();
        $collector->setPreserveTrailingSlash(true);

        $collector->group('/api', function (RouteCollector $r): void {
            $r->get('', 'handler');
            $r->get('/', 'handler');
            $r->group('/v1/', function (RouteCollector $r): void {
                $r->get('', 'handler');
                $r->get('users', 'handler');
            });
        });

        $this->assertSame(['/api', '/api/', '/api/v1', '/api/v1/users'], self::patterns($collector));
    }

    public function testOutsideAGroupAnEmptyPatternIsTheRoot(): void
    {
        $collector = new RouteCollector();
        $collector->setPreserveTrailingSlash(true);

        $collector->get('', 'handler');

        $this->assertSame(['/'], self::patterns($collector));
    }

    public function testInTheModeIgnoreNothingChanges(): void
    {
        $collector = new RouteCollector();

        $collector->group('/api', function (RouteCollector $r): void {
            $r->get('', 'handler');
            $r->post('/', 'handler');
        });

        $this->assertSame(['/api', '/api'], self::patterns($collector));
    }
}
