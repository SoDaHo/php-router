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
    private bool $ignoreTrailingSlash = false;

    /** @var array<string, list<array{literal: string, name: string|null, type: string|null}>> Patterns taken apart, once each */
    private array $parsed = [];

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
        $this->patterns = $patterns ?? new RouteCollector()->getPatterns();

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
     * Tell the generator that the router runs in the trailing slash mode 'ignore': it drops
     * the slashes at the end of a request path before it looks the route up, so a value
     * that ends in a slash (or is empty at the end of the path) does not come back.
     *
     * @param bool $ignore Whether slashes at the end of a path are ignored
     */
    public function setIgnoreTrailingSlash(bool $ignore): void
    {
        $this->ignoreTrailingSlash = $ignore;
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
        $address = ($this->encodeParams ? self::encodeLiteral($this->basePath) : $this->basePath)
            . $this->replaceParameters($name, $pattern, $params);

        // What goes out has the form of a path on this site: one slash in front and no
        // backslash (a client reads it as a slash). Parameter values cannot break that
        // form, they are encoded, and so is the literal text of route pattern and base
        // path — all but the backslash, which no request path may contain in any form.
        if ($this->encodeParams && preg_match('#\A/(?!/)[^\\\\]*\z#', $address) !== 1) {
            throw new RouterException(
                'The address would not be a path on this site: it has to begin with a single "/" and contain no backslash',
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
                'Cannot generate absolute URL: baseUrl is not configured. Pass \'baseUrl\' in the config, call setBaseUrl(), or create the router with Router::fromEnv() and APP_URL.'
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
     * The path of a route with the parameter values in place.
     *
     * With URL encoding on, the address is only returned when it leads back to its route
     * with exactly these values: the path as the router would see it — values in place, not
     * yet encoded — has to match the route's own regular expression, each placeholder
     * capturing its value. That refuses a value that does not fit its placeholder ('12a'
     * for {id:int}, an empty one, a slash where one segment is expected) and values that
     * the pattern would split differently among its placeholders. A slash that the
     * placeholder takes ({path:any}) stays a slash; each segment is encoded on its own —
     * the router refuses %2F.
     *
     * @param array<string, string|int|float|bool|null> $params
     *
     * @throws RouterException When a parameter is missing or null, or the address would not reach the route
     */
    private function replaceParameters(string $name, string $pattern, array $params): string
    {
        // Optional segments [/suffix] are not supported - fail fast instead of silent misbehavior
        if (str_contains($pattern, '[') || str_contains($pattern, ']')) {
            throw new RouterException(
                'Optional segments [] are not supported in a route pattern. Define separate routes instead.',
                debugMessage: $pattern,
            );
        }

        $parts = $this->parsed[$pattern] ??= RouteCollector::parts($pattern);
        $values = [];

        foreach ($parts as $part) {
            $parameter = $part['name'];

            if ($parameter === null) {
                continue;
            }

            if (!array_key_exists($parameter, $params)) {
                throw new RouterException(sprintf('Missing parameter "%s" for URL generation', $parameter));
            }
            if ($params[$parameter] === null) {
                throw new RouterException(sprintf('Parameter "%s" for URL generation is null', $parameter));
            }

            $value = $params[$parameter];
            if (is_float($value) && !is_finite($value)) {
                // PHP would warn and write NAN or INF
                throw new RouterException(sprintf('Parameter "%s" is not a finite number', $parameter));
            }
            $values[$parameter] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }

        // The path as the router sees it after decoding
        $candidate = self::fill($parts, $values);

        if (!$this->encodeParams) {
            // The application encodes itself — and answers for what it writes
            return $candidate;
        }

        foreach ($values as $parameter => $value) {
            if (str_contains($value, '\\')) {
                throw new RouterException(
                    sprintf('Parameter "%s" contains a backslash, which no route accepts', $parameter),
                    debugMessage: $value,
                );
            }

            // Encoded it would be %0A and the like — a path the router answers with 404
            if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                throw new RouterException(
                    sprintf('Parameter "%s" contains a control character, which no route accepts', $parameter),
                    debugMessage: $value,
                );
            }
        }

        if ($values !== []) {
            // A generator built without the collector's patterns does not know a type of
            // the application's own — the router's table would have refused the route
            foreach ($parts as $part) {
                if ($part['type'] !== null && !isset($this->patterns[$part['type']])) {
                    throw new RouterException(
                        sprintf('The URL generator does not know the pattern type "%s": pass the patterns of the route collector (getPatterns()) to its constructor', $part['type']),
                        debugMessage: $pattern,
                    );
                }
            }

            [$regex, $casts] = RouteCollector::compile($pattern, $this->patterns);

            // … and, in the mode 'ignore', without the slashes at its end
            $seen = $this->ignoreTrailingSlash && $candidate !== '/' ? rtrim($candidate, '/') : $candidate;

            if (preg_match($regex, $seen, $captured) !== 1 || array_intersect_key($captured, $values) !== $values) {
                throw new RouterException(
                    sprintf('The parameters do not fit the pattern of route "%s": the address would not lead back to it', $name),
                    debugMessage: $candidate,
                );
            }

            // … and a value the pattern takes may still be one the router does not hand
            // on: '01' for an integer, a number too large for one (400 for the request)
            foreach ($casts as $parameter => $type) {
                try {
                    RouteDispatcher::castValue($type, $values[$parameter], $parameter);
                } catch (\TypeError) {
                    throw new RouterException(
                        sprintf('The parameters do not fit the pattern of route "%s": the address would not lead back to it', $name),
                        debugMessage: $candidate,
                    );
                }
            }
        }

        // Literal text is encoded like the values: the route '/a b/{x}' is asked for as
        // '/a%20b/…', '/100%' as '/100%25'
        $url = self::fill(
            array_map(
                static fn (array $part): array => ['literal' => self::encodeLiteral($part['literal'])] + $part,
                $parts,
            ),
            array_map(
                static fn (string $value): string => implode('/', array_map(rawurlencode(...), explode('/', $value))),
                $values,
            ),
        );

        // A '.' or '..' segment never arrives: a client resolves it before it asks (the
        // encoded forms too). Looked for in the finished address — '/dl/{name}.json' with
        // the value '..' has none, '/x/{a}.' with a value that the pattern lets be '.' has one.
        if (preg_match('#(?:^|/)\.\.?(?:/|$)#D', $url) === 1) {
            throw new RouterException(
                'The address would contain a "." or ".." path segment, which a client resolves before it asks',
                debugMessage: $url,
            );
        }

        // Nor does one that begins with '//': a client reads what follows as another host.
        // ('/{path:any}' with the value '/evil.example/x' — 1.x wrote '/%2Fevil.example%2Fx'.)
        if (str_starts_with($url, '//')) {
            throw new RouterException(
                'The address would begin with "//", which a client reads as another host',
                debugMessage: $url,
            );
        }

        return $url;
    }

    /**
     * Literal text of a route pattern or base path as it stands in an address: what a
     * path may contain as it is stays (also ':', '@' and the sub-delimiters, so that
     * '/v1:batch' and '/@{user}' read as before), everything else is percent-encoded. The
     * backslash is left for url() to refuse.
     */
    private static function encodeLiteral(string $literal): string
    {
        return (string) preg_replace_callback(
            '/[^A-Za-z0-9\-._~!$&\'()*+,;=:@\/\\\\]/',
            static fn (array $match): string => rawurlencode($match[0]),
            $literal,
        );
    }

    /**
     * @param list<array{literal: string, name: string|null, type: string|null}> $parts
     * @param array<string, string> $values
     */
    private static function fill(array $parts, array $values): string
    {
        return implode('', array_map(
            static fn (array $part): string => $part['name'] === null ? $part['literal'] : $values[$part['name']],
            $parts,
        ));
    }
}
