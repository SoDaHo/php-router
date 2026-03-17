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
 * Cache-friendly alternative to Closures.
 */
class RedirectHandler implements RequestHandlerInterface
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
        // Replace ONLY route parameters (not all attributes) with URL encoding
        $target = $this->target;
        /** @var array<string, scalar> $params */
        $params = $request->getAttribute('_route_params', []);

        foreach ($params as $key => $value) {
            // Always encode parameter values to prevent injection attacks
            $target = str_replace('{' . $key . '}', rawurlencode((string) $value), $target);
        }

        return Response::redirect($target, $this->status);
    }

    /**
     * Get target URL (for serialization).
     */
    public function getTarget(): string
    {
        return $this->target;
    }

    /**
     * Get status code (for serialization).
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Restore object from var_export() output.
     *
     * Required for OPcache-friendly route caching.
     *
     * @param array{target: string, status: int} $data Exported data
     */
    public static function __set_state(array $data): self
    {
        return new self($data['target'], $data['status']);
    }
}
