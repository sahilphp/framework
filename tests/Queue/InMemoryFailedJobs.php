<?php

declare(strict_types=1);

namespace Sahil\Tests\Queue;

use Sahil\Contracts\Queue\FailedJobRepositoryContract;
use Sahil\Queue\Failed\FailedJob;

/**
 * Failed jobs kept in memory, in insertion order.
 */
final class InMemoryFailedJobs implements FailedJobRepositoryContract
{
    /**
     * @var array<string, FailedJob>
     */
    public array $jobs = [];

    public function log(FailedJob $job): void
    {
        $this->jobs[$job->id] = $job;
    }

    public function all(string $queue): array
    {
        return array_values(array_filter(
            $this->jobs,
            static fn (FailedJob $job): bool => $job->queue === $queue,
        ));
    }

    public function find(string $id): ?FailedJob
    {
        return $this->jobs[$id] ?? null;
    }

    public function delete(string $id): void
    {
        unset($this->jobs[$id]);
    }

    public function count(string $queue): int
    {
        return count($this->all($queue));
    }

    public function isEmpty(string $queue): bool
    {
        return $this->count($queue) === 0;
    }
}
