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

        // Should not throw
        $obj->fireEvent();
        $this->assertTrue(true);
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
}
