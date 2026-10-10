<?php

declare(strict_types=1);

namespace Sodaho\Router;

use Sodaho\Router\Exception\RouterException;

/**
 * Value object representing a single route.
 *
 * Partially mutable while the routes file runs: middleware, name and attributes can be set
 * via fluent setters (or by assigning the property as a whole). Once the route table is
 * built (RouteCollector::getData()) the route is frozen: it is shared by every request it
 * serves, and what one request changed on it held for every request after it in the same
 * process — so every further change throws a RouterException. Writing into one of the
 * arrays in place ($route->attributes['k'] = …, $route->middleware[] = …) is never
 * possible: PHP refuses it with an Error, the setters are the way.
 */
final class Route
{
    /** Set when the route table is built: from then on nothing of the route changes */
    private bool $frozen = false;

    /**
     * What the application wants to know about this route before its handler runs
     * (response format, a CORS flag, ...). The router does not interpret it.
     *
     * Declared with a default instead of in the constructor's signature: a route that an
     * application unserializes from data written before 1.2 has no such property and wakes
     * up with [].
     *
     * @var array<string, mixed>
     */
    public array $attributes = [] {
        set(array $value) {
            $this->assertNotFrozen();
            $this->attributes = $value;
        }
    }

    /**
     * Create a new Route instance.
     *
     * @param string[] $methods HTTP methods (GET, POST, etc.)
     * @param string $pattern The route pattern (e.g., '/users/{id}')
     * @param mixed $handler Controller class, callable, or RequestHandler
     * @param array<string|object> $middleware Middleware class names/instances, outermost first
     *                                         (string keys as the application gave them)
     * @param string|null $name Optional route name for URL generation
     * @param array<string, mixed> $attributes Application-defined attributes
     */
    public function __construct(
        public readonly array $methods,
        public readonly string $pattern,
        public readonly mixed $handler,
        public array $middleware = [] {
            set(array $value) {
                $this->assertNotFrozen();
                $this->middleware = $value;
            }
        },
        public ?string $name = null {
            set(?string $value) {
                $this->assertNotFrozen();
                $this->name = $value;
            }
        },
        array $attributes = [],
    ) {
        $this->attributes = $attributes;
    }

    /**
     * Fluent setter for middleware.
     *
     * @param string|array<string|object>|object $middleware Middleware class name(s) or instance(s)
     *
     * @throws RouterException When the route table is built already
     */
    public function middleware(string|array|object $middleware): self
    {
        // array_merge(): numbered entries add up; one under a string key that is there
        // already replaces it in its place
        $middleware = is_array($middleware) ? $middleware : [$middleware];
        $this->middleware = array_merge($this->middleware, $middleware);
        return $this;
    }

    /**
     * Fluent setter for route name.
     *
     * @param string $name Route name for URL generation (e.g., 'users.show')
     *
     * @throws RouterException When the route table is built already
     */
    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Fluent setter for one attribute. Overrides what a surrounding attributeGroup() set.
     *
     * For the routes file: once the table is built the route is frozen.
     *
     * @param string $key Attribute name (e.g., 'format')
     * @param mixed $value Any value
     *
     * @throws RouterException When the route table is built already
     */
    public function attribute(string $key, mixed $value): self
    {
        // Assigned as a whole: an array behind a set hook cannot be written in place
        $attributes = $this->attributes;
        $attributes[$key] = $value;
        $this->attributes = $attributes;
        return $this;
    }

    /**
     * Read an attribute.
     *
     * @param mixed $default Returned when the attribute is not set
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->attributes) ? $this->attributes[$key] : $default;
    }

    /**
     * Freeze the route: called when the route table is built from it. A change after that
     * would hold for every request the route serves afterwards, in the same process.
     *
     * @internal
     */
    public function freeze(): void
    {
        $this->frozen = true;
    }

    /**
     * @throws RouterException When the route is frozen
     */
    private function assertNotFrozen(): void
    {
        if ($this->frozen) {
            throw new RouterException(
                'Route cannot be changed once the route table is built: it serves every request after that',
                debugMessage: $this->pattern,
            );
        }
    }
}
