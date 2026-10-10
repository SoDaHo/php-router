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
 * Whoever builds it — RouteCollector::redirect() or an application by hand — gets the same
 * rules: the target is refused when it is written if it could never go out (a control
 * character, a status that is no redirect, a placeholder that is not {name}) or if it
 * leaves scheme or host to a placeholder. What a request's values render is checked again
 * where the redirect goes out: no other scheme or host, no '.' or '..' segment of their own.
 */
final class RedirectHandler implements RequestHandlerInterface
{
    /**
     * Create a new RedirectHandler instance.
     *
     * @param string $target Target URL (can contain {param} placeholders)
     * @param int $status HTTP status code (default: 302), a 3xx status
     *
     * @throws RouterException If the target has a control character other than a tab, a
     *                         placeholder that is not of the form {name} or one where scheme
     *                         or host belong, or the status is no 3xx status
     */
    public function __construct(
        private readonly string $target,
        private readonly int $status = 302
    ) {
        // What a response cannot carry in its Location header is said here, not by a 500
        // for every request to the route (a tab it carries, as it always did)
        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $target) === 1) {
            throw new RouterException(
                'Redirect target must not contain a control character',
                debugMessage: (string) json_encode($target, JSON_INVALID_UTF8_SUBSTITUTE),
            );
        }

        // A Location with a 200 is no redirect: the client shows the empty body
        if ($status < 300 || $status > 399) {
            throw new RouterException('Redirect status must be a 3xx status', debugMessage: (string) $status);
        }

        // The target takes {name}, nothing else in braces: anything else would go out as it stands
        if (strpbrk((string) preg_replace('/\{[A-Za-z0-9_]+\}/', '', $target), '{}') !== false) {
            throw new RouterException(
                'Redirect target has a placeholder that is not of the form {name}',
                debugMessage: $target,
            );
        }

        if (str_contains($target, '{') && !self::fixesSchemeAndHost($target)) {
            throw new RouterException(
                'Redirect target has a placeholder where scheme or host belong: write them into the target, the host closed by "/", "?" or "#"',
                debugMessage: $target,
            );
        }
    }

    /**
     * Handle the request by returning a redirect response.
     *
     * @param ServerRequestInterface $request PSR-7 request
     *
     * @throws RouterException When the values would change scheme or host of the target, or
     *                         make a '.' or '..' segment
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
        // (A scheme cannot come from a value where the constructor took the target; the
        // comparison stays as the last word where the address goes out.)
        $sent = self::asABrowserReadsIt($target);
        $written = self::asABrowserReadsIt($this->target);

        if ((str_starts_with($sent, '//') && !str_starts_with($written, '//')) || self::scheme($sent) !== self::scheme($written)) {
            throw new RouterException(
                'Redirect not sent: its placeholders would change scheme or host of the target (an empty value in front of a slash)',
                debugMessage: $target,
            );
        }

        // Nor may a value be a '.' or '..' segment, or make one with the text around it
        // ('/a/%2e{x}' and '.'): a client resolves it before it asks, and '/docs/{x}/' with
        // '..' leaves /docs/. The segments are those of the target — a value brings no
        // slash — so a segment more is one a value made.
        if (self::dotSegments($sent) > self::dotSegments($written)) {
            throw new RouterException(
                'Redirect not sent: a value would make a "." or ".." path segment, which a client resolves before it asks',
                debugMessage: $target,
            );
        }

        return Response::redirect($target, $this->status);
    }

    /**
     * Whether a redirect target leaves scheme and host of the address to nothing a request
     * brings: either it has neither (a path, '?…' or '#…', no ':' in front of its first
     * '/', '?' or '#'), or it writes both — scheme and '//' or '//' alone, a host that is
     * not empty, closed by '/', '?' or '#' — before its first placeholder. A value is
     * encoded as a whole, so it cannot bring a '/', '?', '#' or ':' of its own; but in
     * scheme or host position it would be the scheme or the host itself: 'https:///{path}'
     * with 'evil.example' is https://evil.example for a browser.
     */
    private static function fixesSchemeAndHost(string $target): bool
    {
        $seen = self::asABrowserReadsIt($target);
        $front = (string) strstr($seen . '{', '{', true);

        // A ':' in front of the first '/', '?' or '#' ends a scheme — if what stands in front
        // of it is one (RFC 3986, see scheme()): '1:x' is a path. A placeholder in there
        // could make one ('{a}:', 'ht{a}tps:') unless a character in front of it already
        // rules that out.
        $firstSegment = substr($seen, 0, strcspn($seen, '/?#'));
        $colon = strpos($firstSegment, ':');
        $scheme = false;

        if ($colon !== false) {
            $beforeColon = substr($firstSegment, 0, $colon);
            if (preg_match('~^(?:[A-Za-z][A-Za-z0-9+.\-]*)?\{~', $beforeColon) === 1) {
                return false;
            }
            $scheme = self::scheme($firstSegment) !== null;
        }

        if (!$scheme && !str_starts_with($seen, '//')) {
            // A path, a query or a fragment of the address the client is at
            return true;
        }

        // Scheme ('https:') — written, not a placeholder — and host, all before the first
        // placeholder: '//', user information if any, a host that is not empty, a port if
        // any, then '/', '?' or '#'
        return preg_match(
            '~^(?:[A-Za-z][A-Za-z0-9+.\-]*:)?//(?:[^/?#@]*@)?(?:\[[^\]/?#]+\]|[^/?#@:\[\]]+)(?::\d*)?[/?#]~',
            $front,
        ) === 1;
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
     * '-' or '.', then ':'), null for none. Compared as written: what a value renders
     * never changes the case of the text around it.
     */
    private static function scheme(string $address): ?string
    {
        return preg_match('~^([A-Za-z][A-Za-z0-9+.\-]*):~', $address, $match) === 1 ? $match[1] : null;
    }

    /**
     * How many '.' or '..' segments the path of an address (read as a browser reads it)
     * has — the encoded dots too ('%2e', '.%2E'), as a browser resolves them. The path
     * begins behind scheme and host and ends at '?' or '#'.
     */
    private static function dotSegments(string $address): int
    {
        $path = (string) preg_replace('~^(?:[A-Za-z][A-Za-z0-9+.\-]*:)?(?://[^/?#]*)?~', '', $address);
        $path = substr($path, 0, strcspn($path, '?#'));

        return count(preg_grep('~^(?:\.|%2e){1,2}\z~i', explode('/', $path)) ?: []);
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
