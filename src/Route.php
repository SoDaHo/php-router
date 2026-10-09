<?php

declare(strict_types=1);

namespace Sodaho\Router;

/**
 * Value object representing a single route.
 *
 * Partially mutable: middleware, name and attributes can be set via fluent setters — meant
 * for the routes file. The object is shared by every request the route serves: what a
 * request changes on it holds for every request after it in the same process.
 */
final class Route
{
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
    public array $attributes = [];

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
        public array $middleware = [],
        public ?string $name = null,
        array $attributes = [],
    ) {
        $this->attributes = $attributes;
    }

    /**
     * Fluent setter for middleware.
     *
     * @param string|array<string|object>|object $middleware Middleware class name(s) or instance(s)
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
     */
    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Fluent setter for one attribute. Overrides what a surrounding attributeGroup() set.
     *
     * For the routes file: the route object is shared by every request it serves.
     *
     * @param string $key Attribute name (e.g., 'format')
     * @param mixed $value Any value
     */
    public function attribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;
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
}
