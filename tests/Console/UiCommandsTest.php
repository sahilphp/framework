<?php

declare(strict_types=1);

namespace Sahil\Tests\Console;

use Sahil\Tests\FrameworkTestCase;

final class UiCommandsTest extends FrameworkTestCase
{
    public function test_help_advertises_the_ui_commands(): void
    {
        $this->runCommand('--help')
            ->assertSuccessful()
            ->assertOutputContains('ui:install')
            ->assertOutputContains('ui:dev')
            ->assertOutputContains('ui:build')
            ->assertOutputContains('ui:status')
            ->assertOutputContains('ui:theme')
            ->assertOutputContains('--source=FILE')
            ->assertOutputContains('--dry-run');
    }

    public function test_install_dry_run_writes_nothing(): void
    {
        $project = $this->tempDirectory('sahil-ui-cli');

        $this->runCommand(['ui:install', 'tailwind', '--path='.$project, '--no-install', '--dry-run'])
            ->assertSuccessful()
            ->assertOutputContains('would create')
            ->assertOutputContains('package.json');

        self::assertSame([], $this->files($project));
    }

    public function test_install_status_and_dry_run_commands_work_end_to_end(): void
    {
        $project = $this->tempDirectory('sahil-ui-cli');

        $this->runCommand(['ui:install', 'bootstrap', '--path='.$project, '--no-install'])
            ->assertSuccessful()
            ->assertOutputContains('UI profile: Bootstrap 5')
            ->assertOutputContains('create   package.json');

        self::assertFileExists($project.'/package.json');
        self::assertFileExists($project.'/resources/js/bootstrap.js');
        self::assertStringContainsString('"bootstrap"', (string) file_get_contents($project.'/package.json'));

        $this->runCommand(['ui:status', '--path='.$project])
            ->assertFailed()
            ->assertOutputContains('Bootstrap 5')
            ->assertOutputContains('Missing npm package(s)')
            ->assertOutputContains('Missing build output(s)');

        $this->runCommand(['ui:status', '--path='.$project, '--json'])
            ->assertFailed()
            ->assertOutputContains('"profile": "bootstrap"')
            ->assertOutputContains('"ok"')
            ->assertOutputContains('"assets"');
    }

    public function test_build_and_dev_support_dry_run(): void
    {
        $project = $this->tempDirectory('sahil-ui-cli');

        $this->runCommand(['ui:install', 'tailwind', '--path='.$project, '--no-install'])
            ->assertSuccessful();

        $this->runCommand(['ui:build', '--path='.$project, '--dry-run', '--clean'])
            ->assertSuccessful()
            ->assertOutputContains('would remove public/build')
            ->assertOutputContains('would run:')
            ->assertOutputContains('run build');

        $this->runCommand(['ui:dev', '--path='.$project, '--dry-run', '--host=0.0.0.0', '--port=5199'])
            ->assertSuccessful()
            ->assertOutputContains('run dev')
            ->assertOutputContains('--host=0.0.0.0')
            ->assertOutputContains('--port=5199');

        self::assertDirectoryDoesNotExist($project.'/public/build');
        self::assertFileDoesNotExist($project.'/public/hot');
    }

    public function test_dev_falls_back_to_a_free_port_and_cleans_up_the_hot_file(): void
    {
        $project = $this->tempDirectory('sahil-ui-cli');
        $bin = $this->tempDirectory('sahil-ui-bin');
        $this->fakeToolchain($bin);

        $this->runCommand(['ui:install', 'tailwind', '--path='.$project, '--no-install'])
            ->assertSuccessful();

        mkdir($project.'/node_modules/vite', 0o700, true);
        file_put_contents($project.'/node_modules/vite/package.json', '{"name":"vite"}');

        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($listener, (string) $errorMessage);

        $name = (string) stream_socket_get_name($listener, false);
        $busy = (int) substr($name, strrpos($name, ':') + 1);

        try {
            $this->runCommand(
                ['ui:dev', '--path='.$project, '--host=127.0.0.1', '--port='.$busy],
                ['PATH' => $bin],
            )
                ->assertSuccessful()
                ->assertOutputContains(sprintf('127.0.0.1:%d is already in use', $busy))
                ->assertOutputContains('using ')
                ->assertOutputContains('public/hot');
        } finally {
            fclose($listener);
        }

        // The pointer is removed when Vite exits, so templates never follow a
        // server that is no longer running.
        self::assertFileDoesNotExist($project.'/public/hot');
    }

    public function test_install_reuses_the_recorded_profile_when_none_is_given(): void
    {
        $project = $this->tempDirectory('sahil-ui-cli');

        $this->runCommand(['ui:install', 'bootstrap', '--path='.$project, '--no-install'])
            ->assertSuccessful();

        $this->runCommand(['ui:install', '--path='.$project, '--no-install', '--dry-run'])
            ->assertSuccessful()
            ->assertOutputContains('UI profile: Bootstrap 5');
    }

    public function test_conflict_is_reported_and_nothing_is_written(): void
    {
        $project = $this->tempDirectory('sahil-ui-cli');
        file_put_contents($project.'/vite.config.js', "// mine\n");

        $this->runCommand(['ui:install', 'tailwind', '--path='.$project, '--no-install'])
            ->assertFailed()
            ->assertOutputContains('Nothing was written')
            ->assertOutputContains('vite.config.js')
            ->assertOutputContains('--merge')
            ->assertOutputContains('--overwrite');

        self::assertSame("// mine\n", (string) file_get_contents($project.'/vite.config.js'));
        self::assertFileDoesNotExist($project.'/package.json');
    }

    public function test_merge_preserves_and_overwrite_backs_up(): void
    {
        $project = $this->tempDirectory('sahil-ui-cli');
        file_put_contents($project.'/vite.config.js', "// mine\n");
        file_put_contents($project.'/package.json', "{\"name\":\"existing\",\"scripts\":{\"dev\":\"custom\"}}\n");

        $this->runCommand(['ui:install', 'tailwind', '--path='.$project, '--no-install', '--merge'])
            ->assertSuccessful()
            ->assertOutputContains('preserved vite.config.js');

        self::assertSame("// mine\n", (string) file_get_contents($project.'/vite.config.js'));
        self::assertStringContainsString('"name": "existing"', (string) file_get_contents($project.'/package.json'));
        self::assertStringContainsString('"dev": "custom"', (string) file_get_contents($project.'/package.json'));

        $this->runCommand(['ui:install', 'tailwind', '--path='.$project, '--no-install', '--overwrite'])
            ->assertSuccessful()
            ->assertOutputContains('backed up');

        self::assertStringContainsString('@tailwindcss/vite', (string) file_get_contents($project.'/vite.config.js'));

        $backups = glob($project.'/storage/framework/ui-backups/*/*');
        self::assertIsArray($backups);
        self::assertGreaterThanOrEqual(2, count($backups));
    }

    public function test_missing_prerequisites_explain_how_to_install_node(): void
    {
        $project = $this->tempDirectory('sahil-ui-cli');

        $this->runCommand(
            ['ui:install', 'tailwind', '--path='.$project],
            ['PATH' => '/definitely-not-a-real-path'],
        )
            ->assertFailed()
            ->assertOutputContains('Missing frontend prerequisites')
            ->assertOutputContains('Node.js was not found')
            ->assertOutputContains('https://nodejs.org')
            ->assertOutputContains('--no-install');

        self::assertFileDoesNotExist($project.'/package.json');
    }

    public function test_dependency_install_failure_propagates_the_package_manager_exit_code(): void
    {
        $project = $this->tempDirectory('sahil-ui-cli');
        $bin = $this->tempDirectory('sahil-ui-bin');
        $this->fakeToolchain($bin, installExit: 9);

        $this->runCommand(
            ['ui:install', 'tailwind', '--path='.$project],
            ['PATH' => $bin],
        )
            ->assertExitCode(9)
            ->assertOutputContains('Dependency installation failed')
            ->assertOutputContains('exited 9');

        self::assertFileExists($project.'/package.json');
    }

    public function test_build_failure_propagates_the_package_manager_exit_code(): void
    {
        $project = $this->tempDirectory('sahil-ui-cli');
        $bin = $this->tempDirectory('sahil-ui-bin');
        $this->fakeToolchain($bin, buildExit: 7);

        $this->runCommand(['ui:install', 'tailwind', '--path='.$project, '--no-install'])
            ->assertSuccessful();

        mkdir($project.'/node_modules/vite', 0o700, true);
        file_put_contents($project.'/node_modules/vite/package.json', '{"name":"vite"}');

        $this->runCommand(
            ['ui:build', '--path='.$project],
            ['PATH' => $bin],
        )
            ->assertExitCode(7)
            ->assertOutputContains('asset build failed')
            ->assertOutputContains('exited 7');
    }

    public function test_unknown_profile_and_options_are_usage_errors(): void
    {
        $project = $this->tempDirectory('sahil-ui-cli');

        $this->runCommand(['ui:install', 'svelte', '--path='.$project, '--no-install'])
            ->assertFailed()
            ->assertOutputContains('Unknown profile [svelte]')
            ->assertOutputContains('tailwind');

        $this->runCommand(['ui:status', '--path='.$project, '--made-up'])
            ->assertFailed()
            ->assertOutputContains('unknown option');

        $this->runCommand(['ui:dev', '--path='.$project, '--dry-run', '--port=99999'])
            ->assertFailed()
            ->assertOutputContains('between 1 and 65535');

        $this->runCommand(['ui:install', 'tailwind', 'bootstrap', '--path='.$project, '--no-install'])
            ->assertFailed()
            ->assertOutputContains('unexpected argument');
    }

    public function test_unknown_profile_without_arguments_is_a_usage_error(): void
    {
        $project = $this->tempDirectory('sahil-ui-cli');

        $this->runCommand(['ui:build', '--path='.$project, '--dry-run'])
            ->assertFailed()
            ->assertOutputContains('No profile was given')
            ->assertOutputContains('tailwind');
    }

    /**
     * @return list<string>
     */
    private function files(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $files[] = $item->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function fakeToolchain(string $bin, int $installExit = 0, int $buildExit = 0): void
    {
        file_put_contents($bin.'/node', "#!/bin/sh\necho 'v24.0.0'\n");
        file_put_contents(
            $bin.'/npm',
            <<<SH
                #!/bin/sh
                if [ "\$1" = "install" ]; then
                    echo "install failed" >&2
                    exit {$installExit}
                fi
                if [ "\$1" = "run" ] && [ "\$2" = "build" ]; then
                    echo "build failed" >&2
                    exit {$buildExit}
                fi
                echo '12.0.0'
                SH
        );

        chmod($bin.'/node', 0o755);
        chmod($bin.'/npm', 0o755);
    }
}
