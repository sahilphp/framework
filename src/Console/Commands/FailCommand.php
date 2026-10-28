<?php

declare(strict_types=1);

namespace Sahil\Console\Commands;

use Sahil\Console\Application;
use Sahil\Console\Input;
use Sahil\Console\Output;

class FailCommand extends Command
{
    protected $name = 'queue:fail';

    protected $description = 'Mark a job as failed';

    public function __construct(
        private readonly Application $application,
        private readonly ?Input $input,
        private readonly ?Output $output,
    ) {
        parent::__construct($input, $output);
    }

    public function handle(): void
    {
        $this->output->info('Marking job as failed...');

        $this->application->failJob();
    }
}
