<?php

declare(strict_types=1);

namespace Sahil\Console\Commands;

use Sahil\Console\Application;
use Sahil\Console\Input;
use Sahil\Console\Output;

abstract class Command
{
    protected $name;

    protected $description;

    public function __construct(
        protected readonly ?Input $input,
        protected readonly ?Output $output,
    ) {
    }

    abstract public function handle(): void;

    public function run(): int
    {
        if ($this->input === null || $this->output === null) {
            return 1;
        }

        try {
            $this->handle();
        } catch (\Throwable $e) {
            $this->output->error($e->getMessage());

            return 1;
        }

        return 0;
    }

    public function getInput(): Input
    {
        return $this->input ?? new Input();
    }

    public function getOutput(): Output
    {
        return $this->output ?? new Output();
    }
}
