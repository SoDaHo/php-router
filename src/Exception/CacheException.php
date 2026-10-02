<?php

declare(strict_types=1);

namespace Sodaho\Router\Exception;

/**
 * Thrown when cache operations fail.
 */
class CacheException extends RouterException
{
    /**
     * Create exception for unwritable directory.
     *
     * @param string $directory Directory path
     */
    public static function directoryNotWritable(string $directory): self
    {
        return new self(
            sprintf('Cache directory is not writable: %s', $directory),
            0,
            null,
            'Ensure the directory exists and has write permissions.'
        );
    }

    /**
     * Create exception for write failure.
     *
     * @param string $file File path
     */
    public static function writeFailed(string $file): self
    {
        return new self(
            sprintf('Failed to write cache file: %s', $file),
            0,
            null,
            'Check file permissions and disk space.'
        );
    }

    /**
     * Create exception for invalid signature.
     */
    public static function invalidSignature(): self
    {
        return new self(
            'Cache file signature is invalid',
            0,
            null,
            'The cache file may have been tampered with or the signature key has changed.'
        );
    }

    /**
     * Create exception for a correctly signed cache whose content cannot be restored.
     *
     * @param string $reason What does not fit (unknown class, failed wake-up)
     */
    public static function outdated(string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            'Cache file is outdated',
            0,
            $previous,
            sprintf('%s. The cache does not fit the classes of this process; it is rebuilt from the routes file.', ucfirst($reason))
        );
    }

    /**
     * Create exception for missing signature key.
     */
    public static function signatureKeyRequired(): self
    {
        return new self(
            'Cache signature key is required when caching is enabled',
            0,
            null,
            'Provide a signature key via enableCache($file, $key) or set ROUTER_CACHE_KEY environment variable. '
            . 'This prevents RCE attacks via tampered cache files.'
        );
    }
}
