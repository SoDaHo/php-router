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
 * Looks the route up, runs the middleware for every request around the answer and
 * delegates route middleware and handlers to specialized classes.
 */
class RouteDispatcher implements RequestHandlerInterface
{
    use HasHooks;

    private Dispatcher $dispatcher;
    private string $basePath;
    private string $trailingSlash;
    private bool $debug;
    private bool $implicitHead = false;

    /** @var array<int, string|object> Middleware for every request, outermost first */
    private array $middleware = [];

    /** @var (\Closure(\Throwable, ServerRequestInterface): ResponseInterface)|null */
    private ?\Closure $errorResponder = null;

    /**
     * The lookups this dispatcher made, each with the request path it was made for. Only
     * these are taken over from a request — a RouteMatch built elsewhere never is.
     *
     * @var \WeakMap<RouteMatch, string>
     */
    private \WeakMap $issued;

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
        private ?ContainerInterface $container = null,
        string $basePath = '',
        string $trailingSlash = 'strict',
        bool $debug = false
    ) {
        $this->dispatcher = new Dispatcher($dispatchData[0], $dispatchData[1]);
        $this->basePath = $basePath;
        $this->trailingSlash = $trailingSlash;
        $this->debug = $debug;
        $this->issued = new \WeakMap();
    }

    // ==================== Wiring (used by Router) ====================

    /**
     * Set the PSR-11 container used to resolve middleware and controllers.
     */
    public function setContainer(?ContainerInterface $container): static
    {
        $this->container = $container;
        return $this;
    }

    /**
     * Middleware that runs for every request — also for those that end in 404 or 405.
     *
     * @param array<int, string|object> $middleware Class names or instances, outermost first
     */
    public function setMiddleware(array $middleware): static
    {
        $this->middleware = $middleware;
        return $this;
    }

    /**
     * Let HEAD requests without a HEAD route of their own run through the GET route.
     */
    public function setImplicitHead(bool $implicitHead): static
    {
        $this->implicitHead = $implicitHead;

        // What was looked up under the other setting no longer stands in for a fresh lookup
        $this->issued = new \WeakMap();

        return $this;
    }

    /**
     * Turn what route middleware and handlers throw into a response, inside the middleware
     * set with setMiddleware() — and, as the last resort, what that middleware throws
     * itself. Without a responder exceptions leave handle() as before. What the responder
     * throws itself leaves handle() too; it is not asked a second time.
     *
     * @param (\Closure(\Throwable, ServerRequestInterface): ResponseInterface)|null $responder
     */
    public function setErrorResponder(?\Closure $responder): static
    {
        $this->errorResponder = $responder;
        return $this;
    }

    // ==================== Lookup ====================

    /**
     * Look the request up in the route table without executing anything.
     *
     * No hook fires, no middleware or handler runs, no container is needed.
     */
    public function match(ServerRequestInterface $request): RouteMatch
    {
        return $this->lookup($request);
    }

    /**
     * The request with its lookup result as attribute RouteMatch::class and, on a hit, the
     * route as Route::class.
     *
     * A RouteMatch the request already carries is kept while it still describes the request:
     * made by this dispatcher, for this method and this path. Otherwise the request is
     * looked up again.
     */
    private function attachMatch(ServerRequestInterface $request): ServerRequestInterface
    {
        $match = $request->getAttribute(RouteMatch::class);

        if (!$match instanceof RouteMatch
            || ($this->issued[$match] ?? null) !== $request->getUri()->getPath()
            || $match->method !== $request->getMethod()) {
            $match = $this->lookup($request);
        }

        $route = $match->isFound() ? $match->route : null;

        if ($request->getAttribute(RouteMatch::class) === $match && $request->getAttribute(Route::class) === $route) {
            return $request;
        }

        $request = $request->withAttribute(RouteMatch::class, $match);

        return $route !== null
            ? $request->withAttribute(Route::class, $route)
            : $request->withoutAttribute(Route::class);
    }

    private function lookup(ServerRequestInterface $request): RouteMatch
    {
        $method = $request->getMethod();
        $requestPath = $request->getUri()->getPath();
        $path = $this->normalizePath($request);

        if ($path === null) {
            // Outside the base path. The path stays as requested (decoded), as the notFound hook reports it.
            $match = new RouteMatch(RouteMatch::NOT_FOUND, $method, rawurldecode($requestPath));

            return $this->issue($match, $requestPath);
        }

        $result = $this->dispatcher->dispatch($method, $path);
        $viaGet = false;
        $implicitHead = $this->implicitHead;

        if ($result[0] !== Dispatcher::FOUND && $method === 'HEAD' && $this->implicitHead) {
            $asGet = $this->dispatcher->dispatch('GET', $path);
            if ($asGet[0] === Dispatcher::FOUND) {
                $result = $asGet;
                $viaGet = true;
            }
        }

        $match = match ($result[0]) {
            Dispatcher::FOUND => new RouteMatch(
                RouteMatch::FOUND,
                $method,
                $path,
                $this->ensureRoute($result[1]),
                $result[2],
                $result[3],
                $viaGet,
                // For a hit the list costs another pass over the table, so it is built on demand —
                // with the setting of this moment, like everything else the match says
                fn (): array => self::withHead($this->dispatcher->allowedMethods($path), $implicitHead),
            ),
            Dispatcher::METHOD_NOT_ALLOWED => new RouteMatch(
                RouteMatch::METHOD_NOT_ALLOWED,
                $method,
                $path,
                $this->routeOfPath($path, $this->ensureStringArray($result[1])),
                allowedMethods: self::withHead($this->ensureStringArray($result[1]), $implicitHead),
            ),
            default => new RouteMatch(RouteMatch::NOT_FOUND, $method, $path),
        };

        return $this->issue($match, $requestPath);
    }

    private function issue(RouteMatch $match, string $requestPath): RouteMatch
    {
        $this->issued[$match] = $requestPath;

        return $match;
    }

    /**
     * A route registered for the path although the request's method is not: the GET route
     * if there is one, otherwise the route of the first allowed method.
     *
     * @param list<string> $allowed
     */
    private function routeOfPath(string $path, array $allowed): ?Route
    {
        $method = in_array('GET', $allowed, true) ? 'GET' : ($allowed[0] ?? null);
        if ($method === null) {
            // @codeCoverageIgnoreStart
            // METHOD_NOT_ALLOWED always comes with at least one method
            return null;
            // @codeCoverageIgnoreEnd
        }

        $result = $this->dispatcher->dispatch($method, $path);

        return $result[0] === Dispatcher::FOUND ? $this->ensureRoute($result[1]) : null;
    }

    /**
     * With implicitHead, a path that answers GET answers HEAD: HEAD stands right behind GET,
     * also when the path has a HEAD route of its own.
     *
     * @param list<string> $methods
     *
     * @return list<string>
     */
    private static function withHead(array $methods, bool $implicitHead): array
    {
        if (!$implicitHead || !in_array('GET', $methods, true)) {
            return $methods;
        }

        $methods = array_values(array_diff($methods, ['HEAD']));
        $position = (int) array_search('GET', $methods, true) + 1;

        return [...array_slice($methods, 0, $position), 'HEAD', ...array_slice($methods, $position)];
    }

    /**
     * The path the route table is asked with: decoded, base path removed, trailing slash
     * as configured. Null when the request lies outside the base path.
     */
    private function normalizePath(ServerRequestInterface $request): ?string
    {
        $uri = rawurldecode($request->getUri()->getPath());

        // BasePath handling: requests MUST start with basePath
        if ($this->basePath !== '') {
            $basePathLen = strlen($this->basePath);
            // Must start with basePath AND either be exact match or followed by '/'
            // This prevents /api from matching /apiX
            if (!str_starts_with($uri, $this->basePath) ||
                (strlen($uri) > $basePathLen && $uri[$basePathLen] !== '/')) {
                return null;
            }
            $uri = substr($uri, $basePathLen) ?: '/';
        }

        // Trailing slash handling
        if ($this->trailingSlash === 'ignore' && $uri !== '/') {
            $uri = rtrim($uri, '/');
        }

        return $uri;
    }

    // ==================== Request Handling ====================

    /**
     * PSR-15: Handle a request and return a response.
     *
     * From the outside in: the middleware for every request, the error responder, and
     * finally the 404/405 answer or the route's own middleware and handler. The route is
     * looked up before the first of them and travels with the request as attribute
     * RouteMatch::class (and Route::class on a hit). A middleware that passes the request
     * on with another method or path has it looked up again for everything further in.
     *
     * @param ServerRequestInterface $request PSR-7 request
     *
     * @return ResponseInterface PSR-7 response
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $startTime = microtime(true);

        // The request as it was last passed inwards, and whether it has been HEAD at any step
        $current = $request;
        $head = false;

        // What the error responder threw itself — nothing answers that a second time
        /** @var \WeakMap<\Throwable, true> $unanswerable */
        $unanswerable = new \WeakMap();

        $enter = function (ServerRequestInterface $request) use (&$current, &$head): ServerRequestInterface {
            $current = $this->attachMatch($request);
            $head = $head || $current->getMethod() === 'HEAD';

            return $current;
        };

        $handler = new Middleware\CallableHandler(function (ServerRequestInterface $request) use ($enter, $startTime, $unanswerable): ResponseInterface {
            $request = $enter($request);
            $match = $request->getAttribute(RouteMatch::class);
            assert($match instanceof RouteMatch);

            try {
                return $this->respond($match, $request, $startTime);
            } catch (\Throwable $e) {
                if ($this->errorResponder === null) {
                    throw $e;
                }
            }

            try {
                return ($this->errorResponder)($e, $request);
            } catch (\Throwable $failure) {
                $unanswerable[$failure] = true;

                throw $failure;
            }
        });

        try {
            foreach (array_reverse($this->middleware) as $middleware) {
                $next = new MiddlewareHandler($this->resolveMiddleware($middleware), $handler);
                $handler = new Middleware\CallableHandler(
                    fn (ServerRequestInterface $request): ResponseInterface => $next->handle($enter($request))
                );
            }

            $response = $handler->handle($request);
        } catch (\Throwable $e) {
            if ($this->errorResponder === null || isset($unanswerable[$e])) {
                throw $e;
            }

            // Last resort: a middleware for every request threw or could not be built. The
            // responder gets the request as far as it came, with its RouteMatch; its answer
            // does not pass through the middleware any more.
            $response = ($this->errorResponder)($e, $enter($current));
        }

        // With implicitHead on, no answer to a HEAD request carries a body — whoever made it:
        // a GET route, a middleware for every request (a 404 page), the error responder.
        // Cut here, at the very end: the middleware has seen the body GET would send (for an
        // ETag, a Content-Length), and status and headers stay exactly as they are. "HEAD"
        // is a request that was HEAD at any step on its way in — also in a delegation whose
        // answer a middleware threw away to delegate again.
        if ($this->implicitHead && $head) {
            $response = $response->withBody(\Nyholm\Psr7\Stream::create(''));
        }

        return $response;
    }

    /**
     * The answer of the route table itself: 404, 405, 400 for a parameter that does not
     * cast, or what the route's middleware and handler return.
     */
    private function respond(RouteMatch $match, ServerRequestInterface $request, float $startTime): ResponseInterface
    {
        $method = $match->method;
        $uri = $match->path;

        // Handle non-FOUND cases directly (no casting involved)
        if ($match->status === RouteMatch::NOT_FOUND) {
            return $this->handleNotFound($method, $uri);
        }

        if ($match->status === RouteMatch::METHOD_NOT_ALLOWED) {
            return $this->handleMethodNotAllowed($method, $uri, $match->allowedMethods());
        }

        // FOUND: Cast parameters first (wrapped in try-catch)
        // This ONLY catches casting errors, not controller TypeErrors!
        try {
            $castedParams = $this->castParams($match->params, $match->casts);
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
        return $this->handleFound($this->ensureRoute($match->route), $castedParams, $request, $method, $uri, $startTime);
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
                $constructor = (new \ReflectionClass($middleware))->getConstructor();
                if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
                    throw new RouterException(
                        sprintf(
                            'Middleware "%s" requires constructor parameters. Register it in a PSR-11 container or pass an instance.',
                            $middleware
                        )
                    );
                }

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

        $floatVal = (float) $value;

        // Overflow check: a few hundred digits pass the pattern and cast to INF
        if (!is_finite($floatVal)) {
            throw new \TypeError(
                sprintf("Parameter '%s': decimal overflow", $key)
            );
        }

        return $floatVal;
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

    /** @return list<string> */
    private function ensureStringArray(mixed $value): array
    {
        assert(is_array($value));
        /** @var list<string> $value */
        return $value;
    }
}
