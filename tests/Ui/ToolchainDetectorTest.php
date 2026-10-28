<?php

declare(strict_types=1);

namespace Sahil\Tests\Ui;

use Sahil\Ui\ProcessRunner;
use Sahil\Ui\ToolchainDetector;

final class ToolchainDetectorTest extends UiTestCase
{
    private string $binPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->binPath = $this->projectPath.'/bin';
        mkdir($this->binPath, 0o700, true);
    }

    public function test_it_detects_node_and_npm_from_a_lock_file(): void
    {
        $this->fakeExecutable('node', 'v24.0.0');
        $this->fakeExecutable('npm', '12.0.0');
        $this->write('package-lock.json', '{}');

        $toolchain = $this->detector()->detect($this->projectPath);

        self::assertTrue($toolchain->isReady());
        self::assertSame('v24.0.0', $toolchain->nodeVersion);
        self::assertSame('npm', $toolchain->packageManager);
        self::assertSame('12.0.0', $toolchain->packageManagerVersion);
        self::assertSame('package-lock.json', $toolchain->lockFile);
        self::assertSame([$this->binPath.'/npm'], $toolchain->command());
    }

    public function test_it_prefers_pnpm_when_a_pnpm_lock_file_exists(): void
    {
        $this->fakeExecutable('node', 'v24.0.0');
        $this->fakeExecutable('npm', '12.0.0');
        $this->fakeExecutable('pnpm', '10.0.0');
        $this->write('pnpm-lock.yaml', "lockfileVersion: '9.0'\n");

        $toolchain = $this->detector()->detect($this->projectPath);

        self::assertSame('pnpm', $toolchain->packageManager);
        self::assertSame($this->binPath.'/pnpm', $toolchain->packageManagerPath);
        self::assertSame('pnpm-lock.yaml', $toolchain->lockFile);
    }

    public function test_a_lock_file_without_its_manager_produces_actionable_guidance(): void
    {
        $this->fakeExecutable('node', 'v24.0.0');
        $this->fakeExecutable('npm', '12.0.0');
        $this->write('pnpm-lock.yaml', "lockfileVersion: '9.0'\n");

        $toolchain = $this->detector()->detect($this->projectPath);

        self::assertFalse($toolchain->isReady());
        self::assertSame('pnpm', $toolchain->packageManager);
        self::assertNull($toolchain->packageManagerPath);

        $issues = implode("\n", $toolchain->issues());
        self::assertStringContainsString('pnpm-lock.yaml', $issues);
        self::assertStringContainsString('pnpm', $issues);
    }

    public function test_it_falls_back_to_npm_when_no_lock_file_exists(): void
    {
        $this->fakeExecutable('node', 'v24.0.0');
        $this->fakeExecutable('npm', '12.0.0');
        $this->fakeExecutable('pnpm', '10.0.0');

        $toolchain = $this->detector()->detect($this->projectPath);

        self::assertSame('npm', $toolchain->packageManager);
        self::assertNull($toolchain->lockFile);
    }

    public function test_missing_node_is_reported_with_install_guidance(): void
    {
        $toolchain = $this->detector()->detect($this->projectPath);

        self::assertFalse($toolchain->hasNode());
        self::assertFalse($toolchain->isReady());

        $issues = implode("\n", $toolchain->issues());
        self::assertStringContainsString('Node.js was not found', $issues);
        self::assertStringContainsString('https://nodejs.org', $issues);
    }

    private function detector(): ToolchainDetector
    {
        return new ToolchainDetector(new ProcessRunner(pollMicroseconds: 1_000), $this->binPath);
    }

    private function fakeExecutable(string $name, string $version): void
    {
        $path = $this->binPath.'/'.$name;

        file_put_contents($path, "#!/bin/sh\necho '".$version."'\n");
        chmod($path, 0o755);

        self::assertTrue(is_executable($path));
    }
}
