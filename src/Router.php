<?php

declare(strict_types=1);

namespace Sodaho\Router;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
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

    /** Bytes pulled from the response body per emit() iteration — the default of 'emitChunkSize'. */
    private const EMIT_CHUNK_SIZE = 8192;

    /** Below this a response is mostly loop; above it one request holds too much at once. */
    private const EMIT_CHUNK_SIZE_MIN = 1024;
    private const EMIT_CHUNK_SIZE_MAX = 16 * 1024 * 1024;

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

    /** @var array{debug: bool, basePath: string, baseUrl: ?string, trailingSlash: string, routesFile: ?string, urlEncoding: bool, implicitHead: bool, emitChunkSize: int} */
    private array $config;

    /** @var array<int, string|object> Middleware for every request, outermost first */
    private array $middleware = [];

    /** @var (\Closure(\Throwable, ServerRequestInterface): ?ResponseInterface)|null */
    private ?\Closure $errorHandler = null;

    /** @var list<AppFolder> Web app folders, the longest prefix first */
    private array $apps = [];

    private ?ContainerInterface $container = null;
    private ?RouteDispatcher $dispatcher = null;
    private ?RouteCollector $collector = null;
    private ?UrlGenerator $urlGenerator = null;

    /** Config key => environment variable, for fromEnv() */
    private const ENV_VARIABLES = [
        'debug' => 'APP_DEBUG',
        'baseUrl' => 'APP_URL',
        'basePath' => 'ROUTER_BASE_PATH',
        'trailingSlash' => 'ROUTER_TRAILING_SLASH',
        'urlEncoding' => 'ROUTER_URL_ENCODING',
    ];

    /**
     * Create a new Router instance.
     *
     * Only what $config says counts: the constructor does not look at the environment
     * (fromEnv() does). A key that is missing or null takes its default.
     *
     * @param array{debug?: bool|int|string|null, basePath?: string|null, baseUrl?: string|null, trailingSlash?: string|null, routesFile?: string|null, urlEncoding?: bool|int|string|null, implicitHead?: bool|int|string|null, emitChunkSize?: int|string|null} $config
     *
     * @throws RouterException If 'debug', 'urlEncoding' or 'implicitHead' is neither a boolean
     *                         nor boolean-like nor empty ('' and 0 count as off; null is
     *                         the default), or 'emitChunkSize' is not an integer (or a
     *                         string of digits) from 1024 to 16777216
     */
    public function __construct(array $config = [])
    {
        $this->config = [
            'debug' => self::flag('debug', $config['debug'] ?? false),
            'basePath' => self::normalizeBasePath((string) ($config['basePath'] ?? '')),
            'baseUrl' => $config['baseUrl'] ?? null,
            'trailingSlash' => self::trailingSlash($config['trailingSlash'] ?? 'strict'),
            'routesFile' => $config['routesFile'] ?? null,
            'urlEncoding' => self::flag('urlEncoding', $config['urlEncoding'] ?? true),
            'implicitHead' => self::flag('implicitHead', $config['implicitHead'] ?? true),
            'emitChunkSize' => self::chunkSize($config['emitChunkSize'] ?? self::EMIT_CHUNK_SIZE),
        ];
    }

    /**
     * A variable of the environment: $_ENV, then the environment of the process.
     *
     * getenv($key, true), not getenv($key): under PHP-FPM (and mod_php) the plain call
     * also returns what came with the request — the web server's fastcgi_param/SetEnv,
     * and every request header as HTTP_*. That is not the environment. ($_ENV carries
     * the same where variables_order contains E; the names read here are fixed and none
     * starts with HTTP_, so a client cannot set them either way.)
     */
    private static function env(string $key): ?string
    {
        // $_ENV is thread-safe, preferred
        $value = $_ENV[$key] ?? null;
        // Scalars only — and no NAN or INF: they mean nothing here, and PHP 8.5 warns when a
        // NAN is turned into a string
        if (is_scalar($value) && !(is_float($value) && !is_finite($value))) {
            return (string) $value;
        }

        // For setups that do not fill $_ENV (variables_order without E)
        $value = getenv($key, true);

        return $value !== false ? $value : null;
    }

    /**
     * @throws RouterException If the value is neither a boolean nor boolean-like nor empty
     */
    private static function flag(string $name, mixed $value): bool
    {
        // NAN and INF are no flags. Refused first: PHP 8.5 warns when a NAN is turned into
        // a string or a boolean, which is what the checks below would do with it.
        $meaningless = is_float($value) && !is_finite($value);

        // Config arrays are often built from env files, so 'true'/'false'/'1'/'0' count too.
        $flag = $meaningless ? null : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($flag !== null) {
            return $flag;
        }

        // Neither a boolean nor boolean-like. Something truthy used to end in a TypeError on
        // every request; something empty ([]) simply is not "on".
        if (!$meaningless && !$value) {
            return false;
        }

        throw new RouterException(sprintf("Config '%s' must be a boolean, got %s", $name, get_debug_type($value)));
    }

    /**
     * @throws RouterException If the value is not an integer within the allowed range
     */
    private static function chunkSize(mixed $value): int
    {
        // An integer, or what an env file makes of one ('65536') — digits, nothing around them
        $size = match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match('/^\d+$/D', $value) === 1 => (int) $value,
            default => false,
        };

        if ($size === false || $size < self::EMIT_CHUNK_SIZE_MIN || $size > self::EMIT_CHUNK_SIZE_MAX) {
            throw new RouterException(sprintf(
                "Config 'emitChunkSize' must be an integer between %d and %d",
                self::EMIT_CHUNK_SIZE_MIN,
                self::EMIT_CHUNK_SIZE_MAX
            ));
        }

        return $size;
    }

    /**
     * '/api', '/api/' and 'api' all mean the same prefix; the dispatcher compares against '/api'.
     */
    private static function normalizeBasePath(string $basePath, string $what = "Config 'basePath'"): string
    {
        $trimmed = trim($basePath, '/');

        // The same rule as for a route pattern: compared with the decoded request path,
        // so written decoded — and a path
        if (preg_match(RouteCollector::NOT_A_PLAIN_PATH, $trimmed) === 1) {
            throw new RouterException($what . ' ' . RouteCollector::PLAIN_PATH_RULE);
        }

        return $trimmed === '' ? '' : '/' . $trimmed;
    }

    /**
     * @throws RouterException If the value is not one of the two modes — any other value
     *                         left the router half in one mode and half in the other
     */
    private static function trailingSlash(mixed $value): string
    {
        // Empty means the default, as null does: 'ROUTER_TRAILING_SLASH=' in a .env file
        if ($value === '') {
            return 'strict';
        }

        if ($value !== 'strict' && $value !== 'ignore') {
            throw new RouterException("Config 'trailingSlash' must be 'strict' or 'ignore'");
        }

        return $value;
    }

    /**
     * What goes into the routing table when it is built cannot be changed afterwards — the
     * call would be accepted and have no effect, or half an effect.
     *
     * @throws RouterException When the table is already built
     */
    private function assertNotInUse(string $method): void
    {
        if ($this->dispatcher !== null) {
            throw new RouterException(sprintf(
                '%s() has to be called before the first request, match() or url(): the routing table is already built',
                $method
            ));
        }
    }

    /**
     * Factory method for fluent creation. Like the constructor it does not look at the
     * environment.
     *
     * @param array{debug?: bool|int|string|null, basePath?: string|null, baseUrl?: string|null, trailingSlash?: string|null, routesFile?: string|null, urlEncoding?: bool|int|string|null, implicitHead?: bool|int|string|null, emitChunkSize?: int|string|null} $config
     */
    public static function create(array $config = []): self
    {
        return new self($config);
    }

    /**
     * Create a router from the environment — the only place where the router reads it.
     *
     * Read are, from $_ENV and then the environment of the process: APP_DEBUG (debug), APP_URL (baseUrl),
     * ROUTER_BASE_PATH (basePath), ROUTER_TRAILING_SLASH (trailingSlash) and
     * ROUTER_URL_ENCODING (urlEncoding). A key that $config contains wins over its variable —
     * also with null, false or an empty value.
     *
     * @param array{debug?: bool|int|string|null, basePath?: string|null, baseUrl?: string|null, trailingSlash?: string|null, routesFile?: string|null, urlEncoding?: bool|int|string|null, implicitHead?: bool|int|string|null, emitChunkSize?: int|string|null} $config Values that take precedence
     *
     * @throws RouterException As the constructor; and if APP_DEBUG or ROUTER_URL_ENCODING is
     *                         read and its value is not boolean-like (APP_DEBUG=maybe)
     */
    public static function fromEnv(array $config = []): self
    {
        $fromEnvironment = [];
        foreach (self::ENV_VARIABLES as $key => $variable) {
            // A key that $config has is settled, whatever its value: its variable is not even looked at
            if (array_key_exists($key, $config)) {
                continue;
            }

            $value = self::env($variable);
            if ($value === null) {
                continue;
            }

            if (in_array($key, ['debug', 'urlEncoding'], true)
                && filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === null) {
                // Names the variable — the config key would send the reader to the wrong place
                throw new RouterException(sprintf(
                    'Environment variable %s must be boolean-like (true/false, 1/0, on/off, yes/no or empty)',
                    $variable
                ));
            }

            if ($key === 'trailingSlash' && !in_array($value, ['strict', 'ignore', ''], true)) {
                throw new RouterException(sprintf("Environment variable %s must be 'strict', 'ignore' or empty", $variable));
            }

            if ($key === 'basePath') {
                self::normalizeBasePath($value, 'Environment variable ' . $variable);
            }

            $fromEnvironment[$key] = $value;
        }

        return new self($config + $fromEnvironment);
    }

    /**
     * Quick boot: create, load routes, and run. Reads no environment either — for that:
     * Router::fromEnv()->loadRoutes($routesFile)->run().
     *
     * @param array{debug?: bool|int|string|null, basePath?: string|null, baseUrl?: string|null, trailingSlash?: string|null, routesFile?: string|null, urlEncoding?: bool|int|string|null, implicitHead?: bool|int|string|null, emitChunkSize?: int|string|null} $config
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

        // match() may have built the dispatcher before the application had its container
        $this->dispatcher?->setContainer($container);

        return $this;
    }

    /**
     * Enable/disable debug mode.
     *
     * @param bool $debug Enable debug mode
     */
    public function setDebug(bool $debug): self
    {
        $this->assertNotInUse('setDebug');
        $this->config['debug'] = $debug;
        return $this;
    }

    /**
     * Whether debug mode is on.
     */
    public function isDebug(): bool
    {
        return $this->config['debug'];
    }

    /**
     * Add middleware that runs for every request, in the order it is added (first = outermost).
     *
     * Unlike route middleware it also sees requests that end in 404, 405 or 400 and the
     * responses made from exceptions. The request it receives already carries the result
     * of the route lookup as attribute RouteMatch::class.
     *
     * @param string|array<string|object>|object $middleware Middleware class name(s) or instance(s)
     */
    public function middleware(string|array|object $middleware): self
    {
        $this->middleware = array_merge($this->middleware, is_array($middleware) ? $middleware : [$middleware]);
        $this->dispatcher?->setMiddleware($this->middleware);

        return $this;
    }

    /**
     * Serve a folder with a built web app (index.html plus assets) under a path prefix.
     *
     * Routes come first. Where no route matches a GET or HEAD request under the prefix, an
     * existing file of the folder is sent; every other path gets the start page (the app's
     * own router takes over) — unless it looks like a file (a dot in its last segment):
     * that is a 404. Middleware added with middleware() runs before. See AppFolder for the
     * options and for what is never served.
     *
     * @param string $prefix Path prefix, relative to the base path ('/login'; '/' for the root)
     * @param string $directory The folder with the start page
     * @param array{index?: string, types?: array<string, string|null>, immutable?: string|null, cacheIndex?: string|null, cacheImmutable?: string|null, cacheOther?: string|null} $options
     *
     * @throws RouterException If the folder does not exist, the prefix is taken or an option is not understood
     */
    public function app(string $prefix, string $directory, array $options = []): self
    {
        $app = new AppFolder($prefix, $directory, $options);

        foreach ($this->apps as $existing) {
            if ($existing->prefix === $app->prefix) {
                throw new RouterException('App prefix is already in use', debugMessage: $prefix);
            }
        }

        $this->apps[] = $app;

        // '/login' before '/': the most specific prefix gets the request
        usort($this->apps, static fn (AppFolder $a, AppFolder $b): int => strlen($b->prefix) <=> strlen($a->prefix));

        $this->dispatcher?->setApps($this->apps);

        return $this;
    }

    /**
     * Let the application build the response for an exception.
     *
     * Called for whatever route middleware and handlers throw, inside the middleware added
     * with middleware() — and, as the last resort, for what that middleware throws itself;
     * that response is returned as it is. Return null to get the router's own 500; throwing
     * counts as null. The error hook fires in either case. handle() does not throw for any
     * of this. (What still leaves it, as before: a responder set with
     * Response::setResponder() that throws while the 500 is built.)
     *
     * @param callable(\Throwable, ServerRequestInterface): ?ResponseInterface $handler
     */
    public function setErrorHandler(callable $handler): self
    {
        $this->errorHandler = $handler(...);
        return $this;
    }

    /**
     * Set base path for all routes.
     *
     * @param string $basePath Base path prefix (e.g., '/api/v1')
     */
    public function setBasePath(string $basePath): self
    {
        $this->assertNotInUse('setBasePath');
        $this->config['basePath'] = self::normalizeBasePath($basePath);
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
        $this->assertNotInUse('loadRoutes');
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
     * Requires baseUrl: from the config, or APP_URL through fromEnv().
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
     * Whatever goes wrong while the request is handled becomes a 500 response (see handle()) —
     * unless a responder set with Response::setResponder() throws while that 500 is built.
     *
     * @throws RouterException If the response body cannot be read (closed or detached)
     */
    public function run(): void
    {
        $psr17 = new Psr17Factory();
        $creator = new ServerRequestCreator($psr17, $psr17, $psr17, $psr17);
        $request = $creator->fromGlobals();
        $this->send($this->handle($request), $request->getMethod() !== 'HEAD');
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
            $dispatcher = $this->getDispatcher();
        } catch (\Throwable $e) {
            // The routes could not be loaded
            $response = $this->errorResponse($e, $request);

            // No answer to HEAD carries a body with implicitHead on — this one included
            if ($this->config['implicitHead'] && $request->getMethod() === 'HEAD') {
                $response = $response->withBody(\Nyholm\Psr7\Stream::create(''));
            }

            return $response;
        }

        // Everything a request runs into from here on is answered in the dispatcher, through
        // errorResponse() (see getDispatcher()). What still comes out is what errorResponse()
        // could not answer itself: a responder that threw while the 500 was built. That
        // leaves handle(), as it did before 1.2.
        return $dispatcher->handle($request);
    }

    /**
     * Look a request up in the route table without executing anything: no middleware or
     * handler runs, none of the routing hooks fires, no container is needed.
     *
     * Pass the request on with the result as attribute RouteMatch::class and handle() does
     * not look it up a second time.
     *
     * The first call loads the routes, as the first handle() or url() does: base path and
     * trailing slash mode are taken as they are at that moment.
     *
     * @throws RouterException If no routes are loaded or routes file is invalid
     */
    public function match(ServerRequestInterface $request): RouteMatch
    {
        return $this->getDispatcher()->match($request);
    }

    /**
     * The response for an exception: the application's (setErrorHandler()) or a 500.
     */
    private function errorResponse(\Throwable $e, ServerRequestInterface $request): ResponseInterface
    {
        $this->trigger('error', [
            'exception' => $e,
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
        ]);

        if ($this->errorHandler !== null) {
            try {
                $response = ($this->errorHandler)($e, $request);
                if ($response !== null) {
                    return $response;
                }
            } catch (\Throwable $failure) {
                // The error handler failed itself: report that too, answer for the original.
                // One that only hands the exception back has nothing new to report.
                if ($failure !== $e) {
                    $this->reportFailure($failure, $request);
                }
            }
        }

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

    private function reportFailure(\Throwable $failure, ServerRequestInterface $request): void
    {
        $this->trigger('error', [
            'exception' => $failure,
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
        ]);
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

        $this->dispatcher = new RouteDispatcher(
            $this->collector->getData(),
            $this->container,
            $this->config['basePath'],
            $this->config['trailingSlash'],
            $this->config['debug']
        );
        $this->dispatcher
            ->setImplicitHead($this->config['implicitHead'])
            ->setMiddleware($this->middleware)
            ->setApps($this->apps)
            ->setErrorResponder($this->errorResponse(...));

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
            // Ensure routes are loaded (initializes the collector)
            $this->getDispatcher();
            assert($this->collector !== null);

            $this->urlGenerator = new UrlGenerator($this->collector->getRoutes(), $this->collector->getPatterns());

            $this->urlGenerator->setBasePath($this->config['basePath']);
            $this->urlGenerator->setEncodeParams($this->config['urlEncoding']);

            if ($this->config['baseUrl']) {
                $this->urlGenerator->setBaseUrl($this->config['baseUrl']);
            }
        }

        return $this->urlGenerator;
    }

    public function emit(ResponseInterface $response, bool $withBody = true): void
    {
        $this->send($response, $withBody);
    }

    /**
     * What emit() does. run() calls it directly: a subclass with an emit() of its own had
     * no say in run() while emit() was private, and still has none.
     */
    private function send(ResponseInterface $response, bool $withBody): void
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
        // challenge would arrive as 401 and a 202 with a Location as 302 — and a 200 with a
        // Location, which 1.x still left to PHP, as a redirect nobody asked for.
        header($statusLine);

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
            $chunk = $body->read($this->config['emitChunkSize']);
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
