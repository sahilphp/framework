<?php

declare(strict_types=1);

namespace Sahil\Console\Commands;

use Sahil\Console\Application;
use Sahil\Console\Input;
use Sahil\Console\Output;

class RunCommand extends Command
{
    protected $name = 'queue:run';

    protected $description = 'Run the queue runner';

    public function __construct(
        private readonly Application $application,
        private readonly ?Input $input,
        private readonly ?Output $output,
    ) {
        parent::__construct($input, $output);
    }

    public function handle(): void
    {
        $this->output->info('Running the queue runner...');

        $this->application->getWorker()->work();
    }
}
