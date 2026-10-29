<?php

declare(strict_types=1);

namespace Sahil\Tests\Console;

use Sahil\Tests\FrameworkTestCase;

final class UiThemeCommandTest extends FrameworkTestCase
{
    public function test_theme_dry_run_then_install_generates_framework_files(): void
    {
        $project = $this->tempDirectory('sahil-ui-theme');

        $this->runCommand(['ui:install', 'tailwind', '--path='.$project, '--no-install'])
            ->assertSuccessful();

        $this->runCommand(['ui:theme', '--path='.$project, '--dry-run'])
            ->assertSuccessful()
            ->assertOutputContains('UI theme: sahil-neutral')
            ->assertOutputContains('Mode: dry run')
            ->assertOutputContains('would create')
            ->assertOutputContains('theme/tokens.json');

        self::assertFileDoesNotExist($project.'/theme/tokens.json');

        $this->runCommand(['ui:theme', '--path='.$project])
            ->assertSuccessful()
            ->assertOutputContains('Profile: Tailwind CSS v4')
            ->assertOutputContains('create     theme/tokens.json')
            ->assertOutputContains('resources/css/theme.tailwind.generated.css');

        self::assertFileExists($project.'/theme/tokens.json');
        self::assertFileExists($project.'/resources/css/theme.tokens.generated.css');
        self::assertFileExists($project.'/resources/css/theme.tailwind.generated.css');
        self::assertFileExists($project.'/resources/css/theme.overrides.css');
        self::assertStringContainsString(
            '@import "./theme.tailwind.generated.css";',
            (string) file_get_contents($project.'/resources/css/tailwind.css')
        );
    }

    public function test_theme_json_output_is_machine_readable(): void
    {
        $project = $this->tempDirectory('sahil-ui-theme');

        $this->runCommand(['ui:install', 'none', '--path='.$project, '--no-install'])
            ->assertSuccessful();

        $this->runCommand(['ui:theme', '--path='.$project, '--json'])
            ->assertSuccessful()
            ->assertOutputContains('"theme": "sahil-neutral"')
            ->assertOutputContains('"profile": "none"')
            ->assertOutputContains('"created"')
            ->assertOutputContains('"dry_run": false');
    }

    public function test_theme_uses_the_recorded_profile_and_custom_source(): void
    {
        $project = $this->tempDirectory('sahil-ui-theme');

        $this->runCommand(['ui:install', 'both', '--path='.$project, '--no-install'])
            ->assertSuccessful();

        $source = $project.'/theme/brand.json';
        mkdir(dirname($source), 0o700, true);

        $data = \Sahil\Theme\ThemeTokens::sample()->toArray();
        $data['name'] = 'brand-theme';
        $data['colors']['primary'] = ['light' => '#0f766e', 'dark' => '#2dd4bf'];
        file_put_contents(
            $source,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n"
        );

        $this->runCommand([
            'ui:theme',
            '--path='.$project,
            '--source=theme/brand.json',
        ])
            ->assertSuccessful()
            ->assertOutputContains('UI theme: brand-theme')
            ->assertOutputContains('Profile: Tailwind CSS v4 + Bootstrap 5')
            ->assertOutputContains('Source: theme/brand.json');

        self::assertFileDoesNotExist($project.'/theme/tokens.json');
        self::assertStringContainsString(
            '#0f766e',
            (string) file_get_contents($project.'/resources/css/theme.tokens.generated.css')
        );
        self::assertStringContainsString(
            'theme.bootstrap.generated.css',
            (string) file_get_contents($project.'/resources/css/bootstrap.css')
        );
        self::assertStringContainsString(
            'theme.tailwind.generated.css',
            (string) file_get_contents($project.'/resources/css/tailwind.css')
        );
    }

    public function test_invalid_custom_source_is_reported_without_writing_generated_files(): void
    {
        $project = $this->tempDirectory('sahil-ui-theme');

        $this->runCommand(['ui:install', 'tailwind', '--path='.$project, '--no-install'])
            ->assertSuccessful();

        $this->runCommand([
            'ui:theme',
            '--path='.$project,
            '--source=theme/missing.json',
        ])
            ->assertFailed()
            ->assertOutputContains('was not found');

        self::assertFileDoesNotExist($project.'/resources/css/theme.tokens.generated.css');
    }
}
