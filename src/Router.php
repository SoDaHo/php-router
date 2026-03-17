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
     * @param array{debug?: bool, basePath?: string, baseUrl?: string, trailingSlash?: string, cacheFile?: string, cacheSignature?: string, routesFile?: string, urlEncoding?: bool} $config
     */
    public function __construct(array $config = [])
    {
        // Config precedence: $config > $_ENV > getenv() > default (consistent with pdo-wrapper)
        $this->config = [
            'debug' => $config['debug']
                ?? filter_var(self::env('APP_DEBUG') ?? false, FILTER_VALIDATE_BOOL)
                ?: in_array(self::env('APP_ENV') ?? '', ['local', 'dev', 'development'], true),
            'basePath' => (string) ($config['basePath'] ?? self::env('ROUTER_BASE_PATH') ?? ''),
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
        if (isset($_ENV[$key])) {
            return (string) $_ENV[$key];
        }

        // getenv() fallback for legacy compatibility
        $value = getenv($key);

        return $value !== false ? $value : null;
    }

    /**
     * Factory method for fluent creation.
     *
     * @param array{debug?: bool, basePath?: string, baseUrl?: string, trailingSlash?: string, cacheFile?: string, cacheSignature?: string, routesFile?: string, urlEncoding?: bool} $config
     */
    public static function create(array $config = []): self
    {
        return new self($config);
    }

    /**
     * Quick boot: create, load routes, and run.
     *
     * @param array{debug?: bool, basePath?: string, baseUrl?: string, trailingSlash?: string, cacheFile?: string, cacheSignature?: string, routesFile?: string, urlEncoding?: bool} $config
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
     * Set base path for all routes.
     *
     * @param string $basePath Base path prefix (e.g., '/api/v1')
     */
    public function setBasePath(string $basePath): self
    {
        $this->config['basePath'] = rtrim($basePath, '/');
        return $this;
    }

    /**
     * Enable route caching.
     *
     * @param string $file Path to cache file
     * @param string|null $signature Cache invalidation key (e.g., app version)
     */
    public function enableCache(string $file, ?string $signature = null): self
    {
        $this->config['cacheFile'] = $file;
        $this->config['cacheSignature'] = $signature;
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
     * @throws RouterException If no routes are loaded
     */
    public function run(): void
    {
        $psr17 = new Psr17Factory();
        $creator = new ServerRequestCreator($psr17, $psr17, $psr17, $psr17);
        $response = $this->handle($creator->fromGlobals());
        $this->emit($response);
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

        // Try loading from cache (only in non-debug mode)
        if ($this->config['cacheFile']) {
            $cache = new RouteCache(
                $this->config['cacheFile'],
                $this->config['cacheSignature'],
                !$this->config['debug'] // Disabled in debug mode
            );

            if (!$this->config['debug']) {
                try {
                    $data = $cache->load();
                } catch (CacheException $e) {
                    // Cache corrupted (e.g., HMAC mismatch) - trigger error hook and rebuild
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

            // Save to cache (fails gracefully if Closures are used)
            try {
                $cache?->save([
                    'dispatchData' => $dispatchData,
                    'namedRoutes' => $namedRoutes,
                ]);
            } catch (\LogicException $e) {
                // Closures cannot be cached - trigger error hook and continue without cache
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

    private function emit(ResponseInterface $response): void
    {
        // @codeCoverageIgnoreStart
        // headers_sent() is always false in CLI/PHPUnit
        if (headers_sent()) {
            return;
        }
        // @codeCoverageIgnoreEnd

        // Status line
        header(sprintf(
            'HTTP/%s %d %s',
            $response->getProtocolVersion(),
            $response->getStatusCode(),
            $response->getReasonPhrase()
        ));

        // Headers
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header("$name: $value", false);
            }
        }

        // Body
        echo $response->getBody();
    }
}
