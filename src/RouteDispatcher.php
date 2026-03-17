<?php

declare(strict_types=1);

namespace Sodaho\Router;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\Middleware\MiddlewareHandler;
use Sodaho\Router\Middleware\RouteHandler;
use Sodaho\Router\Traits\HasHooks;

/**
 * PSR-15 RequestHandler that dispatches requests to routes.
 *
 * Kept slim (~200 LOC) by delegating to specialized classes.
 */
class RouteDispatcher implements RequestHandlerInterface
{
    use HasHooks;

    private Dispatcher $dispatcher;
    private string $basePath;
    private string $trailingSlash;
    private bool $debug;

    /**
     * Create a new RouteDispatcher.
     *
     * @param array{0: array<string, array<string, Route>>, 1: array<string, array<int, array{regex: string, route: Route, casts: array<string, string>}>>} $dispatchData Compiled route data from RouteCollector
     * @param ContainerInterface|null $container PSR-11 container for dependency injection
     * @param string $basePath Base path prefix
     * @param string $trailingSlash Trailing slash mode ('strict' or 'ignore')
     * @param bool $debug Enable debug mode
     */
    public function __construct(
        array $dispatchData,
        private readonly ?ContainerInterface $container = null,
        string $basePath = '',
        string $trailingSlash = 'strict',
        bool $debug = false
    ) {
        $this->dispatcher = new Dispatcher($dispatchData[0], $dispatchData[1]);
        $this->basePath = $basePath;
        $this->trailingSlash = $trailingSlash;
        $this->debug = $debug;
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
        $method = $request->getMethod();
        $uri = rawurldecode($request->getUri()->getPath());

        // BasePath handling: requests MUST start with basePath
        if ($this->basePath !== '') {
            $basePathLen = strlen($this->basePath);
            // Must start with basePath AND either be exact match or followed by '/'
            // This prevents /api from matching /apiX
            if (!str_starts_with($uri, $this->basePath) ||
                (strlen($uri) > $basePathLen && $uri[$basePathLen] !== '/')) {
                // Request doesn't have required basePath prefix -> 404
                return $this->handleNotFound($method, $uri);
            }
            $uri = substr($uri, $basePathLen) ?: '/';
        }

        // Trailing slash handling
        if ($this->trailingSlash === 'ignore' && $uri !== '/') {
            $uri = rtrim($uri, '/');
        }

        $startTime = microtime(true);
        $match = $this->dispatcher->dispatch($method, $uri);

        // Handle non-FOUND cases directly (no casting involved)
        if ($match[0] === Dispatcher::NOT_FOUND) {
            return $this->handleNotFound($method, $uri);
        }

        if ($match[0] === Dispatcher::METHOD_NOT_ALLOWED) {
            return $this->handleMethodNotAllowed($method, $uri, $this->ensureStringArray($match[1]));
        }

        if ($match[0] !== Dispatcher::FOUND) {
            // @codeCoverageIgnoreStart
            // Defense-in-depth: Dispatcher only returns FOUND, NOT_FOUND, or METHOD_NOT_ALLOWED
            return Response::serverError('Unknown dispatcher result');
            // @codeCoverageIgnoreEnd
        }

        // FOUND: Cast parameters first (wrapped in try-catch)
        // This ONLY catches casting errors, not controller TypeErrors!
        try {
            $castedParams = $this->castParams($match[2], $match[3]);
        } catch (\TypeError $e) {
            // Casting errors (invalid int, float, bool) -> 400 Bad Request
            // This is a client error (invalid parameter), not a server error
            $this->trigger('error', [
                'method' => $method,
                'path' => $uri,
                'exception' => $e,
            ]);
            $message = $this->debug ? $e->getMessage() : 'Bad Request';
            return Response::error($message, 400, 'INVALID_PARAMETER');
        }

        // Controller execution is NOT wrapped - TypeErrors here are real 500s
        return $this->handleFound($this->ensureRoute($match[1]), $castedParams, $request, $method, $uri, $startTime);
    }

    /**
     * @param array<string, string|int|float|bool> $params Already-casted route parameters
     */
    private function handleFound(
        Route $route,
        array $params,
        ServerRequestInterface $request,
        string $method,
        string $uri,
        float $startTime
    ): ResponseInterface {
        // 1. Inject parameters into Request (BEFORE Middleware!)
        // Store route params separately for handler invocation
        $request = $request->withAttribute('_route_params', $params);
        foreach ($params as $key => $value) {
            $request = $request->withAttribute($key, $value);
        }

        // 2. Build Middleware Chain
        $handler = new RouteHandler($route->handler, $this->container);
        foreach (array_reverse($route->middleware) as $middleware) {
            $handler = new MiddlewareHandler($this->resolveMiddleware($middleware), $handler);
        }

        $response = $handler->handle($request);

        // 3. Trigger hook AFTER successful dispatch
        $this->trigger('dispatch', [
            'method' => $method,
            'path' => $uri,
            'route' => $route->pattern,
            'handler' => $route->handler,
            'params' => $params,
            'duration' => microtime(true) - $startTime,
        ]);

        return $response;
    }

    /**
     * @param array<string, string> $params
     * @param array<string, string> $casts
     *
     * @throws \TypeError If casting fails
     *
     * @return array<string, string|int|float|bool>
     */
    private function castParams(array $params, array $casts): array
    {
        /** @var array<string, string|int|float|bool> $result */
        $result = $params;

        foreach ($casts as $key => $type) {
            if (!isset($result[$key])) {
                continue;
            }

            /** @var string $value */
            $value = $result[$key];
            $result[$key] = match ($type) {
                'int' => $this->castInt($value, $key),
                'float' => $this->castFloat($value, $key),
                'bool' => $this->castBool($value, $key),
                default => $value,
            };
        }

        return $result;
    }

    private function handleNotFound(string $method, string $uri): ResponseInterface
    {
        $this->trigger('notFound', ['method' => $method, 'path' => $uri]);
        return Response::notFound();
    }

    /**
     * @param string[] $allowed
     */
    private function handleMethodNotAllowed(string $method, string $uri, array $allowed): ResponseInterface
    {
        $this->trigger('methodNotAllowed', [
            'method' => $method,
            'path' => $uri,
            'allowed_methods' => $allowed,
        ]);
        return Response::methodNotAllowed($allowed);
    }

    /**
     * @throws RouterException If middleware cannot be resolved
     */
    private function resolveMiddleware(string|object $middleware): MiddlewareInterface
    {
        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }

        if (is_string($middleware)) {
            if ($this->container?->has($middleware)) {
                $resolved = $this->container->get($middleware);
                if ($resolved instanceof MiddlewareInterface) {
                    return $resolved;
                }
            }
            if (class_exists($middleware)) {
                $instance = new $middleware();
                if ($instance instanceof MiddlewareInterface) {
                    return $instance;
                }
            }
        }

        throw new RouterException(
            sprintf("Cannot resolve middleware '%s'", is_string($middleware) ? $middleware : $middleware::class)
        );
    }

    // ==================== Validated Casting (Spec-compliant) ====================

    /**
     * Rejects: 01, 1e3, 5.0, abc (only pure integers allowed).
     *
     * @throws \TypeError If value is not a valid integer
     */
    private function castInt(string $value, string $key): int
    {
        // Accepts: 0, 5, -10. Rejects: 00, -0 (except literal 0), 01, 1e3, 5.0
        if (!preg_match('/^-?(?:0|[1-9]\d*)$/', $value)) {
            throw new \TypeError(
                sprintf("Parameter '%s': expected integer, got '%s'", $key, $value)
            );
        }

        $intVal = (int) $value;

        // Overflow check: casting back should give same string
        if ((string) $intVal !== $value) {
            throw new \TypeError(
                sprintf("Parameter '%s': integer overflow", $key)
            );
        }

        return $intVal;
    }

    /**
     * @throws \TypeError If value is not a valid decimal
     */
    private function castFloat(string $value, string $key): float
    {
        // Accepts: 5, 5.5, -3.14. Rejects: 1e3, 5.
        if (!preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
            throw new \TypeError(
                sprintf("Parameter '%s': expected decimal, got '%s'", $key, $value)
            );
        }

        return (float) $value;
    }

    /**
     * @throws \TypeError If value is not a valid boolean
     *
     * @codeCoverageIgnore Dead code: regex pattern filters invalid bool values before this is called
     */
    private function castBool(string $value, string $key): bool
    {
        return match (strtolower($value)) {
            'true', '1' => true,
            'false', '0' => false,
            default => throw new \TypeError(
                sprintf("Parameter '%s': expected boolean (true/false/1/0), got '%s'", $key, $value)
            ),
        };
    }

    // ==================== Type Assertion Helpers ====================

    private function ensureRoute(mixed $value): Route
    {
        assert($value instanceof Route);
        return $value;
    }

    /** @return string[] */
    private function ensureStringArray(mixed $value): array
    {
        assert(is_array($value));
        /** @var string[] $value */
        return $value;
    }
}
