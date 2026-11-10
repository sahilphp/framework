<?php

declare(strict_types=1);

namespace Sahil\Tests\Console;

use PHPUnit\Framework\TestCase;
use Sahil\Console\Application as ConsoleApplication;
use Sahil\Console\CommandOutput;
use Sahil\Console\Commands\ErrorPublishCommand;

final class ErrorPublishCommandTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir().'/sahil-error-publish-'.bin2hex(random_bytes(6));

        if (!mkdir($this->project, 0o700, true) && !is_dir($this->project)) {
            self::fail('Unable to create the temporary project.');
        }
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->project);
    }

    public function test_publishing_writes_every_framework_error_page(): void
    {
        [$exit, $out] = $this->runConsole(['error:publish']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('write  resources/views/errors/404.sahil.php', $out);
        self::assertStringContainsString('write  resources/views/errors/500.sahil.php', $out);
        self::assertStringContainsString('write  resources/views/errors/layout.sahil.php', $out);
        self::assertStringContainsString('Published 12 error page(s)', $out);

        $source = ErrorPublishCommand::sourceDirectory();

        self::assertFileExists($this->project.'/resources/views/errors/404.sahil.php');
        self::assertFileExists($this->project.'/resources/views/errors/4xx.sahil.php');
        self::assertFileExists($this->project.'/resources/views/errors/5xx.sahil.php');
        self::assertStringEqualsFile(
            $source.'/404.sahil.php',
            (string) file_get_contents($this->project.'/resources/views/errors/404.sahil.php'),
        );
    }

    public function test_existing_pages_are_kept_until_force_is_passed(): void
    {
        $this->runConsole(['error:publish']);

        $published = $this->project.'/resources/views/errors/404.sahil.php';
        file_put_contents($published, '<main>my own 404</main>');

        [$exit, $out] = $this->runConsole(['error:publish']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('keep   resources/views/errors/404.sahil.php', $out);
        self::assertStringContainsString('--force', $out);
        self::assertSame('<main>my own 404</main>', (string) file_get_contents($published));

        [$exit, $out] = $this->runConsole(['error:publish', '--force']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('write  resources/views/errors/404.sahil.php', $out);
        self::assertStringNotContainsString('my own 404', (string) file_get_contents($published));
    }

    public function test_a_missing_project_directory_fails_cleanly(): void
    {
        [$exit, , $err] = $this->runConsole(['error:publish', '--path='.$this->project.'/missing']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('does not exist', $err);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function runConsole(array $arguments): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');

        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $exit = (new ConsoleApplication($this->project, new CommandOutput($stdout, $stderr)))->run($arguments);

        rewind($stdout);
        rewind($stderr);

        return [$exit, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($directory);
    }
}
