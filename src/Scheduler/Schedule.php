<?php

declare(strict_types=1);

namespace Sahil\Scheduler;

use Closure;
use DateTimeImmutable;
use Sahil\Contracts\Queue\DispatcherContract;
use Sahil\Contracts\Queue\Jobs\JobContract;
use Sahil\Foundation\Application;

/**
 * The registered tasks for this installation.
 *
 * Tasks are defined in `routes/schedule.php` and registered once at boot.
 * Names are unique: the name is the operator-facing identity used by
 * `schedule:list`, `schedule:run --only=…` and the overlap lock, so a
 * duplicate registration is a configuration error rather than a silent
 * replacement.
 */
final class Schedule
{
    /** @var array<string, Task> */
    private array $tasks = [];

    public function __construct(private readonly string $timezone = 'UTC')
    {
        if ($timezone === '') {
            throw new SchedulerException('The schedule timezone must not be empty.');
        }
    }

    /**
     * Default timezone for tasks that do not name one.
     */
    public function timezone(): string
    {
        return $this->timezone;
    }

    /**
     * Register a PHP task. The callback runs in this process, so it may
     * throw; the runner records the failure and keeps going with the rest.
     */
    public function call(string $name, Closure $callback): Task
    {
        if (isset($this->tasks[$name])) {
            throw new SchedulerException(sprintf('Task [%s] is already registered.', $name));
        }

        return $this->tasks[$name] = new Task($name, $callback, $this->timezone);
    }

    /**
     * Register a queueable job as a scheduled task — the due task hands
     * the job to the queue's `DispatcherContract` at execution time, so
     * retries, timeouts and failed-job handling stay the queue's own.
     * Nothing but the job object is registered; the dispatcher resolves
     * fresh when the task runs.
     *
     * @param JobContract $job a `Job`/`JobContract`; `onQueue()` names the queue
     * @param string|null $name task name — defaults to the job class in kebab-case
     */
    public function job(JobContract $job, ?string $name = null): Task
    {
        return $this->call($name ?? self::taskName($job), static function () use ($job): void {
            $container = Application::peek()?->container();

            if ($container === null || !$container->has(DispatcherContract::class)) {
                throw new SchedulerException(sprintf(
                    'Scheduled job [%s] needs a bound %s to dispatch onto the queue.',
                    $job::class,
                    DispatcherContract::class,
                ));
            }

            $jobQueue = $job->queue();

            if ($jobQueue !== null) {
                $container->make(DispatcherContract::class)->pushOn($jobQueue, $job);
            } else {
                $container->make(DispatcherContract::class)->dispatch($job);
            }
        });
    }

    /**
     * Register a job that runs its `handle()` inline in the scheduler
     * process — for cheap maintenance work that does not need a worker.
     * The execution mode is explicit in the name.
     */
    public function jobSync(JobContract $job, ?string $name = null): Task
    {
        return $this->call($name ?? self::taskName($job), static function () use ($job): void {
            $job->handle();
        });
    }

    /** `App\Jobs\CleanupExpiredSessions` → `cleanup-expired-sessions` */
    private static function taskName(JobContract $job): string
    {
        $base = (new \ReflectionClass($job))->getShortName();

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $base));
    }

    public function task(string $name): ?Task
    {
        return $this->tasks[$name] ?? null;
    }

    /**
     * Every task, in registration order.
     *
     * @return list<Task>
     */
    public function tasks(): array
    {
        return array_values($this->tasks);
    }

    public function isEmpty(): bool
    {
        return $this->tasks === [];
    }

    /**
     * Tasks due in the minute of `$now`, in registration order.
     *
     * @return list<Task>
     */
    public function dueAt(DateTimeImmutable $now, ?string $only = null): array
    {
        $due = [];

        foreach ($this->tasks as $task) {
            if ($only !== null && $task->name() !== $only) {
                continue;
            }

            if ($task->isDue($now)) {
                $due[] = $task;
            }
        }

        return $due;
    }
}
