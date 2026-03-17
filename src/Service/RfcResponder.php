<?php

declare(strict_types=1);

namespace Sodaho\Router\Service;

use Sodaho\Router\Contract\ResponderInterface;

/**
 * RFC 7807 Problem Details responder.
 *
 * Error format follows RFC 7807 (Problem Details for HTTP APIs):
 * {
 *   "type": "https://example.com/errors/not-found",
 *   "title": "Resource not found",
 *   "status": 404,
 *   "detail": "User with ID 123 not found",
 *   "instance": "/users/123"
 * }
 *
 * Success format uses simple JSON (RFC 7807 only defines error format):
 * {"data": {...}, "message": "..."}
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7807
 */
final class RfcResponder implements ResponderInterface
{
    /**
     * Base URI for error type references.
     *
     * Error codes like 'NOT_FOUND' become '{typeBaseUri}/not-found'
     */
    private string $typeBaseUri;

    /**
     * Create a new RfcResponder instance.
     *
     * @param string $typeBaseUri Base URI for error types (e.g., 'https://api.example.com/errors')
     */
    public function __construct(string $typeBaseUri = 'about:blank')
    {
        $this->typeBaseUri = rtrim($typeBaseUri, '/');
    }

    public function formatSuccess(mixed $data, ?string $message = null, ?array $meta = null): array
    {
        // RFC 7807 only defines error format, use simple structure for success
        $body = ['data' => $data];

        if ($message !== null) {
            $body['message'] = $message;
        }

        if ($meta !== null) {
            $body['meta'] = $meta;
        }

        return $body;
    }

    public function formatError(string $message, ?string $code = null, ?array $details = null): array
    {
        $body = [
            'type' => $this->buildTypeUri($code),
            'title' => $message,
        ];

        // Add detail if provided in details array
        if ($details !== null) {
            // If 'detail' key exists, use it as the detail field
            if (isset($details['detail'])) {
                $body['detail'] = $details['detail'];
                unset($details['detail']);
            }

            // If 'instance' key exists, use it as the instance field
            if (isset($details['instance'])) {
                $body['instance'] = $details['instance'];
                unset($details['instance']);
            }

            // If 'status' key exists, use it as the status field
            if (isset($details['status']) && (is_int($details['status']) || is_numeric($details['status']))) {
                $body['status'] = (int) $details['status'];
                unset($details['status']);
            }

            // Remaining details become extension members
            if (!empty($details)) {
                foreach ($details as $key => $value) {
                    $body[$key] = $value;
                }
            }
        }

        return $body;
    }

    public function getContentType(): string
    {
        return 'application/problem+json';
    }

    /**
     * RFC 7807: Success responses use standard JSON, not problem+json.
     */
    public function getSuccessContentType(): string
    {
        return 'application/json';
    }

    private function buildTypeUri(?string $code): string
    {
        if ($code === null) {
            return 'about:blank';
        }

        // If typeBaseUri is about:blank, keep it simple
        if ($this->typeBaseUri === 'about:blank') {
            return 'about:blank';
        }

        // Convert CODE_NAME to code-name for URI
        $slug = strtolower(str_replace('_', '-', $code));

        return $this->typeBaseUri . '/' . $slug;
    }
}
