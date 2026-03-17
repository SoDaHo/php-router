<?php

declare(strict_types=1);

namespace Sodaho\Router\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Wraps a middleware and next handler into a single handler.
 *
 * Enables middleware chain execution.
 */
class MiddlewareHandler implements RequestHandlerInterface
{
    /**
     * Create a new MiddlewareHandler instance.
     *
     * @param MiddlewareInterface $middleware PSR-15 middleware
     * @param RequestHandlerInterface $next Next handler in the chain
     */
    public function __construct(
        private readonly MiddlewareInterface $middleware,
        private readonly RequestHandlerInterface $next
    ) {
    }

    /**
     * Handle the request by processing middleware and passing to next handler.
     *
     * @param ServerRequestInterface $request PSR-7 request
     *
     * @return ResponseInterface PSR-7 response
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->middleware->process($request, $this->next);
    }
}
