<?php

declare(strict_types=1);

namespace Sahil\Tests\Queue;

use Sahil\Contracts\Queue\QueueContract;
use Sahil\Contracts\Queue\QueuedJob;

/**
 * In-memory queue store: FIFO per queue, attempts incremented at
 * reservation time like the database store, delay honoured by `release()`.
 */
final class InMemoryQueueStore implements QueueContract
{
    /**
     * @var array<string, array<string, array{payload: string, attempts: int, availableAt: int}>>
     */
    private array $jobs = [];

    private int $sequence = 0;

    public function push(string $queue, string $payload, int $delay = 0): string
    {
        $id = 'job-'.(++$this->sequence);

        $this->jobs[$queue][$id] = [
            'payload' => $payload,
            'attempts' => 0,
            'availableAt' => time() + $delay,
        ];

        return $id;
    }

    public function later(string $queue, string $payload, int $delay): string
    {
        return $this->push($queue, $payload, $delay);
    }

    public function pop(string $queue): ?QueuedJob
    {
        foreach ($this->jobs[$queue] ?? [] as $id => $job) {
            if ($job['availableAt'] > time()) {
                continue;
            }

            $this->jobs[$queue][$id]['attempts']++;

            return new QueuedJob($id, $queue, $job['payload'], $job['attempts'] + 1);
        }

        return null;
    }

    public function delete(string $id): void
    {
        foreach (array_keys($this->jobs) as $queue) {
            unset($this->jobs[$queue][$id]);
        }
    }

    public function release(string $id, int $delay = 0): void
    {
        foreach (array_keys($this->jobs) as $queue) {
            if (isset($this->jobs[$queue][$id])) {
                $this->jobs[$queue][$id]['availableAt'] = time() + $delay;
            }
        }
    }

    public function size(string $queue): int
    {
        return count($this->jobs[$queue] ?? []);
    }

    public function isEmpty(string $queue): bool
    {
        return $this->size($queue) === 0;
    }
}
