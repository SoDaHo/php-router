<?php

declare(strict_types=1);

namespace Sodaho\Router\Traits;

/**
 * Trait for event hooks.
 *
 * Provides on() for registering and trigger() for firing events. A hook that throws never
 * interrupts the caller: its exception goes to the 'hookError' hook (a line on stderr
 * without one), and the hooks after it still run — the router has to answer every request.
 * The trait adds no member a class that uses it could already have (see
 * handleHookException()).
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
     * Trigger all callbacks for an event, in the order they were registered.
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
     * A failing hook never interrupts the request.
     *
     * With callbacks registered for 'hookError' the application gets event and exception
     * and decides what to do with them. Without one a line goes to stderr (error_log()
     * where there is no stderr). A callback that fails itself gets that line too — and so
     * does the failure it was called for, so that nothing is lost.
     *
     * The line names the event, the class of the exception and where it was thrown — not
     * its message: that may carry what a request sent (a line break that forges a second
     * line in the log, a token). The whole exception goes to 'hookError' only.
     */
    protected function handleHookException(string $event, \Throwable $e): void
    {
        // State and helper live in this method: the trait adds no member that a class
        // using it could already have
        /** @var \WeakMap<object, true>|null $reporting Objects whose hookError callbacks are running */
        static $reporting = null;
        $reporting ??= new \WeakMap();

        $write = function (string $event, \Throwable $e): void {
            $message = sprintf(
                "[Router] Hook error in '%s': %s in %s:%d\n",
                $event,
                $e::class,
                $e->getFile(),
                $e->getLine()
            );

            try {
                if ($this->hasStderr()) {
                    fwrite(STDERR, $message);
                } else {
                    error_log($message);
                }
            } catch (\Throwable) {
                // Nowhere left to say it (stderr was closed, or a handler turns the
                // warning of a failed write into an exception): a failing hook still
                // never interrupts the request
            }
        };

        // Not while a hookError callback runs: what fails in there must not come back to it
        if (!isset($reporting[$this]) && $event !== 'hookError' && ($this->hooks['hookError'] ?? []) !== []) {
            $reporting[$this] = true;
            $reported = true;

            try {
                foreach ($this->hooks['hookError'] as $callback) {
                    try {
                        $callback(['event' => $event, 'exception' => $e]);
                    } catch (\Throwable $failure) {
                        $reported = false;
                        $write('hookError', $failure);
                    }
                }
            } finally {
                unset($reporting[$this]);
            }

            if ($reported) {
                return;
            }
        }

        $write($event, $e);
    }

    protected function hasStderr(): bool
    {
        return defined('STDERR');
    }
}
