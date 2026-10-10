<?php

declare(strict_types=1);

namespace Sodaho\Router;

use Sodaho\Router\Exception\RouteNotFoundException;
use Sodaho\Router\Exception\RouterException;

/**
 * Generates URLs from named routes.
 *
 * Values are always encoded, and an address is only returned when it leads back to its
 * route with exactly the values given, and when it is a path on this site: one slash in
 * front, no backslash, no '.' or '..' segment, nothing a client would read as another
 * host.
 */
final class UrlGenerator
{
    /** Values that do not lead back to their route — the route's name is in the debug message */
    private const NO_FIT = 'The parameters do not fit the pattern of the route: the address would not lead back to it';

    /** @var array<string, string> Named routes: name => pattern */
    private array $namedRoutes = [];

    private string $basePath = '';
    private ?string $baseUrl = null;
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
     *
     * @throws Exception\DuplicateRouteException When two of the Route objects have different patterns and the same name
     */
    public function __construct(array $routes = [], ?array $patterns = null)
    {
        $this->patterns = $patterns ?? new RouteCollector()->getPatterns();

        // Two routes of different patterns under one name: the last one would win without a word
        RouteCollector::assertNamesOnce(array_filter($routes, static fn (mixed $route): bool => $route instanceof Route));

        foreach ($routes as $key => $value) {
            if ($value instanceof Route) {
                // Route object: extract name and pattern
                if ($value->name !== null) {
                    $this->namedRoutes[$value->name] = $value->pattern;
                }
            } else {
                // Pattern mapping: name => pattern. A name that is a number ('1' => …) is a
                // key PHP keeps as an integer; the name is its string form all the same.
                $this->namedRoutes[(string) $key] = $value;
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
     * Set the base URL for absolute URL generation: an http(s) address of a host (see
     * checkBaseUrl()), or null or '' for none. It is put in front of every absolute address
     * as it is — 'javascript:alert(1)//' would make every one a script.
     *
     * @param string|null $baseUrl Base URL (e.g., 'https://example.com')
     *
     * @throws RouterException When the value is no http(s) address of a host
     */
    public function setBaseUrl(?string $baseUrl): void
    {
        if ($baseUrl === null || $baseUrl === '') {
            $this->baseUrl = null;

            return;
        }

        self::checkBaseUrl($baseUrl);
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * What a base URL has to be, whoever gives it (the router's config, setBaseUrl(),
     * APP_URL, a generator built by hand): what an address can begin with — http or https,
     * '://' and a host, then a port and a path at most. No control character or blank (a
     * line break would make every absolute address a Location header the response refuses), no
     * query or fragment (the path would land behind them), not 'example.com' (every address
     * relative) and no other scheme. No user information and no backslash
     * ('https://evil\@trusted.example' is the host 'evil' for a browser), a port of digits if
     * any, and a browser's parser (WHATWG URL) takes it with a host ('https://:443',
     * 'https://[::1' have none).
     *
     * @internal Router asks it with the name of where the value came from ($what)
     *
     * @throws RouterException When the value is no http(s) address of a host
     */
    public static function checkBaseUrl(string $value, string $what = 'Base URL'): void
    {
        if (preg_match('/[\x00-\x20\x7F]/', $value) === 1) {
            throw new RouterException(
                $what . ' must not contain a control character or a blank',
                debugMessage: (string) json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE),
            );
        }

        if (!self::isAddressOfAHost($value)) {
            throw new RouterException(
                $what . ' must be an address of a host: http:// or https://, the host, a port and a path at most — no user information, query or fragment',
                debugMessage: $value,
            );
        }
    }

    /**
     * Whether a base URL is an http(s) address of a host, as written and as a browser reads
     * it. As written: http or https, '://', a host (an IPv6 one in brackets), a port of
     * digits if any — not an empty one —, a path at most; no user information, backslash,
     * query or fragment. As read: the WHATWG parser of PHP takes it, with a host, on a port
     * from 1 to 65535. How the parser writes the host is its own: 'Bücher.example',
     * '[0:0:0:0:0:0:0:1]' or '127.1' name the same host for a browser as written — what
     * would name another one ('@', '\') is refused as written already.
     */
    private static function isAddressOfAHost(string $value): bool
    {
        if (preg_match('~^https?://(?:\[[^]/?#\\\\@]*\]|[^:/?#\\\\@[\]]+)(?::\d+)?(?:/[^?#\\\\]*)?\z~i', $value) !== 1) {
            return false;
        }

        $url = \Uri\WhatWg\Url::parse($value);
        if ($url === null || ($url->getAsciiHost() ?? '') === '') {
            return false;
        }

        $port = $url->getPort();

        return $port === null || ($port >= 1 && $port <= 65535);
    }

    /**
     * URL encoding is always on: parameters are encoded with rawurlencode() ('John Doe'
     * becomes 'John%20Doe'), and the address is checked. Off, values would go out as they
     * are, and every check of url() with them — so off is refused, not ignored.
     *
     * @deprecated since 2.2.0: encoding cannot be turned off; true changes nothing
     *
     * @param bool $encode Must be true
     *
     * @throws RouterException When $encode is false
     */
    public function setEncodeParams(bool $encode): void
    {
        if (!$encode) {
            throw new RouterException('URL encoding cannot be turned off: url() always encodes values and checks the address');
        }
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
     * Generate a relative URL for a named route. Parameters that are no placeholders of the
     * route are left out without a word — no query string, and a misspelled key is not
     * noticed.
     *
     * @param string $name Route name
     * @param array<string, string|int|float|bool> $params Route parameters
     *
     * @throws RouteNotFoundException If route name does not exist
     * @throws RouterException If the parameters do not lead back to the route, or the address
     *                         — base path included — would be no path on this site (a '.' or
     *                         '..' segment, a backslash, '//' in front)
     *
     * @return string Generated URL
     */
    public function url(string $name, array $params = []): string
    {
        $pattern = $this->getPatternByName($name);
        $address = self::encodeLiteral($this->basePath) . $this->replaceParameters($name, $pattern, $params);

        // A '.' or '..' segment never arrives: a client resolves it before it asks (the
        // encoded forms too). Looked for in the finished address, base path included —
        // '/tenant/..' in front of '/login' would make '/tenant/../login', which a client reads as
        // '/login', outside the base path. '/dl/{name}.json' with the value '..' has none,
        // '/x/{a}.' with a value that the pattern lets be '.' has one.
        if (preg_match('#(?:^|/)\.\.?(?:/|$)#D', $address) === 1) {
            throw new RouterException(
                'The address would contain a "." or ".." path segment, which a client resolves before it asks',
                debugMessage: $address,
            );
        }

        // What goes out has the form of a path on this site: one slash in front and no
        // backslash (a client reads it as a slash). Parameter values cannot break that
        // form, they are encoded, and so is the literal text of route pattern and base
        // path — all but the backslash, which no request path may contain in any form.
        if (preg_match('#\A/(?!/)[^\\\\]*\z#', $address) !== 1) {
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
     * @throws RouterException If baseUrl is not configured, or as url()
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
     * The address is only returned when it leads back to its route with exactly these
     * values: the path as the router would see it — values in place, not yet encoded —
     * has to match the route's own regular expression, each placeholder capturing its
     * value. That refuses a value that does not fit its placeholder ('12a'
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
                throw new RouterException('Missing parameter for URL generation', debugMessage: sprintf('%s: {%s}', $name, $parameter));
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

            $result = preg_match($regex, $seen, $captured);

            // PCRE gave up on the expression (the backtrack limit, the JIT stack): that says
            // nothing about whether the values fit
            if ($result === false) {
                throw new RouterException(
                    sprintf('The parameters could not be checked against the pattern of route "%s": %s', $name, preg_last_error_msg()),
                    debugMessage: $candidate,
                );
            }

            if ($result !== 1 || array_intersect_key($captured, $values) !== $values) {
                throw new RouterException(
                    self::NO_FIT,
                    debugMessage: sprintf('%s: %s', $name, $candidate),
                );
            }

            // … and a value the pattern takes may still be one the router does not hand
            // on: '01' for an integer, a number too large for one (400 for the request)
            foreach ($casts as $parameter => $type) {
                try {
                    RouteDispatcher::castValue($type, $values[$parameter], $parameter);
                } catch (\TypeError) {
                    throw new RouterException(
                        self::NO_FIT,
                        debugMessage: sprintf('%s: %s', $name, $candidate),
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

        // An address that begins with '//' is none of this site: a client reads what follows
        // as another host ('/{path:any}' with the value '/evil.example/x').
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
     * '/v1:batch' and '/@{user}' read as written), everything else is percent-encoded. The
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
