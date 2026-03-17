<?php

declare(strict_types=1);

namespace Sodaho\Router;

/**
 * Low-level route matching.
 *
 * Matches request method and URI against compiled route data.
 */
class Dispatcher
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
            if (preg_match($data['regex'], $uri, $matches)) {
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

    /** @return string[] */
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
                if (preg_match($data['regex'], $uri)) {
                    $allowed[] = $method;
                    break; // Once found for a method, skip to next method
                }
            }
        }

        return array_unique($allowed);
    }
}
