<?php

declare(strict_types=1);

namespace Sahil\Tests\Console;

use Sahil\Testing\ApplicationTestCase;

/**
 * End-to-end checks of the real `bin/sahil` entry point.
 *
 * Each command runs in its own PHP process, so these tests also prove the
 * binary boots from a clean process rather than from this test's memory.
 */
final class SahilCliCommandsTest extends ApplicationTestCase
{
    public function test_about_reports_application_facts(): void
    {
        $this->runCommand('about')
            ->assertSuccessful()
            ->assertOutputContains('app name:')
            ->assertOutputContains('SahilPHP')
            ->assertOutputContains('php:')
            ->assertOutputContains('booted:');
    }

    public function test_config_show_redacts_secret_values(): void
    {
        $this->runCommand(['config:show', 'database'])
            ->assertSuccessful()
            ->assertOutputContains('database.password')
            ->assertOutputContains('[redacted]')
            ->assertOutputMissing('DB_PASSWORD=');
    }

    public function test_env_check_lists_documented_variables_without_values(): void
    {
        $this->runCommand('env:check')
            ->assertSuccessful()
            ->assertOutputContains('APP_ENV')
            ->assertOutputMissing('APP_KEY=');
    }

    public function test_unknown_option_prints_help_to_stderr_and_exits_one(): void
    {
        $this->runCommand('--made-up')
            ->assertFailed()
            ->assertExitCode(1)
            ->assertOutputContains('Unknown option')
            ->assertOutputContains('Usage: bin/sahil');
    }

    public function test_version_prints_framework_and_php_versions(): void
    {
        $this->runCommand('--version')
            ->assertSuccessful()
            ->assertOutputContains('SahilPHP')
            ->assertOutputContains(PHP_VERSION);
    }
}
