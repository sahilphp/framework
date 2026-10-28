<?php

declare(strict_types=1);

namespace Sahil\Console\Commands;

use Sahil\Console\Application;
use Sahil\Console\Input;
use Sahil\Console\Output;
use Sahil\Contracts\Foundation\ApplicationContract;
use Sahil\Contracts\Queue\Jobs\JobContract;

class DispatchCommand extends Command
{
    protected $name = 'queue:dispatch';

    protected $description = 'Dispatch a queued job';

    public function __construct(
        private readonly Application $application,
        private readonly ?Input $input,
        private readonly ?Output $output,
    ) {
        parent::__construct($input, $output);
    }

    public function handle(): void
    {
        $job = $this->input->getArgument('job');

        if (!class_exists($job)) {
            $this->output->error("Job [{$job}] does not exist.");

            return;
        }

        if (!is_subclass_of($job, JobContract::class)) {
            $this->output->error("Job [{$job}] must implement JobContract.");

            return;
        }

        $jobInstance = new $job();

        $this->output->info("Dispatching job: {$job}");

        $result = $jobInstance->dispatch();

        $this->output->line("Job dispatched successfully. ID: {$result}");
    }
}
