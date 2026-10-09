<?php

declare(strict_types=1);

namespace Sodaho\Router;

use Sodaho\Router\Exception\RouterException;

/**
 * Low-level route matching.
 *
 * Matches request method and URI against compiled route data. It takes the path as it is
 * given, decoded and unchecked: what RouteDispatcher refuses before it asks (a hidden
 * separator, a control character, a path outside the base path) is not refused here —
 * '/u/a' . "\n" finds the route '/u/{id}'. Ask Router::match() for what the router does.
 */
final class Dispatcher
{
    /** Route not found */
    public const NOT_FOUND = 0;

    /** Route found */
    public const FOUND = 1;

    /** Route found but method not allowed */
    public const METHOD_NOT_ALLOWED = 2;

    /**
     * Create a new Dispatcher instance.
     *
     * @param array<string, array<string, Route>> $staticRoutes Static routes by method and pattern
     * @param array<string, array<int, array{regex: string, route: Route, casts: array<string, string>}>> $dynamicRoutes Dynamic routes by method
     */
    public function __construct(
        private readonly array $staticRoutes,
        private readonly array $dynamicRoutes
    ) {
    }

    /**
     * Match a request method and URI to a route.
     *
     * @param string $method HTTP method
     * @param string $uri Request URI
     *
     * @throws RouterException When PCRE gives up on a route's expression (see matches())
     *
     * @return array{0: int, 1: mixed, 2: array<string, string>, 3: array<string, string>} [Status, Route|AllowedMethods, Params, Casts]
     */
    public function dispatch(string $method, string $uri): array
    {
        // 1. Static Route Check (O(1) - Ultra Fast)
        if (isset($this->staticRoutes[$method][$uri])) {
            return [self::FOUND, $this->staticRoutes[$method][$uri], [], []];
        }

        // 2. Dynamic Route Check (Regex Loop)
        $route = $this->dispatchDynamic($method, $uri);
        if ($route !== null) {
            return $route;
        }

        // 3. Method Not Allowed Check
        $allowedMethods = $this->getAllowedMethods($uri);
        if (!empty($allowedMethods)) {
            return [self::METHOD_NOT_ALLOWED, $allowedMethods, [], []];
        }

        return [self::NOT_FOUND, null, [], []];
    }

    /**
     * @return array{0: int, 1: Route, 2: array<string, string>, 3: array<string, string>}|null
     */
    private function dispatchDynamic(string $method, string $uri): ?array
    {
        if (!isset($this->dynamicRoutes[$method])) {
            return null;
        }

        foreach ($this->dynamicRoutes[$method] as $data) {
            if (self::matches($data, $uri, $matches)) {
                // Filter numeric keys from matches (we only want named params)
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                return [
                    self::FOUND,
                    $data['route'],
                    $params,
                    $data['casts'],
                ];
            }
        }

        return null;
    }

    /**
     * Every method a route is registered with for this URI.
     *
     * Methods of static routes first, then those of dynamic routes; within each kind in the
     * order in which a method was first registered among all routes of that kind (not only
     * those of this URI).
     *
     * @param string $uri Request URI
     *
     * @throws RouterException When PCRE gives up on a route's expression (see matches())
     *
     * @return list<string>
     */
    public function allowedMethods(string $uri): array
    {
        return $this->getAllowedMethods($uri);
    }

    /** @return list<string> */
    private function getAllowedMethods(string $uri): array
    {
        $allowed = [];

        // Check static routes
        foreach ($this->staticRoutes as $method => $routes) {
            if (isset($routes[$uri])) {
                $allowed[] = $method;
            }
        }

        // Check dynamic routes
        foreach ($this->dynamicRoutes as $method => $routes) {
            foreach ($routes as $data) {
                if (self::matches($data, $uri)) {
                    $allowed[] = $method;
                    break; // Once found for a method, skip to next method
                }
            }
        }

        // array_values(): array_unique() keeps keys, and a list with gaps is a JSON object in the 405 body.
        return array_values(array_unique($allowed));
    }

    /**
     * Whether a route's expression matches the path. PCRE may give up on an expression
     * instead of answering (the backtrack limit, the JIT stack — a pattern of your own with
     * nested quantifiers): that is no "no match", which would hand the request to the next
     * route that matches (a catch-all) or leave a method out of the 405 list without a
     * word. It is thrown.
     *
     * @param array{regex: string, route: Route, casts: array<string, string>} $data
     * @param array<int|string, string>|null $matches
     *
     * @param-out array<int|string, string> $matches
     *
     * @throws RouterException When PCRE gives up on the expression
     */
    private static function matches(array $data, string $uri, ?array &$matches = null): bool
    {
        $result = preg_match($data['regex'], $uri, $matches);

        if ($result === false) {
            throw new RouterException(
                'Route pattern could not be matched: ' . preg_last_error_msg(),
                debugMessage: $data['route']->pattern,
            );
        }

        return $result === 1;
    }
}
