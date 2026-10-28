<?php

declare(strict_types=1);

namespace Sahil\Tests\Ui;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Temporary project helper for UI command tests.
 */
abstract class UiTestCase extends TestCase
{
    protected string $projectPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectPath = sys_get_temp_dir().'/sahil-ui-test-'.bin2hex(random_bytes(6));

        if (!mkdir($this->projectPath, 0o700, true) && !is_dir($this->projectPath)) {
            self::fail(sprintf('Unable to create temp project [%s].', $this->projectPath));
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectPath);

        parent::tearDown();
    }

    protected function write(string $relative, string $contents): void
    {
        $path = $this->projectPath.'/'.$relative;

        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0o700, true) && !is_dir(dirname($path))) {
            self::fail(sprintf('Unable to create directory [%s].', dirname($path)));
        }

        file_put_contents($path, $contents);
    }

    protected function read(string $relative): string
    {
        $contents = file_get_contents($this->projectPath.'/'.$relative);

        self::assertNotFalse($contents, sprintf('Unable to read [%s].', $relative));

        return $contents;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }
}
