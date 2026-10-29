<?php

declare(strict_types=1);

namespace Sahil\Tests\Installer;

use PHPUnit\Framework\Attributes\DataProvider;
use Sahil\Installer\ExecutableLocator;
use Sahil\Installer\InstallerException;
use Sahil\Installer\ProjectInstaller;
use Sahil\Installer\ProjectOptions;
use Sahil\Ui\Profile;
use Sahil\Ui\ToolchainDetector;

final class ProjectInstallerTest extends InstallerTestCase
{
    /**
     * @return array<string, array{Profile, list<string>, list<string>}>
     */
    public static function uiProfiles(): array
    {
        return [
            'tailwind' => [
                Profile::Tailwind,
                ['resources/css/tailwind.css'],
                ['bootstrap', 'resources/css/bootstrap.css', 'resources/js/bootstrap.js', 'vite.bootstrap.config.js'],
            ],
            'bootstrap' => [
                Profile::Bootstrap,
                ['resources/css/bootstrap.css', 'resources/js/bootstrap.js'],
                ['tailwindcss', '@tailwindcss/vite', 'resources/css/tailwind.css'],
            ],
        ];
    }

    /**
     * @param list<string> $requiredFiles
     * @param list<string> $forbidden
     */
    #[DataProvider('uiProfiles')]
    public function test_each_variant_generates_only_the_selected_ui(
        Profile $profile,
        array $requiredFiles,
        array $forbidden,
    ): void {
        $this->makeSource();
        $destination = $this->workspace.'/apps/'.$profile->value.'-app';

        $report = $this->installer()->install(new ProjectOptions(
            sourcePath: $this->source,
            destination: $destination,
            projectName: $profile->value.'-app',
            ui: $profile,
            installDependencies: false,
        ));

        self::assertSame($destination, $report->destination);
        self::assertSame($profile, $report->uiProfile);
        self::assertTrue($report->applicationKeyWritten);
        self::assertGreaterThan(0, $report->filesCopied);
        self::assertDirectoryExists($destination);

        foreach ($requiredFiles as $file) {
            self::assertFileExists($destination.'/'.$file);
        }

        $package = json_decode((string) file_get_contents($destination.'/package.json'), true, 512, JSON_THROW_ON_ERROR);
        $manifest = json_decode((string) file_get_contents($destination.'/.sahil-ui.json'), true, 512, JSON_THROW_ON_ERROR);
        $vite = (string) file_get_contents($destination.'/vite.config.js');

        self::assertSame($profile->value, $manifest['profile']);
        self::assertSame('vite build', $package['scripts']['build']);

        foreach ($forbidden as $value) {
            if ($value === 'bootstrap' || $value === 'tailwindcss' || $value === '@tailwindcss/vite') {
                self::assertArrayNotHasKey($value, $package['devDependencies']);

                continue;
            }

            self::assertFileDoesNotExist($destination.'/'.$value);
        }

        if ($profile === Profile::Tailwind) {
            self::assertArrayHasKey('tailwindcss', $package['devDependencies']);
            self::assertArrayHasKey('@tailwindcss/vite', $package['devDependencies']);
            self::assertStringContainsString('@tailwindcss/vite', $vite);
        } else {
            self::assertArrayHasKey('bootstrap', $package['devDependencies']);
            self::assertStringNotContainsString('tailwind', $vite);
        }

        foreach ([
            'postcss.config.cjs',
            'postcss.config.js',
            'resources/css/app.css',
            'resources/css/bootstrap-overrides.css',
            'resources/js/bootstrap-sass.js',
            'resources/js/bootstrap-starter.js',
            'resources/sass/bootstrap.scss',
            'tailwind.config.cjs',
            'tailwind.config.js',
            'vite.bootstrap.config.js',
        ] as $obsolete) {
            self::assertFileDoesNotExist($destination.'/'.$obsolete);
        }

        self::assertFileDoesNotExist($destination.'/package-lock.json');
        self::assertFileDoesNotExist($destination.'/public/build/assets/bootstrap.css');
        self::assertFileDoesNotExist($destination.'/public/build/assets/tailwind.css');
        self::assertFileDoesNotExist($destination.'/public/hot');
        self::assertFileDoesNotExist($destination.'/vendor/autoload.php');
        self::assertFileDoesNotExist($destination.'/node_modules/vite/package.json');
        self::assertFileDoesNotExist($destination.'/storage/logs/app.log');
        self::assertFileDoesNotExist($destination.'/storage/framework/views/compiled.php');
        self::assertDirectoryDoesNotExist($destination.'/storage/framework/ui-backups');
        self::assertFileDoesNotExist($destination.'/.git/config');
        self::assertFileDoesNotExist($destination.'/composer.json.backup');
        self::assertFileExists($destination.'/storage/logs/.gitkeep');
        self::assertFileExists($destination.'/storage/README.md');

        $environment = (string) file_get_contents($destination.'/.env');
        self::assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/=]{44}$/m', $environment);
        self::assertStringContainsString('APP_ENV=development', $environment);

        self::assertFileExists($this->source.'/resources/css/tailwind.css', 'The source starter-kit must not be modified.');
        self::assertFileExists($this->source.'/vendor/autoload.php');
    }

    public function test_existing_non_empty_destination_is_rejected_without_changes(): void
    {
        $this->makeSource();
        $destination = $this->workspace.'/apps/existing-app';
        mkdir($destination, 0o700, true);
        file_put_contents($destination.'/keep.txt', 'mine');

        try {
            $this->installer()->install($this->options($destination));
            self::fail('Expected an existing-destination failure.');
        } catch (InstallerException $failure) {
            self::assertStringContainsString('already exists and is not empty', $failure->getMessage());
        }

        self::assertSame('mine', (string) file_get_contents($destination.'/keep.txt'));
        self::assertFileDoesNotExist($destination.'/composer.json');
    }

    public function test_existing_empty_destination_is_supported(): void
    {
        $this->makeSource();
        $destination = $this->workspace.'/apps/empty-app';
        mkdir($destination, 0o700, true);

        $this->installer()->install($this->options($destination));

        self::assertFileExists($destination.'/composer.json');
        self::assertFileExists($destination.'/resources/css/tailwind.css');
    }

    public function test_destination_inside_the_source_tree_is_supported_without_recursion(): void
    {
        $this->makeSource();
        $destination = $this->source.'/nested-app';

        $this->installer()->install($this->options($destination));

        self::assertFileExists($destination.'/composer.json');
        self::assertFileExists($destination.'/resources/css/tailwind.css');
        self::assertDirectoryDoesNotExist($destination.'/nested-app');
    }

    public function test_destination_equal_to_the_source_tree_is_rejected(): void
    {
        $this->makeSource();

        $this->expectException(InstallerException::class);
        $this->expectExceptionMessage('is the starter-kit source directory');

        $this->installer()->install($this->options($this->source));
    }

    public function test_destination_paths_with_spaces_are_supported(): void
    {
        $this->makeSource();
        $destination = $this->workspace.'/apps/my app';

        $this->installer()->install($this->options($destination));

        self::assertFileExists($destination.'/resources/css/tailwind.css');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidNames(): array
    {
        return [
            'empty' => [''],
            'parent traversal' => ['../escape'],
            'path separator' => ['bad/name'],
            'backslash' => ['bad\\name'],
            'space' => ['bad name'],
            'hidden' => ['.hidden'],
            'leading dash' => ['-app'],
            'percent' => ['app%2f'],
            'trailing dot' => ['app.'],
        ];
    }

    #[DataProvider('invalidNames')]
    public function test_invalid_project_names_are_rejected(string $name): void
    {
        $this->makeSource();

        $this->expectException(InstallerException::class);
        $this->expectExceptionMessage('Invalid project name');

        $this->installer()->install(new ProjectOptions(
            sourcePath: $this->source,
            destination: $this->workspace.'/apps/app',
            projectName: $name,
            ui: Profile::Tailwind,
            installDependencies: false,
        ));
    }

    public function test_unsupported_ui_profile_is_rejected(): void
    {
        $this->makeSource();

        $this->expectException(InstallerException::class);
        $this->expectExceptionMessage('exactly one UI framework');

        $this->installer()->install(new ProjectOptions(
            sourcePath: $this->source,
            destination: $this->workspace.'/apps/app',
            projectName: 'app',
            ui: Profile::Both,
            installDependencies: false,
        ));
    }

    public function test_missing_composer_fails_before_creating_the_destination(): void
    {
        $this->makeSource();
        $emptyBin = $this->workspace.'/empty-bin';
        mkdir($emptyBin, 0o700, true);
        $destination = $this->workspace.'/apps/no-composer';

        $installer = new ProjectInstaller(locator: new ExecutableLocator($emptyBin));

        try {
            $installer->install(new ProjectOptions(
                sourcePath: $this->source,
                destination: $destination,
                projectName: 'no-composer',
                ui: Profile::Tailwind,
            ));
            self::fail('Expected a missing-Composer failure.');
        } catch (InstallerException $failure) {
            self::assertStringContainsString('Composer was not found', $failure->getMessage());
            self::assertStringContainsString('--no-install', $failure->getMessage());
        }

        self::assertDirectoryDoesNotExist($destination);
    }

    public function test_missing_node_fails_before_creating_the_destination(): void
    {
        $this->makeSource();
        $bin = $this->workspace.'/bin';
        $emptyBin = $this->workspace.'/empty-bin';
        mkdir($bin, 0o700, true);
        mkdir($emptyBin, 0o700, true);
        file_put_contents($bin.'/composer', "#!/bin/sh\nexit 0\n");
        chmod($bin.'/composer', 0o755);

        $destination = $this->workspace.'/apps/no-node';
        $installer = new ProjectInstaller(
            toolchainDetector: new ToolchainDetector(searchPath: $emptyBin),
            locator: new ExecutableLocator($bin),
        );

        try {
            $installer->install(new ProjectOptions(
                sourcePath: $this->source,
                destination: $destination,
                projectName: 'no-node',
                ui: Profile::Bootstrap,
            ));
            self::fail('Expected a missing-Node failure.');
        } catch (InstallerException $failure) {
            self::assertStringContainsString('Node.js was not found', $failure->getMessage());
            self::assertStringContainsString('--no-install', $failure->getMessage());
        }

        self::assertDirectoryDoesNotExist($destination);
    }

    private function installer(): ProjectInstaller
    {
        return new ProjectInstaller();
    }

    private function options(string $destination): ProjectOptions
    {
        return new ProjectOptions(
            sourcePath: $this->source,
            destination: $destination,
            projectName: 'fixture-app',
            ui: Profile::Tailwind,
            installDependencies: false,
        );
    }
}
