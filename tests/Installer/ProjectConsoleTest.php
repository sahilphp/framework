<?php

declare(strict_types=1);

namespace Sahil\Tests\Installer;

use PHPUnit\Framework\Attributes\DataProvider;
use Sahil\Installer\ProjectConsole;
use Sahil\Ui\Profile;

final class ProjectConsoleTest extends InstallerTestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function explicitProfiles(): array
    {
        return [
            'tailwind' => ['tailwind'],
            'bootstrap' => ['bootstrap'],
        ];
    }

    #[DataProvider('explicitProfiles')]
    public function test_explicit_ui_selection_generates_the_requested_variant(string $ui): void
    {
        $this->makeSource();
        [$io, $out] = $this->consoleIO([], false);

        $code = (new ProjectConsole(io: $io))->run(
            ['explicit-app', '--ui='.$ui, '--no-install', '--no-interaction'],
            $this->source,
            $this->workspace,
        );

        $output = $this->streamContents($out);
        $destination = $this->workspace.'/explicit-app';
        $manifest = json_decode((string) file_get_contents($destination.'/.sahil-ui.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Project created successfully.', $output);
        self::assertStringContainsString('Creating project...', $output);
        self::assertStringContainsString('Generating the selected UI configuration...', $output);
        self::assertSame($ui, $manifest['profile']);
    }

    public function test_interactive_ui_selection_installs_tailwind_only(): void
    {
        $this->makeSource();
        [$io, $out] = $this->consoleIO(['interactive-app', '1'], true);

        $code = (new ProjectConsole(io: $io))->run(
            ['--no-install'],
            $this->source,
            $this->workspace,
        );

        $output = $this->streamContents($out);
        $destination = $this->workspace.'/interactive-app';
        $package = json_decode((string) file_get_contents($destination.'/package.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Choose your UI framework:', $output);
        self::assertStringContainsString('Select UI framework [1-2]:', $output);
        self::assertArrayHasKey('tailwindcss', $package['devDependencies']);
        self::assertArrayNotHasKey('bootstrap', $package['devDependencies']);
        self::assertFileDoesNotExist($destination.'/resources/css/bootstrap.css');
    }

    public function test_interactive_ui_selection_accepts_bootstrap_by_name(): void
    {
        $this->makeSource();
        [$io, $out] = $this->consoleIO(['interactive-bootstrap', 'bootstrap'], true);

        $code = (new ProjectConsole(io: $io))->run(
            ['--no-install'],
            $this->source,
            $this->workspace,
        );

        $output = $this->streamContents($out);
        $package = json_decode(
            (string) file_get_contents($this->workspace.'/interactive-bootstrap/package.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(0, $code, $output);
        self::assertArrayHasKey('bootstrap', $package['devDependencies']);
        self::assertArrayNotHasKey('tailwindcss', $package['devDependencies']);
    }

    public function test_non_interactive_execution_without_a_ui_choice_fails(): void
    {
        $this->makeSource();
        [$io, $out, $err] = $this->consoleIO([], false);

        $code = (new ProjectConsole(io: $io))->run(
            ['no-ui-app', '--no-install', '--no-interaction'],
            $this->source,
            $this->workspace,
        );

        self::assertSame(1, $code);
        self::assertSame('', $this->streamContents($out));
        self::assertStringContainsString('--ui=tailwind', $this->streamContents($err));
        self::assertStringContainsString('non-interactive', $this->streamContents($err));
        self::assertDirectoryDoesNotExist($this->workspace.'/no-ui-app');
    }

    public function test_no_interaction_wins_over_forced_interactive_mode(): void
    {
        $this->makeSource();
        [$io, $out, $err] = $this->consoleIO(['forced-app'], true);

        $code = (new ProjectConsole(io: $io))->run(
            ['forced-app', '--no-install', '--no-interaction', '--interactive'],
            $this->source,
            $this->workspace,
        );

        self::assertSame(1, $code);
        self::assertSame('', $this->streamContents($out));
        self::assertStringContainsString('--ui=tailwind', $this->streamContents($err));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidProfiles(): array
    {
        return [
            'unknown' => ['svelte'],
            'both' => ['both'],
            'none' => ['none'],
        ];
    }

    #[DataProvider('invalidProfiles')]
    public function test_invalid_ui_names_fail_with_available_choices(string $ui): void
    {
        $this->makeSource();
        [$io, $out, $err] = $this->consoleIO([], false);

        $code = (new ProjectConsole(io: $io))->run(
            ['bad-ui-app', '--ui='.$ui, '--no-install', '--no-interaction'],
            $this->source,
            $this->workspace,
        );

        self::assertSame(1, $code);
        self::assertSame('', $this->streamContents($out));
        self::assertStringContainsString('Unknown UI framework', $this->streamContents($err));
        self::assertStringContainsString('tailwind', $this->streamContents($err));
        self::assertStringContainsString('bootstrap', $this->streamContents($err));
        self::assertDirectoryDoesNotExist($this->workspace.'/bad-ui-app');
    }

    public function test_non_interactive_name_can_be_derived_from_the_destination_path(): void
    {
        $this->makeSource();
        [$io, $out] = $this->consoleIO([], false);
        $destination = $this->workspace.'/derived-app';

        $code = (new ProjectConsole(io: $io))->run(
            ['--path='.$destination, '--ui=tailwind', '--no-install', '--no-interaction'],
            $this->source,
            $this->workspace,
        );

        self::assertSame(0, $code, $this->streamContents($out));
        self::assertStringContainsString('Project:      derived-app', $this->streamContents($out));
        self::assertFileExists($destination.'/composer.json');
    }

    public function test_missing_project_name_fails_non_interactively(): void
    {
        $this->makeSource();
        [$io, , $err] = $this->consoleIO([], false);

        $code = (new ProjectConsole(io: $io))->run(
            ['--ui=tailwind', '--no-install', '--no-interaction'],
            $this->source,
            $this->workspace,
        );

        self::assertSame(1, $code);
        self::assertStringContainsString('Project name is required', $this->streamContents($err));
    }

    public function test_default_destination_works_when_run_from_the_source_root(): void
    {
        $this->makeSource();
        [$io, $out] = $this->consoleIO([], false);

        $code = (new ProjectConsole(io: $io))->run(
            ['nested-default', '--ui=tailwind', '--no-install', '--no-interaction'],
            $this->source,
            $this->source,
        );

        self::assertSame(0, $code, $this->streamContents($out));
        self::assertFileExists($this->source.'/nested-default/composer.json');
        self::assertDirectoryDoesNotExist($this->source.'/nested-default/nested-default');
    }

    public function test_help_lists_the_documented_non_interactive_options(): void
    {
        [$io, $out] = $this->consoleIO([], false);

        $code = (new ProjectConsole(io: $io))->run(['--help'], $this->source, $this->workspace);

        $output = $this->streamContents($out);

        self::assertSame(0, $code);
        self::assertStringContainsString('SahilPHP Installer', $output);
        self::assertStringContainsString('--ui=NAME', $output);
        self::assertStringContainsString('--no-interaction', $output);
        self::assertStringContainsString('--no-install', $output);
    }

    public function test_unknown_options_are_rejected(): void
    {
        [$io, , $err] = $this->consoleIO([], false);

        $code = (new ProjectConsole(io: $io))->run(
            ['app', '--ui='.Profile::Tailwind->value, '--sneaky'],
            $this->source,
            $this->workspace,
        );

        self::assertSame(1, $code);
        self::assertStringContainsString('unknown option [--sneaky]', $this->streamContents($err));
    }
}
