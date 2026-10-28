<?php

declare(strict_types=1);

namespace Sahil\Tests\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Sahil\Scheduler\SchedulerException;
use Sahil\Scheduler\Task;

final class TaskTest extends TestCase
{
    private static function utc(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    private static function task(string $timezone = 'UTC'): Task
    {
        return new Task('example', static function (): void {
        }, $timezone);
    }

    public function test_frequency_helpers_build_expressions(): void
    {
        $task = self::task();

        self::assertSame('* * * * *', $task->everyMinute()->expression());
        self::assertSame('*/5 * * * *', $task->every(5)->expression());
        self::assertSame('0 * * * *', $task->hourly()->expression());
        self::assertSame('15 * * * *', $task->hourlyAt(15)->expression());
        self::assertSame('0 0 * * *', $task->daily()->expression());
        self::assertSame('30 7 * * *', $task->dailyAt('07:30')->expression());
        self::assertSame('0 0 * * 0', $task->weekly()->expression());
        self::assertSame('15 9 * * 3', $task->weeklyOn(3, '09:15')->expression());
        self::assertSame('0 0 1 * *', $task->monthly()->expression());
        self::assertSame('45 23 15 * *', $task->monthlyOn(15, '23:45')->expression());
        self::assertSame('1 2 3 4 5', $task->cron('1 2 3 4 5')->expression());
    }

    public function test_invalid_frequency_arguments_are_rejected(): void
    {
        $this->expectException(SchedulerException::class);

        self::task()->every(0);
    }

    public function test_invalid_time_is_rejected(): void
    {
        $this->expectException(SchedulerException::class);

        self::task()->dailyAt('24:00');
    }

    public function test_unknown_timezone_is_rejected(): void
    {
        $this->expectException(SchedulerException::class);
        $this->expectExceptionMessage('Unknown timezone');

        self::task('Mars/Olympus_Mons');
    }

    public function test_timezone_changes_the_wall_clock_used_for_matching(): void
    {
        $task = self::task('America/New_York')->dailyAt('09:00');

        self::assertTrue($task->isDue(self::utc('2026-01-15 14:00:00')), '09:00 EST');
        self::assertFalse($task->isDue(self::utc('2026-01-15 13:00:00')), '08:00 EST');

        self::assertTrue($task->isDue(self::utc('2026-07-15 13:00:00')), '09:00 EDT');
        self::assertFalse($task->isDue(self::utc('2026-07-15 14:00:00')), '10:00 EDT');
    }

    public function test_spring_forward_skips_a_local_time_that_does_not_exist(): void
    {
        // US DST starts 2026-03-08: 02:00 EST becomes 03:00 EDT, so 02:30
        // never happens that day.
        $task = self::task('America/New_York')->dailyAt('02:30');

        self::assertFalse($task->isDue(self::utc('2026-03-08 06:30:00')), '01:30 EST');
        self::assertFalse($task->isDue(self::utc('2026-03-08 07:30:00')), '03:30 EDT');

        self::assertTrue($task->isDue(self::utc('2026-03-07 07:30:00')), 'the day before at 02:30 EST');
        self::assertTrue($task->isDue(self::utc('2026-03-09 06:30:00')), 'the day after at 02:30 EDT');
    }

    public function test_fall_back_matches_both_local_occurrences(): void
    {
        // US DST ends 2026-11-01: 02:00 EDT becomes 01:00 EST, so 01:30
        // happens twice; both instants are a legitimate 01:30 locally.
        $task = self::task('America/New_York')->dailyAt('01:30');

        self::assertTrue($task->isDue(self::utc('2026-11-01 05:30:00')), '01:30 EDT');
        self::assertTrue($task->isDue(self::utc('2026-11-01 06:30:00')), '01:30 EST');
    }

    public function test_next_run_is_reported_in_the_task_timezone(): void
    {
        $task = self::task('America/New_York')->dailyAt('09:00');

        $next = $task->nextRunAfter(self::utc('2026-01-15 08:00:00'));

        self::assertSame('2026-01-15 09:00', $next->format('Y-m-d H:i'));
        self::assertSame('America/New_York', $next->getTimezone()->getName());
    }

    public function test_next_run_uses_the_local_wall_clock_of_the_task(): void
    {
        $task = self::task('America/New_York')->dailyAt('12:00');

        // 15:00 UTC is 10:00 in New York, so the next run is TODAY at 12:00
        // local, not tomorrow.
        $next = $task->nextRunAfter(self::utc('2026-01-15 15:00:00'));

        self::assertSame('2026-01-15 12:00', $next->format('Y-m-d H:i'));
        self::assertSame('2026-01-15 17:00', $next->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i'));
    }

    public function test_next_run_finds_the_repeated_local_hour_after_a_fall_back(): void
    {
        $task = self::task('America/New_York')->dailyAt('01:30');

        // 05:31 UTC is 01:31 EDT, the first pass through 01:30 has gone;
        // the second 01:30 (EST) still lies ahead at 06:30 UTC.
        $next = $task->nextRunAfter(self::utc('2026-11-01 05:31:00'));

        self::assertSame('2026-11-01 01:30', $next->format('Y-m-d H:i'));
        self::assertSame('2026-11-01 06:30', $next->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i'));
    }

    public function test_retry_settings_are_validated(): void
    {
        $task = self::task();

        self::assertFalse($task->preventsOverlap());
        self::assertSame(0, $task->timeout());

        $task->withoutOverlapping()->timeoutAfter(30);

        self::assertTrue($task->preventsOverlap());
        self::assertSame(30, $task->timeout());
        self::assertSame('schedule:example', $task->lockKey());
    }

    public function test_invalid_task_names_are_rejected(): void
    {
        $this->expectException(SchedulerException::class);

        new Task('bad name', static function (): void {
        });
    }
}
