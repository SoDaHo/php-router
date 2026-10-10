<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\Route;
use Sodaho\Router\UrlGenerator;

/**
 * The base URL rule lives in the URL generator, which puts the value in front of every
 * absolute address: a generator built by hand gets it as well as the router's config.
 * Without the rule in UrlGenerator::setBaseUrl(), 'javascript:alert(1)//' would make every
 * absolute address a script.
 */
class UrlGeneratorBaseUrlTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function baseUrlsThatAreRefused(): array
    {
        $host = 'Base URL must be an address of a host: http:// or https://, the host, a port and a path at most — no user information, query or fragment';
        $blank = 'Base URL must not contain a control character or a blank';

        return [
            'a script' => ['javascript:alert(1)//', $host],
            'scheme-relative' => ['//evil.example', $host],
            'no scheme' => ['example.com', $host],
            'a backslash: the host is evil for a browser' => ['https://evil\\@trusted.example/base', $host],
            'user information' => ['https://user:secret@app.example', $host],
            'a port and no host' => ['https://:443', $host],
            'an empty port' => ['https://app.example:', $host],
            'a query' => ['https://app.example?x=1', $host],
            'a line break' => ["https://app.example\r\nX: 1", $blank],
        ];
    }

    #[DataProvider('baseUrlsThatAreRefused')]
    public function testGeneratorBuiltByHandRefusesWhatTheRouterRefuses(string $baseUrl, string $message): void
    {
        $generator = new UrlGenerator([new Route(['GET'], '/users/{id}', 'handler', [], 'users.show')]);

        try {
            $generator->setBaseUrl($baseUrl);
            $this->fail('The base URL was taken');
        } catch (RouterException $e) {
            $this->assertSame($message, $e->getMessage());
        }

        // Nothing was set: there is still no base URL
        $this->expectException(RouterException::class);
        $this->expectExceptionMessage('Cannot generate absolute URL: baseUrl is not configured');
        $generator->absoluteUrl('users.show', ['id' => 5]);
    }

    public function testAddressOfAHostIsTakenAndEmptyMeansNone(): void
    {
        $generator = new UrlGenerator([new Route(['GET'], '/users/{id}', 'handler', [], 'users.show')]);

        $generator->setBaseUrl('https://app.example:8443/base/');
        $this->assertSame('https://app.example:8443/base/users/5', $generator->absoluteUrl('users.show', ['id' => 5]));

        // A host the parser writes otherwise is put in front as it is written
        $generator->setBaseUrl('https://Bücher.example');
        $this->assertSame('https://Bücher.example/users/5', $generator->absoluteUrl('users.show', ['id' => 5]));
        $generator->setBaseUrl('https://[0:0:0:0:0:0:0:1]:8443');
        $this->assertSame('https://[0:0:0:0:0:0:0:1]:8443/users/5', $generator->absoluteUrl('users.show', ['id' => 5]));

        $generator->setBaseUrl('');
        $this->expectException(RouterException::class);
        $generator->absoluteUrl('users.show', ['id' => 5]);
    }
}
