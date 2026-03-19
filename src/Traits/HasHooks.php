<?php

declare(strict_types=1);

namespace Sodaho\Router\Traits;

/**
 * Trait for event hooks.
 *
 * Provides on() for registering and trigger() for firing events.
 * Unlike pdo-wrapper/container (which let exceptions bubble up), this
 * implementation catches hook exceptions and logs them. The router must
 * always return a response to the client (API-first design).
 */
trait HasHooks
{
    /** @var array<string, array<callable>> */
    private array $hooks = [];

    /**
     * Register a hook callback for an event.
     *
     * @param string $event Event name (e.g., 'dispatch', 'notFound', 'error')
     * @param callable $callback Callback receiving event data array
     */
    public function on(string $event, callable $callback): static
    {
        $this->hooks[$event][] = $callback;
        return $this;
    }

    /**
     * Trigger all callbacks for an event.
     *
     * Method is named trigger() for API consistency with pdo-wrapper/container.
     *
     * @param string $event Event name
     * @param array<string, mixed> $data Event data passed to callbacks
     */
    protected function trigger(string $event, array $data): void
    {
        foreach ($this->hooks[$event] ?? [] as $callback) {
            try {
                $callback($data);
            } catch (\Throwable $e) {
                $this->handleHookException($event, $e);
            }
        }
    }

    /**
     * Logs to stderr but never interrupts the request.
     */
    protected function handleHookException(string $event, \Throwable $e): void
    {
        $message = sprintf(
            "[Router] Hook error in '%s': %s in %s:%d\n",
            $event,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );

        if ($this->hasStderr()) {
            fwrite(STDERR, $message);
        } else {
            error_log($message);
        }
    }

    protected function hasStderr(): bool
    {
        return defined('STDERR');
    }
}
