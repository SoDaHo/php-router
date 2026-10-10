<?php

declare(strict_types=1);

namespace Sodaho\Router\Exception;

/**
 * Thrown when a route with the same pattern and method is registered twice, or when two
 * routes of different patterns have the same name (when the route table is built, and in
 * new UrlGenerator()): a name belongs to one address.
 */
class DuplicateRouteException extends RouterException
{
}
