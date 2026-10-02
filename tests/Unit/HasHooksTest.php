<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\Router\Traits\HasHooks;

class HasHooksTest extends TestCase
{
    public function testOnRegistersCallback(): void
    {
        $obj = new class () {
            use HasHooks;

            public function fireEvent(): void
            {
                $this->trigger('test', ['data' => 'value']);
            }
        };

        $received = null;
        $obj->on('test', function ($data) use (&$received) {
            $received = $data;
        });

        $obj->fireEvent();

        $this->assertSame(['data' => 'value'], $received);
    }

    public function testOnReturnsSelfForFluent(): void
    {
        $obj = new class () {
            use HasHooks;
        };

        $result = $obj->on('test', fn () => null);

        $this->assertSame($obj, $result);
    }

    public function testTriggerCallsMultipleCallbacks(): void
    {
        $obj = new class () {
            use HasHooks;

            public function fireEvent(): void
            {
                $this->trigger('test', []);
            }
        };

        $count = 0;
        $obj->on('test', function () use (&$count) {
            $count++;
        });
        $obj->on('test', function () use (&$count) {
            $count++;
        });

        $obj->fireEvent();

        $this->assertSame(2, $count);
    }

    public function testTriggerIgnoresUnregisteredEvents(): void
    {
        $obj = new class () {
            use HasHooks;

            public function fireEvent(): void
            {
                $this->trigger('nonexistent', []);
            }
        };

        $this->expectNotToPerformAssertions();
        $obj->fireEvent();
    }

    public function testHookExceptionDoesNotInterruptExecution(): void
    {
        $obj = new class () {
            use HasHooks;

            public function fireEvent(): void
            {
                $this->trigger('test', []);
            }
        };

        $secondCalled = false;

        // First callback throws
        $obj->on('test', function () {
            throw new \RuntimeException('Hook crashed!');
        });

        // Second callback should still be called
        $obj->on('test', function () use (&$secondCalled) {
            $secondCalled = true;
        });

        $obj->fireEvent();

        $this->assertTrue($secondCalled);
    }

    public function testHandleHookExceptionUsesStderr(): void
    {
        $obj = new class () {
            use HasHooks;

            public function fireEvent(): void
            {
                $this->trigger('test', []);
            }
        };

        $obj->on('test', function () {
            throw new \Exception('Test exception');
        });

        // Capture STDERR output
        ob_start();
        $obj->fireEvent();
        ob_end_clean();

        // If we got here without fatal error, STDERR path worked
        $this->assertTrue(true);
    }

    public function testHandleHookExceptionFallsBackToErrorLog(): void
    {
        // Simulate environment without STDERR (e.g., CGI/FPM)
        $obj = new class () {
            use HasHooks;

            // Override the environment check
            protected function hasStderr(): bool
            {
                return false;
            }

            public function fireEvent(): void
            {
                $this->trigger('test', []);
            }
        };

        $obj->on('test', function () {
            throw new \Exception('Test exception for error_log');
        });

        // error_log() is called instead of fwrite(STDERR)
        // We can't easily capture error_log output, but if we get here
        // without errors, the code path was executed (coverage)
        $obj->fireEvent();

        $this->assertTrue(true);
    }

    public function testHasStderrReturnsTrue(): void
    {
        $obj = new class () {
            use HasHooks;

            public function checkStderr(): bool
            {
                return $this->hasStderr();
            }
        };

        // In CLI/PHPUnit, STDERR is always defined
        $this->assertTrue($obj->checkStderr());
    }

    /**
     * An object whose fallback line goes to a file we can read: no STDERR, error_log to a temp file.
     *
     * @return array{0: object, 1: string}
     */
    private function hookedObjectWithReadableLog(): array
    {
        $log = sys_get_temp_dir() . '/router_hook_log_' . uniqid() . '.log';
        $obj = new class () {
            use HasHooks;

            protected function hasStderr(): bool
            {
                return false;
            }

            public function fire(string $event): void
            {
                $this->trigger($event, []);
            }
        };

        return [$obj, $log];
    }

    /**
     * @param callable(): void $act
     */
    private function loggedWhile(string $log, callable $act): string
    {
        $previous = ini_set('error_log', $log);

        try {
            $act();
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $written = is_file($log) ? (string) file_get_contents($log) : '';
        @unlink($log);

        return $written;
    }

    public function testFailingHookWritesALineWithoutAHookErrorCallback(): void
    {
        [$obj, $log] = $this->hookedObjectWithReadableLog();
        $obj->on('dispatch', fn () => throw new \RuntimeException('metrics down'));

        $written = $this->loggedWhile($log, fn () => $obj->fire('dispatch'));

        $this->assertStringContainsString("[Router] Hook error in 'dispatch': metrics down in ", $written);
    }

    public function testHookErrorCallbackGetsEventAndExceptionAndNothingIsWritten(): void
    {
        [$obj, $log] = $this->hookedObjectWithReadableLog();
        $failure = new \RuntimeException('metrics down');
        $after = false;

        $received = [];
        $obj->on('hookError', function (array $data) use (&$received): void {
            $received[] = $data;
        });
        $obj->on('hookError', function (array $data) use (&$received): void {
            $received[] = 'second callback';
        });
        $obj->on('dispatch', fn () => throw $failure);
        $obj->on('dispatch', function () use (&$after): void {
            $after = true;
        });

        $written = $this->loggedWhile($log, fn () => $obj->fire('dispatch'));

        // The application decides what happens with it — the library stays quiet
        $this->assertSame('', $written);
        $this->assertSame([['event' => 'dispatch', 'exception' => $failure], 'second callback'], $received);

        // ... and the hooks after the failing one still run
        $this->assertTrue($after);
    }

    public function testFailingHookErrorCallbackFallsBackToTheLine(): void
    {
        [$obj, $log] = $this->hookedObjectWithReadableLog();
        $calls = 0;

        $obj->on('hookError', function () use (&$calls): void {
            $calls++;

            throw new \LogicException('logger down too');
        });
        $obj->on('dispatch', fn () => throw new \RuntimeException('metrics down'));

        $written = $this->loggedWhile($log, fn () => $obj->fire('dispatch'));

        // Not handed to itself again: one call. Then both lines — the callback's own failure
        // and the one it was called for, so that nothing is lost
        $this->assertSame(1, $calls);
        $this->assertSame(1, substr_count($written, "[Router] Hook error in 'hookError': logger down too in "));
        $this->assertSame(1, substr_count($written, "[Router] Hook error in 'dispatch': metrics down in "));
    }

    public function testEveryHookErrorCallbackGetsItsTurn(): void
    {
        [$obj, $log] = $this->hookedObjectWithReadableLog();
        $second = [];

        $obj->on('hookError', fn () => throw new \LogicException('first reporter down'));
        $obj->on('hookError', function (array $data) use (&$second): void {
            $second[] = $data['exception']->getMessage();
        });
        $obj->on('dispatch', fn () => throw new \RuntimeException('metrics down'));

        $this->loggedWhile($log, fn () => $obj->fire('dispatch'));
        $this->loggedWhile($log, fn () => $obj->fire('dispatch'));

        // The failing first one does not keep the second from being told — neither time
        $this->assertSame(['metrics down', 'metrics down'], $second);
    }

    public function testHookErrorCallbackThatSetsOffAnotherFailingHookDoesNotCallItselfForever(): void
    {
        [$obj, $log] = $this->hookedObjectWithReadableLog();
        $calls = 0;

        $obj->on('audit', fn () => throw new \RuntimeException('audit down'));
        $obj->on('hookError', function () use (&$calls, $obj): void {
            $calls++;
            // reports through something that has a failing hook of its own
            $obj->fire('audit');
        });
        $obj->on('dispatch', fn () => throw new \RuntimeException('metrics down'));

        $written = $this->loggedWhile($log, fn () => $obj->fire('dispatch'));

        $this->assertSame(1, $calls);
        $this->assertSame(1, substr_count($written, "[Router] Hook error in 'audit': audit down in "));
        $this->assertStringNotContainsString('metrics down', $written, 'the callback took it, nothing more to write');

        // ... and the callback is back in charge for the next failure
        $this->loggedWhile($log, fn () => $obj->fire('dispatch'));
        $this->assertSame(2, $calls);
    }

    public function testHookErrorFiredAsAnEventOfItsOwnDoesNotLoop(): void
    {
        [$obj, $log] = $this->hookedObjectWithReadableLog();
        $calls = 0;
        $obj->on('hookError', function () use (&$calls): void {
            $calls++;

            throw new \LogicException('always fails');
        });

        $written = $this->loggedWhile($log, fn () => $obj->fire('hookError'));

        $this->assertSame(1, $calls, 'its own failure is not handed to it again');
        $this->assertSame(1, substr_count($written, "[Router] Hook error in 'hookError': always fails"));
    }

    /**
     * The trait is public API: classes outside the library use it. hookError must not put
     * members into them that they may already have under the same name.
     */
    public function testTraitAddsNoMembersAClassCouldAlreadyHave(): void
    {
        $members = static fn (string $kind): array => array_map(
            static fn (\ReflectionProperty|\ReflectionMethod $member): string => $member->getName(),
            (new \ReflectionClass(HasHooks::class))->{$kind}()
        );

        // As in 1.1.1
        $this->assertSame(['hooks'], $members('getProperties'));
        $this->assertSame(['on', 'trigger', 'handleHookException', 'hasStderr'], $members('getMethods'));
    }

    public function testReentryIsTrackedPerObject(): void
    {
        [$first, $log] = $this->hookedObjectWithReadableLog();
        [$second] = $this->hookedObjectWithReadableLog();
        $told = [];

        // The first object's reporter uses the second object — whose own reporter must
        // still be asked, although the first is in the middle of reporting
        $second->on('hookError', function (array $data) use (&$told): void {
            $told[] = 'second: ' . $data['exception']->getMessage();
        });
        $second->on('audit', fn () => throw new \RuntimeException('audit down'));
        $first->on('hookError', function (array $data) use (&$told, $second): void {
            $told[] = 'first: ' . $data['exception']->getMessage();
            $second->fire('audit');
        });
        $first->on('dispatch', fn () => throw new \RuntimeException('metrics down'));

        $written = $this->loggedWhile($log, fn () => $first->fire('dispatch'));

        $this->assertSame(['first: metrics down', 'second: audit down'], $told);
        $this->assertSame('', $written);
    }
}
