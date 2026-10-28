<?php

declare(strict_types=1);

namespace Sahil\Tests\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Sahil\Scheduler\Schedule;
use Sahil\Scheduler\SchedulerException;

final class ScheduleTest extends TestCase
{
    private static function utc(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    public function test_tasks_keep_registration_order(): void
    {
        $schedule = new Schedule();

        $schedule->call('first', static function (): void {
        });
        $schedule->call('second', static function (): void {
        });

        self::assertSame(
            ['first', 'second'],
            array_map(static fn ($task): string => $task->name(), $schedule->tasks()),
        );
        self::assertSame('first', $schedule->task('first')?->name());
        self::assertNull($schedule->task('missing'));
    }

    public function test_a_duplicate_name_is_a_configuration_error(): void
    {
        $schedule = new Schedule();
        $schedule->call('unique', static function (): void {
        });

        $this->expectException(SchedulerException::class);
        $this->expectExceptionMessage('already registered');

        $schedule->call('unique', static function (): void {
        });
    }

    public function test_tasks_inherit_the_schedule_timezone_and_can_override_it(): void
    {
        $schedule = new Schedule('Europe/Berlin');

        $inherited = $schedule->call('inherited', static function (): void {
        });
        $explicit = $schedule->call('explicit', static function (): void {
        })->inTimezone('America/New_York');

        self::assertSame('Europe/Berlin', $schedule->timezone());
        self::assertSame('Europe/Berlin', $inherited->timezone());
        self::assertSame('America/New_York', $explicit->timezone());
    }

    public function test_due_at_returns_only_matching_tasks(): void
    {
        $schedule = new Schedule('UTC');

        $schedule->call('hourly', static function (): void {
        })->hourly();
        $schedule->call('midnight', static function (): void {
        })->dailyAt('00:00');

        self::assertSame(
            ['hourly'],
            array_map(
                static fn ($task): string => $task->name(),
                $schedule->dueAt(self::utc('2026-10-28 09:00:00')),
            ),
        );

        self::assertSame(
            ['hourly', 'midnight'],
            array_map(
                static fn ($task): string => $task->name(),
                $schedule->dueAt(self::utc('2026-10-28 00:00:00')),
            ),
        );
    }

    public function test_due_at_can_be_limited_to_one_task(): void
    {
        $schedule = new Schedule('UTC');
        $schedule->call('a', static function (): void {
        })->everyMinute();
        $schedule->call('b', static function (): void {
        })->everyMinute();

        $due = $schedule->dueAt(self::utc('2026-10-28 09:00:00'), 'b');

        self::assertCount(1, $due);
        self::assertSame('b', $due[0]->name());
    }

    public function test_an_empty_schedule_is_reported(): void
    {
        $schedule = new Schedule();

        self::assertTrue($schedule->isEmpty());
        self::assertSame([], $schedule->tasks());
    }
}
