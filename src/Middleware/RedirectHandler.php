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

        // A value encoded as a whole, as 2.1.0 did: its slashes are %2F, so it can neither
        // begin nor end a host — but a value with slashes ({path:any}) leads to a path the
        // router answers with 404
        $whole = $this->fill($params, rawurlencode(...));

        // Each segment encoded on its own, as url() writes it: the value goes out as a path
        $segments = $this->fill($params, static fn (string $value): string => implode('/', array_map(rawurlencode(...), explode('/', $value))));

        return Response::redirect($this->slashesStayInThePath($segments, $whole) ? $segments : $whole, $this->status);
    }

    /**
     * Whether the slashes a value brings in stay in the path — so that scheme and host of
     * the address are what the target as written says, whatever the values are. A browser
     * (WHATWG URL) is lenient here: 'https:/evil.example' is https://evil.example from a
     * page of another scheme, tabs and blanks are dropped, a backslash is a slash. So the
     * segment-wise form is taken only where that cannot happen:
     *
     * - the target as written names scheme and host (or '//' and host) and ends the host
     *   with '/', '?' or '#' before its first placeholder: 'https://app.example/{path}';
     * - or the address has neither: it begins with '?' or '#', with '/' and something
     *   other than a second slash ('/{path}' with 'a/b', not with '/evil.example'), or with
     *   a segment without ':' ('docs/{path}') — a value's own ':' is encoded.
     *
     * A target with a backslash, a blank or a control character, and an address that gets
     * a '.' or '..' segment its values did not bring as a whole, keep the whole encoding.
     */
    private function slashesStayInThePath(string $segments, string $whole): bool
    {
        if ($segments === $whole || preg_match('/[\x00-\x20\x7F\\\\]/', $this->target) === 1) {
            return $segments === $whole;
        }

        // A '.' or '..' segment a client resolves away ('/a/.' with the value './b'),
        // counted in the part in front of the query
        $dots = static fn (string $address): int => (int) preg_match_all('~(?:^|/)\.\.?(?=/|$)~', substr($address, 0, strcspn($address, '?#')));
        if ($dots($segments) > $dots($whole)) {
            return false;
        }

        // Scheme and host as written, and the host ended, before the first placeholder
        $front = (string) strstr($this->target . '{', '{', true);
        if (preg_match('~^(?:[A-Za-z][A-Za-z0-9+.\-]*:)?//[^/?#]*[/?#]~', $front) === 1) {
            return true;
        }

        // No scheme and no host at all, whatever the values brought in
        return preg_match('~^(?:[?#]|/(?!/)|[^/?#:]+(?:[/?#]|$))~', $segments) === 1;
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
