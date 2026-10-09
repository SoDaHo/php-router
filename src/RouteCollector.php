<?php

declare(strict_types=1);

namespace Sodaho\Router;

use Sodaho\Router\Exception\DuplicateRouteException;
use Sodaho\Router\Exception\RouterException;

/**
 * Collects route definitions and compiles them for the Dispatcher.
 */
final class RouteCollector
{
    /** @var Route[] */
    private array $routes = [];

    /** @var string Current group prefix */
    private string $currentPrefix = '';

    /** @var array<int, string|object> Current group middleware stack */
    private array $currentMiddleware = [];

    /** @var array<string, mixed> Attributes of the groups a route is being registered in */
    private array $currentAttributes = [];

    /** @var array<string, true> Registered method+pattern combinations for duplicate detection */
    private array $registeredRoutes = [];

    /** @var bool Whether to preserve trailing slashes in route patterns (for strict mode) */
    private bool $preserveTrailingSlash = false;

    /**
     * What a route pattern and a base path must not contain. Both are compared with the
     * request path after it was decoded, so they are written decoded — and they are
     * paths: '?' and '#' end one, a client resolves dot segments before it asks, and no
     * request with a backslash has a route.
     *
     * @internal
     */
    public const NOT_A_PLAIN_PATH = '~\\\\|[\x00-\x1F\x7F]|%[0-9a-f]{2}|[?#]|(?:^|/)\.\.?(?:/|$)~i';

    /** The characters NOT_A_PLAIN_PATH is about, for a cheap first look (the dot of a dot segment aside) */
    private const PLAIN_PATH_SUSPECTS = "\\%?#\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    /** @internal */
    public const PLAIN_PATH_RULE = 'must be a plain path, written decoded: no backslash, control character, percent-encoded character (%20), "?", "#" or dot segment';

    /**
     * How a pattern is read, for parts() and compile() alike: anything in braces, and what
     * a placeholder looks like. Spelled out instead of \w, which takes bytes beyond ASCII
     * for letters under some locales.
     */
    private const SPLIT = '/(\{[^}]+\})/';
    private const PLACEHOLDER = '/^\{([A-Za-z0-9_]+)(?::([A-Za-z0-9_]+))?\}$/';

    /** Regex shortcuts for route parameters */
    private const BUILT_IN = [
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

    /** @var array<string, string> The built-in shortcuts and those added with addPattern() */
    private array $patterns = self::BUILT_IN;

    /**
     * Add a custom pattern shortcut.
     *
     * @param string $name Pattern name (e.g., 'date')
     * @param string $regex Regex pattern (e.g., '\d{4}-\d{2}-\d{2}')
     */
    public function addPattern(string $name, string $regex): self
    {
        // {id:name} takes word characters as a name; any other name could never be used
        if (preg_match('/^[A-Za-z0-9_]+$/D', $name) !== 1) {
            throw new RouterException('Pattern name must consist of ASCII letters, digits and underscores', debugMessage: $name);
        }

        // '#' is the delimiter the route table is compiled with. Unescaped it would end the
        // expression there — for every route that uses the pattern, with a warning per
        // request and no match, ever. (Whether the fragment compiles shows when the table
        // is built: it may refer to another placeholder of its route.)
        if (preg_match('/(?<!\\\\)(?:\\\\\\\\)*#/', $regex) === 1) {
            throw new RouterException("Pattern fragment must not contain an unescaped '#' (write \\#)", debugMessage: $name);
        }

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

        try {
            $callback($this);
        } finally {
            $this->currentPrefix = $previousPrefix;
        }
    }

    /**
     * Group routes with common middleware — and, if given, common attributes: the same as
     * an attributeGroup() around the middlewareGroup(), with the same rules (nested groups
     * add up, the inner group wins per key, Route::attribute() wins over every group).
     *
     * @param string|array<string|object>|object $middleware Middleware class name(s) or instance(s)
     * @param callable $callback Receives RouteCollector instance
     * @param array<string, mixed> $attributes Attribute name => value
     */
    public function middlewareGroup(string|array|object $middleware, callable $callback, array $attributes = []): void
    {
        if ($attributes !== []) {
            $this->attributeGroup($attributes, fn (self $r) => $r->middlewareGroup($middleware, $callback));

            return;
        }

        // A list, whatever keys the array came with: nested groups add up, also under the same key
        $middleware = is_array($middleware) ? array_values($middleware) : [$middleware];

        $previousMiddleware = $this->currentMiddleware;
        $this->currentMiddleware = [...$this->currentMiddleware, ...$middleware];

        try {
            $callback($this);
        } finally {
            $this->currentMiddleware = $previousMiddleware;
        }
    }

    /**
     * Group routes with common attributes.
     *
     * Nested groups add up; for the same key the inner group wins, and Route::attribute()
     * wins over every group.
     *
     * @param array<string, mixed> $attributes Attribute name => value
     * @param callable $callback Receives RouteCollector instance
     */
    public function attributeGroup(array $attributes, callable $callback): void
    {
        $previousAttributes = $this->currentAttributes;
        $this->currentAttributes = array_merge($this->currentAttributes, $attributes);

        try {
            $callback($this);
        } finally {
            $this->currentAttributes = $previousAttributes;
        }
    }

    // ==================== Redirect Routes ====================

    /**
     * Register a redirect route.
     *
     * Uses RedirectHandler instead of a Closure.
     *
     * @param string $from Source URL pattern
     * @param string $to Target URL
     * @param int $status HTTP status code (default: 302)
     *
     * @throws DuplicateRouteException If route already exists
     */
    public function redirect(string $from, string $to, int $status = 302): Route
    {
        // A placeholder in the target that the source (with the prefix of its groups) does
        // not have would go out as it stands. Said before the route is registered.
        $known = array_column(self::parts($this->currentPrefix . '/' . $from), 'name');
        preg_match_all('/\{([A-Za-z0-9_]+)\}/', $to, $wanted);

        // … and so would one with a type: the target takes {name}, nothing else in braces
        if (strpbrk((string) preg_replace('/\{[A-Za-z0-9_]+\}/', '', $to), '{}') !== false) {
            throw new RouterException(
                'Redirect target has a placeholder that is not of the form {name}',
                debugMessage: $to,
            );
        }
        $unknown = array_diff($wanted[1], $known);
        if ($unknown !== []) {
            throw new RouterException(
                'Redirect target has a placeholder that its source pattern does not have',
                debugMessage: sprintf('{%s} in %s', implode('}, {', $unknown), $to),
            );
        }

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
            $trimmed = trim($pattern);

            // Blanks at the edges are trimmed in this mode. A line break or tab is no
            // blank that may vanish unseen by the checks below.
            if ($trimmed !== $pattern && preg_match('/[\x00-\x1F\x7F]/', $pattern) === 1) {
                throw new RouterException('Route pattern ' . self::PLAIN_PATH_RULE, debugMessage: $pattern);
            }

            $normalizedPattern = '/' . ltrim($trimmed, '/');
            $path = $prefix . $normalizedPattern;
            $path = '/' . ltrim($path, '/');
        } else {
            // Ignore mode: normalize everything (current behavior)
            $path = '/' . trim($this->currentPrefix . '/' . trim($pattern, '/'), '/');
        }

        self::assertPattern($path);

        // Check for duplicate routes
        foreach ($methods as $method) {
            $key = $method . ':' . $path;
            if (isset($this->registeredRoutes[$key])) {
                throw new DuplicateRouteException(
                    'Route is already registered for this method',
                    debugMessage: sprintf('%s %s', $method, $path),
                );
            }
            $this->registeredRoutes[$key] = true;
        }

        $route = new Route($methods, $path, $handler, attributes: $this->currentAttributes);

        if (!empty($this->currentMiddleware)) {
            $route->middleware($this->currentMiddleware);
        }

        $this->routes[] = $route;
        return $route;
    }

    /**
     * What can be said about a route pattern as soon as it is written — before a request
     * finds out the hard way. Cheap: this runs for every route of every request, the
     * table is built anew each time.
     *
     * @throws RouterException When no request could match the pattern, or when it would match by accident
     */
    private static function assertPattern(string $pattern): void
    {
        // One look for everything the checks below are about. Most patterns have none of
        // it and are done — a static route has nothing else to be checked for.
        if (strpbrk($pattern, '{}[]' . self::PLAIN_PATH_SUSPECTS) === false) {
            if (str_contains($pattern, '/.') || $pattern[0] === '.') {
                self::assertPlain($pattern);
            }

            return;
        }

        if (strpbrk($pattern, '{}[]') === false) {
            self::assertPlain($pattern);

            return;
        }

        // Every brace belongs to a placeholder {name} or {name:type} — or one of them is
        // something else, most often a regular expression written into the placeholder
        // ('{id:\d+}', '{id:[0-9]+}'), which would be read as literal text
        $placeholders = preg_match_all('/\{([A-Za-z0-9_]+)(?::[A-Za-z0-9_]+)?\}/', $pattern, $found);

        if ($placeholders !== substr_count($pattern, '{') || $placeholders !== substr_count($pattern, '}')) {
            throw new RouterException(
                'Route pattern has a placeholder that is not of the form {name} or {name:type}. A regular expression of your own goes into addPattern().',
                debugMessage: $pattern,
            );
        }

        // Optional segments [/suffix] are not supported: such a pattern would be read as literal text
        if (strpbrk($pattern, '[]') !== false) {
            throw new RouterException(
                'Optional segments [] are not supported in a route pattern. Define separate routes instead.',
                debugMessage: $pattern,
            );
        }

        self::assertPlain($pattern);

        $names = [];
        foreach ($found[1] as $name) {
            // A name the regular expression can carry — older PCRE versions refuse more
            // than 32 characters (10.36 does, 10.44 does not) — and a handler parameter
            // can have
            if (strlen($name) > 32 || ($name[0] >= '0' && $name[0] <= '9') || $name === '_route_params') {
                throw new RouterException(
                    'Placeholder name must begin with an ASCII letter or underscore, have at most 32 characters and not be _route_params',
                    debugMessage: sprintf('{%s} in %s', $name, $pattern),
                );
            }

            if (isset($names[$name])) {
                throw new RouterException(
                    sprintf('Placeholder "%s" is used twice in one route pattern', $name),
                    debugMessage: $pattern,
                );
            }
            $names[$name] = true;
        }
    }

    /**
     * @throws RouterException When the pattern is not a plain path (see NOT_A_PLAIN_PATH)
     */
    private static function assertPlain(string $pattern): void
    {
        // Most patterns have none of the characters the rule is about: one cheap look
        if (strpbrk($pattern, self::PLAIN_PATH_SUSPECTS) === false && !str_contains($pattern, '/.') && $pattern[0] !== '.') {
            return;
        }

        if (preg_match(self::NOT_A_PLAIN_PATH, $pattern) === 1) {
            throw new RouterException('Route pattern ' . self::PLAIN_PATH_RULE, debugMessage: $pattern);
        }
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
     * The pattern shortcuts: the built-in ones and those added with addPattern().
     *
     * @return array<string, string> name => regular expression fragment
     */
    public function getPatterns(): array
    {
        return $this->patterns;
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
                [$regex, $casts, $own] = self::compile($route->pattern, $this->patterns);

                // The built-in patterns compile; only a route with a pattern of its own is
                // tried out. (A handler that turns the warning into an exception gets
                // there first — loud either way.)
                if ($own && @preg_match($regex, '') === false) {
                    throw new RouterException(
                        'Route pattern does not compile with its own pattern types',
                        debugMessage: sprintf('%s: %s', $route->pattern, preg_last_error_msg()),
                    );
                }

                if ($own) {
                    $this->assertOwnFragments($route->pattern);
                }

                $dynamicRoutes[$method][] = [
                    'regex' => $regex,
                    'route' => $route,
                    'casts' => $casts,
                ];
            }
        }

        return [$staticRoutes, $dynamicRoutes];
    }

    /**
     * Each fragment of addPattern() that a route uses is a regular expression of its own.
     * The route's expression wraps it in its group, '(?P<v>' . $fragment . ')', and with
     * that a fragment that closes the group early still compiles: 'a)|(.*' turns the rest
     * of the expression into an alternative that matches any path — of every dynamic route
     * registered after it. So each fragment is compiled once more on its own, behind an
     * empty group for every placeholder of the route (its own one included): a fragment may
     * refer to them ('(?P=a)', '(?P>v)'), nothing else. This comes on top of compiling the
     * whole expression: '+\d' compiles behind an empty group, not in its place.
     *
     * @throws RouterException When a fragment compiles only together with the group around it
     */
    private function assertOwnFragments(string $pattern): void
    {
        $parts = self::parts($pattern);

        $groups = '';
        foreach ($parts as $part) {
            if ($part['name'] !== null) {
                $groups .= '(?P<' . $part['name'] . '>)';
            }
        }

        foreach ($parts as $part) {
            $type = $part['type'];
            if ($type === null || $this->patterns[$type] === (self::BUILT_IN[$type] ?? null)) {
                continue;
            }

            if (@preg_match('#' . $groups . $this->patterns[$type] . '#', '') === false) {
                throw new RouterException(
                    'Route pattern uses a pattern type that is no regular expression of its own (its parentheses do not pair up)',
                    debugMessage: sprintf('%s: {%s:%s} is %s', $pattern, $part['name'], $type, $this->patterns[$type]),
                );
            }
        }
    }

    /**
     * A route pattern taken apart: literal text and placeholders, in the order in which
     * they stand. One place for the table and for url(), so that both read a pattern alike.
     *
     * @internal
     *
     * @return list<array{literal: string, name: string|null, type: string|null}> A placeholder has a name
     *                                                                            (and '' as literal), literal text has none
     */
    public static function parts(string $pattern): array
    {
        $parts = [];

        // Split pattern into placeholder and literal parts
        foreach (preg_split(self::SPLIT, $pattern, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            $parts[] = preg_match(self::PLACEHOLDER, $part, $matches) === 1
                ? ['literal' => '', 'name' => $matches[1], 'type' => $matches[2] ?? null]
                : ['literal' => $part, 'name' => null, 'type' => null];
        }

        return $parts;
    }

    /**
     * The regular expression a pattern with placeholders is matched with, the casts of its
     * placeholders, and whether it uses a pattern type that is not one of the built-in ones.
     *
     * @internal
     *
     * @param array<string, string> $patterns Pattern shortcut => regular expression fragment
     *
     * @throws RouterException When the pattern uses a type that is not defined
     *
     * @return array{0: string, 1: array<string, string>, 2: bool}
     */
    public static function compile(string $pattern, array $patterns): array
    {
        $casts = [];
        $regex = '';
        $own = false;

        // The same split as parts(), without the array in between: this runs for every
        // dynamic route of every request
        foreach (preg_split(self::SPLIT, $pattern, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if ($part[0] !== '{' || preg_match(self::PLACEHOLDER, $part, $matches) !== 1) {
                // Literal text — escaped, so that the dot of /v1.0/ is a dot
                $regex .= preg_quote($part, '#');

                continue;
            }

            $name = $matches[1];
            $type = $matches[2] ?? null;

            if ($type === null) {
                $regex .= '(?P<' . $name . '>[^/]+)';

                continue;
            }

            // {id:integer} would silently be "one segment". Said here and not where the
            // route is written: patterns may be added after the routes that use them.
            if (!isset($patterns[$type])) {
                throw new RouterException(
                    'Route pattern uses a pattern type that is not defined. Built in: ' . implode(', ', array_keys(self::BUILT_IN)),
                    debugMessage: sprintf('{%s:%s} in %s', $name, $type, $pattern),
                );
            }

            // Register cast for int, float, bool
            if ($type === 'int' || $type === 'float' || $type === 'bool') {
                $casts[$name] = $type;
            }

            $own = $own || $patterns[$type] !== (self::BUILT_IN[$type] ?? null);
            $regex .= '(?P<' . $name . '>' . $patterns[$type] . ')';
        }

        // \z, not $: $ also matches before a trailing newline, so '/users/5%0A' would hit '/users/{id:int}'
        return ['#^' . $regex . '\z#', $casts, $own];
    }
}
