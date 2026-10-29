<?php

declare(strict_types=1);

namespace Sahil\Tests\Ui;

use Sahil\Ui\InstallOptions;
use Sahil\Ui\ProcessRunner;
use Sahil\Ui\Profile;
use Sahil\Ui\ToolchainDetector;
use Sahil\Ui\UiInstaller;
use Sahil\Ui\UiStatus;

final class UiStatusTest extends UiTestCase
{
    private string $binPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->binPath = $this->projectPath.'/fake-bin';
        mkdir($this->binPath, 0o700, true);

        $this->fakeExecutable('node', 'v24.0.0');
        $this->fakeExecutable('npm', '12.0.0');
    }

    public function test_status_reports_no_profile_for_an_empty_project(): void
    {
        $report = $this->inspector()->inspect($this->projectPath);

        self::assertNull($report->profile);
        self::assertFalse($report->ok());
        self::assertStringContainsString('No UI profile is recorded', implode("\n", $report->lines()));
    }

    public function test_status_infers_the_profile_from_package_json(): void
    {
        $this->write('package.json', json_encode([
            'devDependencies' => [
                'vite' => '^8.0.0',
                'tailwindcss' => '^4.0.0',
                '@tailwindcss/vite' => '^4.0.0',
            ],
        ], JSON_PRETTY_PRINT)."\n");

        $report = $this->inspector()->inspect($this->projectPath);

        self::assertSame(Profile::Tailwind, $report->profile);
        self::assertSame('package.json dependencies', $report->profileSource);
    }

    public function test_status_reports_missing_packages_and_build_output(): void
    {
        $this->install(Profile::Tailwind);

        $report = $this->inspector()->inspect($this->projectPath);

        self::assertSame(Profile::Tailwind, $report->profile);
        self::assertFalse($report->ok());

        $issues = implode("\n", $report->issues);

        self::assertStringContainsString('Missing npm package(s)', $issues);
        self::assertStringContainsString('Missing build output(s)', $issues);
        self::assertStringContainsString('public/build/assets/tailwind.css', $issues);
    }

    public function test_status_is_ready_when_sources_packages_and_fresh_assets_exist(): void
    {
        $this->install(Profile::Tailwind);
        $this->fakePackages(['vite', '@tailwindcss/vite', 'tailwindcss']);

        $asset = $this->projectPath.'/public/build/assets/tailwind.css';
        mkdir(dirname($asset), 0o700, true);
        file_put_contents($asset, 'body{}');
        touch($asset, time() + 5);

        $report = $this->inspector()->inspect($this->projectPath);

        self::assertTrue($report->ok(), implode("\n", $report->issues));
        self::assertStringContainsString('ready:            yes', implode("\n", $report->lines()));
    }

    public function test_status_marks_an_asset_as_stale_when_a_source_is_newer(): void
    {
        $this->install(Profile::Tailwind);
        $this->fakePackages(['vite', '@tailwindcss/vite', 'tailwindcss']);

        $asset = $this->projectPath.'/public/build/assets/tailwind.css';
        mkdir(dirname($asset), 0o700, true);
        file_put_contents($asset, 'body{}');
        touch($asset, time() - 60);
        touch($this->projectPath.'/resources/css/tailwind.css', time() + 10);

        $report = $this->inspector()->inspect($this->projectPath);

        self::assertFalse($report->ok());
        self::assertContains('public/build/assets/tailwind.css', $report->staleAssets);
        self::assertStringContainsString('older than the source', implode("\n", $report->issues));
    }

    public function test_json_report_exposes_profile_toolchain_and_assets(): void
    {
        $this->install(Profile::Bootstrap);

        $json = $this->inspector()->inspect($this->projectPath)->toArray();

        self::assertFalse($json['ok']);
        self::assertSame('bootstrap', $json['profile']);
        self::assertNull($json['toolchain']['lock_file']);
        self::assertSame('npm', $json['toolchain']['package_manager']['name']);
        self::assertArrayHasKey('public/build/assets/bootstrap.css', $json['assets']);
    }

    public function test_status_reports_all_bootstrap_assets(): void
    {
        $report = $this->inspector()->inspect($this->projectPath);

        self::assertNull($report->profile);

        $this->install(Profile::Bootstrap);
        $report = $this->inspector()->inspect($this->projectPath);

        self::assertSame(
            ['public/build/assets/bootstrap.css', 'public/build/assets/bootstrap.js'],
            $report->missingAssets,
        );
    }

    private function install(Profile $profile): void
    {
        (new UiInstaller(
            new ProcessRunner(pollMicroseconds: 1_000),
            $this->detector(),
        ))->install($this->projectPath, $profile, new InstallOptions(installDependencies: false));
    }

    private function inspector(): UiStatus
    {
        return new UiStatus($this->detector());
    }

    private function detector(): ToolchainDetector
    {
        return new ToolchainDetector(new ProcessRunner(pollMicroseconds: 1_000), $this->binPath);
    }

    /**
     * @param list<string> $packages
     */
    private function fakePackages(array $packages): void
    {
        foreach ($packages as $package) {
            $path = $this->projectPath.'/node_modules/'.$package.'/package.json';

            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0o700, true);
            }

            file_put_contents($path, '{"name":"'.$package.'"}');
        }
    }

    private function fakeExecutable(string $name, string $version): void
    {
        $path = $this->binPath.'/'.$name;

        file_put_contents($path, "#!/bin/sh\necho '".$version."'\n");
        chmod($path, 0o755);
    }
}
