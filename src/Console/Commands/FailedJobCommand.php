<?php

declare(strict_types=1);

namespace Sahil\Console\Commands;

use Sahil\Console\Application;
use Sahil\Console\Input;
use Sahil\Console\Output;

class FailedJobCommand extends Command
{
    protected $name = 'queue:failed-job';

    protected $description = 'Get a failed job';

    public function __construct(
        private readonly Application $application,
        private readonly ?Input $input,
        private readonly ?Output $output,
    ) {
        parent::__construct($input, $output);
    }

    public function handle(): void
    {
        $this->output->info('Getting failed job...');

        $this->application->getFailedJobs();
    }
}
