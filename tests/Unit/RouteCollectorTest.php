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

        try {
            $this->collector->get('/users', 'handler2');
            $this->fail('The route was registered twice');
        } catch (DuplicateRouteException $e) {
            // Which route: in the debug message, like every pattern
            $this->assertSame('Route is already registered for this method', $e->getMessage());
            $this->assertSame('GET /users', $e->getDebugMessage());
        }
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

    public function testCompiledRegexIsAnchoredAtTheVeryEnd(): void
    {
        $this->collector->get('/users/{id:int}', 'handler');
        $this->collector->get('/files/{name}', 'handler');

        [, $dynamic] = $this->collector->getData();
        $dispatcher = new \Sodaho\Router\Dispatcher([], $dynamic);

        // "$" also matches before a trailing newline: '/users/5%0A' addressed the same route
        // as '/users/5', while caches, logs and rate limiters in front saw two different paths.
        $this->assertSame(\Sodaho\Router\Dispatcher::FOUND, $dispatcher->dispatch('GET', '/users/5')[0]);
        $this->assertSame(\Sodaho\Router\Dispatcher::NOT_FOUND, $dispatcher->dispatch('GET', "/users/5\n")[0]);
        $this->assertSame(\Sodaho\Router\Dispatcher::NOT_FOUND, $dispatcher->dispatch('GET', "/users/5\r\n")[0]);

        // An untyped parameter takes whatever is not a slash — the newline is part of the value, not dropped
        $match = $dispatcher->dispatch('GET', "/files/a\n");
        $this->assertSame(\Sodaho\Router\Dispatcher::FOUND, $match[0]);
        $this->assertSame("a\n", $match[2]['name']);
    }

    public function testAddPatternAcceptsAnEscapedDelimiterAndGroups(): void
    {
        $this->collector->addPatterns(['tag' => '\#[a-z]+', 'version' => 'v(?:\d+)(?:\.\d+)?']);
        $this->collector->get('/t/{tag:tag}/{v:version}', 'handler');

        $regex = $this->collector->getData()[1]['GET'][0]['regex'];

        $this->assertSame(1, preg_match($regex, '/t/#php/v8.4'));
        $this->assertSame(0, preg_match($regex, '/t/php/v8.4'));
    }

    public function testAttributeGroup(): void
    {
        $this->collector->get('/before', 'h');

        $this->collector->attributeGroup(['format' => 'envelope', 'cors' => false], function (RouteCollector $r): void {
            $r->get('/plain', 'h');
            $r->get('/own', 'h')->attribute('cors', true)->attribute('tag', 'me');

            $r->attributeGroup(['format' => 'oauth', 'scope' => 'admin'], function (RouteCollector $r): void {
                $r->post('/nested', 'h');
            });

            $r->get('/after-nested', 'h');
        });

        $this->collector->get('/after', 'h');

        $attributes = [];
        foreach ($this->collector->getRoutes() as $route) {
            $attributes[$route->pattern] = $route->attributes;
        }

        // On the Route object right at registration — whoever reads getRoutes() sees them
        $this->assertSame(
            [
                '/before' => [],
                '/plain' => ['format' => 'envelope', 'cors' => false],
                // the route itself wins over the group
                '/own' => ['format' => 'envelope', 'cors' => true, 'tag' => 'me'],
                // the inner group wins per key and adds its own
                '/nested' => ['format' => 'oauth', 'cors' => false, 'scope' => 'admin'],
                // ... and is over when it ends
                '/after-nested' => ['format' => 'envelope', 'cors' => false],
                '/after' => [],
            ],
            $attributes
        );
    }

    public function testAttributeGroupCombinesWithTheOtherGroups(): void
    {
        $this->collector->group('/api', function (RouteCollector $r): void {
            $r->middlewareGroup('Auth', function (RouteCollector $r): void {
                $r->attributeGroup(['format' => 'oauth'], function (RouteCollector $r): void {
                    $r->get('/token', 'h');
                });
            });
        });

        $route = $this->collector->getRoutes()[0];

        $this->assertSame('/api/token', $route->pattern);
        $this->assertSame(['Auth'], $route->middleware);
        $this->assertSame(['format' => 'oauth'], $route->attributes);

        // The compiled table holds the same object
        $this->assertSame($route, $this->collector->getData()[0]['GET']['/api/token']);
    }

    public function testMiddlewareGroupTakesAttributesAsAnAttributeGroupAroundIt(): void
    {
        $this->collector->attributeGroup(['format' => 'envelope', 'cors' => false], function (RouteCollector $r): void {
            $r->middlewareGroup('Auth', function (RouteCollector $r): void {
                $r->get('/token', 'h');
                $r->get('/avatar', 'h')->attribute('format', 'binary');
                $r->middlewareGroup('Log', function (RouteCollector $r): void {
                    $r->get('/deep', 'h');
                }, ['scope' => 'admin', 'cors' => true]);
            }, ['format' => 'oauth']);
            $r->get('/after', 'h');
        });
        $this->collector->get('/outside', 'h');

        $seen = [];
        foreach ($this->collector->getRoutes() as $route) {
            $seen[$route->pattern] = [$route->middleware, $route->attributes];
        }

        $this->assertSame([
            // The inner group wins per key …
            '/token' => [['Auth'], ['format' => 'oauth', 'cors' => false]],
            // … the route wins over every group …
            '/avatar' => [['Auth'], ['format' => 'binary', 'cors' => false]],
            // … nested groups add up, middleware and attributes alike …
            '/deep' => [['Auth', 'Log'], ['format' => 'oauth', 'cors' => true, 'scope' => 'admin']],
            // … and both are over when the group ends
            '/after' => [[], ['format' => 'envelope', 'cors' => false]],
            '/outside' => [[], []],
        ], $seen);
    }

    public function testShortFormBuildsTheSameRoutesAsTheLongForm(): void
    {
        $long = new RouteCollector();
        $long->group('/api', function (RouteCollector $r): void {
            $r->attributeGroup(['format' => 'oauth'], function (RouteCollector $r): void {
                $r->middlewareGroup('Auth', function (RouteCollector $r): void {
                    $r->get('/users/{id}', 'h')->name('users.show');
                    $r->redirect('/old/{id}', '/api/users/{id}', 301);
                    $r->attributeGroup(['scope' => 'admin'], function (RouteCollector $r): void {
                        $r->middlewareGroup('Log', function (RouteCollector $r): void {
                            $r->post('/admin', 'h');
                        });
                    });
                });
            });
        });

        $short = new RouteCollector();
        $short->group('/api', function (RouteCollector $r): void {
            $r->middlewareGroup('Auth', function (RouteCollector $r): void {
                $r->get('/users/{id}', 'h')->name('users.show');
                $r->redirect('/old/{id}', '/api/users/{id}', 301);
                // Named arguments as well
                $r->middlewareGroup(middleware: 'Log', callback: function (RouteCollector $r): void {
                    $r->post('/admin', 'h');
                }, attributes: ['scope' => 'admin']);
            }, ['format' => 'oauth']);
        });

        $describe = static fn (RouteCollector $collector): array => array_map(
            static fn (\Sodaho\Router\Route $route): array => [
                $route->methods,
                $route->pattern,
                $route->middleware,
                $route->attributes,
                $route->name,
                is_object($route->handler) ? $route->handler::class : $route->handler,
            ],
            $collector->getRoutes()
        );

        $this->assertSame($describe($long), $describe($short));
        $this->assertCount(3, $describe($short));
    }

    public function testMiddlewareGroupWithoutAttributesIsAsBefore(): void
    {
        $this->collector->middlewareGroup('Auth', function (RouteCollector $r): void {
            $r->get('/a', 'h');
        }, []);

        $route = $this->collector->getRoutes()[0];
        $this->assertSame(['Auth'], $route->middleware);
        $this->assertSame([], $route->attributes);
    }

    /**
     * A routes file that catches what a group's callback throws and carries on must not
     * register the routes after it inside that group.
     */
    public function testGroupsEndEvenWhenTheirCallbackThrows(): void
    {
        $giveUp = function (): void {
            throw new \RuntimeException('routes of an optional module are missing');
        };

        foreach ([
            fn () => $this->collector->group('/admin', $giveUp),
            fn () => $this->collector->middlewareGroup('Auth', $giveUp),
            fn () => $this->collector->attributeGroup(['cors' => true], $giveUp),
            fn () => $this->collector->middlewareGroup('Auth', $giveUp, ['cors' => true]),
        ] as $group) {
            // Not with fail() inside the try: PHPUnit's failure is a RuntimeException as well
            $thrown = null;
            try {
                $group();
            } catch (\RuntimeException $e) {
                $thrown = $e;
            }
            $this->assertNotNull($thrown, 'The exception was swallowed');
            $this->assertSame('routes of an optional module are missing', $thrown->getMessage());
        }

        $route = $this->collector->get('/public', 'h');

        $this->assertSame('/public', $route->pattern);
        $this->assertSame([], $route->middleware);
        $this->assertSame([], $route->attributes);
    }
}
