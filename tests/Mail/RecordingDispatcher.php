<?php

declare(strict_types=1);

namespace Sahil\Tests\Mail;

use Sahil\Contracts\Queue\DispatcherContract;
use Sahil\Contracts\Queue\Jobs\JobContract;
use Throwable;

/**
 * Queue dispatcher double: records the job, queue and delay it was given.
 */
final class RecordingDispatcher implements DispatcherContract
{
    /** @var list<array{queue: string, job: JobContract, delay: int, id: string}> */
    public array $pushed = [];

    public function __construct(private readonly ?Throwable $failure = null)
    {
    }

    public function dispatch(JobContract $job, int $delay = 0): string
    {
        return $this->pushOn('default', $job, $delay);
    }

    public function pushOn(string $queue, JobContract $job, int $delay = 0): string
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $id = 'queued-'.(count($this->pushed) + 1);
        $this->pushed[] = ['queue' => $queue, 'job' => $job, 'delay' => $delay, 'id' => $id];

        return $id;
    }

    public function pushRaw(string $queue, string $payload, int $delay = 0): string
    {
        // Mail never queues raw payloads; only whole jobs are meaningful.
        throw new \LogicException('pushRaw() is not part of the mail delivery path.');
    }
}
