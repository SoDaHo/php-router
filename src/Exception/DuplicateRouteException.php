<?php

declare(strict_types=1);

namespace Sodaho\Router\Exception;

/**
 * Thrown when a route with the same pattern and method is registered twice.
 */
class DuplicateRouteException extends RouterException
{
}
