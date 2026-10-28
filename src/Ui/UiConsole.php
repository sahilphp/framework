<?php

declare(strict_types=1);

namespace Sahil\Ui;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Sahil\Theme\ThemeException;
use Sahil\Theme\ThemeInstaller;
use SplFileInfo;
use Throwable;

/**
 * CLI surface for UI installation and assets.
 *
 * Commands are intentionally usable before the application boots: they only
 * need the project directory, not configuration or a database connection.
 */
final class UiConsole
{
    /** @var list<string> */
    public const COMMANDS = ['ui:install', 'ui:dev', 'ui:build', 'ui:status', 'ui:theme'];

    public function __construct(
        private readonly ?UiInstaller $installer = null,
        private readonly ?UiStatus $status = null,
        private readonly ?ProcessRunner $runner = null,
        private readonly ?ThemeInstaller $themeInstaller = null,
    ) {
    }

    /**
     * @param list<string> $args arguments after the command name
     */
    public function run(string $command, array $args, string $basePath): int
    {
        try {
            $options = $this->parseOptions($args, $command);
            $projectPath = $this->projectPath($basePath, $options['path']);

            return match ($command) {
                'ui:install' => $this->install($projectPath, $options),
                'ui:dev' => $this->dev($projectPath, $options),
                'ui:build' => $this->build($projectPath, $options),
                'ui:status' => $this->status($projectPath, $options),
                'ui:theme' => $this->theme($projectPath, $options),
                default => throw new UiException(sprintf('Unknown UI command [%s].', $command)),
            };
        } catch (ConflictException $conflict) {
            $this->error(sprintf('%s: %s', $command, $conflict->getMessage()));
            $this->error('Nothing was written.');

            foreach ($conflict->conflicts as $file) {
                $this->error('  - '.$file);
            }

            $this->error('Choose a conflict policy and re-run:');
            $this->error('  --merge      keep existing files, merge package.json and .gitignore');
            $this->error('  --overwrite  replace managed files (originals are backed up)');

            return 1;
        } catch (ProcessFailureException $failure) {
            $this->error(sprintf('%s: %s', $command, $failure->getMessage()));

            return $failure->processExitCode;
        } catch (UiException|ThemeException $failure) {
            $this->error(sprintf('%s: %s', $command, $failure->getMessage()));

            return 1;
        } catch (Throwable $unexpected) {
            $this->error(sprintf('%s: %s', $command, $unexpected->getMessage()));

            if (getenv('SAHIL_DEBUG') === '1') {
                $this->error($unexpected::class);
            }

            return 1;
        }
    }

    /**
     * @param array{positional: list<string>, path: ?string, profile: ?string, source: ?string, dry_run: bool, merge: bool, overwrite: bool, no_install: bool, json: bool, clean: bool, host: ?string, port: ?string} $options
     */
    private function install(string $basePath, array $options): int
    {
        if (count($options['positional']) > 1) {
            throw new UiException(sprintf(
                'ui:install: unexpected argument [%s].',
                implode(' ', array_slice($options['positional'], 1))
            ));
        }

        $profile = $this->resolveProfile($basePath, $options['profile'], $options['positional'][0] ?? null);

        $installOptions = new InstallOptions(
            dryRun: $options['dry_run'],
            merge: $options['merge'],
            overwrite: $options['overwrite'],
            installDependencies: !$options['no_install'],
        );

        $report = $this->installer()->install($basePath, $profile, $installOptions);

        foreach ($report->lines() as $line) {
            $this->line($line);
        }

        return 0;
    }

    /**
     * @param array{positional: list<string>, path: ?string, profile: ?string, source: ?string, dry_run: bool, merge: bool, overwrite: bool, no_install: bool, json: bool, clean: bool, host: ?string, port: ?string} $options
     */
    private function build(string $basePath, array $options): int
    {
        $this->rejectPositional($options, 'ui:build');

        $profile = $this->resolveProfile($basePath, $options['profile'], null);
        $toolchain = $this->readyToolchain($basePath, $options['dry_run']);
        $script = $this->packageScript($basePath, 'build');

        if ($script === null) {
            throw new UiException(
                'package.json has no "build" script. Run `bin/sahil ui:install --merge` '
                .'to add the generated scripts (existing scripts are preserved).'
            );
        }

        if (!$options['dry_run']) {
            $this->ensureDependencies($basePath, $toolchain);
        }

        if ($options['clean']) {
            $this->line($options['dry_run']
                ? 'would remove public/build'
                : 'removing public/build');

            if (!$options['dry_run']) {
                $this->removeBuildDirectory($basePath);
            }
        }

        $command = [...$this->managerCommand($toolchain, $options['dry_run']), 'run', 'build'];

        if ($options['dry_run']) {
            $this->line('would run: '.$this->describe($command));

            return 0;
        }

        $this->line('building UI assets ('.$profile->label().') with '.$this->describe($command));
        $result = $this->runner()->run($command, $basePath, ['NODE_ENV' => 'production']);

        $this->emitProcessOutput($result);

        if (!$result->successful()) {
            $this->error(sprintf(
                'ui:build: asset build failed (%s exited %d).',
                $result->describe(),
                $result->exitCode
            ));

            return $result->exitCode > 0 ? $result->exitCode : 1;
        }

        $missing = [];

        foreach ($profile->expectedAssets() as $asset) {
            if (!is_file($basePath.'/'.$asset)) {
                $missing[] = $asset;
            }
        }

        if ($missing !== []) {
            $this->error('ui:build: the build command succeeded but expected output is missing:');
            $this->error('  - '.implode("\n  - ", $missing));
            $this->error('If you customised vite.config.js, check that its output names match the profile.');

            return 1;
        }

        $this->line('built:');

        foreach ($profile->expectedAssets() as $asset) {
            $this->line(sprintf('  %s (%s)', $asset, $this->humanBytes((int) filesize($basePath.'/'.$asset))));
        }

        return 0;
    }

    /**
     * @param array{positional: list<string>, path: ?string, profile: ?string, source: ?string, dry_run: bool, merge: bool, overwrite: bool, no_install: bool, json: bool, clean: bool, host: ?string, port: ?string} $options
     */
    private function dev(string $basePath, array $options): int
    {
        $this->rejectPositional($options, 'ui:dev');

        $profile = $this->resolveProfile($basePath, $options['profile'], null);
        $toolchain = $this->readyToolchain($basePath, $options['dry_run']);
        $script = $this->packageScript($basePath, 'dev');

        if ($script === null) {
            throw new UiException(
                'package.json has no "dev" script. Run `bin/sahil ui:install --merge` '
                .'to add the generated scripts (existing scripts are preserved).'
            );
        }

        $command = [...$this->managerCommand($toolchain, $options['dry_run']), 'run', 'dev'];

        if ($options['host'] !== null || $options['port'] !== null) {
            $command[] = '--';

            if ($options['host'] !== null) {
                $command[] = '--host='.$options['host'];
            }

            if ($options['port'] !== null) {
                $command[] = '--port='.$options['port'];
            }
        }

        if (!$options['dry_run']) {
            $this->ensureDependencies($basePath, $toolchain);
        }

        if ($options['dry_run']) {
            $this->line('would run: '.$this->describe($command));

            return 0;
        }

        $this->line('starting UI development server ('.$profile->label().') with '.$this->describe($command));
        $this->line('press Ctrl+C to stop');

        $result = $this->runner()->runInteractive($command, $basePath);

        return $result->exitCode === 0 ? 0 : ($result->exitCode > 0 ? $result->exitCode : 1);
    }

    /**
     * @param array{positional: list<string>, path: ?string, profile: ?string, source: ?string, dry_run: bool, merge: bool, overwrite: bool, no_install: bool, json: bool, clean: bool, host: ?string, port: ?string} $options
     */
    private function status(string $basePath, array $options): int
    {
        $this->rejectPositional($options, 'ui:status');

        $report = $this->statusInspector()->inspect($basePath);

        if ($options['json']) {
            $json = json_encode(
                $report->toArray(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );

            if ($json === false) {
                throw new UiException('Unable to encode the status report as JSON.');
            }

            $this->line($json);

            return $report->ok() ? 0 : 1;
        }

        foreach ($report->lines() as $line) {
            $this->line($line);
        }

        return $report->ok() ? 0 : 1;
    }

    /**
     * @param array{positional: list<string>, path: ?string, profile: ?string, source: ?string, dry_run: bool, merge: bool, overwrite: bool, no_install: bool, json: bool, clean: bool, host: ?string, port: ?string} $options
     */
    private function theme(string $basePath, array $options): int
    {
        if (count($options['positional']) > 1) {
            throw new UiException(sprintf(
                'ui:theme: unexpected argument [%s].',
                implode(' ', array_slice($options['positional'], 1))
            ));
        }

        $profile = $this->resolveProfile($basePath, $options['profile'], $options['positional'][0] ?? null);
        $report = $this->themeInstaller()->install(
            $basePath,
            $profile,
            $options['source'],
            $options['dry_run'],
        );

        if ($options['json']) {
            $json = json_encode(
                $report->toArray(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );

            if ($json === false) {
                throw new UiException('Unable to encode the theme report as JSON.');
            }

            $this->line($json);

            return 0;
        }

        foreach ($report->lines() as $line) {
            $this->line($line);
        }

        return 0;
    }

    /**
     * @param list<string> $args
     *
     * @return array{positional: list<string>, path: ?string, profile: ?string, source: ?string, dry_run: bool, merge: bool, overwrite: bool, no_install: bool, json: bool, clean: bool, host: ?string, port: ?string}
     */
    private function parseOptions(array $args, string $command): array
    {
        $options = [
            'positional' => [],
            'path' => null,
            'profile' => null,
            'source' => null,
            'dry_run' => false,
            'merge' => false,
            'overwrite' => false,
            'no_install' => false,
            'json' => false,
            'clean' => false,
            'host' => null,
            'port' => null,
        ];

        for ($index = 0; $index < count($args); $index++) {
            $arg = $args[$index];

            if (!str_starts_with($arg, '--')) {
                $options['positional'][] = $arg;

                continue;
            }

            $name = $arg;
            $value = null;

            if (str_contains($arg, '=')) {
                [$name, $value] = explode('=', $arg, 2);
            }

            switch ($name) {
                case '--dry-run':
                    $options['dry_run'] = true;

                    break;
                case '--merge':
                    $options['merge'] = true;

                    break;
                case '--overwrite':
                case '--force':
                    $options['overwrite'] = true;

                    break;
                case '--no-install':
                    $options['no_install'] = true;

                    break;
                case '--json':
                    $options['json'] = true;

                    break;
                case '--clean':
                    $options['clean'] = true;

                    break;
                case '--path':
                case '--profile':
                case '--source':
                case '--host':
                case '--port':
                    if ($value === null) {
                        $next = $args[$index + 1] ?? null;

                        if ($next === null || str_starts_with($next, '--')) {
                            throw new UiException(sprintf('%s: %s requires a value.', $command, $name));
                        }

                        $value = $next;
                        $index++;
                    }

                    $key = substr($name, 2);
                    $value = trim($value);

                    if ($value === '') {
                        throw new UiException(sprintf('%s: %s requires a non-empty value.', $command, $name));
                    }

                    $options[$key] = $value;

                    break;
                default:
                    throw new UiException(sprintf('%s: unknown option [%s].', $command, $name));
            }
        }

        if ($options['port'] !== null) {
            $port = filter_var($options['port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);

            if ($port === false) {
                throw new UiException('--port must be an integer between 1 and 65535.');
            }

            $options['port'] = (string) $port;
        }

        return $options;
    }

    private function resolveProfile(string $basePath, ?string $explicit, ?string $positional): Profile
    {
        if ($explicit !== null && $positional !== null) {
            $explicitProfile = Profile::resolve($explicit);
            $positionalProfile = Profile::resolve($positional);

            if ($explicitProfile !== $positionalProfile) {
                throw new UiException(sprintf(
                    'Conflicting profiles [%s] and [%s]. Pass only one.',
                    $positional,
                    $explicit
                ));
            }
        }

        $name = $explicit ?? $positional;

        if ($name !== null) {
            $profile = Profile::resolve($name);

            if ($profile === null) {
                throw new UiException(sprintf(
                    "Unknown profile [%s].\nAvailable profiles:\n%s",
                    $name,
                    $this->profileChoices()
                ));
            }

            return $profile;
        }

        [$detected] = $this->statusInspector()->detectProfile($basePath);

        if ($detected !== null) {
            return $detected;
        }

        throw new UiException(
            "No profile was given and none is recorded in .sahil-ui.json.\nAvailable profiles:\n"
            .$this->profileChoices()
        );
    }

    private function profileChoices(): string
    {
        $lines = [];

        foreach (Profile::cases() as $profile) {
            $lines[] = sprintf('  %-11s %s', $profile->value, $profile->description());
        }

        return implode("\n", $lines);
    }

    /**
     * @param array{positional: list<string>, path: ?string, profile: ?string, source: ?string, dry_run: bool, merge: bool, overwrite: bool, no_install: bool, json: bool, clean: bool, host: ?string, port: ?string} $options
     */
    private function rejectPositional(array $options, string $command): void
    {
        if ($options['positional'] !== []) {
            throw new UiException(sprintf(
                '%s: unexpected argument [%s].',
                $command,
                implode(' ', $options['positional'])
            ));
        }
    }

    private function readyToolchain(string $basePath, bool $allowMissing): Toolchain
    {
        $toolchain = (new ToolchainDetector())->detect($basePath);

        if (!$toolchain->isReady() && !$allowMissing) {
            throw new UiException(
                "Missing frontend prerequisites:\n  - "
                .implode("\n  - ", $toolchain->issues())
            );
        }

        return $toolchain;
    }

    private function ensureDependencies(string $basePath, Toolchain $toolchain): void
    {
        if (is_file($basePath.'/node_modules/vite/package.json')) {
            return;
        }

        throw new UiException(sprintf(
            'Frontend dependencies are not installed (node_modules/vite is missing).%s'
            .'Run `bin/sahil ui:install` or `%s install`.',
            PHP_EOL,
            $toolchain->packageManager ?? 'npm'
        ));
    }

    /**
     * @return list<string>
     */
    private function managerCommand(Toolchain $toolchain, bool $allowFallback): array
    {
        if ($toolchain->hasPackageManager()) {
            return $toolchain->command();
        }

        if ($allowFallback) {
            return [$toolchain->packageManager ?? 'npm'];
        }

        return $toolchain->command();
    }

    private function packageScript(string $basePath, string $script): ?string
    {
        $path = $basePath.'/package.json';

        if (!is_file($path)) {
            throw new UiException('package.json is missing. Run `bin/sahil ui:install` first.');
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (!is_array($decoded) || !isset($decoded['scripts']) || !is_array($decoded['scripts'])) {
            return null;
        }

        $value = $decoded['scripts'][$script] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function removeBuildDirectory(string $basePath): void
    {
        $target = $basePath.'/public/build';

        if (!is_dir($target)) {
            return;
        }

        $resolved = realpath($target);
        $root = realpath($basePath);

        if ($resolved === false || $root === false || !str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)) {
            throw new UiException(sprintf('Refusing to remove [%s]: it is outside the project.', $target));
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            $path = $item->getPathname();

            if ($item->isDir() && !$item->isLink()) {
                if (!rmdir($path)) {
                    throw new UiException(sprintf('Unable to remove directory [%s].', $path));
                }
            } elseif (!unlink($path)) {
                throw new UiException(sprintf('Unable to remove file [%s].', $path));
            }
        }

        if (!rmdir($resolved)) {
            throw new UiException(sprintf('Unable to remove directory [%s].', $resolved));
        }
    }

    private function projectPath(string $basePath, ?string $option): string
    {
        $path = $option ?? $basePath;

        if (
            !str_starts_with($path, DIRECTORY_SEPARATOR)
            && preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1
        ) {
            $path = rtrim($basePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;
        }

        return $path;
    }

    /**
     * @param list<string> $command
     */
    private function describe(array $command): string
    {
        return (new ProcessResult($command, 0))->describe();
    }

    private function emitProcessOutput(ProcessResult $result): void
    {
        $stdout = trim($result->stdout);
        $stderr = trim($result->stderr);

        if ($stdout !== '') {
            $this->line($stdout);
        }

        if ($stderr !== '') {
            $this->error($stderr);
        }
    }

    private function installer(): UiInstaller
    {
        return $this->installer ?? new UiInstaller();
    }

    private function statusInspector(): UiStatus
    {
        return $this->status ?? new UiStatus();
    }

    private function runner(): ProcessRunner
    {
        return $this->runner ?? new ProcessRunner();
    }

    private function themeInstaller(): ThemeInstaller
    {
        return $this->themeInstaller ?? new ThemeInstaller();
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return sprintf('%.1f kB', $bytes / 1024);
        }

        return sprintf('%.1f MB', $bytes / (1024 * 1024));
    }

    private function line(string $line = ''): void
    {
        fwrite(STDOUT, $line.PHP_EOL);
    }

    private function error(string $line): void
    {
        fwrite(STDERR, $line.PHP_EOL);
    }
}
