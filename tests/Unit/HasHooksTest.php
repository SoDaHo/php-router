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
        $reported = [];

        // First callback throws
        $failure = new \RuntimeException('Hook crashed!');
        $obj->on('test', function () use ($failure) {
            throw $failure;
        });

        // Second callback should still be called
        $obj->on('test', function () use (&$secondCalled) {
            $secondCalled = true;
        });

        // Told to the application, not to stderr
        $obj->on('hookError', function (array $data) use (&$reported): void {
            $reported[] = $data;
        });

        $obj->fireEvent();

        $this->assertTrue($secondCalled);
        $this->assertSame([['event' => 'test', 'exception' => $failure]], $reported);
    }

    /**
     * The line goes to the stderr of the process — read from the outside, in a process of
     * its own
     */
    public function testHandleHookExceptionUsesStderr(): void
    {
        $code = 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . '$obj = new class () { use Sodaho\Router\Traits\HasHooks; public function fire(): void { $this->trigger("test", []); } };'
            . '$obj->on("test", function (): void { throw new LogicException("not in the line"); });'
            . '$obj->fire();'
            . 'echo "went on";';

        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_log=', '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $this->assertSame('went on', $stdout);
        $this->assertMatchesRegularExpression("#^\\[Router\\] Hook error in 'test': LogicException in .+:\\d+\\n\\z#", $stderr);
        $this->assertStringNotContainsString('not in the line', $stderr);
    }

    public function testHandleHookExceptionFallsBackToErrorLog(): void
    {
        // Without STDERR (e.g. CGI/FPM) the line goes to error_log()
        [$obj, $log] = $this->hookedObjectWithReadableLog();
        $obj->on('test', function () {
            throw new \Exception('Test exception for error_log');
        });

        $written = $this->loggedWhile($log, fn () => $obj->fire('test'));

        $this->assertSame(1, substr_count($written, "[Router] Hook error in 'test': Exception in " . __FILE__ . ':'));
    }

    public function testHookThatFailsWhereNothingCanBeWrittenStillDoesNotInterrupt(): void
    {
        // stderr closed, or a handler that turns the warning of a failed write into an exception
        $obj = new class () {
            use HasHooks;

            public bool $asked = false;

            protected function hasStderr(): bool
            {
                $this->asked = true;

                throw new \ErrorException('fwrite(): Write of 60 bytes failed with errno=32 Broken pipe');
            }

            public function fireEvent(): string
            {
                $this->trigger('test', []);

                return 'went on';
            }
        };

        $obj->on('test', function (): void {
            throw new \Exception('hook failed');
        });

        $this->assertSame('went on', $obj->fireEvent());
        $this->assertTrue($obj->asked);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testHookThatFailsWithStderrClosedDoesNotInterrupt(): void
    {
        $obj = new class () {
            use HasHooks;

            public function fireEvent(): string
            {
                $this->trigger('test', []);

                return 'went on';
            }
        };

        $obj->on('test', function (): void {
            throw new \Exception('hook failed');
        });

        // 1.x: TypeError "fwrite(): Argument #1 ($stream) must be an open stream resource"
        fclose(STDERR);

        $this->assertSame('went on', $obj->fireEvent());
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
     * @return array{0: HookedWithoutStderr, 1: string}
     */
    private function hookedObjectWithReadableLog(): array
    {
        return [new HookedWithoutStderr(), sys_get_temp_dir() . '/router_hook_log_' . uniqid() . '.log'];
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

        $this->assertStringContainsString("[Router] Hook error in 'dispatch': RuntimeException in ", $written);
    }

    /**
     * The message of an exception may carry what a request sent — a line break included,
     * which forged a second line in a line-based log. The line says where, not what: the
     * whole exception goes to hookError only.
     */
    public function testLineNamesTheExceptionAndItsPlaceButNotItsMessage(): void
    {
        [$obj, $log] = $this->hookedObjectWithReadableLog();
        $line = __LINE__ + 1;
        $obj->on('dispatch', fn () => throw new \RuntimeException("token=secret\n[Router] Hook error in 'forged': x in y:1"));

        $written = $this->loggedWhile($log, fn () => $obj->fire('dispatch'));

        $this->assertStringContainsString("[Router] Hook error in 'dispatch': RuntimeException in " . __FILE__ . ':' . $line . "\n", $written);
        $this->assertStringNotContainsString('secret', $written);
        $this->assertStringNotContainsString('forged', $written);
        $this->assertSame(1, substr_count($written, '[Router]'));
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
        $this->assertSame(1, substr_count($written, "[Router] Hook error in 'hookError': LogicException in "));
        $this->assertSame(1, substr_count($written, "[Router] Hook error in 'dispatch': RuntimeException in "));
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
        $this->assertSame(1, substr_count($written, "[Router] Hook error in 'audit': RuntimeException in "));
        $this->assertStringNotContainsString("'dispatch'", $written, 'the callback took it, nothing more to write');

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
        $this->assertSame(1, substr_count($written, "[Router] Hook error in 'hookError': LogicException in "));
    }

    /**
     * The trait is public API: classes outside the library use it. hookError must not put
     * members into them that they may already have under the same name.
     */
    public function testTraitAddsNoMembersAClassCouldAlreadyHave(): void
    {
        $members = static fn (string $kind): array => array_map(
            static fn (\ReflectionProperty|\ReflectionMethod $member): string => $member->getName(),
            new \ReflectionClass(HasHooks::class)->{$kind}()
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

/**
 * A hooked object whose fallback line goes to error_log(): it has no STDERR.
 */
final class HookedWithoutStderr
{
    use HasHooks;

    protected function hasStderr(): bool
    {
        return false;
    }

    public function fire(string $event): void
    {
        $this->trigger($event, []);
    }
}
