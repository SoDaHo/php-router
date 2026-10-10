<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\Route;
use Sodaho\Router\UrlGenerator;

/**
 * A '.' or '..' segment of the base path of a generator built by hand went out: url() looked
 * for one in what the route made of its values only, and the base path was put in front
 * afterwards — '/tenant/..' in front of '/login' gave '/tenant/../login', which a client reads
 * as '/login', outside the base path. The finished address is checked now, base path
 * included. (The router's 'basePath' refused such a path already, see RouterConfigTest.)
 */
class UrlGeneratorBasePathDotsTest extends TestCase
{
    private const DOTS = 'The address would contain a "." or ".." path segment, which a client resolves before it asks';

    private static function generator(string $basePath): UrlGenerator
    {
        $generator = new UrlGenerator([new Route(['GET'], '/login', 'handler', [], 'login')]);
        $generator->setBasePath($basePath);
        $generator->setBaseUrl('https://example.com');

        return $generator;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function basePathsWithADotSegment(): array
    {
        return [
            'current segment' => ['/.', '/./login'],
            'parent segment' => ['/..', '/../login'],
            'parent segment in the middle' => ['/a/../b', '/a/../b/login'],
            'parent segment at the end' => ['/tenant/..', '/tenant/../login'],
        ];
    }

    #[DataProvider('basePathsWithADotSegment')]
    public function testAddressWithADotSegmentOfTheBasePathIsRefused(string $basePath, string $address): void
    {
        $generator = self::generator($basePath);

        foreach (['url', 'absoluteUrl'] as $method) {
            try {
                $generator->{$method}('login');
                $this->fail($method . '() gave an address');
            } catch (RouterException $e) {
                $this->assertSame(self::DOTS, $e->getMessage());
                $this->assertSame($address, $e->getDebugMessage());
            }
        }
    }

    public function testBasePathWithoutADotSegmentStaysInFront(): void
    {
        $generator = self::generator('/tenant/');

        $this->assertSame('/tenant/login', $generator->url('login'));
        $this->assertSame('https://example.com/tenant/login', $generator->absoluteUrl('login'));
    }

    public function testDotsThatAreNoSegmentOfTheirOwnStay(): void
    {
        $generator = self::generator('/v1.0/..x');

        $this->assertSame('/v1.0/..x/login', $generator->url('login'));
    }
}
