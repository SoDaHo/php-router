<?php

declare(strict_types=1);

namespace Sodaho\Router\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sodaho\Router\Response;

/**
 * Handler for redirect routes.
 *
 * A class instead of a Closure, so that a redirect route can be inspected (target, status).
 */
final class RedirectHandler implements RequestHandlerInterface
{
    /**
     * Create a new RedirectHandler instance.
     *
     * @param string $target Target URL (can contain {param} placeholders)
     * @param int $status HTTP status code (default: 302)
     */
    public function __construct(
        private readonly string $target,
        private readonly int $status = 302
    ) {
    }

    /**
     * Handle the request by returning a redirect response.
     *
     * @param ServerRequestInterface $request PSR-7 request
     *
     * @return ResponseInterface Redirect response
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Replace ONLY route parameters (not all attributes)
        /** @var array<string, scalar> $params */
        $params = $request->getAttribute('_route_params', []);

        // Each segment of a value is encoded on its own, as url() writes it: a value with
        // slashes ({path:any}) goes out as a path — encoded as a whole, its %2F would be
        // answered with 404 by the router it leads to
        $target = $this->fill($params, static fn (string $value): string => implode('/', array_map(rawurlencode(...), explode('/', $value))));

        // … except where that would make the target begin with '//' when the target as
        // written does not: '/{path}' with the value '/evil.example' names another host.
        // There the slashes are encoded as well.
        if (str_starts_with($target, '//') && !str_starts_with($this->target, '//')) {
            $target = $this->fill($params, rawurlencode(...));
        }

        return Response::redirect($target, $this->status);
    }

    /**
     * The target with each {name} replaced by its value, encoded.
     *
     * @param array<string, scalar> $params
     * @param \Closure(string): string $encode
     */
    private function fill(array $params, \Closure $encode): string
    {
        $target = $this->target;

        foreach ($params as $key => $value) {
            // Encoded, a value cannot spell another placeholder: its braces are %7B, %7D
            $target = str_replace('{' . $key . '}', $encode((string) $value), $target);
        }

        return $target;
    }

    /**
     * Get target URL.
     */
    public function getTarget(): string
    {
        return $this->target;
    }

    /**
     * Get status code.
     */
    public function getStatus(): int
    {
        return $this->status;
    }
}
