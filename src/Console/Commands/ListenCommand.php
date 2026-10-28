<?php

declare(strict_types=1);

namespace Sahil\Console\Commands;

use Sahil\Console\Application;
use Sahil\Console\Input;
use Sahil\Console\Output;

class ListenCommand extends Command
{
    protected $name = 'queue:listen';

    protected $description = 'Listen to the queue for new jobs';

    public function __construct(
        private readonly Application $application,
        private readonly ?Input $input,
        private readonly ?Output $output,
    ) {
        parent::__construct($input, $output);
    }

    public function handle(): void
    {
        $this->output->info('Listening for jobs...');

        while (true) {
            $this->application->getWorker()->work();
        }
    }
}
