<?php

declare(strict_types=1);

namespace Sahil\Tests\Migrations;

/**
 * Throwaway workspace helper: fixture migrations/seeders are written to a
 * unique directory under the system temp dir and removed in tearDown, so
 * tests never touch the project's `database/` directory.
 */
trait TempDirectory
{
    /** @var list<string> */
    private array $tempDirectories = [];

    private function makeTempDirectory(): string
    {
        $directory = sys_get_temp_dir().'/sahil-test-'.bin2hex(random_bytes(6));

        if (!mkdir($directory, 0o777, true) && !is_dir($directory)) {
            self::fail(sprintf('Unable to create temp directory [%s].', $directory));
        }

        $this->tempDirectories[] = $directory;

        return $directory;
    }

    private function writeFixture(string $directory, string $name, string $contents): string
    {
        $path = $directory.'/'.$name;

        file_put_contents($path, $contents);

        return $path;
    }

    private function removeTempDirectories(): void
    {
        foreach ($this->tempDirectories as $directory) {
            self::removeDirectory($directory);
        }

        $this->tempDirectories = [];
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);

        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $path = $directory.'/'.$entry;

                is_dir($path) ? self::removeDirectory($path) : @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
