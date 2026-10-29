<?php

declare(strict_types=1);

namespace Sahil\Tests\Ui;

use PHPUnit\Framework\TestCase;
use Sahil\Ui\ProcessRunner;
use Sahil\Ui\UiException;

final class ProcessRunnerTest extends TestCase
{
    private ProcessRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new ProcessRunner(pollMicroseconds: 1_000);
    }

    public function test_it_captures_stdout_and_a_zero_exit_code(): void
    {
        $result = $this->runner->run(
            [PHP_BINARY, '-r', 'echo "hello"; fwrite(STDERR, "warning");'],
            sys_get_temp_dir(),
        );

        self::assertTrue($result->successful());
        self::assertSame(0, $result->exitCode);
        self::assertSame('hello', $result->stdout);
        self::assertSame('warning', $result->stderr);
    }

    public function test_it_propagates_a_nonzero_exit_code_and_stderr(): void
    {
        $result = $this->runner->run(
            [PHP_BINARY, '-r', 'fwrite(STDERR, "broken"); exit(7);'],
            sys_get_temp_dir(),
        );

        self::assertFalse($result->successful());
        self::assertSame(7, $result->exitCode);
        self::assertSame('broken', $result->stderr);
        self::assertStringContainsString(PHP_BINARY, $result->describe());
    }

    public function test_arguments_are_never_interpreted_by_a_shell(): void
    {
        $marker = sys_get_temp_dir().'/sahil-ui-injection-'.bin2hex(random_bytes(4));
        $payload = 'hello; touch '.$marker;

        $result = $this->runner->run(
            [PHP_BINARY, '-r', 'echo $argv[1];', $payload],
            sys_get_temp_dir(),
        );

        self::assertTrue($result->successful());
        self::assertSame($payload, $result->stdout);
        self::assertFileDoesNotExist($marker);
    }

    public function test_empty_commands_are_rejected(): void
    {
        $this->expectException(UiException::class);
        $this->expectExceptionMessage('cannot be empty');

        $this->runner->run([], sys_get_temp_dir());
    }

    public function test_empty_arguments_are_rejected(): void
    {
        $this->expectException(UiException::class);
        $this->expectExceptionMessage('non-empty strings');

        $this->runner->run(['/bin/echo', ''], sys_get_temp_dir());
    }
}
