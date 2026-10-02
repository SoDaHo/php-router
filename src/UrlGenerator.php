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

    /** @var array<string, string> Pattern shortcut => regular expression fragment */
    private array $patterns;

    /**
     * Create a new UrlGenerator instance.
     *
     * @param array<Route>|array<string, string> $routes Route objects or name => pattern mapping
     * @param array<string, string>|null $patterns The collector's pattern shortcuts (RouteCollector::getPatterns());
     *                                             null: the built-in ones
     */
    public function __construct(array $routes = [], ?array $patterns = null)
    {
        $this->patterns = $patterns ?? (new RouteCollector())->getPatterns();

        foreach ($routes as $key => $value) {
            if ($value instanceof Route) {
                // Route object: extract name and pattern
                if ($value->name !== null) {
                    $this->namedRoutes[$value->name] = $value->pattern;
                }
            } else {
                // Pattern mapping: name => pattern
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
     * @param array<string, string|int|float|bool> $params Route parameters
     *
     * @throws RouteNotFoundException If route name does not exist
     *
     * @return string Generated URL
     */
    public function url(string $name, array $params = []): string
    {
        $pattern = $this->getPatternByName($name);
        $address = $this->basePath . $this->replaceParameters($pattern, $params);

        // What goes out has the form of a path on this site: one slash in front, no
        // backslash (a client reads it as a slash), no control character (a client drops
        // tab and line breaks before it reads the address — '/<tab>/host' is '//host').
        // Parameter values cannot break that form, they are encoded; a route pattern or a
        // base path could.
        if ($this->encodeParams && preg_match('#\A/(?!/)[^\\\\\x00-\x1F\x7F]*\z#', $address) !== 1) {
            throw new RouterException(
                'The address would not be a path on this site: it has to begin with a single "/" and contain no backslash or control character',
                debugMessage: $address,
            );
        }

        return $address;
    }

    /**
     * Generate an absolute URL for a named route.
     *
     * @param string $name Route name
     * @param array<string, string|int|float|bool> $params Route parameters
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
                'Cannot generate absolute URL: baseUrl is not configured. Pass \'baseUrl\' in the config or create the router with Router::fromEnv() and APP_URL.'
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
     * @param array<string, string|int|float|bool> $params
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
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}/',
            function (array $matches) use ($params): string {
                $name = $matches[1];

                if (!isset($params[$name]) && !array_key_exists($name, $params)) {
                    throw new RouterException(
                        sprintf('Missing parameter "%s" for URL generation', $name)
                    );
                }

                $rawValue = $params[$name];
                $value = is_bool($rawValue) ? ($rawValue ? '1' : '0') : (string) $rawValue;

                return $this->encodeParams ? $this->encode($name, $value, $matches[2] ?? null) : $value;
            },
            $pattern
        );

        // preg_replace_callback returns null only on error, which won't happen with valid pattern
        $url ??= $pattern;

        // A '.' or '..' segment never arrives: a client resolves it before it asks (the
        // encoded forms too). Looked for in the finished address — '/dl/{name}.json' with
        // the value '..' has none, '/x/{a}.' with an empty value has one.
        if ($this->encodeParams && preg_match('#(?:^|/)\.\.?(?:/|$)#D', $url) === 1) {
            throw new RouterException(
                'The address would contain a "." or ".." path segment, which a client resolves before it asks',
                debugMessage: $url,
            );
        }

        // Nor does one that begins with '//': a client reads what follows as another host.
        // ('/{path:any}' with the value '/evil.example/x' — 1.x wrote '/%2Fevil.example%2Fx'.)
        if ($this->encodeParams && str_starts_with($url, '//')) {
            throw new RouterException(
                'The address would begin with "//", which a client reads as another host',
                debugMessage: $url,
            );
        }

        return $url;
    }

    /**
     * A parameter value as it goes into the path.
     *
     * The router refuses requests with an encoded separator (%2F, %5C), so a slash is never
     * written as %2F: where the placeholder takes several segments ({path:any}) the slashes
     * stay and each segment is encoded on its own; everywhere else a value with a slash
     * has no address. Neither has a value with a backslash. ('.' and '..' segments are
     * looked for in the finished address, see replaceParameters().)
     *
     * @throws RouterException When the value cannot be part of a path that reaches the route
     */
    private function encode(string $name, string $value, ?string $type): string
    {
        if (str_contains($value, '\\')) {
            throw new RouterException(
                sprintf('Parameter "%s" contains a backslash, which no route accepts', $name),
                debugMessage: $value,
            );
        }

        $segments = explode('/', $value);

        if (count($segments) > 1) {
            $fragment = $this->patterns[$type ?? ''] ?? '[^/]+';

            // The fragment is asked on its own. One that does not stand on its own — it
            // looks at the text around it or refers to another placeholder — does not
            // compile or does not match here; that is a refusal like any other, whatever
            // the application's error handler makes of a warning. (The handler is swapped
            // for the length of this one call; the swap goes away when the finished
            // address is checked against the whole route instead.)
            set_error_handler(static fn (): bool => true);
            try {
                $fits = preg_match('#\A(?:' . $fragment . ')\z#', $value) === 1;
            } finally {
                restore_error_handler();
            }

            if (!$fits) {
                throw new RouterException(
                    sprintf('Parameter "%s" contains a slash, which its placeholder does not accept', $name),
                    debugMessage: $value,
                );
            }
        }

        return implode('/', array_map(rawurlencode(...), $segments));
    }
}
