<?php

declare(strict_types=1);

namespace Sahil\Tests\Queue;

use PHPUnit\Framework\TestCase;
use Sahil\Queue\Worker;

/**
 * Worker semantics: acknowledged jobs are removed, failing jobs are
 * released while attempts remain, and the failed-job store only sees jobs
 * that exhausted their budget (or whose payload is unreadable).
 */
final class WorkerTest extends TestCase
{
    private InMemoryQueueStore $store;

    private InMemoryFailedJobs $failures;

    protected function setUp(): void
    {
        RecordingJob::$handled = [];

        $this->store = new InMemoryQueueStore();
        $this->failures = new InMemoryFailedJobs();
    }

    private function worker(int $maxAttempts = 3, int $retryDelay = 0): Worker
    {
        return new Worker(
            $this->store,
            $this->failures,
            'default',
            $maxAttempts,
            $retryDelay,
        );
    }

    public function test_a_successful_job_runs_once_and_is_acknowledged(): void
    {
        $this->store->push('default', serialize(new RecordingJob('send-email')));

        self::assertTrue($this->worker()->runNextJob());

        self::assertSame(['send-email'], RecordingJob::$handled);
        self::assertSame(0, $this->store->size('default'));
        self::assertSame([], $this->failures->jobs);
    }

    public function test_run_next_job_returns_false_when_the_queue_is_empty(): void
    {
        self::assertFalse($this->worker()->runNextJob());
    }

    public function test_a_failing_job_is_released_while_attempts_remain(): void
    {
        $worker = $this->worker(maxAttempts: 2);
        $id = $this->store->push('default', serialize(new RecordingJob('flaky', fails: true)));

        self::assertTrue($worker->runNextJob());

        self::assertSame(1, $this->store->size('default'), 'the job is retried, not dropped');
        self::assertSame([], $this->failures->jobs, 'nothing is failed until the budget is spent');

        self::assertTrue($worker->runNextJob());

        self::assertSame(0, $this->store->size('default'));
        self::assertCount(1, $this->failures->jobs);

        $failure = array_values($this->failures->jobs)[0];
        self::assertSame($id, $failure->id);
        self::assertSame('default', $failure->queue);
        self::assertSame('Job [flaky] failed.', $failure->exception);
    }

    public function test_a_job_specific_attempt_budget_overrides_the_worker_default(): void
    {
        $worker = $this->worker(maxAttempts: 5);
        $this->store->push('default', serialize(new RecordingJob('once', fails: true, attemptBudget: 1)));

        $worker->runNextJob();

        self::assertSame(0, $this->store->size('default'));
        self::assertCount(1, $this->failures->jobs);
    }

    public function test_a_job_specific_retry_delay_delays_the_next_attempt(): void
    {
        $worker = $this->worker(retryDelay: 0);
        $this->store->push('default', serialize(new RecordingJob('slow', fails: true, delay: 30)));

        $worker->runNextJob();

        self::assertSame(1, $this->store->size('default'));
        self::assertFalse($worker->runNextJob(), 'the released job is not available yet');
        self::assertSame([], $this->failures->jobs);
    }

    public function test_an_unreadable_payload_is_recorded_and_removed(): void
    {
        $this->store->push('default', 'this-is-not-a-serialized-job');

        self::assertTrue($this->worker()->runNextJob());

        self::assertSame(0, $this->store->size('default'));
        self::assertCount(1, $this->failures->jobs);

        $failure = array_values($this->failures->jobs)[0];
        self::assertStringContainsString('JobContract', $failure->exception);
    }

    public function test_work_processes_at_most_the_requested_number_of_jobs(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->store->push('default', serialize(new RecordingJob('job-'.$i)));
        }

        self::assertSame(2, $this->worker()->work(2));
        self::assertSame(1, $this->store->size('default'));
    }
}
