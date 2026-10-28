<?php

declare(strict_types=1);

namespace Sahil\Tests\Scheduler;

use PHPUnit\Framework\TestCase;
use Sahil\Scheduler\TaskTimeoutException;
use Sahil\Scheduler\TimeoutGuard;

final class TimeoutGuardTest extends TestCase
{
    public function test_a_disabled_timeout_just_runs_the_task(): void
    {
        $ran = false;

        (new TimeoutGuard(false))->run('plain', static function () use (&$ran): void {
            $ran = true;
        }, 0);

        self::assertTrue($ran);
    }

    public function test_a_fast_task_is_not_timed_out(): void
    {
        $ran = false;

        (new TimeoutGuard(false))->run('fast', static function () use (&$ran): void {
            $ran = true;
            usleep(10_000);
        }, 1);

        self::assertTrue($ran);
    }

    public function test_the_measured_fallback_reports_an_overrun(): void
    {
        $this->expectException(TaskTimeoutException::class);
        $this->expectExceptionMessage('timed out after 1 second(s)');

        (new TimeoutGuard(false))->run('slow', static function (): void {
            usleep(1_100_000);
        }, 1);
    }

    public function test_a_long_task_is_really_interrupted_when_the_process_supports_signals(): void
    {
        $guard = new TimeoutGuard();

        if (!$guard->supportsInterruption()) {
            self::markTestSkipped('pcntl is not available; only the measured fallback applies.');
        }

        $started = microtime(true);

        try {
            $guard->run('blocked', static function (): void {
                usleep(3_000_000);
            }, 1);

            self::fail('The task should have been interrupted.');
        } catch (TaskTimeoutException $timeout) {
            self::assertStringContainsString('timed out after 1 second(s)', $timeout->getMessage());
        }

        self::assertLessThan(2.5, microtime(true) - $started, 'the task must stop near its deadline');
    }

    public function test_the_alarm_is_cancelled_after_a_successful_run(): void
    {
        $guard = new TimeoutGuard();

        $guard->run('quick', static function (): void {
        }, 30);

        // No alarm should fire into the next test that happens to run here.
        self::assertTrue(true);
    }
}
