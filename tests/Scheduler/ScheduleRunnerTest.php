<?php

declare(strict_types=1);

namespace Sahil\Tests\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sahil\Scheduler\Schedule;
use Sahil\Scheduler\ScheduleRunner;
use Sahil\Scheduler\TaskResult;
use Sahil\Scheduler\TimeoutGuard;

final class ScheduleRunnerTest extends TestCase
{
    private Schedule $schedule;

    private RecordingLock $lock;

    private ArrayLogger $logger;

    protected function setUp(): void
    {
        $this->schedule = new Schedule('UTC');
        $this->lock = new RecordingLock();
        $this->logger = new ArrayLogger();
    }

    private static function utc(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    private function runner(DateTimeImmutable $now, ?TimeoutGuard $guard = null): ScheduleRunner
    {
        return new ScheduleRunner(
            $this->schedule,
            new FrozenClock($now),
            $this->lock,
            $this->logger,
            $guard,
        );
    }

    public function test_a_due_task_runs_once(): void
    {
        $calls = 0;
        $this->schedule->call('plain', static function () use (&$calls): void {
            $calls++;
        })->hourly();

        $report = $this->runner(self::utc('2026-10-28 09:00:10'))->run();

        self::assertSame(1, $calls);
        self::assertSame(1, $report->ran());
        self::assertFalse($report->failed());
        self::assertSame([], $this->lock->acquired, 'no lock is taken when overlap prevention is off');
    }

    public function test_a_task_that_is_not_due_does_not_run(): void
    {
        $calls = 0;
        $this->schedule->call('morning', static function () use (&$calls): void {
            $calls++;
        })->dailyAt('07:00');

        $report = $this->runner(self::utc('2026-10-28 09:00:00'))->run();

        self::assertSame(0, $calls);
        self::assertTrue($report->isEmpty());
    }

    public function test_a_late_start_within_the_same_minute_still_runs_the_task(): void
    {
        $calls = 0;
        $this->schedule->call('nine', static function () use (&$calls): void {
            $calls++;
        })->dailyAt('09:00');

        $report = $this->runner(self::utc('2026-10-28 09:00:59'))->run();

        self::assertSame(1, $calls);
        self::assertSame(1, $report->ran());
    }

    public function test_a_missed_minute_is_not_back_filled(): void
    {
        $calls = 0;
        $this->schedule->call('nine', static function () use (&$calls): void {
            $calls++;
        })->dailyAt('09:00');

        $report = $this->runner(self::utc('2026-10-28 09:01:00'))->run();

        self::assertSame(0, $calls, 'starting at 09:01 must not replay the 09:00 run');
        self::assertTrue($report->isEmpty());
    }

    public function test_overlapping_runs_are_skipped_while_the_lock_is_held(): void
    {
        $calls = 0;
        $this->schedule->call('slow', static function () use (&$calls): void {
            $calls++;
        })->everyMinute()->withoutOverlapping();

        $this->lock->hold('schedule:slow');

        $report = $this->runner(self::utc('2026-10-28 09:00:00'))->run();

        self::assertSame(0, $calls);
        self::assertSame(1, $report->skipped());
        self::assertSame(TaskResult::SKIPPED, $report->results()[0]->status);
        self::assertStringContainsString('previous run', $report->results()[0]->message);
        self::assertSame([], $this->lock->released, 'a lock that was not taken is not released');
        self::assertCount(1, $this->logger->entries);
        self::assertSame('warning', $this->logger->entries[0]['level']);
    }

    public function test_the_lock_is_released_after_a_successful_run(): void
    {
        $this->schedule->call('locked', static function (): void {
        })->everyMinute()->withoutOverlapping();

        $this->runner(self::utc('2026-10-28 09:00:00'))->run();

        self::assertSame(['schedule:locked'], $this->lock->acquired);
        self::assertSame(['schedule:locked'], $this->lock->released);
        self::assertFalse($this->lock->holds('schedule:locked'));
    }

    public function test_the_lock_is_released_after_a_failure(): void
    {
        $this->schedule->call('failing', static function (): void {
            throw new RuntimeException('boom');
        })->everyMinute()->withoutOverlapping();

        $report = $this->runner(self::utc('2026-10-28 09:00:00'))->run();

        self::assertSame(1, $report->failedCount());
        self::assertFalse($this->lock->holds('schedule:failing'));
    }

    public function test_a_failure_is_recorded_and_does_not_stop_other_tasks(): void
    {
        $otherRan = false;

        $this->schedule->call('failing', static function (): void {
            throw new RuntimeException('boom');
        })->everyMinute();

        $this->schedule->call('healthy', static function () use (&$otherRan): void {
            $otherRan = true;
        })->everyMinute();

        $report = $this->runner(self::utc('2026-10-28 09:00:00'))->run();

        self::assertTrue($otherRan);
        self::assertTrue($report->failed());
        self::assertSame(1, $report->ran());
        self::assertSame(1, $report->failedCount());

        $failure = $report->failures()[0];
        self::assertSame('failing', $failure->task);
        self::assertSame('boom', $failure->message);

        $errors = array_values(array_filter(
            $this->logger->entries,
            static fn (array $entry): bool => $entry['level'] === 'error',
        ));

        self::assertCount(1, $errors);
        self::assertStringContainsString('boom', $errors[0]['message']);
        self::assertSame('failing', $errors[0]['context']['task']);
        self::assertSame(RuntimeException::class, $errors[0]['context']['exception']);
    }

    public function test_only_restricts_which_due_tasks_run(): void
    {
        $ran = [];
        $record = static function (string $name) use (&$ran): \Closure {
            return static function () use (&$ran, $name): void {
                $ran[] = $name;
            };
        };

        $this->schedule->call('first', $record('first'))->everyMinute();
        $this->schedule->call('second', $record('second'))->everyMinute();

        $report = $this->runner(self::utc('2026-10-28 09:00:00'))->run('second');

        self::assertSame(['second'], $ran);
        self::assertSame(1, $report->ran());
    }

    public function test_only_does_not_force_a_task_that_is_not_due(): void
    {
        $calls = 0;
        $this->schedule->call('later', static function () use (&$calls): void {
            $calls++;
        })->dailyAt('23:00');

        $report = $this->runner(self::utc('2026-10-28 09:00:00'))->run('later');

        self::assertSame(0, $calls);
        self::assertTrue($report->isEmpty());
    }

    public function test_a_timeout_is_reported_as_a_failure_and_releases_the_lock(): void
    {
        $this->schedule->call('sleepy', static function (): void {
            usleep(1_200_000);
        })->everyMinute()->withoutOverlapping()->timeoutAfter(1);

        // Measure-only guard: deterministic without relying on signals.
        $report = $this->runner(self::utc('2026-10-28 09:00:00'), new TimeoutGuard(false))->run();

        self::assertSame(1, $report->failedCount());
        self::assertStringContainsString('timed out after 1 second(s)', $report->results()[0]->message);
        self::assertFalse($this->lock->holds('schedule:sleepy'));
        self::assertSame('error', $this->logger->entries[0]['level']);
    }

    public function test_results_carry_the_evaluated_instant(): void
    {
        $this->schedule->call('every', static function (): void {
        })->everyMinute();

        $at = self::utc('2026-10-28 09:00:00');
        $report = $this->runner($at)->run();

        self::assertSame($at, $report->at);
    }
}
