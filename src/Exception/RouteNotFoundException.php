<?php

declare(strict_types=1);

namespace Sodaho\Router\Exception;

/**
 * Thrown when URL generation fails because the route name does not exist.
 */
class RouteNotFoundException extends RouterException
{
}
