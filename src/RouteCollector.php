<?php

declare(strict_types=1);

namespace Sodaho\Router;

use Sodaho\Router\Exception\DuplicateRouteException;

/**
 * Collects route definitions and compiles them for the Dispatcher.
 */
class RouteCollector
{
    /** @var Route[] */
    private array $routes = [];

    /** @var string Current group prefix */
    private string $currentPrefix = '';

    /** @var array<int, string|object> Current group middleware stack */
    private array $currentMiddleware = [];

    /** @var array<string, true> Registered method+pattern combinations for duplicate detection */
    private array $registeredRoutes = [];

    /** @var bool Whether to preserve trailing slashes in route patterns (for strict mode) */
    private bool $preserveTrailingSlash = false;

    /**
     * Regex shortcuts for route parameters.
     *
     * @var array<string, string>
     */
    private array $patterns = [
        'int'      => '-?\d+',                   // Integers (including negative)
        'float'    => '-?\d+(?:\.\d+)?',         // Decimals (including negative)
        'bool'     => '(?:[tT][rR][uU][eE]|[fF][aA][lL][sS][eE]|0|1)', // Booleans (case-insensitive)
        'alpha'    => '[a-zA-Z]+',
        'alphanum' => '[a-zA-Z0-9]+',
        'slug'     => '[a-z0-9-]+',
        'uuid'     => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}',
        'ulid'     => '[0-9A-Za-z]{26}',
        'any'      => '.*',
    ];

    /**
     * Add a custom pattern shortcut.
     *
     * @param string $name Pattern name (e.g., 'date')
     * @param string $regex Regex pattern (e.g., '\d{4}-\d{2}-\d{2}')
     */
    public function addPattern(string $name, string $regex): self
    {
        $this->patterns[$name] = $regex;
        return $this;
    }

    /**
     * Add multiple pattern shortcuts at once.
     *
     * @param array<string, string> $patterns Name => regex pairs
     */
    public function addPatterns(array $patterns): self
    {
        foreach ($patterns as $name => $regex) {
            $this->addPattern($name, $regex);
        }
        return $this;
    }

    /**
     * Set whether to preserve trailing slashes in route patterns.
     *
     * When true (strict mode): /users/ and /users are different routes.
     * When false (ignore mode): /users/ is normalized to /users.
     *
     * @param bool $preserve Preserve trailing slashes
     */
    public function setPreserveTrailingSlash(bool $preserve): self
    {
        $this->preserveTrailingSlash = $preserve;
        return $this;
    }

    // ==================== HTTP Method Shortcuts ====================

    /**
     * Register a GET route.
     *
     * @param string $pattern URL pattern (e.g., '/users/{id:int}')
     * @param mixed $handler Controller class, callable, or RequestHandler
     *
     * @throws DuplicateRouteException If route already exists
     *
     * @return Route Fluent route for chaining ->name(), ->middleware()
     */
    public function get(string $pattern, mixed $handler): Route
    {
        return $this->addRoute(['GET'], $pattern, $handler);
    }

    /**
     * Register a POST route.
     *
     * @param string $pattern URL pattern
     * @param mixed $handler Controller class, callable, or RequestHandler
     *
     * @throws DuplicateRouteException If route already exists
     *
     * @return Route Fluent route for chaining
     */
    public function post(string $pattern, mixed $handler): Route
    {
        return $this->addRoute(['POST'], $pattern, $handler);
    }

    /**
     * Register a PUT route.
     *
     * @param string $pattern URL pattern
     * @param mixed $handler Controller class, callable, or RequestHandler
     *
     * @throws DuplicateRouteException If route already exists
     *
     * @return Route Fluent route for chaining
     */
    public function put(string $pattern, mixed $handler): Route
    {
        return $this->addRoute(['PUT'], $pattern, $handler);
    }

    /**
     * Register a PATCH route.
     *
     * @param string $pattern URL pattern
     * @param mixed $handler Controller class, callable, or RequestHandler
     *
     * @throws DuplicateRouteException If route already exists
     *
     * @return Route Fluent route for chaining
     */
    public function patch(string $pattern, mixed $handler): Route
    {
        return $this->addRoute(['PATCH'], $pattern, $handler);
    }

    /**
     * Register a DELETE route.
     *
     * @param string $pattern URL pattern
     * @param mixed $handler Controller class, callable, or RequestHandler
     *
     * @throws DuplicateRouteException If route already exists
     *
     * @return Route Fluent route for chaining
     */
    public function delete(string $pattern, mixed $handler): Route
    {
        return $this->addRoute(['DELETE'], $pattern, $handler);
    }

    /**
     * Register an OPTIONS route.
     *
     * @param string $pattern URL pattern
     * @param mixed $handler Controller class, callable, or RequestHandler
     *
     * @throws DuplicateRouteException If route already exists
     *
     * @return Route Fluent route for chaining
     */
    public function options(string $pattern, mixed $handler): Route
    {
        return $this->addRoute(['OPTIONS'], $pattern, $handler);
    }

    /**
     * Register a HEAD route.
     *
     * @param string $pattern URL pattern
     * @param mixed $handler Controller class, callable, or RequestHandler
     *
     * @throws DuplicateRouteException If route already exists
     *
     * @return Route Fluent route for chaining
     */
    public function head(string $pattern, mixed $handler): Route
    {
        return $this->addRoute(['HEAD'], $pattern, $handler);
    }

    /**
     * Register route for multiple HTTP methods.
     *
     * @param string[] $methods HTTP methods (e.g., ['GET', 'POST'])
     * @param string $pattern URL pattern
     * @param mixed $handler Controller class, callable, or RequestHandler
     *
     * @throws DuplicateRouteException If route already exists
     *
     * @return Route Fluent route for chaining
     */
    public function match(array $methods, string $pattern, mixed $handler): Route
    {
        return $this->addRoute(array_map('strtoupper', $methods), $pattern, $handler);
    }

    /**
     * Register route for all HTTP methods.
     *
     * @param string $pattern URL pattern
     * @param mixed $handler Controller class, callable, or RequestHandler
     *
     * @throws DuplicateRouteException If route already exists
     *
     * @return Route Fluent route for chaining
     */
    public function any(string $pattern, mixed $handler): Route
    {
        return $this->addRoute(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], $pattern, $handler);
    }

    // ==================== Grouping ====================

    /**
     * Group routes with a common prefix.
     *
     * @param string $prefix URL prefix (e.g., '/api/v1')
     * @param callable $callback Receives RouteCollector instance
     */
    public function group(string $prefix, callable $callback): void
    {
        $previousPrefix = $this->currentPrefix;
        $this->currentPrefix .= '/' . trim($prefix, '/');

        $callback($this);

        $this->currentPrefix = $previousPrefix;
    }

    /**
     * Group routes with common middleware.
     *
     * @param string|array<string|object>|object $middleware Middleware class name(s) or instance(s)
     * @param callable $callback Receives RouteCollector instance
     */
    public function middlewareGroup(string|array|object $middleware, callable $callback): void
    {
        $middleware = is_array($middleware) ? $middleware : [$middleware];

        $previousMiddleware = $this->currentMiddleware;
        $this->currentMiddleware = array_merge($this->currentMiddleware, $middleware);

        $callback($this);

        $this->currentMiddleware = $previousMiddleware;
    }

    // ==================== Redirect Routes ====================

    /**
     * Register a redirect route.
     *
     * Uses RedirectHandler instead of Closure (cache-friendly).
     *
     * @param string $from Source URL pattern
     * @param string $to Target URL
     * @param int $status HTTP status code (default: 302)
     *
     * @throws DuplicateRouteException If route already exists
     */
    public function redirect(string $from, string $to, int $status = 302): Route
    {
        return $this->addRoute(
            ['GET', 'HEAD'],
            $from,
            new Middleware\RedirectHandler($to, $status)
        );
    }

    // ==================== Internal ====================

    /**
     * Add a route to the collection.
     *
     * @param string[] $methods HTTP methods
     * @param string $pattern URL pattern
     * @param mixed $handler Route handler
     *
     * @throws DuplicateRouteException If route already exists for method+pattern
     */
    private function addRoute(array $methods, string $pattern, mixed $handler): Route
    {
        if ($this->preserveTrailingSlash) {
            // Strict mode: preserve trailing slash, only normalize leading
            $prefix = rtrim($this->currentPrefix, '/');
            $normalizedPattern = '/' . ltrim(trim($pattern), '/');
            $path = $prefix . $normalizedPattern;
            $path = '/' . ltrim($path, '/');
        } else {
            // Ignore mode: normalize everything (current behavior)
            $path = '/' . trim($this->currentPrefix . '/' . trim($pattern, '/'), '/');
        }

        // Check for duplicate routes
        foreach ($methods as $method) {
            $key = $method . ':' . $path;
            if (isset($this->registeredRoutes[$key])) {
                throw new DuplicateRouteException(
                    sprintf('Route %s %s is already registered', $method, $path)
                );
            }
            $this->registeredRoutes[$key] = true;
        }

        $route = new Route($methods, $path, $handler);

        if (!empty($this->currentMiddleware)) {
            $route->middleware($this->currentMiddleware);
        }

        $this->routes[] = $route;
        return $route;
    }

    /**
     * Get all registered routes.
     *
     * @return Route[]
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Get named routes as cacheable data (name => pattern).
     *
     * @return array<string, string>
     */
    public function getNamedRoutesData(): array
    {
        $namedRoutes = [];
        foreach ($this->routes as $route) {
            if ($route->name !== null) {
                $namedRoutes[$route->name] = $route->pattern;
            }
        }
        return $namedRoutes;
    }

    /**
     * Compile routes for the Dispatcher.
     *
     * @return array{0: array<string, array<string, Route>>, 1: array<string, array<int, array{regex: string, route: Route, casts: array<string, string>}>>} [staticRoutes, dynamicRoutes]
     */
    public function getData(): array
    {
        $staticRoutes = [];
        $dynamicRoutes = [];

        foreach ($this->routes as $route) {
            foreach ($route->methods as $method) {
                // STATIC OPTIMIZATION: Routes without parameters
                if (!str_contains($route->pattern, '{')) {
                    $staticRoutes[$method][$route->pattern] = $route;
                    continue;
                }

                // DYNAMIC COMPILATION: Routes with parameters
                $casts = [];

                // Split pattern into placeholder and literal parts
                // This ensures literal segments are properly escaped (e.g., dots in /v1.0/)
                $parts = preg_split('/(\{[^}]+\})/', $route->pattern, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
                $regexParts = [];

                foreach ($parts ?: [] as $part) {
                    if (preg_match('/^\{(\w+)(?::(\w+))?\}$/', $part, $matches)) {
                        // Placeholder - convert to regex capture group
                        $name = $matches[1];
                        $type = $matches[2] ?? null;
                        $segmentPattern = '[^/]+'; // Default

                        if ($type !== null && isset($this->patterns[$type])) {
                            $segmentPattern = $this->patterns[$type];

                            // Register cast for int, float, bool
                            if (in_array($type, ['int', 'float', 'bool'], true)) {
                                $casts[$name] = $type;
                            }
                        }

                        $regexParts[] = "(?P<{$name}>{$segmentPattern})";
                    } else {
                        // Literal segment - escape regex special chars
                        $regexParts[] = preg_quote($part, '#');
                    }
                }

                $regex = implode('', $regexParts);

                $dynamicRoutes[$method][] = [
                    'regex' => '#^' . $regex . '$#',
                    'route' => $route,
                    'casts' => $casts,
                ];
            }
        }

        return [$staticRoutes, $dynamicRoutes];
    }
}
