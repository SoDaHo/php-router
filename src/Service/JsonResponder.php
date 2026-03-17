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

    public function getContentType(): string
    {
        return 'application/json';
    }

    public function getSuccessContentType(): string
    {
        return 'application/json';
    }
}
