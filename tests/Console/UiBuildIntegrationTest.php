<?php

declare(strict_types=1);

namespace Sahil\Tests\Console;

use Sahil\Tests\FrameworkTestCase;

/**
 * Real Vite builds in temporary projects.
 *
 * These tests use the repository's installed node_modules through a
 * symlink, so they require no network access. They are skipped when the
 * frontend dependencies have not been installed (for example in a PHP-only
 * CI job).
 */
final class UiBuildIntegrationTest extends FrameworkTestCase
{
    private function nodeModulesPath(): string
    {
        return dirname(__DIR__, 2).'/node_modules';
    }

    public function test_tailwind_assets_build_in_a_temporary_project(): void
    {
        $project = $this->build('tailwind', ['public/build/assets/tailwind.css']);

        self::assertStringContainsString('tailwindcss', (string) file_get_contents($project.'/public/build/assets/tailwind.css'));
    }

    public function test_bootstrap_assets_build_in_a_temporary_project(): void
    {
        $this->build('bootstrap', [
            'public/build/assets/bootstrap.css',
            'public/build/assets/bootstrap.js',
        ]);
    }

    public function test_both_profiles_build_into_the_same_output_directory(): void
    {
        $this->build('both', [
            'public/build/assets/tailwind.css',
            'public/build/assets/bootstrap.css',
            'public/build/assets/bootstrap.js',
        ]);
    }

    public function test_plain_profile_builds_without_a_framework(): void
    {
        $this->build('none', ['public/build/assets/app.css']);
    }

    public function test_tailwind_theme_builds(): void
    {
        $project = $this->build('tailwind', ['public/build/assets/tailwind.css'], theme: true);

        self::assertStringContainsString(
            '--sahil-color-primary',
            (string) file_get_contents($project.'/public/build/assets/tailwind.css'),
        );
    }

    public function test_bootstrap_theme_builds(): void
    {
        $project = $this->build('bootstrap', [
            'public/build/assets/bootstrap.css',
            'public/build/assets/bootstrap.js',
        ], theme: true);

        self::assertStringContainsString(
            '--sahil-color-primary',
            (string) file_get_contents($project.'/public/build/assets/bootstrap.css'),
        );
    }

    public function test_both_profile_theme_builds(): void
    {
        $project = $this->build('both', [
            'public/build/assets/tailwind.css',
            'public/build/assets/bootstrap.css',
            'public/build/assets/bootstrap.js',
        ], theme: true);

        self::assertStringContainsString(
            '--sahil-color-primary',
            (string) file_get_contents($project.'/public/build/assets/tailwind.css'),
        );
        self::assertStringContainsString(
            '--sahil-color-primary',
            (string) file_get_contents($project.'/public/build/assets/bootstrap.css'),
        );
    }

    public function test_plain_theme_builds(): void
    {
        $project = $this->build('none', ['public/build/assets/app.css'], theme: true);

        self::assertStringContainsString(
            '--sahil-color-primary',
            (string) file_get_contents($project.'/public/build/assets/app.css'),
        );
    }

    public function test_tailwind_theme_utilities_use_generated_tokens(): void
    {
        $project = $this->build(
            'tailwind',
            ['public/build/assets/tailwind.css'],
            theme: true,
            view: '<div class="bg-primary p-4 rounded-md"></div>'."\n",
        );

        $css = (string) file_get_contents($project.'/public/build/assets/tailwind.css');

        self::assertStringContainsString('background-color:var(--color-primary)', $css);
        self::assertStringContainsString('calc(var(--sahil-space-base) * 4)', $css);
        self::assertStringContainsString('var(--sahil-radius-md)', $css);
    }

    public function test_clean_removes_stale_build_output(): void
    {
        $project = $this->build('tailwind', ['public/build/assets/tailwind.css']);
        file_put_contents($project.'/public/build/assets/stale.css', 'stale');

        $this->runCommand(['ui:build', '--path='.$project, '--clean'])
            ->assertSuccessful()
            ->assertOutputContains('removing public/build');

        self::assertFileDoesNotExist($project.'/public/build/assets/stale.css');
        self::assertFileExists($project.'/public/build/assets/tailwind.css');
    }

    /**
     * @param list<string> $assets
     */
    private function build(string $profile, array $assets, bool $theme = false, ?string $view = null): string
    {
        $this->requireNodeModules([
            'vite',
            ...match ($profile) {
                'tailwind' => ['tailwindcss', '@tailwindcss/vite'],
                'bootstrap' => ['bootstrap'],
                'both' => ['tailwindcss', '@tailwindcss/vite', 'bootstrap'],
                default => [],
            },
        ]);

        $project = $this->tempDirectory('sahil-ui-build');

        $this->runCommand(['ui:install', $profile, '--path='.$project, '--no-install'])
            ->assertSuccessful();

        if ($theme) {
            $this->runCommand(['ui:theme', '--path='.$project])
                ->assertSuccessful()
                ->assertOutputContains('UI theme: sahil-neutral');
        }

        if ($view !== null) {
            if (!is_dir($project.'/views')) {
                mkdir($project.'/views', 0o700, true);
            }

            file_put_contents($project.'/views/theme-test.sahil.php', $view);
        }

        if (!symlink($this->nodeModulesPath(), $project.'/node_modules')) {
            self::markTestSkipped('Unable to symlink the repository node_modules directory.');
        }

        $result = $this->runCommand(['ui:build', '--path='.$project]);

        $result->assertSuccessful();

        foreach ($assets as $asset) {
            self::assertFileExists($project.'/'.$asset, $result->diagnostics());
        }

        return $project;
    }

    /**
     * @param list<string> $packages
     */
    private function requireNodeModules(array $packages): void
    {
        foreach ($packages as $package) {
            if (!is_file($this->nodeModulesPath().'/'.$package.'/package.json')) {
                self::markTestSkipped(sprintf(
                    'Frontend dependency [%s] is not installed; run `npm install` to enable build integration tests.',
                    $package,
                ));
            }
        }
    }
}
