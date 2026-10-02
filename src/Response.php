<?php

declare(strict_types=1);

namespace Sodaho\Router;

use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;
use Sodaho\Router\Contract\ResponderInterface;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\Service\JsonResponder;
use Sodaho\Router\Stream\FileStream;

/**
 * Facade for standardized API responses.
 *
 * Delegates body formatting to a ResponderInterface implementation.
 * Default: JsonResponder with {success, data, error} format.
 *
 * JSON Structure:
 * Success: {"success": true, "data": {...}, "message": "...", "meta": {...}}
 * Error: {"success": false, "message": "...", "error": {"message": "...", "code": "...", "details": {...}}}
 */
final class Response
{
    /** Upper bound for the filename inside Content-Disposition (see contentDisposition()). */
    private const FILENAME_MAX_BYTES = 200;

    /** Longest suffix still treated as a file extension when truncating (last dot wins, so ".gz" — not ".tar.gz"). */
    private const FILENAME_MAX_EXT_BYTES = 16;

    private static ?ResponderInterface $responder = null;

    /**
     * Set a custom responder for response formatting.
     */
    public static function setResponder(ResponderInterface $responder): void
    {
        self::$responder = $responder;
    }

    /**
     * Get the current responder (lazy-loads JsonResponder as default).
     */
    public static function getResponder(): ResponderInterface
    {
        return self::$responder ??= new JsonResponder();
    }

    /**
     * Reset to default responder.
     *
     * Call in test tearDown() for isolation.
     */
    public static function reset(): void
    {
        self::$responder = null;
    }

    // ==================== Success Responses ====================

    /**
     * 200 OK response.
     *
     * @param mixed $data Response data
     * @param string|null $message Optional success message
     * @param array<string, mixed>|null $meta Optional metadata
     */
    public static function success(
        mixed $data,
        ?string $message = null,
        ?array $meta = null,
    ): ResponseInterface {
        return self::envelope(200, self::getResponder()->formatSuccess($data, $message, $meta));
    }

    /**
     * 201 Created response.
     *
     * @param mixed $data Created resource data
     * @param string|null $message Optional success message
     * @param string|null $location Optional Location header URL
     */
    public static function created(
        mixed $data,
        ?string $message = null,
        ?string $location = null,
    ): ResponseInterface {
        $response = self::envelope(201, self::getResponder()->formatSuccess($data, $message));

        if ($location !== null) {
            $response = $response->withHeader('Location', $location);
        }

        return $response;
    }

    /**
     * 202 Accepted response.
     *
     * @param mixed $data Response data
     * @param string|null $message Optional success message
     */
    public static function accepted(mixed $data, ?string $message = null): ResponseInterface
    {
        return self::envelope(202, self::getResponder()->formatSuccess($data, $message));
    }

    /**
     * 204 No Content response.
     */
    public static function noContent(): ResponseInterface
    {
        return new Psr7Response(204);
    }

    /**
     * 200 OK response with pagination meta.
     *
     * @param array<mixed> $items Paginated items
     * @param int $total Total number of items
     * @param int $page Current page number
     * @param int $perPage Items per page
     */
    public static function paginated(
        array $items,
        int $total,
        int $page,
        int $perPage,
    ): ResponseInterface {
        if ($perPage < 1) {
            throw new \InvalidArgumentException('$perPage must be at least 1');
        }

        $lastPage = (int) ceil($total / $perPage);

        return self::envelope(200, self::getResponder()->formatSuccess($items, null, [
            'pagination' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => $lastPage,
                'from' => $total > 0 ? ($page - 1) * $perPage + 1 : 0,
                'to' => $total > 0 ? min($page * $perPage, $total) : 0,
            ],
        ]));
    }

    // ==================== Error Responses ====================

    /**
     * Generic error response.
     *
     * @param string $message Error message
     * @param int $status HTTP status code (default: 400)
     * @param string|null $code Error code (e.g., 'INVALID_INPUT')
     * @param array<string, mixed>|null $details Additional error details
     */
    public static function error(
        string $message,
        int $status = 400,
        ?string $code = null,
        ?array $details = null,
    ): ResponseInterface {
        return self::envelope($status, self::getResponder()->formatError($message, $code, $details));
    }

    /**
     * 404 Not Found response.
     *
     * @param string|null $resource Resource type (e.g., 'User')
     * @param string|int|null $identifier Resource identifier
     */
    public static function notFound(
        ?string $resource = null,
        string|int|null $identifier = null,
    ): ResponseInterface {
        if ($resource !== null && $identifier !== null) {
            $message = sprintf('%s with identifier %s not found', $resource, $identifier);
        } elseif ($resource !== null) {
            $message = sprintf('%s not found', $resource);
        } else {
            $message = 'Resource not found';
        }

        return self::envelope(404, self::getResponder()->formatError($message, 'NOT_FOUND'));
    }

    /**
     * 401 Unauthorized response.
     *
     * @param string|null $message Custom error message
     */
    public static function unauthorized(?string $message = null): ResponseInterface
    {
        $message ??= 'Unauthorized';
        return self::envelope(401, self::getResponder()->formatError($message, 'UNAUTHORIZED'));
    }

    /**
     * 403 Forbidden response.
     *
     * @param string|null $message Custom error message
     */
    public static function forbidden(?string $message = null): ResponseInterface
    {
        $message ??= 'Forbidden';
        return self::envelope(403, self::getResponder()->formatError($message, 'FORBIDDEN'));
    }

    /**
     * 422 Validation Error response.
     *
     * @param array<string, string|array<string>> $errors Field => error message(s)
     */
    public static function validationError(array $errors): ResponseInterface
    {
        return self::envelope(422, self::getResponder()->formatError(
            'Validation failed',
            'VALIDATION_ERROR',
            ['fields' => $errors],
        ));
    }

    /**
     * 405 Method Not Allowed response.
     *
     * @param string[] $allowedMethods Allowed HTTP methods
     */
    public static function methodNotAllowed(array $allowedMethods): ResponseInterface
    {
        $response = self::envelope(405, self::getResponder()->formatError(
            'Method not allowed',
            'METHOD_NOT_ALLOWED',
            ['allowed' => $allowedMethods],
        ));

        return $response->withHeader('Allow', implode(', ', $allowedMethods));
    }

    /**
     * 429 Too Many Requests response.
     *
     * @param int $retryAfter Seconds until retry is allowed
     */
    public static function tooManyRequests(int $retryAfter): ResponseInterface
    {
        $response = self::envelope(429, self::getResponder()->formatError(
            'Too many requests',
            'TOO_MANY_REQUESTS',
            ['retry_after' => $retryAfter],
        ));

        return $response->withHeader('Retry-After', (string) $retryAfter);
    }

    /**
     * 500 Internal Server Error response.
     *
     * @param string|null $message Custom error message
     * @param array<string, mixed>|null $debug Debug info (only include in dev!)
     */
    public static function serverError(
        ?string $message = null,
        ?array $debug = null,
    ): ResponseInterface {
        $userMessage = $message ?? 'Internal server error';
        $details = $debug !== null ? ['debug' => $debug] : null;

        return self::envelope(500, self::getResponder()->formatError($userMessage, 'SERVER_ERROR', $details));
    }

    // ==================== Other Responses ====================

    /**
     * HTML response.
     *
     * @param string $content HTML content
     * @param int $status HTTP status code (default: 200)
     */
    public static function html(string $content, int $status = 200): ResponseInterface
    {
        return new Psr7Response(
            $status,
            ['Content-Type' => 'text/html; charset=utf-8'],
            $content,
        );
    }

    /**
     * Plain text response.
     *
     * @param string $content Text content
     * @param int $status HTTP status code (default: 200)
     */
    public static function text(string $content, int $status = 200): ResponseInterface
    {
        return new Psr7Response(
            $status,
            ['Content-Type' => 'text/plain; charset=utf-8'],
            $content,
        );
    }

    /**
     * JSON response without the {success, data, error} envelope: the body is $data, encoded.
     *
     * For payloads whose shape is not yours to decide (OAuth/OIDC responses, an OpenAPI
     * document). The responder set with setResponder() has no say here.
     *
     * @param mixed $data Anything json_encode() accepts
     * @param int $status HTTP status code (default: 200)
     * @param string $contentType Content-Type (default: 'application/json')
     */
    public static function json(mixed $data, int $status = 200, string $contentType = 'application/json'): ResponseInterface
    {
        return new Psr7Response(
            $status,
            ['Content-Type' => $contentType],
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        );
    }

    /**
     * Redirect response.
     *
     * @param string $url Target URL
     * @param int $status HTTP status code (default: 302)
     */
    public static function redirect(string $url, int $status = 302): ResponseInterface
    {
        return new Psr7Response($status, ['Location' => $url]);
    }

    /**
     * File download response.
     *
     * @param string $content File content
     * @param string $filename Download filename
     * @param string $contentType MIME type (default: 'application/octet-stream')
     */
    public static function download(
        string $content,
        string $filename,
        string $contentType = 'application/octet-stream',
    ): ResponseInterface {
        return new Psr7Response(
            200,
            [
                'Content-Type' => $contentType,
                'Content-Disposition' => self::contentDisposition($filename, false),
                'Content-Length' => (string) strlen($content),
            ],
            $content,
        );
    }

    /**
     * Streamed file response — the file is never held in memory as a whole.
     *
     * Use this instead of download() for anything that can grow: the body is a FileStream
     * and Router::emit() pulls it in chunks, so peak memory is independent of file size.
     * Optionally serves a single HTTP Range (206) — enough for <audio>/<video> seeking;
     * multipart ranges are not supported and fall back to the full response.
     *
     * The filename is sanitized (control, bidi-override and path characters removed) and,
     * when it is not pure ASCII, additionally sent as RFC 5987 `filename*=UTF-8''…`.
     *
     * @param string $path Readable file path
     * @param string|null $filename Name shown to the client (default: basename of $path)
     * @param string $contentType MIME type (default: 'application/octet-stream')
     * @param bool $inline Content-Disposition: inline instead of attachment
     * @param string|null $range Raw Range request header, e.g. 'bytes=0-1023'
     * @param int|null $maxChunk Cap for a single 206 body, always trimming at the END of the
     *                           requested range (a suffix range 'bytes=-5' with maxChunk 2
     *                           therefore yields the first 2 of those last 5 bytes); null = no cap
     *
     * @throws RouterException When the file cannot be read or $maxChunk is below 1
     */
    public static function file(
        string $path,
        ?string $filename = null,
        string $contentType = 'application/octet-stream',
        bool $inline = false,
        ?string $range = null,
        ?int $maxChunk = null,
    ): ResponseInterface {
        // is_file() before filesize(): a directory reports a size, opens on some platforms
        // and only blows up on the first read — long after the headers went out.
        if (!is_file($path) || !is_readable($path)) {
            throw new RouterException(sprintf('Cannot read file: %s', $path));
        }

        if ($maxChunk !== null && $maxChunk < 1) {
            throw new RouterException(sprintf('maxChunk must be at least 1, got %d', $maxChunk));
        }

        $size = filesize($path);
        // @codeCoverageIgnoreStart
        // Not reachable in a test: is_file() above filled PHP's stat cache, and filesize()
        // answers from it. Kept for wrappers that stat without a size.
        if ($size === false) {
            throw new RouterException(sprintf('Cannot determine size of: %s', $path));
        }
        // @codeCoverageIgnoreEnd

        $headers = [
            'Content-Type' => $contentType,
            'Content-Disposition' => self::contentDisposition($filename ?? basename($path), $inline),
            'Accept-Ranges' => 'bytes',
            'X-Content-Type-Options' => 'nosniff',
        ];

        $parsed = $range === null ? null : self::parseRange($range, $size);

        if ($parsed === null) {
            return new Psr7Response(
                200,
                $headers + ['Content-Length' => (string) $size],
                new FileStream($path),
            );
        }

        // Unsatisfiable range (RFC 9110): answer 416 and name the current length. No
        // Content-Type/Disposition — they would describe a body that is not there.
        if ($parsed === false) {
            return new Psr7Response(
                416,
                [
                    'Content-Range' => 'bytes */' . $size,
                    'Accept-Ranges' => 'bytes',
                    'Content-Length' => '0',
                ],
                '',
            );
        }

        [$start, $end] = $parsed;
        if ($maxChunk !== null) {
            $end = min($end, $start + $maxChunk - 1);
        }
        $length = $end - $start + 1;

        return new Psr7Response(
            206,
            $headers + [
                'Content-Range' => sprintf('bytes %d-%d/%d', $start, $end, $size),
                'Content-Length' => (string) $length,
            ],
            new FileStream($path, $start, $length),
        );
    }

    // ==================== Internal Helpers ====================

    /**
     * Create a JSON response in the responder's envelope.
     *
     * @param int $status HTTP status code
     * @param array<string, mixed> $data Response data
     */
    private static function envelope(int $status, array $data): ResponseInterface
    {
        // RFC 7807: application/problem+json is only for error responses (4xx/5xx).
        // Success responses (2xx/3xx) use getSuccessContentType() (allows custom formats like JSON:API).
        $contentType = ($status >= 400)
            ? self::getResponder()->getContentType()
            : self::getResponder()->getSuccessContentType();

        return new Psr7Response(
            $status,
            ['Content-Type' => $contentType],
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        );
    }


    /**
     * Build a Content-Disposition header value for a client-supplied filename.
     *
     * Three problems are handled here, all reachable through uploaded filenames:
     * control characters (a raw \r\n makes PSR-7 reject the header and the download dies
     * with a 500), bidi controls (U+202E turns "Rechnungexe.pdf" into a disguised .exe in
     * the download dialog) and path separators. Non-ASCII names additionally get the RFC
     * 5987 form, without which strict clients decode UTF-8 as latin1 ("RÃ¶ntgen.pdf").
     */
    private static function contentDisposition(string $filename, bool $inline): string
    {
        // ORDER MATTERS. Truncation must happen BEFORE the bidi strip and before the
        // empty/dot guards: cutting the tail can turn an invalid UTF-8 name into a valid one
        // (the invalid byte sat beyond the limit), and the /u-based bidi strip only runs on
        // valid input — so a late truncation would smuggle a right-to-left override back into
        // filename*. Same for the guards: cutting can produce an empty or dots-only name.

        // 1. Control characters, byte-wise (safe for non-UTF-8 names too).
        $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $filename) ?? '';

        // 2. Path separators are not part of a filename.
        $clean = trim(str_replace(['/', '\\'], '_', $clean));

        // 3. Cap the header value, keeping the extension — a client that saves
        //    "Befund" instead of "Befund.pdf" has a file it cannot open.
        if (strlen($clean) > self::FILENAME_MAX_BYTES) {
            $wasValidUtf8 = preg_match('//u', $clean) === 1;

            $extension = '';
            $dot = strrpos($clean, '.');
            if ($dot !== false && $dot > 0 && strlen($clean) - $dot <= self::FILENAME_MAX_EXT_BYTES) {
                $extension = substr($clean, $dot);
            }

            $stem = substr($clean, 0, max(1, self::FILENAME_MAX_BYTES - strlen($extension)));

            // Repair a multi-byte sequence cut in half — but only when the input was valid to
            // begin with. Peeling back on an already invalid name would strip it to nothing.
            if ($wasValidUtf8) {
                for ($i = 0; $i < 3 && preg_match('//u', $stem) !== 1; $i++) {
                    $stem = substr($stem, 0, -1);
                }
            }

            $clean = $stem . $extension;
        }

        // 4. Bidi overrides, isolates and marks (LRM/RLM/ALM) — needs /u, so on invalid UTF-8 preg_replace returns null
        //    and the name stays as it is. Those bytes cannot reach the header anyway: the
        //    ASCII fallback masks them and filename* is skipped for invalid UTF-8.
        $noBidi = preg_replace('/[\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $clean);
        if ($noBidi !== null) {
            $clean = $noBidi;
        }

        // 5. "." and ".." are path segments, not filenames (RFC 6266 §4.3). Runs last so it
        //    also catches what the steps above produced.
        $clean = trim($clean);
        if ($clean === '' || trim($clean, '.') === '') {
            $clean = 'download';
        }

        // 6. ASCII fallback for the quoted-string form: one underscore per CHARACTER, hence /u.
        $ascii = preg_replace('/[^\x20-\x7E]/u', '_', $clean);
        if ($ascii === null) {
            $ascii = preg_replace('/[^\x20-\x7E]/', '_', $clean) ?? 'download';
        }

        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $ascii);
        $value = sprintf('%s; filename="%s"', $inline ? 'inline' : 'attachment', $escaped);

        // RFC 8187: the extended form MUST carry the encoding it declares. Emitting
        // rawurlencode() of non-UTF-8 bytes under UTF-8'' would be a lie.
        if ($ascii !== $clean && preg_match('//u', $clean) === 1) {
            $value .= "; filename*=UTF-8''" . rawurlencode($clean);
        }

        return $value;
    }

    /**
     * Parse a single-range Range header.
     *
     * @return array{0: int, 1: int}|false|null Range as [start, end], false when
     *                                          unsatisfiable (416), null when not a
     *                                          single byte range (serve the full body)
     */
    private static function parseRange(string $range, int $size): array|false|null
    {
        if (preg_match('/^bytes=(\\d*)-(\\d*)$/', trim($range), $m) !== 1) {
            return null;
        }

        [, $from, $to] = $m;
        if ($from === '' && $to === '') {
            return null;
        }

        if ($from === '') {
            // Suffix form 'bytes=-N' — the last N bytes.
            $length = (int) $to;
            if ($length <= 0) {
                return false;
            }

            $start = max(0, $size - $length);
            $end = $size - 1;
        } else {
            $start = (int) $from;
            $end = $to === '' ? $size - 1 : min((int) $to, $size - 1);
        }

        if ($size === 0 || $start >= $size || $start > $end) {
            return false;
        }

        return [$start, $end];
    }
}
