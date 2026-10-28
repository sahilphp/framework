<?php

declare(strict_types=1);

namespace Sahil\Console;

class Output
{
    public function info(string $message): void
    {
        echo $message.PHP_EOL;
    }

    public function error(string $message): void
    {
        fwrite(STDERR, $message.PHP_EOL);
    }

    public function line(string $message): void
    {
        echo $message.PHP_EOL;
    }
}
