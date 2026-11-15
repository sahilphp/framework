<?php

declare(strict_types=1);

namespace Sahil\Tests\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Sahil\Contracts\Queue\DispatcherContract;
use Sahil\Contracts\Queue\Jobs\JobContract;
use Sahil\Scheduler\Schedule;
use Sahil\Scheduler\SchedulerException;

/**
 * `Schedule::job()` / `jobSync()` — queued and inline job tasks, plus the
 * explicit every-five/fifteen/thirty frequency helpers.
 */
final class ScheduledJobTest extends TestCase
{
    public function test_job_registers_with_kebab_name_and_recurrence(): void
    {
        $schedule = new Schedule();

        $task = $schedule->job(new CleanupJob())->dailyAt('02:00')->withoutOverlapping();

        self::assertSame('cleanup-job', $task->name());
        self::assertTrue($task->preventsOverlap());
        self::assertTrue($task->isDue(new DateTimeImmutable('2026-03-01 02:00', new DateTimeZone('UTC'))));
        self::assertFalse($task->isDue(new DateTimeImmutable('2026-03-01 02:01', new DateTimeZone('UTC'))));
    }

    public function test_job_task_dispatches_through_queue_dispatcher(): void
    {
        $schedule = new Schedule();
        $job = new CleanupJob();

        $task = $schedule->job($job);

        // Without an application/queue binding the task fails descriptively.
        try {
            $task->run();
            self::fail('expected');
        } catch (SchedulerException $e) {
            self::assertStringContainsString(DispatcherContract::class, $e->getMessage());
        }
    }

    public function test_named_queue_job_uses_pushOn(): void
    {
        $schedule = new Schedule();
        $job = new NamedQueueJob();

        // The dispatcher resolves at run time through the configured
        // application's container.
        $app = \Sahil\Foundation\Application::configure(sys_get_temp_dir());
        $dispatcher = new RecordingDispatcher();
        $app->container()->instance(DispatcherContract::class, $dispatcher);

        try {
            $schedule->job($job)->run();
            self::assertSame(['reports'], $dispatcher->queues);

            $schedule->jobSync(new CleanupJob())->run();
        } finally {
            \Sahil\Foundation\Application::free();
        }

        self::assertSame(1, CleanupJob::$handled);
    }

    public function test_explicit_minute_frequencies(): void
    {
        $schedule = new Schedule();

        $five = $schedule->call('five', static function (): void {
        })->everyFiveMinutes();
        $fifteen = $schedule->call('fifteen', static function (): void {
        })->everyFifteenMinutes();
        $thirty = $schedule->call('thirty', static function (): void {
        })->everyThirtyMinutes();

        $utc = static fn (string $t): DateTimeImmutable => new DateTimeImmutable($t, new DateTimeZone('UTC'));

        self::assertTrue($five->isDue($utc('2026-03-01 00:10')));
        self::assertFalse($five->isDue($utc('2026-03-01 00:11')));
        self::assertTrue($fifteen->isDue($utc('2026-03-01 00:30')));
        self::assertFalse($fifteen->isDue($utc('2026-03-01 00:31')));
        self::assertTrue($thirty->isDue($utc('2026-03-01 00:30')));
        self::assertFalse($thirty->isDue($utc('2026-03-01 00:45')));
    }

    public function test_duplicate_job_name_rejected(): void
    {
        $schedule = new Schedule();
        $schedule->job(new CleanupJob());

        $this->expectException(SchedulerException::class);
        $schedule->job(new CleanupJob());
    }
}

class CleanupJob implements JobContract
{
    public static int $handled = 0;

    public function handle(): mixed
    {
        self::$handled++;

        return null;
    }

    public function queue(): ?string
    {
        return null;
    }

    public function maxAttempts(): ?int
    {
        return 1;
    }

    public function retryDelay(int $attempt): ?int
    {
        return 0;
    }
}

final class NamedQueueJob extends CleanupJob
{
    public function queue(): ?string
    {
        return 'reports';
    }
}

final class RecordingDispatcher implements DispatcherContract
{
    /** @var list<string> */
    public array $queues = [];

    public function dispatch(JobContract $job, int $delay = 0): string
    {
        $this->queues[] = 'default';

        return 'id';
    }

    public function pushOn(string $queue, JobContract $job, int $delay = 0): string
    {
        $this->queues[] = $queue;

        return 'id';
    }

    public function pushRaw(string $queue, string $payload, int $delay = 0): string
    {
        return 'id';
    }
}
