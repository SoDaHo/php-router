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
use Sodaho\Router\Stream\TextStream;
use Sodaho\Router\Traits\HasHooks;

/**
 * PSR-15 RequestHandler that dispatches requests to routes.
 *
 * Looks the route up, runs the middleware for every request around the answer and
 * delegates route middleware and handlers to specialized classes.
 */
final class RouteDispatcher implements RequestHandlerInterface
{
    use HasHooks;

    private Dispatcher $dispatcher;
    private string $basePath;
    /**
     * The headers of an answer that depends on nothing the application can replace
     *
     * @internal
     */
    public const PLAIN_HEADERS = ['Content-Type' => 'text/plain; charset=utf-8', 'X-Content-Type-Options' => 'nosniff'];

    private string $trailingSlash;
    private bool $debug;
    private bool $implicitHead = true;

    /** @var array<string|object> Middleware for every request, outermost first */
    private array $middleware = [];

    /** @var (\Closure(\Throwable, ServerRequestInterface, \WeakMap<\Throwable, true>): ResponseInterface)|(\Closure(\Throwable, ServerRequestInterface): ResponseInterface)|null */
    private ?\Closure $errorResponder = null;

    /** Whether the error responder takes the call's record (setErrorResponderWithRecord()) */
    private bool $responderTakesRecord = false;

    /** @var list<AppFolder> Web app folders, the longest prefix first */
    private array $apps = [];

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

    /**
     * A plain-text answer whose body needs nothing PHP has to open (a TextStream): what
     * goes out when not even a stream can be opened any more (the php:// wrapper
     * unregistered). A new one each time, so nothing a reader does to one touches another.
     *
     * @internal Also used by Router
     */
    public static function plain(int $status, string $text = ''): ResponseInterface
    {
        return new \Nyholm\Psr7\Response($status, self::PLAIN_HEADERS)->withBody(new TextStream($text));
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
     * @param array<string|object> $middleware Class names or instances, outermost first
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
     * Web app folders that answer where the route table has nothing (see AppFolder).
     *
     * @param list<AppFolder> $apps The longest prefix first: the first app whose prefix
     *                              fits the path decides alone
     */
    public function setApps(array $apps): static
    {
        $this->apps = $apps;
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
        $this->responderTakesRecord = false;
        return $this;
    }

    /**
     * The router's own responder: it gets the call's record of reported exceptions as a
     * third argument, marks there what it reports ($known[$e] = true), and the dispatcher
     * does not report that again.
     *
     * @internal
     *
     * @param \Closure(\Throwable, ServerRequestInterface, \WeakMap<\Throwable, true>): ResponseInterface $responder
     */
    public function setErrorResponderWithRecord(\Closure $responder): static
    {
        $this->errorResponder = $responder;
        $this->responderTakesRecord = true;
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

        if (self::hasNoRoute($requestPath)) {
            // The table is not asked. The path stays as it came — decoded it would read
            // like the path of a route that exists.
            return $this->issue(new RouteMatch(RouteMatch::NOT_FOUND, $method, $requestPath), $requestPath);
        }

        // The path read once: what was checked is what is looked up
        $path = $this->normalizePath($requestPath);

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
     * Whether a request path is the address of no route, whatever the table holds:
     *
     * - It carries a separator that is none in the address: an encoded slash (%2F), an
     *   encoded backslash (%5C) or a backslash. Decoded, '/files/a%2Fb' would be two
     *   segments here and one for whatever stands in front (proxy, access rules of the
     *   web server), as with Apache's default.
     * - It carries a control character (%00 to %1F, %7F), encoded or not. No route pattern
     *   and no base path may contain one, so only a placeholder could take it — and hand a
     *   line break in an id to the handler.
     * - It has a '.' or '..' segment, its dots encoded or not ('/a/..', '/a/%2E%2E/b'). A
     *   client resolves those before it asks, and no route pattern or base path may contain
     *   one: only a placeholder could take it — '{name}' the value '..', '{path:any}' a
     *   value that climbs out of its folder ('../../etc/passwd').
     *
     * Looked at in the path as it came, before it is decoded: decoded, '%2F' and '%2E%2E'
     * read like what they hide.
     *
     * @internal Also asked by AppFolder, which keeps the rule for a caller of its own
     */
    public static function hasNoRoute(string $requestPath): bool
    {
        return preg_match('~%2f|%5c|\\\\|%[01][0-9a-f]|%7f|[\x00-\x1f\x7f]|(?:^|/)(?:\.|%2e){1,2}(?:/|\z)~i', $requestPath) === 1;
    }

    /**
     * The path the route table is asked with: decoded, base path removed, trailing slash
     * as configured. Null when the request lies outside the base path.
     */
    private function normalizePath(string $requestPath): ?string
    {
        // Decoded before the base path is taken off: the base path is written decoded, like
        // route patterns. What decoding could hide (%2F, %2E%2E) was refused before this is
        // asked (hasNoRoute()), so the decoded path has the segments the client sent.
        // A URI built without a path ('http://example.com') asks for the root, as a client
        // that sends it means it; over HTTP the path is never empty
        $uri = $requestPath === '' ? '/' : rawurldecode($requestPath);

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

        // The answers the error responder gave during this call — nothing asks it about
        // one of them — and the exceptions reported during it, here or by the responder:
        // none is reported a second time
        /** @var \WeakMap<ResponseInterface, true> $answered */
        $answered = new \WeakMap();
        /** @var \WeakMap<\Throwable, true> $known */
        $known = new \WeakMap();

        // What the lookup of a request object failed with. Asked again (the last resort
        // below), the same failure is thrown again instead of a new one of the same kind —
        // reported once, like a request object that throws the same exception each time.
        /** @var \WeakMap<ServerRequestInterface, \Throwable> $unroutable */
        $unroutable = new \WeakMap();

        $enter = function (ServerRequestInterface $request) use (&$current, &$head, $unroutable): ServerRequestInterface {
            if (isset($unroutable[$request])) {
                throw $unroutable[$request];
            }

            try {
                $current = $this->attachMatch($request);
            } catch (\Throwable $e) {
                $unroutable[$request] = $e;

                throw $e;
            }

            $head = $head || $current->getMethod() === 'HEAD';

            return $current;
        };

        $handler = new Middleware\CallableHandler(function (ServerRequestInterface $request) use ($enter, $startTime, $unanswerable, $answered, $known): ResponseInterface {
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
                $answer = $this->askResponder($e, $request, $known);
                $answered[$answer] = true;

                return $answer;
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
            $failure = null;

            try {
                $current = $enter($current);
            } catch (\Throwable $failure) {
                // The request object itself fails: the responder gets it as it is
                $head = $head || self::describe($current)['method'] === 'HEAD';
            }

            $response = $this->askResponder($e, $current, $known);
            $answered[$response] = true;

            // … and what it failed with is reported behind the exception it interrupted
            if ($failure !== null) {
                $this->reportOnce($failure, $current, $known);
            }
        }

        // With implicitHead on, no answer to a HEAD request carries a body — whoever made it:
        // a GET route, a middleware for every request (a 404 page), the error responder.
        // Cut here, at the very end: the middleware has seen the body GET would send (for an
        // ETag, a Content-Length), and status and headers stay exactly as they are. "HEAD"
        // is a request that was HEAD at any step on its way in — also in a delegation whose
        // answer a middleware threw away to delegate again.
        if ($this->implicitHead && $head) {
            try {
                $response = $response->withBody(\Nyholm\Psr7\Stream::create(''));
            } catch (\Throwable $e) {
                // A response object that does not take another body
                if ($this->errorResponder === null) {
                    throw $e;
                }

                $response = $this->withoutBody($e, $current, isset($answered[$response]), $known);
            }
        }

        return $response;
    }

    /**
     * The answer to HEAD when the response at hand refuses to lose its body. The error
     * responder is asked — unless that response is one of its own answers: what goes
     * wrong while its answer is finished is reported, and answered without it.
     *
     * @param \WeakMap<\Throwable, true> $known Exceptions reported during this call
     */
    private function withoutBody(\Throwable $e, ServerRequestInterface $request, bool $itsOwn, \WeakMap $known): ResponseInterface
    {
        if (!$itsOwn && $this->errorResponder !== null) {
            $response = $this->askResponder($e, $request, $known);

            try {
                return $response->withBody(\Nyholm\Psr7\Stream::create(''));
            } catch (\Throwable $failure) {
                // Its answer refuses as well
                $this->reportOnce($failure, $request, $known);

                return self::plain(500);
            }
        }

        $this->reportOnce($e, $request, $known);

        return self::plain(500);
    }

    /**
     * @param \WeakMap<\Throwable, true> $known
     */
    private function reportOnce(\Throwable $e, ServerRequestInterface $request, \WeakMap $known): void
    {
        if (!isset($known[$e])) {
            $known[$e] = true;
            $this->report($e, $request);
        }
    }

    private function report(\Throwable $e, ServerRequestInterface $request): void
    {
        // 500: through the router that is its answer (unless an error handler answers
        // instead); a dispatcher on its own has no answer of its own, the 500 says the
        // failure is not the client's
        $this->trigger('error', ['exception' => $e] + self::describe($request) + ['status' => 500]);
    }

    /**
     * @param \WeakMap<\Throwable, true> $known
     */
    private function askResponder(\Throwable $e, ServerRequestInterface $request, \WeakMap $known): ResponseInterface
    {
        assert($this->errorResponder !== null);

        return $this->responderTakesRecord
            ? ($this->errorResponder)($e, $request, $known)
            : ($this->errorResponder)($e, $request);
    }

    /**
     * Method and path for the error hook. What a request object fails to say stays empty —
     * the report goes out all the same.
     *
     * @internal
     *
     * @return array{method: string, path: string}
     */
    public static function describe(ServerRequestInterface $request): array
    {
        $method = '';
        $path = '';

        try {
            $method = $request->getMethod();
        } catch (\Throwable) {
            // Stays empty
        }

        try {
            $path = $request->getUri()->getPath();
        } catch (\Throwable) {
            // Stays empty
        }

        return ['method' => $method, 'path' => $path];
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
            return $this->serveApp($request) ?? $this->handleNotFound($method, $uri);
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
            // This is a client error (invalid parameter), not a server error. The message
            // names the parameter; the value is in the path the hook gets.
            $this->trigger('error', [
                'method' => $method,
                'path' => $uri,
                'exception' => $e,
                'status' => 400,
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
        // Store route params separately for handler invocation. Each parameter is also an
        // attribute of its own name — but never in place of one the request carries
        // already: a middleware for every request may have set user_id from a token, and
        // the value of a placeholder {user_id} there would be what the client wrote into
        // the path. Refused before the route's middleware runs (a 500 through handle()).
        // array_key_exists(): an attribute that is null is there all the same.
        $attributes = $request->getAttributes();
        foreach ($params as $key => $value) {
            if (array_key_exists($key, $attributes)) {
                throw new RouterException(
                    'Route parameter has the name of an attribute the request already carries: rename the placeholder or the attribute',
                    debugMessage: sprintf('{%s} in %s', $key, $route->pattern),
                );
            }
        }

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
            $result[$key] = self::castValue($type, $value, $key);
        }

        return $result;
    }

    /**
     * One value as the handler gets it. url() asks the same question before it writes an
     * address: would this value arrive?
     *
     * @internal
     *
     * @throws \TypeError If the value is not one the type takes
     */
    public static function castValue(string $type, string $value, string $key): string|int|float|bool
    {
        return match ($type) {
            'int' => self::castInt($value, $key),
            'float' => self::castFloat($value, $key),
            'bool' => self::castBool($value, $key),
            default => $value,
        };
    }

    /**
     * A file or the start page of a web app folder — for a path no route knows. Routes come
     * first, always: this is only asked when the lookup said NOT_FOUND.
     */
    private function serveApp(ServerRequestInterface $request): ?ResponseInterface
    {
        // A path that is the address of no route (hidden separator, control character) is
        // no file of an app either, and gets no start page
        $requestPath = $request->getUri()->getPath();
        if ($this->apps === [] || self::hasNoRoute($requestPath)) {
            return null;
        }

        // Prefixes are relative to the base path, like routes; outside it there is no app
        $path = $this->normalizePath($requestPath);
        if ($path === null) {
            return null;
        }

        foreach ($this->apps as $app) {
            if ($app->owns($path)) {
                // The most specific prefix decides alone. What it refuses is a 404 — it does
                // not fall through to an app further up (the one at '/').
                return $app->serve($request, $path);
            }
        }

        return null;
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
     * The middleware instance for an entry of a middleware list: an instance as it is, a
     * class name from the container where it has one — the container decides how it is
     * built (a rate limit with its configured limit) — or built here when its constructor
     * needs nothing. What the container returns for a name it has is taken or refused,
     * never replaced by an instance built here with the constructor's defaults: a factory
     * registered in place of the instance would otherwise quietly run the middleware
     * with other settings than the application gave it.
     *
     * @throws RouterException If middleware cannot be resolved, or the container's entry for
     *                         it is no MiddlewareInterface
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

                throw new RouterException(
                    'The container entry for a middleware is no MiddlewareInterface: register the middleware itself, not a factory or another object',
                    debugMessage: sprintf('%s: %s', $middleware, get_debug_type($resolved)),
                );
            }
            if (class_exists($middleware)) {
                $constructor = new \ReflectionClass($middleware)->getConstructor();
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
    private static function castInt(string $value, string $key): int
    {
        // Accepts: 0, 5, -10. Rejects: 00, -0, 01, 1e3, 5.0 — so that only an overflow is
        // left for the check below
        if (preg_match('/^(?:0|-?[1-9]\d*)$/D', $value) !== 1) {
            throw new \TypeError(
                sprintf("Parameter '%s': expected integer", $key)
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
    private static function castFloat(string $value, string $key): float
    {
        // Accepts: 5, 5.5, -3.14. Rejects: 1e3, 5.
        if (!preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
            throw new \TypeError(
                sprintf("Parameter '%s': expected decimal", $key)
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
     * Turns what {x:bool} took into a boolean — called for every such value ('TRUE' is
     * true). Its default branch is reached only where an application replaced the
     * built-in pattern of the type (addPattern('bool', …)): that one lets nothing else
     * through.
     *
     * @throws \TypeError If value is not a valid boolean
     *
     * @codeCoverageIgnore For the default branch, which the built-in pattern keeps out of reach
     */
    private static function castBool(string $value, string $key): bool
    {
        return match (strtolower($value)) {
            'true', '1' => true,
            'false', '0' => false,
            default => throw new \TypeError(
                sprintf("Parameter '%s': expected boolean (true/false/1/0)", $key)
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
