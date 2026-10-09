<?php

declare(strict_types=1);

namespace Sodaho\Router;

/**
 * Result of looking a request up in the route table — nothing has been executed yet.
 *
 * Router::match() returns it, and every request that passes through the router carries it
 * as attribute RouteMatch::class from the first middleware on.
 */
final class RouteMatch
{
    public const NOT_FOUND = Dispatcher::NOT_FOUND;
    public const FOUND = Dispatcher::FOUND;
    public const METHOD_NOT_ALLOWED = Dispatcher::METHOD_NOT_ALLOWED;

    /** @var list<string>|(\Closure(): list<string>) */
    private array|\Closure $allowedMethods;

    /**
     * @param int $status One of the constants above
     * @param string $method Request method the lookup was made for
     * @param string $path Path the table was asked with: decoded, without basePath, trailing slash as configured.
     *                     Where the table was not asked, the path of the request: decoded for one outside the
     *                     base path, as it came for one with a hidden separator (%2F, %5C, backslash) or
     *                     a control character
     * @param Route|null $route FOUND: the route. METHOD_NOT_ALLOWED: a route registered for the path — the
     *                          GET route if there is one, otherwise that of the first allowed method.
     *                          Tell the two apart by $status, not by this being set
     * @param array<string, string> $params Route parameters as they stand in the path — not cast yet
     * @param array<string, string> $casts Parameter name => type the router casts it to (int, float, bool)
     * @param bool $viaGet True when a HEAD request was matched to a GET route (implicitHead)
     * @param list<string>|(\Closure(): list<string>) $allowedMethods The list, or how to get it when asked for
     *
     * @internal Built by RouteDispatcher. One you build yourself is not taken over by handle()
     */
    public function __construct(
        public readonly int $status,
        public readonly string $method,
        public readonly string $path,
        public readonly ?Route $route = null,
        public readonly array $params = [],
        public readonly array $casts = [],
        public readonly bool $viaGet = false,
        array|\Closure $allowedMethods = [],
    ) {
        $this->allowedMethods = $allowedMethods;
    }

    public function isFound(): bool
    {
        return $this->status === self::FOUND;
    }

    /**
     * Every method registered for this path — also when the request's own method matched.
     *
     * Methods of static routes first, then those of dynamic routes; within each kind in the
     * order in which a method was first registered among all routes of that kind (not only
     * those of this path). With implicitHead, HEAD stands right behind GET. Empty when no
     * route knows the path.
     *
     * For a FOUND match the list is built when it is first asked for — with another pass
     * over the route table, which throws a RouterException where PCRE gives up on a
     * route's expression (see Router::match()).
     *
     * @throws Exception\RouterException When PCRE gives up on a route's expression
     *
     * @return list<string>
     */
    public function allowedMethods(): array
    {
        $allowed = $this->allowedMethods;

        if ($allowed instanceof \Closure) {
            $allowed = $this->allowedMethods = $allowed();
        }

        return $allowed;
    }
}
