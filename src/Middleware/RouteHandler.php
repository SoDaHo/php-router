<?php

declare(strict_types=1);

namespace Sodaho\Router\Middleware;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sodaho\Router\Exception\RouterException;

/**
 * Final handler that executes the route controller/callable.
 *
 * IMPORTANT: Controller MUST return ResponseInterface (no array magic!).
 */
final class RouteHandler implements RequestHandlerInterface
{
    /**
     * Create a new RouteHandler instance.
     *
     * @param mixed $handler Controller [class name, method], a callable ([$object, 'method']
     *                       included), or a RequestHandler
     * @param ContainerInterface|null $container PSR-11 container for dependency injection
     */
    public function __construct(
        private readonly mixed $handler,
        private readonly ?ContainerInterface $container = null
    ) {
    }

    /**
     * Handle the request by executing the route handler.
     *
     * @param ServerRequestInterface $request PSR-7 request
     *
     * @throws RouterException If handler is invalid or does not return ResponseInterface, or
     *                         the container's entry for a controller class is no object of
     *                         that class
     *
     * @return ResponseInterface PSR-7 response
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Use only route params (not all attributes, which may include middleware-added ones)
        $arguments = $request->getAttribute('_route_params', []);

        // PSR-15 RequestHandler (e.g., RedirectHandler)
        if ($this->handler instanceof RequestHandlerInterface) {
            return $this->handler->handle($request);
        }

        // Controller class + method. A class name only: [$object, 'method'] is a callable
        // and is called on that object below — taken for a class name, it would be a
        // TypeError (a 500)
        if (is_array($this->handler) && count($this->handler) === 2 && is_string($this->handler[0] ?? null)) {
            [$class, $method] = $this->handler;

            // Resolve from container or instantiate directly
            /** @var class-string $class */
            $instance = ($this->container?->has($class))
                ? $this->container->get($class)
                : $this->instantiateController($class);

            // What the container returns under the class name has to be an object of that
            // class (or of a class below it, or one that implements the interface named):
            // a wrong alias or factory would run another object's method of the same name — a
            // consent or token handler of the wrong kind — without a word
            if (!$instance instanceof $class) {
                throw new RouterException(
                    'The container entry for a controller is no object of the class the route names: register the controller itself under its class name',
                    debugMessage: sprintf('%s: %s', $class, get_debug_type($instance)),
                );
            }

            // PHP 8 Named Arguments: ['id' => 5] becomes id: 5
            try {
                $response = $instance->{$method}($request, ...$arguments);
            } catch (\Error $e) {
                throw self::nameClash([$instance, $method], $arguments, $e) ?? $e;
            }

        } elseif (is_callable($this->handler)) {
            // Closure or callable
            try {
                $response = ($this->handler)($request, ...$arguments);
            } catch (\Error $e) {
                throw self::nameClash($this->handler, $arguments, $e) ?? $e;
            }

        } else {
            throw new RouterException('Invalid route handler.');
        }

        // NO ARRAY MAGIC! Controller MUST use Response::success() etc.
        if (!$response instanceof ResponseInterface) {
            throw new RouterException(
                sprintf(
                    'Handler must return ResponseInterface, got %s. Use Response::success($data).',
                    get_debug_type($response)
                )
            );
        }

        return $response;
    }

    /**
     * @param class-string $class
     *
     * @throws RouterException If controller requires constructor parameters
     */
    private function instantiateController(string $class): object
    {
        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        // No constructor or constructor with no required parameters -> OK
        if ($constructor === null || $constructor->getNumberOfRequiredParameters() === 0) {
            return new $class();
        }

        // Constructor has required parameters -> needs DI container
        throw new RouterException(
            'Controller requires constructor parameters. Register it in a PSR-11 container or use setContainer().',
            debugMessage: $class,
        );
    }

    /**
     * A placeholder that has the name of the handler's first parameter: PHP refuses the
     * call ("Named parameter $request overwrites previous argument") before the handler
     * runs. Said in the router's words, with the name of the placeholder. Null when the
     * error has another cause — then it came out of the handler itself.
     *
     * @param mixed $handler What was called
     * @param mixed $arguments The route parameters it was called with
     */
    private static function nameClash(mixed $handler, mixed $arguments, \Error $error): ?RouterException
    {
        if (!is_callable($handler) || !is_array($arguments)) {
            return null;
        }

        $first = new \ReflectionFunction(\Closure::fromCallable($handler))->getParameters()[0] ?? null;

        // With a name of its own for the first parameter PHP refuses the call before the
        // handler runs. A variadic first parameter collects the named value instead: the
        // handler runs, and its errors are its own.
        if ($first === null || $first->isVariadic() || !array_key_exists($first->getName(), $arguments)) {
            return null;
        }

        return new RouterException(
            sprintf('Placeholder "%s" has the name of the handler\'s first parameter, which receives the request. Rename one of them.', $first->getName()),
            0,
            $error,
        );
    }
}
