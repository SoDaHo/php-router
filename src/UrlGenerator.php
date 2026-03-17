<?php

declare(strict_types=1);

namespace Sodaho\Router;

use Sodaho\Router\Exception\RouteNotFoundException;
use Sodaho\Router\Exception\RouterException;

/**
 * Generates URLs from named routes.
 */
final class UrlGenerator
{
    /** @var array<string, string> Named routes: name => pattern */
    private array $namedRoutes = [];

    private string $basePath = '';
    private ?string $baseUrl = null;
    private bool $encodeParams = true;

    /**
     * Create a new UrlGenerator instance.
     *
     * @param array<Route>|array<string, string> $routes Route objects or name => pattern mapping
     */
    public function __construct(array $routes = [])
    {
        foreach ($routes as $key => $value) {
            if ($value instanceof Route) {
                // Route object: extract name and pattern
                if ($value->name !== null) {
                    $this->namedRoutes[$value->name] = $value->pattern;
                }
            } else {
                // Pattern mapping from cache: name => pattern
                $this->namedRoutes[$key] = $value;
            }
        }
    }

    /**
     * Set the base path prefix for all generated URLs.
     *
     * @param string $basePath Base path prefix (e.g., '/api/v1')
     */
    public function setBasePath(string $basePath): void
    {
        $this->basePath = rtrim($basePath, '/');
    }

    /**
     * Set the base URL for absolute URL generation.
     *
     * @param string|null $baseUrl Base URL (e.g., 'https://example.com')
     */
    public function setBaseUrl(?string $baseUrl): void
    {
        $this->baseUrl = $baseUrl !== null ? rtrim($baseUrl, '/') : null;
    }

    /**
     * Enable or disable URL encoding for route parameters.
     *
     * When enabled (default), parameters are encoded using rawurlencode().
     * Example: 'John Doe' becomes 'John%20Doe'
     *
     * @param bool $encode Enable URL encoding
     */
    public function setEncodeParams(bool $encode): void
    {
        $this->encodeParams = $encode;
    }

    /**
     * Generate a relative URL for a named route.
     *
     * @param string $name Route name
     * @param array<string, string|int> $params Route parameters
     *
     * @throws RouteNotFoundException If route name does not exist
     *
     * @return string Generated URL
     */
    public function url(string $name, array $params = []): string
    {
        $pattern = $this->getPatternByName($name);
        $url = $this->replaceParameters($pattern, $params);

        return $this->basePath . $url;
    }

    /**
     * Generate an absolute URL for a named route.
     *
     * @param string $name Route name
     * @param array<string, string|int> $params Route parameters
     *
     * @throws RouteNotFoundException If route name does not exist
     * @throws RouterException If baseUrl is not configured
     *
     * @return string Generated absolute URL
     */
    public function absoluteUrl(string $name, array $params = []): string
    {
        if ($this->baseUrl === null) {
            throw new RouterException(
                'Cannot generate absolute URL: baseUrl is not configured. Set APP_URL environment variable or use setBaseUrl().'
            );
        }

        return $this->baseUrl . $this->url($name, $params);
    }

    /**
     * Check if a named route exists.
     *
     * @param string $name Route name
     */
    public function hasRoute(string $name): bool
    {
        return isset($this->namedRoutes[$name]);
    }

    /**
     * @throws RouteNotFoundException If route name does not exist
     */
    private function getPatternByName(string $name): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new RouteNotFoundException(
                sprintf('Route "%s" not found', $name),
                0,
                null,
                sprintf('Available routes: %s', implode(', ', array_keys($this->namedRoutes)) ?: 'none'),
            );
        }

        return $this->namedRoutes[$name];
    }

    /**
     * @param array<string, string|int> $params
     */
    private function replaceParameters(string $pattern, array $params): string
    {
        // Optional segments [/suffix] are not supported - fail fast instead of silent misbehavior
        if (str_contains($pattern, '[') || str_contains($pattern, ']')) {
            throw new RouterException(
                sprintf(
                    'Optional segments [] are not supported in pattern "%s". Define separate routes instead.',
                    $pattern
                )
            );
        }

        // Replace parameters: {name} or {name:constraint}
        $url = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^}]+)?\}/',
            function (array $matches) use ($params): string {
                $name = $matches[1];

                if (!isset($params[$name])) {
                    throw new RouterException(
                        sprintf('Missing parameter "%s" for URL generation', $name)
                    );
                }

                $value = (string) $params[$name];

                return $this->encodeParams ? rawurlencode($value) : $value;
            },
            $pattern
        );

        // preg_replace_callback returns null only on error, which won't happen with valid pattern
        return $url ?? $pattern;
    }
}
