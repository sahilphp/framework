<?php

declare(strict_types=1);

namespace Sahil\Tests\Examples;

use Sahil\Testing\ApplicationTestCase;

/**
 * Example CONSOLE test: the real binary runs in a child process with the
 * installation as its working directory, so exit codes and both output
 * streams are observed exactly as an operator would see them.
 */
final class ConsoleExampleTest extends ApplicationTestCase
{
    public function test_about_reports_application_facts(): void
    {
        $this->runCommand('about')
            ->assertSuccessful()
            ->assertOutputContains('SahilPHP')
            ->assertOutputMatches('/environment\s*:\s*\w+/');
    }

    public function test_unknown_commands_fail_with_a_helpful_message(): void
    {
        $this->runCommand(['not:a-command'])
            ->assertFailed()
            ->assertOutputContains('Unknown option')
            ->assertOutputContains('Usage: bin/sahil');
    }

    public function test_version_flag_reports_php_version(): void
    {
        $this->runCommand('--version')
            ->assertSuccessful()
            ->assertOutputContains(PHP_VERSION);
    }
}
