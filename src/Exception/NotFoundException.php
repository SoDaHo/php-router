<?php

declare(strict_types=1);

namespace Sodaho\Router\Exception;

/**
 * Thrown when no route matches the request (HTTP 404).
 */
class NotFoundException extends RouterException
{
}
