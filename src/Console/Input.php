<?php

declare(strict_types=1);

namespace Sahil\Console;

class Input
{
    private array $arguments = [];

    private array $options = [];

    public function __construct(array $arguments = [], array $options = [])
    {
        $this->arguments = $arguments;
        $this->options = $options;
    }

    public function getArgument(string $name)
    {
        return $this->arguments[$name] ?? null;
    }

    public function getOption(string $name)
    {
        return $this->options[$name] ?? null;
    }
}
