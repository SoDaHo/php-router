<?php

declare(strict_types=1);

namespace Sodaho\Router\Contract;

/**
 * Interface for response body formatting.
 *
 * Implementations define the JSON structure for API responses.
 * Default: JsonResponder with {success, data, error} format.
 */
interface ResponderInterface
{
    /**
     * Format a success response body.
     *
     * @param mixed $data Response data
     * @param string|null $message Optional success message
     * @param array<string, mixed>|null $meta Optional metadata (pagination, etc.)
     *
     * @return array<string, mixed> Formatted response body
     */
    public function formatSuccess(mixed $data, ?string $message = null, ?array $meta = null): array;

    /**
     * Format an error response body.
     *
     * @param string $message User-facing error message
     * @param string|null $code Error code (e.g., 'NOT_FOUND', 'VALIDATION_ERROR')
     * @param array<string, mixed>|null $details Additional error details
     *
     * @return array<string, mixed> Formatted response body
     */
    public function formatError(string $message, ?string $code = null, ?array $details = null): array;

    /**
     * Get the Content-Type header value for error responses (4xx/5xx).
     *
     * @return string MIME type (e.g., 'application/json', 'application/problem+json')
     */
    public function getContentType(): string;

    /**
     * Get the Content-Type header value for success responses (2xx/3xx).
     *
     * RFC 7807: 'application/problem+json' is only for errors.
     * Override this if your format requires a different success Content-Type.
     *
     * @return string MIME type (default: 'application/json')
     */
    public function getSuccessContentType(): string;
}
