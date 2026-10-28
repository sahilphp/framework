<?php

declare(strict_types=1);

namespace Sahil\Console\Commands;

use Sahil\Console\Application;
use Sahil\Console\Input;
use Sahil\Console\Output;

class FailedCommand extends Command
{
    protected $name = 'queue:failed';

    protected $description = 'List failed jobs';

    public function __construct(
        private readonly Application $application,
        private readonly ?Input $input,
        private readonly ?Output $output,
    ) {
        parent::__construct($input, $output);
    }

    public function handle(): void
    {
        $this->output->info('Listing failed jobs...');

        $failed = $this->application->getFailedJobs();

        foreach ($failed as $job) {
            $this->output->line($job['id'].' - '.$job['queue'].' - '.$job['payload']);
        }
    }
}
