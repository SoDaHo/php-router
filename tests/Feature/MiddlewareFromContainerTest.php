<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Feature;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\Router;

/**
 * A middleware name the container has is the container's: what it returns is taken or
 * refused. Built by the router instead, with the constructor's defaults, a rate limit
 * registered as a factory by mistake ran with a limit of a million.
 */
class MiddlewareFromContainerTest extends TestCase
{
    private string $routesFile;

    protected function setUp(): void
    {
        $this->routesFile = sys_get_temp_dir() . '/router_mw_container_' . uniqid() . '.php';

        file_put_contents(
            $this->routesFile,
            <<<'PHP'
                <?php
                use Sodaho\Router\Response;
                use Sodaho\Router\Tests\Feature\RateLimit;

                return function (Sodaho\Router\RouteCollector $r) {
                    $r->post('/login', fn () => Response::text('logged in'))->middleware(RateLimit::class);
                };
                PHP
        );
    }

    protected function tearDown(): void
    {
        unlink($this->routesFile);
    }

    /**
     * @param array<string, mixed> $entries
     * @param list<array<string, mixed>> $reported
     */
    private function router(array $entries, array &$reported, bool $global = false): Router
    {
        $container = new class ($entries) implements ContainerInterface {
            /** @param array<string, mixed> $entries */
            public function __construct(private readonly array $entries)
            {
            }

            public function get(string $id): mixed
            {
                return $this->entries[$id];
            }

            public function has(string $id): bool
            {
                return array_key_exists($id, $this->entries);
            }
        };

        $router = Router::create()->loadRoutes($this->routesFile)->setContainer($container);
        if ($global) {
            $router->middleware(RateLimit::class);
        }
        $router->on('error', function (array $data) use (&$reported): void {
            $reported[] = $data;
        });

        return $router;
    }

    public function testFactoryInPlaceOfTheMiddlewareIsA500AndNoDefaultInstanceRuns(): void
    {
        foreach (['route middleware' => false, 'middleware for every request' => true] as $label => $global) {
            $reported = [];
            $router = $this->router([RateLimit::class => fn () => new RateLimit(5)], $reported, $global);

            $response = $router->handle(new ServerRequest('POST', '/login'));

            $this->assertSame(500, $response->getStatusCode(), $label);
            $this->assertFalse($response->hasHeader('X-Limit'), $label . ': the default instance ran');
            $this->assertCount(1, $reported, $label);
            $this->assertInstanceOf(RouterException::class, $reported[0]['exception']);
            $this->assertSame(
                'The container entry for a middleware is no MiddlewareInterface: register the middleware itself, not a factory or another object',
                $reported[0]['exception']->getMessage(),
                $label,
            );
            $this->assertSame(RateLimit::class . ': Closure', $reported[0]['exception']->getDebugMessage(), $label);
        }
    }

    public function testWhatTheContainerBuiltIsWhatRuns(): void
    {
        $reported = [];
        $response = $this->router([RateLimit::class => new RateLimit(5)], $reported)
            ->handle(new ServerRequest('POST', '/login'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('5', $response->getHeaderLine('X-Limit'));
        $this->assertSame([], $reported);
    }

    public function testNameTheContainerDoesNotHaveIsStillBuiltWhenItNeedsNothing(): void
    {
        $reported = [];
        $response = $this->router([], $reported)->handle(new ServerRequest('POST', '/login'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('1000000', $response->getHeaderLine('X-Limit'));
    }
}

/**
 * Middleware of the fixture with a default the application means to replace.
 */
final class RateLimit implements MiddlewareInterface
{
    public function __construct(private readonly int $limit = 1000000)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)->withHeader('X-Limit', (string) $this->limit);
    }
}
