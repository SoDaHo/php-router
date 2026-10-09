<?php

declare(strict_types=1);

namespace Sodaho\Router\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sodaho\Router\Exception\RouterException;
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
     * @throws RouterException When the values would change scheme or host of the target
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

        // Encoded, a value brings no '/', ':' or '\' of its own — but it may be empty: in
        // '/{a}/{b}' a '' (or false) for {a} makes '//evil.example', another host. What
        // would begin with '//' or carry a scheme where the target as written does not is
        // not sent: a failure of the application (RouterException, a 500), not a redirect.
        $sent = self::asABrowserReadsIt($target);
        $written = self::asABrowserReadsIt($this->target);

        if ((str_starts_with($sent, '//') && !str_starts_with($written, '//')) || self::scheme($sent) !== self::scheme($written)) {
            throw new RouterException(
                'Redirect not sent: its placeholders would change scheme or host of the target (an empty value in front of a slash)',
                debugMessage: $target,
            );
        }

        return Response::redirect($target, $this->status);
    }

    /**
     * An address as a browser reads it (WHATWG URL): blanks and control characters at the
     * edges and tabs and line breaks inside dropped, a backslash taken for a slash.
     */
    private static function asABrowserReadsIt(string $address): string
    {
        return str_replace(['\\', "\t", "\n", "\r"], ['/', '', '', ''], trim($address, "\x00..\x20"));
    }

    /**
     * The scheme an address begins with (RFC 3986: a letter, then letters, digits, '+',
     * '-' or '.', then ':'), null for none.
     */
    private static function scheme(string $address): ?string
    {
        return preg_match('~^([A-Za-z][A-Za-z0-9+.\-]*):~', $address, $match) === 1 ? strtolower($match[1]) : null;
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
