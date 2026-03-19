<?php

declare(strict_types=1);

namespace Sodaho\Router;

use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;
use Sodaho\Router\Contract\ResponderInterface;
use Sodaho\Router\Service\JsonResponder;

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
        return self::json(200, self::getResponder()->formatSuccess($data, $message, $meta));
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
        $response = self::json(201, self::getResponder()->formatSuccess($data, $message));

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
        return self::json(202, self::getResponder()->formatSuccess($data, $message));
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

        return self::json(200, self::getResponder()->formatSuccess($items, null, [
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
        return self::json($status, self::getResponder()->formatError($message, $code, $details));
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

        return self::json(404, self::getResponder()->formatError($message, 'NOT_FOUND'));
    }

    /**
     * 401 Unauthorized response.
     *
     * @param string|null $message Custom error message
     */
    public static function unauthorized(?string $message = null): ResponseInterface
    {
        $message ??= 'Unauthorized';
        return self::json(401, self::getResponder()->formatError($message, 'UNAUTHORIZED'));
    }

    /**
     * 403 Forbidden response.
     *
     * @param string|null $message Custom error message
     */
    public static function forbidden(?string $message = null): ResponseInterface
    {
        $message ??= 'Forbidden';
        return self::json(403, self::getResponder()->formatError($message, 'FORBIDDEN'));
    }

    /**
     * 422 Validation Error response.
     *
     * @param array<string, string|array<string>> $errors Field => error message(s)
     */
    public static function validationError(array $errors): ResponseInterface
    {
        return self::json(422, self::getResponder()->formatError(
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
        $response = self::json(405, self::getResponder()->formatError(
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
        $response = self::json(429, self::getResponder()->formatError(
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

        return self::json(500, self::getResponder()->formatError($userMessage, 'SERVER_ERROR', $details));
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
        // Escape quotes and backslashes in filename for Content-Disposition header
        $escapedFilename = str_replace(['\\', '"'], ['\\\\', '\\"'], $filename);

        return new Psr7Response(
            200,
            [
                'Content-Type' => $contentType,
                'Content-Disposition' => sprintf('attachment; filename="%s"', $escapedFilename),
                'Content-Length' => (string) strlen($content),
            ],
            $content,
        );
    }

    // ==================== Internal Helpers ====================

    /**
     * Create a JSON response.
     *
     * @param int $status HTTP status code
     * @param array<string, mixed> $data Response data
     */
    private static function json(int $status, array $data): ResponseInterface
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

}
