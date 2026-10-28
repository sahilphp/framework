<?php

declare(strict_types=1);

namespace Sahil\Tests\Queue;

use PHPUnit\Framework\TestCase;
use Sahil\Contracts\Queue\Jobs\JobContract;
use Sahil\Queue\Jobs\Job;

/**
 * A job's retry policy and queue selection are per job; unset values fall
 * back to the worker's configuration.
 */
final class JobTest extends TestCase
{
    public function test_defaults_defer_to_the_worker(): void
    {
        $job = new class () extends Job {
            public function handle(): mixed
            {
                return null;
            }
        };

        self::assertInstanceOf(JobContract::class, $job);
        self::assertNull($job->queue());
        self::assertNull($job->maxAttempts());
        self::assertNull($job->retryDelay(1));
    }

    public function test_on_queue_names_the_queue(): void
    {
        $job = new class () extends Job {
            public function handle(): mixed
            {
                return null;
            }
        };

        self::assertSame($job, $job->onQueue('reports'));
        self::assertSame('reports', $job->queue());
    }

    public function test_retry_policy_is_overridable_per_job(): void
    {
        $job = new class () extends Job {
            public function handle(): mixed
            {
                return null;
            }

            public function maxAttempts(): ?int
            {
                return 5;
            }

            public function retryDelay(int $attempt): ?int
            {
                return 2 ** $attempt;
            }
        };

        self::assertSame(5, $job->maxAttempts());
        self::assertSame(2, $job->retryDelay(1));
        self::assertSame(8, $job->retryDelay(3));
    }
}
