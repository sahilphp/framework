<?php

declare(strict_types=1);

namespace Sahil\Tests\Ui;

use PHPUnit\Framework\Attributes\DataProvider;
use Sahil\Ui\ConflictException;
use Sahil\Ui\InstallOptions;
use Sahil\Ui\ProcessRunner;
use Sahil\Ui\Profile;
use Sahil\Ui\ToolchainDetector;
use Sahil\Ui\UiException;
use Sahil\Ui\UiInstaller;

final class UiInstallerTest extends UiTestCase
{
    /**
     * @return array<string, array{Profile, list<string>}>
     */
    public static function profiles(): array
    {
        return [
            'tailwind' => [Profile::Tailwind, [
                '.gitignore',
                '.sahil-ui.json',
                'package.json',
                'resources/css/tailwind.css',
                'vite.config.js',
            ]],
            'bootstrap' => [Profile::Bootstrap, [
                '.gitignore',
                '.sahil-ui.json',
                'package.json',
                'resources/css/bootstrap.css',
                'resources/js/bootstrap.js',
                'vite.config.js',
            ]],
            'both' => [Profile::Both, [
                '.gitignore',
                '.sahil-ui.json',
                'package.json',
                'resources/css/bootstrap.css',
                'resources/css/tailwind.css',
                'resources/js/bootstrap.js',
                'vite.config.js',
            ]],
            'none' => [Profile::None, [
                '.gitignore',
                '.sahil-ui.json',
                'package.json',
                'resources/css/app.css',
                'vite.config.js',
            ]],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('profiles')]
    public function test_install_generates_only_the_profile_files(Profile $profile, array $expected): void
    {
        $report = $this->installer()->install($this->projectPath, $profile, $this->options());

        $actual = $this->listedFiles();

        self::assertSame($expected, $actual);
        self::assertCount(count($expected), $report->created);
    }

    public function test_tailwind_install_writes_expected_manifest_and_package(): void
    {
        $this->installer()->install($this->projectPath, Profile::Tailwind, $this->options());

        $package = json_decode($this->read('package.json'), true, 512, JSON_THROW_ON_ERROR);
        $manifest = json_decode($this->read('.sahil-ui.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('tailwind', $manifest['profile']);
        self::assertSame('vite build', $package['scripts']['build']);
        self::assertArrayHasKey('tailwindcss', $package['devDependencies']);
        self::assertArrayHasKey('@tailwindcss/vite', $package['devDependencies']);
        self::assertStringContainsString('@import "tailwindcss"', $this->read('resources/css/tailwind.css'));
        self::assertStringContainsString('require(\'@tailwindcss/vite\').default', $this->read('vite.config.js'));
    }

    public function test_install_adds_gitignore_entries_once(): void
    {
        $this->write('.gitignore', "vendor/\n");

        $this->installer()->install($this->projectPath, Profile::Tailwind, $this->options());
        $this->installer()->install($this->projectPath, Profile::Tailwind, $this->options());

        $gitignore = $this->read('.gitignore');

        self::assertStringContainsString('vendor/', $gitignore);
        self::assertSame(1, substr_count($gitignore, '/node_modules/'));
        self::assertSame(1, substr_count($gitignore, '/public/build/'));
        self::assertSame(1, substr_count($gitignore, '/storage/framework/vite/'));
    }

    public function test_existing_conflicts_abort_before_writing_anything(): void
    {
        $this->write('vite.config.js', "// my custom config\nmodule.exports = {};\n");

        try {
            $this->installer()->install($this->projectPath, Profile::Tailwind, $this->options());
            self::fail('Expected a ConflictException.');
        } catch (ConflictException $conflict) {
            self::assertContains('vite.config.js', $conflict->conflicts);
        }

        self::assertSame("// my custom config\nmodule.exports = {};\n", $this->read('vite.config.js'));
        self::assertFileDoesNotExist($this->projectPath.'/package.json');
        self::assertFileDoesNotExist($this->projectPath.'/resources/css/tailwind.css');
        self::assertFileDoesNotExist($this->projectPath.'/.sahil-ui.json');
    }

    public function test_merge_preserves_custom_config_and_merges_package_json(): void
    {
        $this->write('vite.config.js', "// my custom config\nmodule.exports = {};\n");
        $this->write('package.json', json_encode([
            'name' => 'existing-app',
            'version' => '2.0.0',
            'scripts' => ['dev' => 'echo custom-dev'],
            'dependencies' => ['lodash' => '^4.17.0'],
            'devDependencies' => ['vite' => '^7.0.0'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $report = $this->installer()->install(
            $this->projectPath,
            Profile::Tailwind,
            new InstallOptions(merge: true, installDependencies: false),
        );

        self::assertContains('vite.config.js', $report->preserved);
        self::assertSame("// my custom config\nmodule.exports = {};\n", $this->read('vite.config.js'));

        $package = json_decode($this->read('package.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('existing-app', $package['name']);
        self::assertSame('2.0.0', $package['version']);
        self::assertSame('echo custom-dev', $package['scripts']['dev']);
        self::assertSame('vite build', $package['scripts']['build']);
        self::assertSame('^4.17.0', $package['dependencies']['lodash']);
        self::assertSame('^7.0.0', $package['devDependencies']['vite']);
        self::assertArrayHasKey('tailwindcss', $package['devDependencies']);
    }

    public function test_overwrite_replaces_files_and_keeps_a_backup(): void
    {
        $this->write('vite.config.js', "// my custom config\nmodule.exports = {};\n");

        $report = $this->installer()->install(
            $this->projectPath,
            Profile::Tailwind,
            new InstallOptions(overwrite: true, installDependencies: false),
        );

        self::assertStringContainsString('UI profile', implode("\n", $report->lines()));
        self::assertStringContainsString('require(\'@tailwindcss/vite\').default', $this->read('vite.config.js'));

        $backups = glob($this->projectPath.'/storage/framework/ui-backups/*/vite.config.js');
        self::assertIsArray($backups);
        self::assertCount(1, $backups);
        self::assertSame("// my custom config\nmodule.exports = {};\n", (string) file_get_contents($backups[0]));
    }

    public function test_dry_run_reports_the_plan_without_writing(): void
    {
        $report = $this->installer()->install(
            $this->projectPath,
            Profile::Bootstrap,
            new InstallOptions(dryRun: true, installDependencies: false),
        );

        self::assertTrue($report->dryRun);
        self::assertContains('package.json', $report->created);
        self::assertContains('resources/js/bootstrap.js', $report->created);
        self::assertSame([], $this->listedFiles());
        self::assertStringContainsString('would create', implode("\n", $report->lines()));
    }

    public function test_reinstall_is_idempotent_for_sources(): void
    {
        $this->installer()->install($this->projectPath, Profile::Tailwind, $this->options());

        $config = $this->read('vite.config.js');
        $report = $this->installer()->install($this->projectPath, Profile::Tailwind, $this->options());

        self::assertSame([], $report->created);
        self::assertNotContains('vite.config.js', $report->updated);
        self::assertNotContains('package.json', $report->updated);
        self::assertSame($config, $this->read('vite.config.js'));
    }

    public function test_invalid_package_json_is_rejected_without_overwrite(): void
    {
        $this->write('package.json', '{"name": "broken"');

        $this->expectException(UiException::class);
        $this->expectExceptionMessage('package.json');

        $this->installer()->install($this->projectPath, Profile::Tailwind, $this->options());
    }

    public function test_invalid_package_json_can_be_replaced_with_overwrite(): void
    {
        $this->write('package.json', '{"name": "broken"');

        $report = $this->installer()->install(
            $this->projectPath,
            Profile::Tailwind,
            new InstallOptions(overwrite: true, installDependencies: false),
        );

        self::assertNotSame([], $report->backups);

        $package = json_decode($this->read('package.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsString($package['name']);
        self::assertArrayHasKey('vite', $package['devDependencies']);
    }

    public function test_install_reports_prerequisite_guidance_without_install(): void
    {
        $report = $this->installer()->install(
            $this->projectPath,
            Profile::Tailwind,
            new InstallOptions(installDependencies: false),
        );

        $lines = implode("\n", $report->lines());

        self::assertStringContainsString('dependency installation skipped', $lines);
    }

    private function installer(): UiInstaller
    {
        return new UiInstaller(
            new ProcessRunner(pollMicroseconds: 1_000),
            new ToolchainDetector(new ProcessRunner(pollMicroseconds: 1_000), $this->projectPath.'/no-bin'),
        );
    }

    private function options(): InstallOptions
    {
        return new InstallOptions(installDependencies: false);
    }

    /**
     * @return list<string>
     */
    private function listedFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectPath, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $files[] = str_replace('\\', '/', substr($item->getPathname(), strlen($this->projectPath) + 1));
        }

        sort($files);

        return $files;
    }
}
