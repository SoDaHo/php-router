<?php

declare(strict_types=1);

namespace Sodaho\Router;

/**
 * Value object representing a single route.
 *
 * Immutable container for route configuration.
 */
class Route
{
    /**
     * Create a new Route instance.
     *
     * @param string[] $methods HTTP methods (GET, POST, etc.)
     * @param string $pattern The route pattern (e.g., '/users/{id}')
     * @param mixed $handler Controller class, callable, or RequestHandler
     * @param array<int, string|object> $middleware List of middleware class names/instances
     * @param string|null $name Optional route name for URL generation
     */
    public function __construct(
        public readonly array $methods,
        public readonly string $pattern,
        public readonly mixed $handler,
        public array $middleware = [],
        public ?string $name = null
    ) {
    }

    /**
     * Fluent setter for middleware.
     *
     * @param string|array<string|object>|object $middleware Middleware class name(s) or instance(s)
     */
    public function middleware(string|array|object $middleware): self
    {
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
     * Restore object from var_export() output.
     *
     * Required for OPcache-friendly route caching.
     *
     * @param array{methods: string[], pattern: string, handler: mixed, middleware?: array<int, string|object>, name?: string|null} $data Exported data
     */
    public static function __set_state(array $data): self
    {
        return new self(
            $data['methods'],
            $data['pattern'],
            $data['handler'],
            $data['middleware'] ?? [],
            $data['name'] ?? null
        );
    }
}
