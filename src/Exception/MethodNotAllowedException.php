<?php

declare(strict_types=1);

namespace Sodaho\Router\Exception;

/**
 * Thrown when route pattern matches but HTTP method is not allowed (HTTP 405).
 */
class MethodNotAllowedException extends RouterException
{
    /**
     * Create a new MethodNotAllowedException.
     *
     * @param string $message User-facing error message
     * @param int $code Error code
     * @param \Throwable|null $previous Previous exception
     * @param string|null $debugMessage Debug message with additional details
     * @param string[] $allowedMethods Allowed HTTP methods
     */
    public function __construct(
        string $message = 'Method not allowed',
        int $code = 0,
        ?\Throwable $previous = null,
        ?string $debugMessage = null,
        private readonly array $allowedMethods = [],
    ) {
        parent::__construct($message, $code, $previous, $debugMessage);
    }

    /**
     * Get the allowed HTTP methods for this route.
     *
     * @return string[]
     */
    public function getAllowedMethods(): array
    {
        return $this->allowedMethods;
    }
}
