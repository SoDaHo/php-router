<?php

declare(strict_types=1);

namespace Sodaho\Router\Service;

use Sodaho\Router\Contract\ResponderInterface;

/**
 * JSON responder with opinionated API format.
 *
 * Success: {"success": true, "data": {...}, "message": "...", "meta": {...}}
 * Error: {"success": false, "message": "...", "error": {"message": "...", "code": "...", "details": {...}}}
 */
final class JsonResponder implements ResponderInterface
{
    /**
     * The success envelope: the data under 'data', message and meta only where given — a
     * client tells success from failure by 'success' alone.
     */
    public function formatSuccess(mixed $data, ?string $message = null, ?array $meta = null): array
    {
        $body = [
            'success' => true,
            'data' => $data,
        ];

        if ($message !== null) {
            $body['message'] = $message;
        }

        if ($meta !== null) {
            $body['meta'] = $meta;
        }

        return $body;
    }

    /**
     * The error envelope: the message at the top (for clients that read only that) and again
     * under 'error', with code and details where given.
     */
    public function formatError(string $message, ?string $code = null, ?array $details = null): array
    {
        $error = [
            'message' => $message,
        ];

        if ($code !== null) {
            $error['code'] = $code;
        }

        if ($details !== null) {
            $error['details'] = $details;
        }

        return [
            'success' => false,
            'message' => $message,
            'error' => $error,
        ];
    }

    /** Errors (4xx/5xx) go out as plain JSON in this format — problem+json is RfcResponder's. */
    public function getContentType(): string
    {
        return 'application/json';
    }

    /** Successes are plain JSON as well. */
    public function getSuccessContentType(): string
    {
        return 'application/json';
    }
}
