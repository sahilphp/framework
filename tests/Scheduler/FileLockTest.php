<?php

declare(strict_types=1);

namespace Sahil\Tests\Scheduler;

use PHPUnit\Framework\TestCase;
use Sahil\Scheduler\FileLock;
use Sahil\Scheduler\SchedulerException;

final class FileLockTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/sahil_lock_'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        foreach (glob($this->directory.'/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($this->directory);
    }

    public function test_a_second_holder_cannot_take_the_same_lock(): void
    {
        $first = new FileLock($this->directory);
        $second = new FileLock($this->directory);

        self::assertTrue($first->acquire('nightly'));
        self::assertTrue($first->holds('nightly'));

        self::assertFalse($second->acquire('nightly'), 'another process holds it');
        self::assertFalse($second->holds('nightly'));
    }

    public function test_the_lock_is_reusable_after_release(): void
    {
        $first = new FileLock($this->directory);
        $second = new FileLock($this->directory);

        self::assertTrue($first->acquire('nightly'));
        self::assertFalse($second->acquire('nightly'));

        $first->release('nightly');

        self::assertTrue($second->acquire('nightly'));
    }

    public function test_different_names_are_independent(): void
    {
        $lock = new FileLock($this->directory);

        self::assertTrue($lock->acquire('one'));
        self::assertTrue($lock->acquire('two'));
        self::assertFalse($lock->acquire('one'), 'the same name is not re-entrant');
    }

    public function test_releasing_an_unheld_lock_is_a_no_op(): void
    {
        $lock = new FileLock($this->directory);

        $lock->release('never-taken');

        self::assertFalse($lock->holds('never-taken'));
    }

    public function test_lock_names_are_hashed_so_they_cannot_escape_the_directory(): void
    {
        $lock = new FileLock($this->directory);

        self::assertTrue($lock->acquire('../../escape'));

        $files = glob($this->directory.'/*.lock') ?: [];

        self::assertCount(1, $files);
        self::assertSame($this->directory, dirname($files[0]));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}\.lock$/', basename($files[0]));
    }

    public function test_the_lock_directory_is_created_with_restricted_permissions(): void
    {
        new FileLock($this->directory);

        self::assertDirectoryExists($this->directory);
        self::assertSame(0o700, fileperms($this->directory) & 0o777);
    }

    public function test_an_unwritable_location_is_rejected(): void
    {
        $this->expectException(SchedulerException::class);

        new FileLock('/proc/definitely-not-writable/schedule');
    }
}
