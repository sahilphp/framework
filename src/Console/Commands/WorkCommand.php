<?php

declare(strict_types=1);

namespace Sahil\Console\Commands;

use Sahil\Console\Application;
use Sahil\Console\Input;
use Sahil\Console\Output;
use Sahil\Queue\Worker;

class WorkCommand extends Command
{
    protected $name = 'queue:work';

    protected $description = 'Process jobs from the queue';

    public function __construct(
        private readonly Application $application,
        private readonly ?Input $input,
        private readonly ?Output $output,
    ) {
        parent::__construct($input, $output);
    }

    public function handle(): void
    {
        $this->output->info('Processing jobs...');

        $worker = $this->application->getWorker();

        $worker->work();
    }
}
