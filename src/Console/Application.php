<?php

declare(strict_types=1);

namespace Sahil\Console;

use Sahil\Contracts\Foundation\ApplicationContract;
use Sahil\Foundation\Application as BaseApplication;
use Sahil\Queue\Failed\FailedJobRepository;
use Sahil\Queue\Worker;

class Application extends BaseApplication
{
    private ?Worker $worker;

    private ?FailedJobRepository $failedJobs;

    public function getWorker(): Worker
    {
        if ($this->worker === null) {
            $this->worker = new Worker(
                $this->container->make('queue'),
                $this->container->make(FailedJobRepository::class),
                $this->container->make(\Sahil\Database\Connection::class),
            );
        }

        return $this->worker;
    }

    public function getFailedJobs(): array
    {
        if ($this->failedJobs === null) {
            $this->failedJobs = $this->container->make(FailedJobRepository::class);
        }

        return $this->failedJobs->all();
    }

    public function failJob(): void
    {
        $this->container->make(FailedJobRepository::class)->log(
            new \Sahil\Queue\Failed\FailedJob(
                queue: 'default',
                payload: 'test',
                exception: 'Test exception',
                failedAt: time(),
                connection: 'default',
                id: 'test',
            )
        );
    }
}
