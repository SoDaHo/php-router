<?php

declare(strict_types=1);

namespace Sodaho\Router\Exception;

/**
 * Base exception for all router errors.
 */
class RouterException extends \Exception
{
    /**
     * Create a new RouterException.
     *
     * @param string $message User-facing error message
     * @param int $code Error code
     * @param \Throwable|null $previous Previous exception
     * @param string|null $debugMessage Debug message with additional details
     */
    public function __construct(
        string $message = 'Router error',
        int $code = 0,
        ?\Throwable $previous = null,
        protected ?string $debugMessage = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Get the debug message with additional details.
     */
    public function getDebugMessage(): ?string
    {
        return $this->debugMessage;
    }
}
