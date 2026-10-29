#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Test-only console entry point for framework CLI tests.
 *
 * This is not a package executable. The framework ships no bin/sahil; the
 * starter-kit owns the real application entry point. Framework tests that
 * exercise the standalone UI commands run them through this harness.
 */

error_reporting(E_ALL);

require_once __DIR__.'/../../vendor/autoload.php';

$args = array_slice($argv, 1);
$command = $args[0] ?? '--help';

$help = <<<TEXT
    SahilPHP framework test console

    Usage: php tests/Fixtures/console.php <command|option> [options]

    Commands:
      ui:install        Install a frontend asset profile.
      ui:dev            Run the Vite development server.
      ui:build          Build production assets.
      ui:status         Report frontend prerequisites, sources and outputs.
      ui:theme          Generate framework theme files from neutral tokens.

    Options:
      --profile=NAME    tailwind | bootstrap | both | none
      --path=DIR        project root to operate on
      --source=FILE     ui:theme: neutral token file
      --dry-run         show the plan without writing or running commands
      --merge           preserve existing files; merge package.json
      --overwrite       replace managed files, keeping backups
      --no-install      scaffold files without running the package manager
      --clean           ui:build: remove public/build before building
      --host=HOST       ui:dev: bind Vite to HOST
      --port=PORT       ui:dev: use PORT
      --json            ui:status/ui:theme: machine-readable output
      --help            Show this help text.

    TEXT;

if ($command === '--help' || $command === '-h') {
    fwrite(STDOUT, $help.PHP_EOL);
    exit(0);
}

if (in_array($command, \Sahil\Ui\UiConsole::COMMANDS, true)) {
    exit((new \Sahil\Ui\UiConsole())->run($command, array_slice($args, 1), __DIR__.'/app-root'));
}

fwrite(STDERR, sprintf("Unknown option: %s\n", $command));
fwrite(STDERR, $help.PHP_EOL);
exit(1);
