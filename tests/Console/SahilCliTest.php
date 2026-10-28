<?php

declare(strict_types=1);

namespace Sahil\Tests\Console;

use PHPUnit\Framework\TestCase;

final class SahilCliTest extends TestCase
{
    /**
     * Boot the real application through bin/sahil in an isolated PHP
     * process (so the per-process Application handle does not clash).
     *
     * @return array{0: int, 1: string}
     */
    private function runCli(string ...$arguments): array
    {
        $command = array_map(
            'escapeshellarg',
            [PHP_BINARY, __DIR__.'/../../bin/sahil', ...$arguments]
        );

        $pipes = [];
        $process = proc_open(implode(' ', $command), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout.$stderr];
    }

    public function test_cli_help_exits_successfully(): void
    {
        [$code, $out] = $this->runCli('--help');

        self::assertSame(0, $code);
        self::assertStringContainsString('SahilPHP', $out);
        self::assertStringContainsString('--version', $out);
    }

    public function test_cli_without_arguments_shows_help(): void
    {
        [$code, $out] = $this->runCli();

        self::assertSame(0, $code);
        self::assertStringContainsString('Usage:', $out);
    }

    public function test_cli_version_reports_framework_and_php(): void
    {
        [$code, $out] = $this->runCli('--version');

        self::assertSame(0, $code);
        self::assertStringContainsString('SahilPHP', $out);
        self::assertStringContainsString(PHP_VERSION, $out);
    }

    public function test_cli_unknown_option_fails_with_nonzero_status(): void
    {
        [$code, $out] = $this->runCli('--definitely-not-real');

        self::assertSame(1, $code);
        self::assertStringContainsString('Unknown option', $out);
    }
}
