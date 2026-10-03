<?php

declare(strict_types=1);

namespace Sodaho\Router\Contract;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sodaho\Router\Exception\RouteNotFoundException;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\RouteMatch;

/**
 * What a router does while a request is answered: handle it (PSR-15), look a route up, write
 * the address of a named route. Type against this to wrap the router (a decorator, a test
 * double) — Router itself is final. The first call of any of these loads the routes. What
 * the routes file itself throws then comes out of match(), url() and absoluteUrl() as it
 * is; handle() answers it with a 500.
 *
 * Setting a router up (loadRoutes(), middleware(), app(), on(), setErrorHandler(), ...) is
 * not part of it: that belongs to the one concrete Router an application builds.
 */
interface RouterInterface extends RequestHandlerInterface
{
    /**
     * What handle() would do with the request, without doing it: the route and its
     * parameters, or why there is none.
     *
     * @throws RouterException If no routes are loaded or the routes file is invalid
     */
    public function match(ServerRequestInterface $request): RouteMatch;

    /**
     * The relative address of a named route.
     *
     * @param array<string, string|int|float|bool> $params Route parameters
     *
     * @throws RouteNotFoundException If no route has that name
     * @throws RouterException If no routes are loaded, or the parameters do not lead back to the route
     */
    public function url(string $name, array $params = []): string;

    /**
     * The absolute address of a named route.
     *
     * @param array<string, string|int|float|bool> $params Route parameters
     *
     * @throws RouteNotFoundException If no route has that name
     * @throws RouterException If no base URL is set, no routes are loaded, or the parameters do
     *                         not lead back to the route
     */
    public function absoluteUrl(string $name, array $params = []): string;
}
