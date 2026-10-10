<?php

declare(strict_types=1);

namespace Sodaho\Router;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Sodaho\Router\Contract\RouterInterface;
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
final class Router implements RouterInterface
{
    use HasHooks;

    /** Bytes pulled from the response body per emit() iteration — the default of 'emitChunkSize'. */
    private const EMIT_CHUNK_SIZE = 8192;

    /** Below this a response is mostly loop; above it one request holds too much at once. */
    private const EMIT_CHUNK_SIZE_MIN = 1024;
    private const EMIT_CHUNK_SIZE_MAX = 16 * 1024 * 1024;

    /** Seconds without a byte from the response body before emit() gives up — the default of 'emitIdleTimeout'. */
    private const EMIT_IDLE_TIMEOUT = 30;

    /** Above this a stalled body holds a worker for more than an hour. */
    private const EMIT_IDLE_TIMEOUT_MAX = 3600;

    /** The longest pause between two empty reads, in microseconds: a body that stalls for a moment goes on soon after. */
    private const EMIT_IDLE_PAUSE_MAX = 50_000;

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

    /** @var array{debug: bool, basePath: string, baseUrl: ?string, trailingSlash: string, routesFile: ?string, implicitHead: bool, emitChunkSize: int, emitIdleTimeout: float} */
    private array $config;

    /** @var array<string|object> Middleware for every request, outermost first (string keys as the application gave them) */
    private array $middleware = [];

    /** @var (\Closure(\Throwable, ServerRequestInterface): ?ResponseInterface)|null */
    private ?\Closure $errorHandler = null;

    /** @var list<AppFolder> Web app folders, the longest prefix first */
    private array $apps = [];

    private ?ContainerInterface $container = null;
    private ?RouteDispatcher $dispatcher = null;
    private ?RouteCollector $collector = null;
    private ?UrlGenerator $urlGenerator = null;

    /**
     * What the routes file gave when this router loaded it: its callable, or what it
     * threw. Loaded once for each loadRoutes() — a file that declares a function or a
     * class cannot be required a second time. Another router, or another loadRoutes(),
     * requires it again, as in 1.x.
     */
    private \Closure|\Throwable|null $routes = null;

    /** Whether the router was used (see getDispatcher()) — set once, never reset */
    private bool $used = false;

    /** What the config array may contain. Anything else is a mistake and is refused. */
    private const CONFIG_KEYS = ['debug', 'basePath', 'baseUrl', 'trailingSlash', 'routesFile', 'urlEncoding', 'implicitHead', 'emitChunkSize', 'emitIdleTimeout'];

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
     * @param array{debug?: bool|int|string|null, basePath?: string|false|null, baseUrl?: string|false|0|null, trailingSlash?: string|null, routesFile?: string|null, urlEncoding?: bool|int|string|null, implicitHead?: bool|int|string|null, emitChunkSize?: int|string|null, emitIdleTimeout?: int|float|string|null} $config
     *
     * @throws RouterException If $config has a key the router does not know, if 'debug',
     *                         'urlEncoding' or 'implicitHead' is neither a boolean nor
     *                         boolean-like nor empty ('' and 0 count as off; null is the
     *                         default), if 'urlEncoding' is off, if 'emitChunkSize' is not
     *                         an integer (or a string of digits) from 1024 to 16777216, if
     *                         'emitIdleTimeout' is no number of seconds above 0 and up to
     *                         3600, if 'baseUrl' is neither a string nor empty, if
     *                         'basePath' is neither a string nor null or false, or if
     *                         'routesFile' is given and no string
     */
    public function __construct(array $config = [])
    {
        $unknown = array_diff(array_keys($config), self::CONFIG_KEYS);
        if ($unknown !== []) {
            // A typo ('basepath') or a key of 1.x ('cacheFile') would otherwise be ignored
            // silently. The message names what is allowed; the keys given are in the debug message.
            throw new RouterException(
                'Unknown config key. Known keys: ' . implode(', ', self::CONFIG_KEYS),
                debugMessage: 'Unknown: ' . implode(', ', array_map(strval(...), $unknown)),
            );
        }

        self::urlEncoding($config['urlEncoding'] ?? true);

        $this->config = [
            'debug' => self::flag('debug', $config['debug'] ?? false),
            'basePath' => self::normalizeBasePath(self::basePath($config['basePath'] ?? null)),
            'baseUrl' => self::baseUrl($config['baseUrl'] ?? null),
            'trailingSlash' => self::trailingSlash($config['trailingSlash'] ?? 'strict'),
            'routesFile' => isset($config['routesFile']) ? self::text('routesFile', $config['routesFile']) : null,
            'implicitHead' => self::flag('implicitHead', $config['implicitHead'] ?? true),
            'emitChunkSize' => self::chunkSize($config['emitChunkSize'] ?? self::EMIT_CHUNK_SIZE),
            'emitIdleTimeout' => self::idleTimeout($config['emitIdleTimeout'] ?? self::EMIT_IDLE_TIMEOUT),
        ];
    }

    /**
     * A plain-text answer that needs nothing the application can replace. When PHP cannot
     * even open the stream for its text (the php:// wrapper unregistered), it goes out with
     * a body that needs none (RouteDispatcher::plain()); what failed is handed to $report.
     *
     * @param \Closure(\Throwable): void $report
     */
    private function plainAnswer(int $status, string $text, \Closure $report): ResponseInterface
    {
        try {
            return new \Nyholm\Psr7\Response($status, RouteDispatcher::PLAIN_HEADERS, $text);
        } catch (\Throwable $e) {
            $report($e);

            return RouteDispatcher::plain($status, $text);
        }
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
     * The base URL as the router keeps it, null for none. Empty means none: null, '' and,
     * as in 1.x, false ('baseUrl' => getenv('APP_URL') without the variable), 0 and '0'.
     * Any other string is put in front of the address as it is (a slash at its end is
     * dropped) — so it has to be what an address can begin with: http or https, '://' and
     * a host, then a port and a path at most. No control character or blank (a line break
     * made every absolute address a Location header the response refuses), no query or
     * fragment (the path would land behind them), not 'example.com' (that made every
     * address relative, 'example.com/users/5') and no other scheme. No user information
     * and no backslash ('https://evil\@trusted.example' is the host 'evil' for a browser),
     * a port of digits if any, and a browser's parser (WHATWG URL) takes it with a host
     * ('https://:443', 'https://[::1' have none). Anything else is refused.
     *
     * @throws RouterException If the value is neither a string nor empty, has a control
     *                         character or a blank, or is no http(s) address of a host
     */
    private static function baseUrl(mixed $value, string $what = "Config 'baseUrl'"): ?string
    {
        if ($value === null || $value === false || $value === '' || $value === '0' || $value === 0) {
            return null;
        }

        if (!is_string($value)) {
            throw new RouterException($what . ' must be a string, or empty for none');
        }

        // The rule belongs to the URL generator, which puts the value in front of every
        // address — a generator built by hand gets it as well (UrlGenerator::setBaseUrl())
        UrlGenerator::checkBaseUrl($value, $what);

        return $value;
    }

    /**
     * The base path from the config: null and, as for 'baseUrl', false mean none — false is
     * what 'basePath' => getenv('ROUTER_BASE_PATH') gives without the variable. Anything else
     * has to be a string (see text()).
     *
     * @throws RouterException If the value is neither of these
     */
    private static function basePath(mixed $value): string
    {
        return $value === null || $value === false ? '' : self::text('basePath', $value);
    }

    /**
     * A config value that has to be a string: anything else was cast ('basePath' => true
     * became '1', [] the word 'Array' with a warning) or failed only when the table was
     * built (a routesFile that is an array, in file_exists()).
     *
     * @throws RouterException If the value is no string
     */
    private static function text(string $name, mixed $value): string
    {
        if (!is_string($value)) {
            throw new RouterException(sprintf("Config '%s' must be a string, got %s", $name, get_debug_type($value)));
        }

        return $value;
    }

    /**
     * URL encoding is always on. Off, url() wrote values as they were and dropped every
     * check with the encoding — a control character, a backslash, a '.' or '..' segment,
     * an address that begins with '//' (another host for a client). The key stays, for a
     * value that means on; anything that means off is refused rather than ignored.
     *
     * @throws RouterException If the value means off, or is neither boolean nor boolean-like
     */
    private static function urlEncoding(mixed $value): void
    {
        if (!self::flag('urlEncoding', $value)) {
            throw new RouterException("Config 'urlEncoding' cannot be turned off: url() always encodes values and checks the address");
        }
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
     * How long emit() waits for the next byte of a body that has not ended: a number of
     * seconds above 0 (a fraction as well), up to an hour — or a string of such a number,
     * with six decimals at most: the wait is a matter of microseconds, and a string with
     * more would be rounded before it is compared ('3600.0000000000000000000000000001' is
     * the float 3600.0). An int or float is compared as the float it is.
     * 0 would give up at the first empty read, which a stream may give while its next
     * bytes are on their way.
     *
     * @throws RouterException If the value is no such number
     */
    private static function idleTimeout(mixed $value): float
    {
        $seconds = match (true) {
            is_int($value), is_float($value) => (float) $value,
            is_string($value) && preg_match('/^\d+(?:\.\d{1,6})?$/D', $value) === 1 => (float) $value,
            default => null,
        };

        // Written so that NAN fails as well: every comparison with it is false
        if ($seconds === null || !($seconds > 0 && $seconds <= self::EMIT_IDLE_TIMEOUT_MAX)) {
            throw new RouterException(sprintf(
                "Config 'emitIdleTimeout' must be a number of seconds above 0 and at most %d",
                self::EMIT_IDLE_TIMEOUT_MAX
            ));
        }

        return $seconds;
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
                '%s() has to be called before the routing table is built: the first request, match(), url() or absoluteUrl() built it',
                $method
            ));
        }
    }

    /**
     * Factory method for fluent creation. Like the constructor it does not look at the
     * environment.
     *
     * @param array{debug?: bool|int|string|null, basePath?: string|false|null, baseUrl?: string|false|0|null, trailingSlash?: string|null, routesFile?: string|null, urlEncoding?: bool|int|string|null, implicitHead?: bool|int|string|null, emitChunkSize?: int|string|null, emitIdleTimeout?: int|float|string|null} $config
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
     * @param array{debug?: bool|int|string|null, basePath?: string|false|null, baseUrl?: string|false|0|null, trailingSlash?: string|null, routesFile?: string|null, urlEncoding?: bool|int|string|null, implicitHead?: bool|int|string|null, emitChunkSize?: int|string|null, emitIdleTimeout?: int|float|string|null} $config Values that take precedence
     *
     * @throws RouterException As the constructor; and if APP_DEBUG or ROUTER_URL_ENCODING is
     *                         read and its value is not boolean-like (APP_DEBUG=maybe), or
     *                         ROUTER_URL_ENCODING means off
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

            // Off is no mode any more (see urlEncoding()); empty meant off as well
            if ($key === 'urlEncoding' && filter_var($value, FILTER_VALIDATE_BOOL) === false) {
                throw new RouterException(sprintf(
                    'Environment variable %s cannot turn URL encoding off: url() always encodes values and checks the address',
                    $variable
                ));
            }

            if ($key === 'trailingSlash' && !in_array($value, ['strict', 'ignore', ''], true)) {
                throw new RouterException(sprintf("Environment variable %s must be 'strict', 'ignore' or empty", $variable));
            }

            if ($key === 'basePath') {
                self::normalizeBasePath($value, 'Environment variable ' . $variable);
            }

            if ($key === 'baseUrl') {
                self::baseUrl($value, 'Environment variable ' . $variable);
            }

            $fromEnvironment[$key] = $value;
        }

        return new self($config + $fromEnvironment);
    }

    /**
     * Quick boot: create, load routes, and run. Reads no environment either — for that:
     * Router::fromEnv()->loadRoutes($routesFile)->run().
     *
     * @param array{debug?: bool|int|string|null, basePath?: string|false|null, baseUrl?: string|false|0|null, trailingSlash?: string|null, routesFile?: string|null, urlEncoding?: bool|int|string|null, implicitHead?: bool|int|string|null, emitChunkSize?: int|string|null, emitIdleTimeout?: int|float|string|null} $config
     * @param string $routesFile Path to routes file
     */
    public static function boot(array $config, string $routesFile): void
    {
        $router = self::create($config)->loadRoutes($routesFile);
        $router->run();
    }

    /**
     * A router is cloned before its first use — a clone is then a router of its own. Once
     * it was used (a request, match(), url(): the route table built or tried), a clone
     * would share the table with the original (a hook added to the clone fired for the
     * original) or run the routes file a second time (what it does to $this, twice).
     *
     * @throws RouterException When the router was used already
     */
    public function __clone()
    {
        if ($this->used) {
            throw new RouterException(
                'A router can be cloned before its first use only: it was already used (a request, match() or url())'
            );
        }
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
     * Set the base URL for absoluteUrl() — for an application that knows it only after the
     * router was built (an .env read later). Like setBasePath(): before the route table is
     * built (by the first request, match(), url() or absoluteUrl()). Null, '' and '0' mean
     * none, as for the config key.
     *
     * @throws RouterException When the route table is built already
     */
    public function setBaseUrl(?string $baseUrl): self
    {
        $this->assertNotInUse('setBaseUrl');
        $this->config['baseUrl'] = self::baseUrl($baseUrl);
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
     *
     * @throws RouterException When a string key is taken already (see Route::addMiddleware())
     */
    public function middleware(string|array|object $middleware): self
    {
        // Numbered entries add up; a string key that is there already is refused (Route::addMiddleware())
        $this->middleware = Route::addMiddleware($this->middleware, is_array($middleware) ? $middleware : [$middleware]);
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
     * of this — nor for a responder set with Response::setResponder() that throws while
     * the router's own 500 is built: that is answered with a plain-text 500.
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
        $this->routes = null;
        return $this;
    }

    // ==================== URL Generation ====================

    /**
     * Generate relative URL for a named route.
     *
     * What the routes file itself throws while it is loaded comes out as it is.
     *
     * @param string $name Route name
     * @param array<string, string|int|float|bool> $params Route parameters
     *
     * @throws Exception\RouteNotFoundException If route name does not exist
     * @throws RouterException If no routes are loaded, or the parameters do not lead back to the route
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
     * Requires baseUrl: from the config, setBaseUrl(), or APP_URL through fromEnv(). What
     * the routes file itself throws while it is loaded comes out as it is.
     *
     * @param string $name Route name
     * @param array<string, string|int|float|bool> $params Route parameters
     *
     * @throws Exception\RouteNotFoundException If route name does not exist
     * @throws RouterException If baseUrl is not configured, no routes are loaded, or the
     *                         parameters do not lead back to the route
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
     * A request the PSR-7 objects do not accept — a Host header with a port that is none, a
     * header name or value outside RFC 7230 — is the client's doing and answered with 400
     * before anything of the application runs; the error hook hears of it. Whatever else
     * fails while the request is built is answered with 500, and reported as well. So is a
     * response whose body was closed or detached before it could be sent (emit() throws
     * for that one).
     */
    public function run(): void
    {
        $psr17 = new Psr17Factory();
        $creator = new ServerRequestCreator($psr17, $psr17, $psr17, $psr17);

        try {
            $request = $creator->fromGlobals();
        } catch (\Throwable $e) {
            $method = $_SERVER['REQUEST_METHOD'] ?? null;
            $uri = $_SERVER['REQUEST_URI'] ?? null;

            $about = [
                'method' => is_string($method) ? $method : '',
                'path' => is_string($uri) ? explode('?', $uri, 2)[0] : '',
            ];
            $this->send($this->unreadableRequest($e, $about), $method !== 'HEAD');

            return;
        }

        $withBody = $request->getMethod() !== 'HEAD';

        // handle() does not throw: what goes wrong is a 500 already
        $response = $this->handle($request);

        // Once output has started, the response is not even looked at
        if (!$this->outputStarted()) {
            $this->deliver($response, $request, $withBody);
        }
    }

    /**
     * What run() sends: everything is read from the response before the first byte goes
     * out; what fails then is answered with a 500, what fails afterwards only reported.
     */
    private function deliver(ResponseInterface $response, ServerRequestInterface $request, bool $withBody): void
    {
        try {
            $prepared = $this->prepare($response, $withBody);
        } catch (\Throwable $e) {
            // Nothing was sent: the response cannot be read (its body closed, a getter
            // that throws). A 500 can go out, and the error hook hears why.
            $this->report($e, $request);
            $prepared = $this->prepare($this->plainAnswer(500, 'Internal Server Error', fn (\Throwable $failure) => $this->report($failure, $request)), $withBody);
        }

        try {
            $this->transmit($prepared, $withBody);
        } catch (\Throwable $e) {
            // Sending failed (a header, the body): nothing can be answered any more, but
            // the error hook hears of it, and no stack trace goes out. Its status is the
            // one that goes out — not the response's when a header failed before the
            // status line (a Location before it made PHP's a 302)
            $this->report($e, $request, $this->sentStatus());
        }
    }

    /**
     * The answer to a request that could not be built. What the PSR-7 objects refuse
     * (InvalidArgumentException) is the client's doing: 400, reported as a RouterException
     * whose message does not repeat what the client sent (that is in getPrevious()).
     * Anything else is not known to be the client's: 500, reported as it is.
     *
     * @param array{method: string, path: string} $about
     */
    private function unreadableRequest(\Throwable $e, array $about): ResponseInterface
    {
        [$status, $text, $code, $report] = $e instanceof \InvalidArgumentException
            ? [400, 'Bad Request', 'BAD_REQUEST', new RouterException('The request could not be read', 0, $e, $e->getMessage())]
            : [500, 'Internal Server Error', 'SERVER_ERROR', $e];

        $this->trigger('error', ['exception' => $report] + $about + ['status' => $status]);

        try {
            return Response::error($text, $status, $code);
        } catch (\Throwable $failure) {
            // The application's responder (Response::setResponder()) failed
            $this->trigger('error', ['exception' => $failure] + $about + ['status' => $status]);

            return $this->plainAnswer($status, $text, fn (\Throwable $last) => $this->trigger('error', ['exception' => $last] + $about + ['status' => $status]));
        }
    }

    /**
     * PSR-15: Handle a request and return a response.
     *
     * @param ServerRequestInterface $request PSR-7 request
     *
     * Never throws. What a handler, a middleware, the routes file, the error handler
     * (setErrorHandler()), the application's responder (Response::setResponder()) or the
     * request and response objects themselves throw becomes a 500 response, and the error
     * hook gets every one of those exceptions.
     *
     * @return ResponseInterface PSR-7 response
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            // Everything a request runs into in there is answered in there, through
            // errorResponse() (see getDispatcher())
            return $this->getDispatcher()->handle($request);
        } catch (\Throwable $e) {
            // What is left: the routes could not be loaded
            /** @var \WeakMap<\Throwable, true> $known */
            $known = new \WeakMap();
            $response = $this->errorResponse($e, $request, $known);

            // No answer to HEAD carries a body with implicitHead on — this one included
            if (!$this->config['implicitHead'] || RouteDispatcher::describe($request)['method'] !== 'HEAD') {
                return $response;
            }

            try {
                return $response->withBody(\Nyholm\Psr7\Stream::create(''));
            } catch (\Throwable $failure) {
                // The error handler's response does not take another body (or PHP cannot
                // open the stream for an empty one)
                $this->reportOnce($failure, $request, $known);

                return RouteDispatcher::plain(500);
            }
        }
    }

    /**
     * Look a request up in the route table without executing anything: no middleware or
     * handler runs, none of the routing hooks fires, no container is needed.
     *
     * Pass the request on with the result as attribute RouteMatch::class and handle() does
     * not look it up a second time.
     *
     * The first call loads the routes, as the first handle() or url() does: base path and
     * trailing slash mode are taken as they are at that moment. What the routes file itself
     * throws while it is loaded comes out as it is.
     *
     * @throws RouterException If no routes are loaded or routes file is invalid, or when PCRE
     *                         gives up on a route's expression (the backtrack limit, the JIT
     *                         stack) — through handle() that is a 500, never "no match"
     */
    public function match(ServerRequestInterface $request): RouteMatch
    {
        return $this->getDispatcher()->match($request);
    }

    /**
     * The response for an exception: the application's (setErrorHandler()), the router's
     * 500 in the format of the responder, or — when that responder fails too — a 500 that
     * depends on nothing the application can replace. Never throws; the error hook gets the
     * exception, and every further one that comes up on the way.
     *
     * @param \WeakMap<\Throwable, true> $known The call's record of reported exceptions (see
     *                                          RouteDispatcher::setErrorResponderWithRecord()): the same exception
     *                                          object is not reported twice — an error handler that only
     *                                          hands the exception back, a responder that throws what the
     *                                          error handler threw, have nothing new to say
     */
    private function errorResponse(\Throwable $e, ServerRequestInterface $request, \WeakMap $known): ResponseInterface
    {
        $this->reportOnce($e, $request, $known);

        if ($this->errorHandler !== null) {
            try {
                $response = ($this->errorHandler)($e, $request);
                if ($response !== null) {
                    return $response;
                }
            } catch (\Throwable $failure) {
                // The error handler failed itself: report that too, answer for the original
                $this->reportOnce($failure, $request, $known);
            }
        }

        try {
            return Response::serverError(
                $this->config['debug'] ? $e->getMessage() : 'Internal Server Error',
                $this->config['debug'] ? [
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => explode("\n", $e->getTraceAsString()),
                ] : null
            );
        } catch (\Throwable $failure) {
            // The application's responder (Response::setResponder()) failed
            $this->reportOnce($failure, $request, $known);

            return $this->plainAnswer(500, 'Internal Server Error', fn (\Throwable $last) => $this->reportOnce($last, $request, $known));
        }
    }

    /**
     * @param \WeakMap<\Throwable, true> $known What was reported during this call
     */
    private function reportOnce(\Throwable $e, ServerRequestInterface $request, \WeakMap $known): void
    {
        if (!isset($known[$e])) {
            $known[$e] = true;
            $this->report($e, $request);
        }
    }

    /**
     * @param int $status The status of the router's own answer to the request
     */
    private function report(\Throwable $e, ServerRequestInterface $request, int $status = 500): void
    {
        $this->trigger('error', ['exception' => $e] + RouteDispatcher::describe($request) + ['status' => $status]);
    }

    /**
     * The status PHP has set for this response so far; where it keeps none (the CLI), the
     * 200 it would send.
     */
    private function sentStatus(): int
    {
        $status = http_response_code();

        return is_int($status) ? $status : 200;
    }

    // ==================== Internal ====================

    /**
     * The route table, built at the first use — the first request, match(), url() — from
     * the routes file and the configuration of that moment, then kept: what goes into it
     * cannot change afterwards (assertNotInUse()). Where it cannot be built (no routes
     * file, a route refused), nothing is kept, and the next use tries again.
     *
     * @throws RouterException If no routes are loaded or routes file is invalid, or a route
     *                         is refused when the table is built
     */
    private function getDispatcher(): RouteDispatcher
    {
        if ($this->dispatcher !== null) {
            return $this->dispatcher;
        }

        // From here on the router is in use, whether the table can be built or not
        $this->used = true;

        $routes = $this->loadRoutesFile();

        $this->collector = new RouteCollector();

        // Configure trailing slash handling based on mode
        if ($this->config['trailingSlash'] === 'strict') {
            $this->collector->setPreserveTrailingSlash(true);
        }

        // What the routes file registers on the router itself — middleware, apps, hooks —
        // belongs to this attempt. A table that cannot be built leaves none of it behind:
        // the next attempt runs the file again, and would otherwise fail at a middleware key
        // or app prefix the first attempt took, hiding why the table cannot be built.
        $middleware = $this->middleware;
        $apps = $this->apps;
        $hooks = $this->hooks;

        try {
            $routes($this->collector);
            $data = $this->collector->getData();
        } catch (\Throwable $e) {
            $this->middleware = $middleware;
            $this->apps = $apps;
            $this->hooks = $hooks;

            throw $e;
        }

        $this->dispatcher = new RouteDispatcher(
            $data,
            $this->container,
            $this->config['basePath'],
            $this->config['trailingSlash'],
            $this->config['debug']
        );
        $this->dispatcher
            ->setImplicitHead($this->config['implicitHead'])
            ->setMiddleware($this->middleware)
            ->setApps($this->apps)
            ->setErrorResponderWithRecord($this->errorResponse(...));

        // Forward hooks from Router to Dispatcher
        foreach ($this->hooks as $event => $callbacks) {
            foreach ($callbacks as $callback) {
                $this->dispatcher->on($event, $callback);
            }
        }

        return $this->dispatcher;
    }

    /**
     * The callable of the routes file. What it threw while it was loaded is thrown again,
     * and when the table could not be built from its callable (a route was refused), the
     * next call tries the callable again — the file is not required a second time.
     *
     * @throws RouterException If no routes file is set or it does not exist
     */
    private function loadRoutesFile(): \Closure
    {
        // Whether the file is there is asked until it was loaded — not afterwards, where
        // the working directory may have changed
        if ($this->routes === null) {
            $file = $this->config['routesFile'];

            if ($file === null || $file === '' || !file_exists($file)) {
                throw new RouterException('No routes loaded. Use loadRoutes() first.');
            }

            // Required in here: the closure of the file sees this router as $this, as in 1.x
            $this->routes = $this->requireRoutes($file);
        }

        if ($this->routes instanceof \Throwable) {
            throw $this->routes;
        }

        return $this->routes;
    }

    /**
     * The callable a routes file returns — or what requiring it threw, kept as the result:
     * a file that declares a function or a class cannot be required a second time, so a
     * failure is not retried by requiring it again but thrown again (loadRoutesFile()).
     */
    private function requireRoutes(string $file): \Closure|\Throwable
    {
        try {
            $callback = require $file;
        } catch (\Throwable $e) {
            return $e;
        }

        if ($callback instanceof \Closure) {
            return $callback;
        }

        return is_callable($callback)
            ? $callback(...)
            : new RouterException('Route file must return callable: return function(RouteCollector $r) { ... };');
    }

    /**
     * The URL generator, built once from the routes of the table and the configuration that
     * went into it (base path, trailing slash mode, base URL) — so that url() writes the
     * addresses the table answers. Kept only once it is set up completely.
     */
    private function getUrlGenerator(): UrlGenerator
    {
        if ($this->urlGenerator === null) {
            // Ensure routes are loaded (initializes the collector)
            $this->getDispatcher();
            assert($this->collector !== null);

            // Kept only once it is set up completely
            $generator = new UrlGenerator($this->collector->getRoutes(), $this->collector->getPatterns());

            $generator->setBasePath($this->config['basePath']);
            $generator->setIgnoreTrailingSlash($this->config['trailingSlash'] === 'ignore');

            if ($this->config['baseUrl'] !== null) {
                $generator->setBaseUrl($this->config['baseUrl']);
            }

            $this->urlGenerator = $generator;
        }

        return $this->urlGenerator;
    }

    /**
     * Send a response: status line, headers and body — for a response built outside
     * handle(), or one handle() returned when run() is not used. What can be read is read
     * before anything is sent; nothing is sent once output has started (the error hook gets
     * type 'emit'). Fields that exist once per message replace what the host set, all other
     * fields add up.
     *
     * @param bool $withBody False for a HEAD request: the body is not read at all — and
     *                       must be, for an answer to HEAD that keeps the Content-Length of
     *                       the GET: emit() cannot tell it from a body that is missing
     *
     * @throws RouterException If the body cannot be read, the reason phrase has a control
     *                         character other than a tab, the protocol version is no
     *                         version, a header line is none, the response carries a
     *                         Transfer-Encoding, a Content-Length that is not one value of
     *                         digits, or one above 0 for a body that is empty — before
     *                         anything is sent; and after the headers, when the body gives
     *                         no byte for 'emitIdleTimeout' seconds before its end, ends
     *                         short of its Content-Length, or goes beyond it (what goes
     *                         beyond is not sent)
     */
    public function emit(ResponseInterface $response, bool $withBody = true): void
    {
        $this->send($response, $withBody);
    }

    /**
     * What emit() does; run() calls it directly.
     */
    private function send(ResponseInterface $response, bool $withBody): void
    {
        // Once output has started, the response is not even looked at
        if (!$this->outputStarted()) {
            $this->transmit($this->prepare($response, $withBody), $withBody);
        }
    }

    /**
     * Whether something was printed before the router could send (a stray echo, a
     * displayed warning). Status and headers can no longer be sent, so nothing is — but
     * not silently: the error hook gets type 'emit'.
     */
    private function outputStarted(): bool
    {
        // @codeCoverageIgnoreStart
        // headers_sent() is always false in CLI/PHPUnit; EmitOverHttpTest covers it over HTTP
        if (headers_sent($file, $line)) {
            // PHP only knows the place when the output came from a script line, not after flush()
            $message = 'Response not sent: output had already started';
            $this->trigger('error', [
                'type' => 'emit',
                'message' => $message,
                'exception' => new RouterException($message, debugMessage: $file !== '' ? sprintf('%s:%d', $file, $line) : null),
                // What went out with the output before: the router's answer did not
                'status' => $this->sentStatus(),
            ]);

            return true;
        }
        // @codeCoverageIgnoreEnd

        return false;
    }

    /**
     * Everything that can be read from the response before anything is sent: status line,
     * headers, the body ready to be read. What fails here fails before the first byte, and
     * run() can still answer with a 500.
     *
     *
     * @throws RouterException If the response body cannot be read (closed or detached), the
     *                         reason phrase has a control character other than a tab, the
     *                         protocol version is no version (a digit, a dot and a digit), a
     *                         header has a name that is no token or a value with a control
     *                         character other than a tab, or the framing fields say nothing
     *                         one can rely on (see framing()), or a Content-Length above 0
     *                         stands in front of a body that is empty
     *
     * @return array{status: string, code: int, headers: array<int|string, array<string>>, body: StreamInterface, length: ?int}
     */
    private function prepare(ResponseInterface $response, bool $withBody): array
    {
        // Readability BEFORE anything is sent: a detached/closed body used to blow up loudly
        // inside __toString(). Throwing after the headers went out would leave a half-sent
        // response; throwing here lets the error handler still produce a proper 500.
        $unreadable = 'Response body is not readable (closed or detached before emit)';
        try {
            $body = $response->getBody();
            $readable = $body->isReadable();
        } catch (\Throwable $e) {
            throw new RouterException($unreadable, 0, $e);
        }

        if (!$readable) {
            throw new RouterException($unreadable);
        }

        // RFC 9112: reason-phrase = *( HTAB / SP / VCHAR / obs-text ). The PSR-7 objects do
        // not check it, and PHP drops a status line with a line break and sends its own 200
        // instead — a 403 went out as a 200, with a warning in the body. Refused here, before
        // anything is sent, so that run() can still answer with a 500.
        $reasonPhrase = $response->getReasonPhrase();
        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $reasonPhrase) === 1) {
            throw new RouterException(
                'Response reason phrase must not contain a control character other than a tab',
                debugMessage: (string) json_encode($reasonPhrase, JSON_INVALID_UTF8_SUBSTITUTE),
            );
        }

        // The protocol version goes into the same line, and the PSR-7 objects do not check
        // it either: "1.1\r\nX-Injected: 1" turned a 403 into PHP's 200 as well. A version
        // is a digit, and a dot and a digit where it has a minor one ("1.1", "2").
        $protocolVersion = $response->getProtocolVersion();
        if (preg_match('/^\d(?:\.\d)?\z/', $protocolVersion) !== 1) {
            throw new RouterException(
                'Response protocol version must be a digit, with a dot and a digit for a minor version (1.1, 2)',
                debugMessage: (string) json_encode($protocolVersion, JSON_INVALID_UTF8_SUBSTITUTE),
            );
        }

        $statusLine = sprintf(
            'HTTP/%s %d %s',
            $protocolVersion,
            $response->getStatusCode(),
            $reasonPhrase
        );

        // A header line is checked as well — A11: the PSR-7 object of an application may
        // not check it, and header() only finds out once lines went out (a Location before
        // it had made PHP's status a 302, a 403 got lost). A name is a token (RFC 9110), a
        // value a string without a control character other than a tab.
        $headers = $response->getHeaders();
        foreach ($headers as $name => $values) {
            $name = (string) $name;
            $fine = $name !== '' && strspn($name, RouteCollector::TOKEN_CHARACTERS) === strlen($name);

            foreach ($fine ? $values : [] as $value) {
                $fine = $fine && preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) !== 1;
            }

            if (!$fine) {
                throw new RouterException(
                    'Response header must have a token as its name and a value without a control character other than a tab',
                    debugMessage: (string) json_encode($name, JSON_INVALID_UTF8_SUBSTITUTE),
                );
            }
        }

        $length = self::framing($headers);
        $code = $response->getStatusCode();

        // A Content-Length above 0 in front of a body that is empty promises bytes that
        // never come — a kept-alive client waits for them, or reads the next response as
        // this one's body. Known before anything is sent where the body knows its size (a
        // body of unknown size is held to it while it is sent, see transmit()). The answer to
        // HEAD keeps the GET's Content-Length: it goes out with $withBody false.
        if ($withBody && $length !== null && $length > 0 && !self::bodiless($code) && $body->getSize() === 0) {
            throw new RouterException(
                'Response body is empty, but its Content-Length is not (an answer to HEAD is emitted with withBody false)',
                debugMessage: sprintf('Content-Length %d', $length),
            );
        }

        // For string bodies the emitted bytes are identical to the previous `echo
        // $response->getBody()`: Nyholm's __toString() rewound the stream and returned
        // everything, which is exactly what transmit() does piecewise.
        if ($withBody && $body->isSeekable()) {
            $body->rewind();
        }

        return ['status' => $statusLine, 'code' => $code, 'headers' => $headers, 'body' => $body, 'length' => $length];
    }

    /**
     * The Content-Length a response's body is held to, null for none — once the fields that
     * say where a body ends (RFC 9112, 6) are known to say one thing. The emitter applies no
     * transfer coding and sends the body as it is: a Transfer-Encoding of the response's own
     * would announce a framing the bytes do not have (with a Content-Length next to it, two
     * framings a sender must not combine). A Content-Length is one value of digits: two of
     * them ('3' and '5', or '3, 3' in one line), or one that is no number, used to be
     * treated as no length at all — and sent as it was, a body of any length behind it.
     *
     * @param array<int|string, array<string>> $headers
     *
     * @throws RouterException If the response has a Transfer-Encoding, or a Content-Length
     *                         that is not exactly one value of digits (up to 18 of them)
     */
    private static function framing(array $headers): ?int
    {
        $lengths = [];

        // By name in any case: a PSR-7 object of another make may keep 'content-length' and
        // 'Content-Length' as two fields
        foreach ($headers as $name => $values) {
            $field = strtolower((string) $name);

            if ($field === 'transfer-encoding') {
                throw new RouterException(
                    'Response must not carry a Transfer-Encoding: the emitter applies no transfer coding, the body goes out as it is',
                    debugMessage: (string) json_encode($values, JSON_INVALID_UTF8_SUBSTITUTE),
                );
            }

            if ($field === 'content-length') {
                array_push($lengths, ...$values);
            }
        }

        if ($lengths === []) {
            return null;
        }

        // Digits only, one value — and no more than PHP counts to: 19 digits and more would
        // be cut to PHP_INT_MAX
        if (count($lengths) !== 1 || preg_match('/^\d+$/D', $lengths[0]) !== 1 || strlen(ltrim($lengths[0], '0')) > 18) {
            throw new RouterException(
                'Response Content-Length must be exactly one value of digits',
                debugMessage: (string) json_encode($lengths, JSON_INVALID_UTF8_SUBSTITUTE),
            );
        }

        return (int) $lengths[0];
    }

    /**
     * Whether a response of this status has no content, whatever its headers say (RFC 9110,
     * 6.4.1): a 1xx, 204 or 304.
     */
    private static function bodiless(int $code): bool
    {
        return $code < 200 || $code === 204 || $code === 304;
    }

    /**
     * @param array{status: string, code: int, headers: array<int|string, array<string>>, body: StreamInterface, length: ?int} $prepared
     */
    private function transmit(array $prepared, bool $withBody): void
    {
        // Asked again: reading the response may have printed something (a getter that
        // echoes, a displayed warning)
        // @codeCoverageIgnoreStart
        if ($this->outputStarted()) {
            return;
        }
        // @codeCoverageIgnoreEnd

        // Headers. A field that exists once per message replaces what the host already set
        // under that name — two Content-Type or Location lines are not a valid response. All
        // other fields are lists: the response's lines are added to the host's, so a
        // "Vary: Cookie" or a session's "Cache-Control: no-store" set before run() stays.
        foreach ($prepared['headers'] as $name => $values) {
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
        header($prepared['status']);

        // HEAD: PHP discards the output anyway, so do not read the body at all — for a
        // Response::file() that would be the whole file.
        if (!$withBody) {
            return;
        }

        // Body — pulled in chunks so large payloads (file downloads via Response::file())
        // never sit in memory as a whole.
        $body = $prepared['body'];

        // An empty read does not mean "done": PSR-7 gives '' where no bytes are there yet
        // (a pump or append stream, a source that is not ready) while eof() is still false,
        // and breaking on it would truncate the body — so would giving up after a few reads
        // in a row (2.1.1 stopped silently at the third). Waited for instead, with a pause
        // that grows from none to EMIT_IDLE_PAUSE_MAX, until 'emitIdleTimeout' passed
        // without a byte — which still guards against a stream that never reports eof — and
        // then said: the client got less than the response promised, which must not look
        // like an answer that went out whole.
        $expected = $prepared['length'];
        // A 1xx, 204 or 304 has no body whatever its Content-Length says (RFC 9110, 8.6): no
        // length is held against what such a response sends
        $bodiless = self::bodiless($prepared['code']);
        $sent = 0;
        $pause = 0;
        // When the wait for the next byte ends, in seconds of the monotonic clock: set by the
        // first read that gives nothing, cleared by a byte that comes in time
        $deadline = null;
        while (!$body->eof()) {
            $chunk = $body->read($this->config['emitChunkSize']);
            $now = hrtime(true) / 1e9;

            // Asked after every read, before what it gave is used: a byte that comes after
            // the deadline came too late, and must not wind the clock back — a source that is
            // always a little late would never be given up on
            if ($deadline !== null && $now >= $deadline) {
                throw new RouterException(
                    'Response body stalled before its end: no byte within emitIdleTimeout',
                    debugMessage: sprintf('%d bytes sent', $sent),
                );
            }

            if ($chunk === '') {
                $deadline ??= $now + $this->config['emitIdleTimeout'];
                if ($pause > 0) {
                    // Half the time left at most: the reads come closer as the deadline nears,
                    // and a byte that comes before it is read before it, not just after
                    usleep((int) min($pause, ($deadline - $now) * 5e5));
                }
                $pause = min(max(2 * $pause, 1000), self::EMIT_IDLE_PAUSE_MAX);

                continue;
            }

            $pause = 0;
            $deadline = null;

            // Never more than the Content-Length: on a kept-alive connection the client reads
            // what goes beyond it as the start of the next response. Sent up to it, then said
            if ($expected !== null && !$bodiless && strlen($chunk) > $expected - $sent) {
                echo substr($chunk, 0, $expected - $sent);

                throw new RouterException(
                    'Response body is longer than its Content-Length',
                    debugMessage: sprintf('%d bytes sent, more followed', $expected),
                );
            }

            $sent += strlen($chunk);
            echo $chunk;
        }

        // So must a body that ended short of its Content-Length — also one that sent no byte
        // at all: a body of unknown size that turns out empty is no answer without a body
        // (an answer to HEAD is emitted with $withBody false, see prepare())
        if ($expected !== null && $sent < $expected && !$bodiless) {
            throw new RouterException(
                'Response body ended before its Content-Length',
                debugMessage: sprintf('%d of %d bytes sent', $sent, $expected),
            );
        }
    }
}
