<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\Router\Exception\DuplicateRouteException;
use Sodaho\Router\RouteCollector;

class RouteCollectorTest extends TestCase
{
    private RouteCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new RouteCollector();
    }

    public function testBasicGetRoute(): void
    {
        $route = $this->collector->get('/users', 'handler');

        $this->assertSame(['GET'], $route->methods);
        $this->assertSame('/users', $route->pattern);
        $this->assertSame('handler', $route->handler);
    }

    public function testAllHttpMethods(): void
    {
        $this->collector->get('/get', 'h');
        $this->collector->post('/post', 'h');
        $this->collector->put('/put', 'h');
        $this->collector->patch('/patch', 'h');
        $this->collector->delete('/delete', 'h');
        $this->collector->options('/options', 'h');
        $this->collector->head('/head', 'h');

        $routes = $this->collector->getRoutes();
        $this->assertCount(7, $routes);
    }

    public function testRouteWithName(): void
    {
        $route = $this->collector->get('/users/{id}', 'handler')
            ->name('user.show');

        $this->assertSame('user.show', $route->name);
    }

    public function testRouteWithMiddleware(): void
    {
        $route = $this->collector->get('/admin', 'handler')
            ->middleware('AuthMiddleware');

        $this->assertContains('AuthMiddleware', $route->middleware);
    }

    public function testGroupPrefix(): void
    {
        $this->collector->group('/api', function (RouteCollector $r) {
            $r->get('/users', 'handler');
        });

        $routes = $this->collector->getRoutes();
        $this->assertSame('/api/users', $routes[0]->pattern);
    }

    public function testNestedGroups(): void
    {
        $this->collector->group('/api', function (RouteCollector $r) {
            $r->group('/v1', function (RouteCollector $r) {
                $r->get('/users', 'handler');
            });
        });

        $routes = $this->collector->getRoutes();
        $this->assertSame('/api/v1/users', $routes[0]->pattern);
    }

    public function testMiddlewareGroup(): void
    {
        $this->collector->middlewareGroup(['Auth', 'Log'], function (RouteCollector $r) {
            $r->get('/protected', 'handler');
        });

        $routes = $this->collector->getRoutes();
        $this->assertContains('Auth', $routes[0]->middleware);
        $this->assertContains('Log', $routes[0]->middleware);
    }

    public function testGetDataSeparatesStaticAndDynamic(): void
    {
        $this->collector->get('/static', 'handler1');
        $this->collector->get('/dynamic/{id}', 'handler2');

        [$static, $dynamic] = $this->collector->getData();

        $this->assertArrayHasKey('GET', $static);
        $this->assertArrayHasKey('/static', $static['GET']);

        $this->assertArrayHasKey('GET', $dynamic);
        $this->assertCount(1, $dynamic['GET']);
    }

    public function testIntPatternGeneratesCast(): void
    {
        $this->collector->get('/users/{id:int}', 'handler');

        [$static, $dynamic] = $this->collector->getData();

        $this->assertSame(['id' => 'int'], $dynamic['GET'][0]['casts']);
    }

    public function testAddPattern(): void
    {
        $this->collector->addPattern('phone', '\d{3}-\d{4}');
        $this->collector->get('/contact/{number:phone}', 'handler');

        [$static, $dynamic] = $this->collector->getData();

        $this->assertStringContainsString('\d{3}-\d{4}', $dynamic['GET'][0]['regex']);
    }

    public function testAddPatterns(): void
    {
        $this->collector->addPatterns([
            'year' => '\d{4}',
            'month' => '(?:0[1-9]|1[0-2])',
        ]);

        $this->collector->get('/archive/{year:year}/{month:month}', 'handler');

        [$static, $dynamic] = $this->collector->getData();

        $this->assertStringContainsString('\d{4}', $dynamic['GET'][0]['regex']);
        $this->assertStringContainsString('(?:0[1-9]|1[0-2])', $dynamic['GET'][0]['regex']);
    }

    public function testMatchMultipleMethods(): void
    {
        $route = $this->collector->match(['get', 'post'], '/form', 'handler');

        $this->assertSame(['GET', 'POST'], $route->methods);
    }

    public function testAnyMethod(): void
    {
        $route = $this->collector->any('/wildcard', 'handler');

        $this->assertContains('GET', $route->methods);
        $this->assertContains('POST', $route->methods);
        $this->assertContains('PUT', $route->methods);
        $this->assertContains('PATCH', $route->methods);
        $this->assertContains('DELETE', $route->methods);
        $this->assertContains('OPTIONS', $route->methods);
        $this->assertContains('HEAD', $route->methods);
    }

    public function testRedirectRoute(): void
    {
        $route = $this->collector->redirect('/old', '/new', 301);

        $this->assertSame(['GET', 'HEAD'], $route->methods);
        $this->assertSame('/old', $route->pattern);
        $this->assertInstanceOf(\Sodaho\Router\Middleware\RedirectHandler::class, $route->handler);
    }

    public function testFloatPatternGeneratesCast(): void
    {
        $this->collector->get('/price/{amount:float}', 'handler');

        [$static, $dynamic] = $this->collector->getData();

        $this->assertSame(['amount' => 'float'], $dynamic['GET'][0]['casts']);
    }

    public function testBoolPatternGeneratesCast(): void
    {
        $this->collector->get('/feature/{enabled:bool}', 'handler');

        [$static, $dynamic] = $this->collector->getData();

        $this->assertSame(['enabled' => 'bool'], $dynamic['GET'][0]['casts']);
    }

    public function testMiddlewareGroupWithSingleMiddleware(): void
    {
        $this->collector->middlewareGroup('SingleAuth', function (RouteCollector $r) {
            $r->get('/single', 'handler');
        });

        $routes = $this->collector->getRoutes();
        $this->assertContains('SingleAuth', $routes[0]->middleware);
    }

    public function testPatternWithNoType(): void
    {
        $this->collector->get('/files/{path}', 'handler');

        [$static, $dynamic] = $this->collector->getData();

        // Default pattern [^/]+ used, no casts
        $this->assertEmpty($dynamic['GET'][0]['casts']);
        $this->assertStringContainsString('[^/]+', $dynamic['GET'][0]['regex']);
    }

    public function testMultipleMethodsInGetData(): void
    {
        $this->collector->match(['GET', 'POST'], '/both', 'handler');

        [$static, $dynamic] = $this->collector->getData();

        $this->assertArrayHasKey('GET', $static);
        $this->assertArrayHasKey('POST', $static);
        $this->assertArrayHasKey('/both', $static['GET']);
        $this->assertArrayHasKey('/both', $static['POST']);
    }

    public function testDuplicateRouteThrowsException(): void
    {
        $this->collector->get('/users', 'handler1');

        $this->expectException(DuplicateRouteException::class);
        $this->expectExceptionMessage('GET /users is already registered');

        $this->collector->get('/users', 'handler2');
    }

    public function testDuplicateRouteWithDifferentMethodsIsAllowed(): void
    {
        $this->collector->get('/users', 'getHandler');
        $this->collector->post('/users', 'postHandler');

        $routes = $this->collector->getRoutes();
        $this->assertCount(2, $routes);
    }

    public function testDuplicateRouteInGroupThrowsException(): void
    {
        $this->collector->group('/api', function (RouteCollector $r) {
            $r->get('/users', 'handler1');
        });

        $this->expectException(DuplicateRouteException::class);

        $this->collector->group('/api', function (RouteCollector $r) {
            $r->get('/users', 'handler2');
        });
    }

    public function testPreserveTrailingSlashDisabledByDefault(): void
    {
        // Default behavior: trailing slashes are trimmed
        $this->collector->get('/users/', 'handler');

        $routes = $this->collector->getRoutes();
        $this->assertSame('/users', $routes[0]->pattern);
    }

    public function testPreserveTrailingSlashEnabled(): void
    {
        $this->collector->setPreserveTrailingSlash(true);
        $this->collector->get('/users/', 'handler1');
        $this->collector->get('/users', 'handler2');

        $routes = $this->collector->getRoutes();
        $this->assertSame('/users/', $routes[0]->pattern);
        $this->assertSame('/users', $routes[1]->pattern);
    }

    public function testPreserveTrailingSlashWithGroups(): void
    {
        $this->collector->setPreserveTrailingSlash(true);

        $this->collector->group('/api', function (RouteCollector $r) {
            $r->get('/items/', 'handler1');
            $r->get('/items', 'handler2');
        });

        $routes = $this->collector->getRoutes();
        $this->assertSame('/api/items/', $routes[0]->pattern);
        $this->assertSame('/api/items', $routes[1]->pattern);
    }

    public function testPreserveTrailingSlashRootRoute(): void
    {
        $this->collector->setPreserveTrailingSlash(true);
        $this->collector->get('/', 'handler');

        $routes = $this->collector->getRoutes();
        $this->assertSame('/', $routes[0]->pattern);
    }

    public function testRegexSpecialCharsInPatternAreEscaped(): void
    {
        // Bug: /v1.0/users/{id} matched /v1X0/users/123 because dot was not escaped
        $this->collector->get('/v1.0/users/{id}', 'handler');

        [$static, $dynamic] = $this->collector->getData();

        $regex = $dynamic['GET'][0]['regex'];

        // Dot should be escaped as \.
        $this->assertStringContainsString('v1\.0', $regex);

        // Should match correct URL
        $this->assertMatchesRegularExpression($regex, '/v1.0/users/123');

        // Should NOT match URL with different char instead of dot
        $this->assertDoesNotMatchRegularExpression($regex, '/v1X0/users/123');
        $this->assertDoesNotMatchRegularExpression($regex, '/v1-0/users/123');
    }
}
