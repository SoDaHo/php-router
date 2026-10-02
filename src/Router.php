<?php

declare(strict_types=1);

namespace Sodaho\Router;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sodaho\Router\Cache\RouteCache;
use Sodaho\Router\Exception\CacheException;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\Traits\HasHooks;

/**
 * Entry point for the router. Provides fluent API for configuration.
 *
 * @example
 * Router::create(['debug' => true])
 *     ->loadRoutes(__DIR__ . '/routes.php')
 *     ->run();
 */
class Router implements RequestHandlerInterface
{
    use HasHooks;

    /** Bytes pulled from the response body per emit() iteration (see emit()). */
    private const EMIT_CHUNK_SIZE = 8192;

    /** Consecutive empty reads tolerated before emit() gives up on a stalled body. */
    private const EMIT_EMPTY_READ_LIMIT = 3;

    /**
     * Response fields that exist once per message. Only for these does the response replace
     * what the host already set; every other field is a list whose lines add up (Vary,
     * Cache-Control, Link, Content-Security-Policy, Set-Cookie, ...).
     *
     * Security fields are sorted by what a browser does with two lines of them. Sent twice,
     * the Cross-Origin-* policies and Origin-Agent-Cluster are no valid value at all and the
     * protection is gone — so they are replaced. X-Frame-Options, Strict-Transport-Security
     * and Access-Control-Allow-Origin fall back to the safe side when two values conflict —
     * so they stay additive, and a route cannot quietly weaken what the host set.
     */
    private const SINGLETON_HEADERS = [
        'content-type' => true,
        'content-length' => true,
        'content-range' => true,
        'content-location' => true,
        'content-disposition' => true,
        'location' => true,
        'etag' => true,
        'last-modified' => true,
        'date' => true,
        'expires' => true,
        'age' => true,
        'retry-after' => true,
        'cross-origin-embedder-policy' => true,
        'cross-origin-embedder-policy-report-only' => true,
        'cross-origin-opener-policy' => true,
        'cross-origin-opener-policy-report-only' => true,
        'cross-origin-resource-policy' => true,
        'origin-agent-cluster' => true,
    ];

    /** @var array{debug: bool, basePath: string, baseUrl: ?string, trailingSlash: string, cacheFile: ?string, cacheSignature: ?string, routesFile: ?string, urlEncoding: bool} */
    private array $config;

    private ?ContainerInterface $container = null;
    private ?RouteDispatcher $dispatcher = null;
    private ?RouteCollector $collector = null;
    private ?UrlGenerator $urlGenerator = null;

    /** @var array<string, string> Cached named routes (name => pattern) */
    private array $cachedNamedRoutes = [];

    /**
     * Create a new Router instance.
     *
     * @param array{debug?: bool|int|string|null, basePath?: string, baseUrl?: string, trailingSlash?: string, cacheFile?: string, cacheSignature?: string, routesFile?: string, urlEncoding?: bool} $config
     *
     * @throws RouterException If 'debug' is neither a boolean nor a boolean-like value
     */
    public function __construct(array $config = [])
    {
        // Config precedence: $config > $_ENV > getenv() > default (consistent with pdo-wrapper)
        $this->config = [
            'debug' => self::resolveDebug($config['debug'] ?? null),
            'basePath' => self::normalizeBasePath((string) ($config['basePath'] ?? self::env('ROUTER_BASE_PATH') ?? '')),
            'baseUrl' => $config['baseUrl'] ?? self::env('APP_URL'),
            'trailingSlash' => (string) ($config['trailingSlash'] ?? self::env('ROUTER_TRAILING_SLASH') ?? 'strict'),
            'cacheFile' => $config['cacheFile'] ?? self::env('ROUTER_CACHE_FILE'),
            'cacheSignature' => $config['cacheSignature'] ?? self::env('ROUTER_CACHE_KEY'),
            'routesFile' => $config['routesFile'] ?? null,
            'urlEncoding' => $config['urlEncoding']
                ?? filter_var(self::env('ROUTER_URL_ENCODING') ?? true, FILTER_VALIDATE_BOOL),
        ];
    }

    /**
     * Get environment variable value ($_ENV > getenv() fallback).
     */
    private static function env(string $key): ?string
    {
        // $_ENV is thread-safe, preferred
        $value = $_ENV[$key] ?? null;
        if (is_scalar($value)) {
            return (string) $value;
        }

        // getenv() fallback for legacy compatibility
        $value = getenv($key);

        return $value !== false ? $value : null;
    }

    /**
     * An explicit config value always wins — an explicit false included. Only without one
     * do APP_DEBUG and APP_ENV decide.
     *
     * @throws RouterException If the config value is not a boolean
     */
    private static function resolveDebug(mixed $debug): bool
    {
        if ($debug === null) {
            return filter_var(self::env('APP_DEBUG') ?? false, FILTER_VALIDATE_BOOL)
                || in_array(self::env('APP_ENV') ?? '', ['local', 'dev', 'development'], true);
        }

        // Config arrays are often built from env files, so 'true'/'false'/'1'/'0' count too.
        $flag = filter_var($debug, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($flag !== null) {
            return $flag;
        }

        // Neither a boolean nor boolean-like. Something truthy used to end in a TypeError on
        // every request; something empty ([]) simply is not "on".
        if (!$debug) {
            return false;
        }

        throw new RouterException(sprintf("Config 'debug' must be a boolean, got %s", get_debug_type($debug)));
    }

    /**
     * '/api', '/api/' and 'api' all mean the same prefix; the dispatcher compares against '/api'.
     */
    private static function normalizeBasePath(string $basePath): string
    {
        $trimmed = trim($basePath, '/');

        return $trimmed === '' ? '' : '/' . $trimmed;
    }

    /**
     * Factory method for fluent creation.
     *
     * @param array{debug?: bool|int|string|null, basePath?: string, baseUrl?: string, trailingSlash?: string, cacheFile?: string, cacheSignature?: string, routesFile?: string, urlEncoding?: bool} $config
     */
    public static function create(array $config = []): self
    {
        return new self($config);
    }

    /**
     * Create a router that takes what $config does not say from the environment
     * ($_ENV, then getenv()): APP_DEBUG, APP_ENV, APP_URL and the ROUTER_* variables.
     *
     * Today create() and the constructor do the same. That silent fallback is deprecated
     * and ends with 2.0 — from then on only fromEnv() reads the environment.
     *
     * @param array{debug?: bool|int|string|null, basePath?: string, baseUrl?: string, trailingSlash?: string, cacheFile?: string, cacheSignature?: string, routesFile?: string, urlEncoding?: bool} $config Values that take precedence
     *
     * @throws RouterException If 'debug' is neither a boolean nor a boolean-like value
     */
    public static function fromEnv(array $config = []): self
    {
        return new self($config);
    }

    /**
     * Quick boot: create, load routes, and run.
     *
     * @param array{debug?: bool|int|string|null, basePath?: string, baseUrl?: string, trailingSlash?: string, cacheFile?: string, cacheSignature?: string, routesFile?: string, urlEncoding?: bool} $config
     * @param string $routesFile Path to routes file
     */
    public static function boot(array $config, string $routesFile): void
    {
        $router = self::create($config)->loadRoutes($routesFile);
        $router->run();
    }

    // ==================== Configuration ====================

    /**
     * Set PSR-11 container for dependency injection.
     *
     * @param ContainerInterface $container PSR-11 container
     */
    public function setContainer(ContainerInterface $container): self
    {
        $this->container = $container;
        return $this;
    }

    /**
     * Enable/disable debug mode.
     *
     * @param bool $debug Enable debug mode
     */
    public function setDebug(bool $debug): self
    {
        $this->config['debug'] = $debug;
        return $this;
    }

    /**
     * Whether debug mode is on — as the router decided it from config and environment.
     */
    public function isDebug(): bool
    {
        return $this->config['debug'];
    }

    /**
     * Set base path for all routes.
     *
     * @param string $basePath Base path prefix (e.g., '/api/v1')
     */
    public function setBasePath(string $basePath): self
    {
        $this->config['basePath'] = self::normalizeBasePath($basePath);
        return $this;
    }

    /**
     * Enable route caching.
     *
     * @deprecated 1.2 The route cache will be removed in 2.0. Measured, loading it costs more
     *             than building the table from the routes file.
     *
     * @param string $file Path to cache file
     * @param string|null $signature HMAC key for integrity verification (required outside debug mode);
     *                               null keeps the key already configured (config or ROUTER_CACHE_KEY)
     */
    public function enableCache(string $file, ?string $signature = null): self
    {
        $this->config['cacheFile'] = $file;
        $this->config['cacheSignature'] = $signature ?? $this->config['cacheSignature'];
        return $this;
    }

    /**
     * Register a hook callback for an event.
     *
     * @param string $event Event name (e.g., 'dispatch', 'notFound', 'error')
     * @param callable $callback Callback receiving event data array
     */
    public function on(string $event, callable $callback): static
    {
        $this->hooks[$event][] = $callback;

        // The dispatcher got a copy of the hooks when it was built; a hook registered after
        // the first request would otherwise never fire for dispatch/notFound/methodNotAllowed.
        $this->dispatcher?->on($event, $callback);

        return $this;
    }

    /**
     * Load routes from a file.
     *
     * File must return a callable: function(RouteCollector $r) { ... }
     *
     * @param string $file Path to routes file
     */
    public function loadRoutes(string $file): self
    {
        $this->config['routesFile'] = $file;
        return $this;
    }

    // ==================== URL Generation ====================

    /**
     * Generate relative URL for a named route.
     *
     * @param string $name Route name
     * @param array<string, int|string> $params Route parameters
     *
     * @throws Exception\RouteNotFoundException If route name does not exist
     *
     * @return string Generated URL
     */
    public function url(string $name, array $params = []): string
    {
        return $this->getUrlGenerator()->url($name, $params);
    }

    /**
     * Generate absolute URL for a named route.
     *
     * Requires baseUrl to be set via config or APP_URL env variable.
     *
     * @param string $name Route name
     * @param array<string, int|string> $params Route parameters
     *
     * @throws Exception\RouteNotFoundException If route name does not exist
     * @throws Exception\RouterException If baseUrl is not configured
     *
     * @return string Generated absolute URL
     */
    public function absoluteUrl(string $name, array $params = []): string
    {
        return $this->getUrlGenerator()->absoluteUrl($name, $params);
    }

    // ==================== Request Handling ====================

    /**
     * Convenience method: create request from globals, handle, and emit response.
     *
     * Whatever goes wrong while the request is handled becomes a 500 response (see handle()).
     *
     * @throws RouterException If the response body cannot be read (closed or detached)
     */
    public function run(): void
    {
        $psr17 = new Psr17Factory();
        $creator = new ServerRequestCreator($psr17, $psr17, $psr17, $psr17);
        $request = $creator->fromGlobals();
        $this->emit($this->handle($request), $request->getMethod() !== 'HEAD');
    }

    /**
     * PSR-15: Handle a request and return a response.
     *
     * @param ServerRequestInterface $request PSR-7 request
     *
     * @return ResponseInterface PSR-7 response
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return $this->getDispatcher()->handle($request);
        } catch (\Throwable $e) {
            $this->trigger('error', [
                'exception' => $e,
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
            ]);

            return Response::serverError(
                $this->config['debug'] ? $e->getMessage() : 'Internal Server Error',
                $this->config['debug'] ? [
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => explode("\n", $e->getTraceAsString()),
                ] : null
            );
        }
    }

    // ==================== Internal ====================

    /**
     * @throws RouterException If no routes are loaded or routes file is invalid
     */
    private function getDispatcher(): RouteDispatcher
    {
        if ($this->dispatcher !== null) {
            return $this->dispatcher;
        }

        $data = null;
        $cache = null;

        // Try loading from cache (only in non-debug mode). The cache is an optimisation: a
        // missing key, a file that fails verification or no longer fits the application and
        // a failed write are reported through the error hook, and the request is served from
        // the routes file.
        if ($this->config['cacheFile']) {
            try {
                $cache = new RouteCache(
                    $this->config['cacheFile'],
                    $this->config['cacheSignature'],
                    !$this->config['debug'] // Disabled in debug mode
                );
            } catch (CacheException $e) {
                $this->trigger('error', [
                    'type' => 'cache',
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }

            if ($cache !== null && !$this->config['debug']) {
                try {
                    $data = $cache->load();
                } catch (\Throwable $e) {
                    // Not a cache file this key signed, outdated, or something in between threw
                    // (an autoloader, a stream wrapper) - trigger error hook and rebuild
                    $this->trigger('error', [
                        'type' => 'cache',
                        'message' => $e->getMessage(),
                        'exception' => $e,
                    ]);
                    $data = null;
                }
            }
        }

        // Load routes if no cache hit
        if ($data === null) {
            if (!$this->config['routesFile'] || !file_exists($this->config['routesFile'])) {
                throw new RouterException('No routes loaded. Use loadRoutes() first.');
            }

            $this->collector = new RouteCollector();

            // Configure trailing slash handling based on mode
            if ($this->config['trailingSlash'] === 'strict') {
                $this->collector->setPreserveTrailingSlash(true);
            }

            $callback = require $this->config['routesFile'];

            if (!is_callable($callback)) {
                throw new RouterException(
                    'Route file must return callable: return function(RouteCollector $r) { ... };'
                );
            }

            $callback($this->collector);
            $dispatchData = $this->collector->getData();
            $namedRoutes = $this->collector->getNamedRoutesData();

            // Save to cache. Routes that cannot be cached (Closures) and a cache file that
            // cannot be written are both reported and neither stops the request: the table
            // just built is complete.
            try {
                $cache?->save([
                    'dispatchData' => $dispatchData,
                    'namedRoutes' => $namedRoutes,
                ]);
            } catch (\Throwable $e) {
                // trigger error hook and continue without cache
                $this->trigger('error', [
                    'type' => 'cache',
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }

            $data = $dispatchData;
        } else {
            // Extract from cached structure
            /** @var array<string, string> $namedRoutes */
            $namedRoutes = $data['namedRoutes'] ?? [];
            $this->cachedNamedRoutes = $namedRoutes;
            $data = $data['dispatchData'] ?? $data;
        }

        assert(is_array($data));
        /** @var array{0: array<string, array<string, Route>>, 1: array<string, array<int, array{regex: string, route: Route, casts: array<string, string>}>>} $data */
        $this->dispatcher = new RouteDispatcher(
            $data,
            $this->container,
            $this->config['basePath'],
            $this->config['trailingSlash'],
            $this->config['debug']
        );

        // Forward hooks from Router to Dispatcher
        foreach ($this->hooks as $event => $callbacks) {
            foreach ($callbacks as $callback) {
                $this->dispatcher->on($event, $callback);
            }
        }

        return $this->dispatcher;
    }

    private function getUrlGenerator(): UrlGenerator
    {
        if ($this->urlGenerator === null) {
            // Ensure routes are loaded (initializes collector or loads cache)
            $this->getDispatcher();

            // Use collector if available, otherwise use cached named routes
            if ($this->collector !== null) {
                $routes = $this->collector->getRoutes();
                $this->urlGenerator = new UrlGenerator($routes);
            } else {
                $this->urlGenerator = new UrlGenerator($this->cachedNamedRoutes);
            }

            $this->urlGenerator->setBasePath($this->config['basePath']);
            $this->urlGenerator->setEncodeParams($this->config['urlEncoding']);

            if ($this->config['baseUrl']) {
                $this->urlGenerator->setBaseUrl($this->config['baseUrl']);
            }
        }

        return $this->urlGenerator;
    }

    private function emit(ResponseInterface $response, bool $withBody = true): void
    {
        // @codeCoverageIgnoreStart
        // headers_sent() is always false in CLI/PHPUnit; EmitOverHttpTest covers it over HTTP
        if (headers_sent($file, $line)) {
            // Something printed before the router did (a stray echo, a displayed warning).
            // Status and headers can no longer be sent, so nothing is — but not silently.
            // PHP only knows the place when the output came from a script line, not after flush()
            $message = 'Response not sent: output had already started'
                . ($file !== '' ? sprintf(' at %s:%d', $file, $line) : '');
            $this->trigger('error', [
                'type' => 'emit',
                'message' => $message,
                'exception' => new RouterException($message),
            ]);

            return;
        }
        // @codeCoverageIgnoreEnd

        // Readability BEFORE anything is sent: a detached/closed body used to blow up loudly
        // inside __toString(). Throwing after the headers went out would leave a half-sent
        // response; throwing here lets the error handler still produce a proper 500.
        if (!$response->getBody()->isReadable()) {
            throw new RouterException('Response body is not readable (closed or detached before emit)');
        }

        $statusLine = sprintf(
            'HTTP/%s %d %s',
            $response->getProtocolVersion(),
            $response->getStatusCode(),
            $response->getReasonPhrase()
        );

        // The one case left to PHP: a response that never chose a status (200) but carries a
        // Location. That has always gone out as a redirect, and code that builds redirects
        // by header alone relies on it. For exactly that case the status line goes first, as
        // it did up to 1.1.0, and Location turns it into 302/303. Kept for 1.x; use
        // Response::redirect().
        $redirectByHeader = $response->getStatusCode() === 200 && $response->hasHeader('Location');
        if ($redirectByHeader) {
            header($statusLine);
        }

        // Headers. A field that exists once per message replaces what the host already set
        // under that name — two Content-Type or Location lines are not a valid response. All
        // other fields are lists: the response's lines are added to the host's, so a
        // "Vary: Cookie" or a session's "Cache-Control: no-store" set before run() stays.
        foreach ($response->getHeaders() as $name => $values) {
            $replace = isset(self::SINGLETON_HEADERS[strtolower((string) $name)]);
            foreach ($values as $value) {
                header("$name: $value", $replace);
                $replace = false;
            }
        }

        // Status line LAST. header() rewrites the status as a side effect: WWW-Authenticate
        // forces 401 and Location forces 302 (unless 201/3xx). Sent first, a 403 with a
        // challenge would arrive as 401 and a 202 with a Location as 302.
        if (!$redirectByHeader) {
            header($statusLine);
        }

        // HEAD: PHP discards the output anyway, so do not read the body at all — for a
        // Response::file() that would be the whole file.
        if (!$withBody) {
            return;
        }

        // Body — pulled in chunks so large payloads (file downloads via Response::file())
        // never sit in memory as a whole. For string bodies the emitted bytes are identical
        // to the previous `echo $response->getBody()`: Nyholm's __toString() rewound the
        // stream and returned everything, which is exactly what this loop does piecewise.
        $body = $response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        // An empty read does not mean "done" — pump/append streams return '' transiently
        // while eof() is still false, and breaking on the first one would truncate the body
        // (the old getContents() looped until eof). Bail out only after several in a row,
        // which still guards against a stream that never reports eof at all.
        $emptyReads = 0;
        while (!$body->eof()) {
            $chunk = $body->read(self::EMIT_CHUNK_SIZE);
            if ($chunk === '') {
                if (++$emptyReads >= self::EMIT_EMPTY_READ_LIMIT) {
                    break;
                }

                continue;
            }

            $emptyReads = 0;
            echo $chunk;
        }
    }
}
