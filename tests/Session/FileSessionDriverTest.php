<?php

declare(strict_types=1);

namespace Sahil\Tests\Session;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sahil\Session\FileSessionDriver;

final class FileSessionDriverTest extends TestCase
{
    private string $tempPath;

    protected function setUp(): void
    {
        $this->tempPath = sys_get_temp_dir().'/sahil_session_test_'.bin2hex(random_bytes(8));

        if (!mkdir($this->tempPath, 0o700, true) && !is_dir($this->tempPath)) {
            throw new RuntimeException("Failed to create temp directory: {$this->tempPath}");
        }
    }

    protected function tearDown(): void
    {
        $this->cleanupDirectory($this->tempPath);
    }

    public function test_driver_generates_valid_session_id(): void
    {
        $driver = new FileSessionDriver($this->tempPath);

        $id = $driver->generateId();

        $this->assertTrue($driver->isValidId($id));
        $this->assertSame(40, strlen($id));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $id);
    }

    public function test_driver_rejects_invalid_session_id(): void
    {
        $driver = new FileSessionDriver($this->tempPath);

        $this->assertFalse($driver->isValidId('invalid'));
        $this->assertFalse($driver->isValidId('12345'));
        $this->assertFalse($driver->isValidId(str_repeat('a', 39)));
        $this->assertFalse($driver->isValidId(str_repeat('a', 41)));
    }

    public function test_driver_reads_and_writes_session_data(): void
    {
        $driver = new FileSessionDriver($this->tempPath);
        $id = $driver->generateId();

        $data = ['user_id' => 123, 'username' => 'testuser'];
        $driver->write($id, $data);

        $read = $driver->read($id);

        $this->assertSame($data, $read);
    }

    public function test_driver_returns_empty_array_for_nonexistent_session(): void
    {
        $driver = new FileSessionDriver($this->tempPath);
        $id = $driver->generateId();

        $read = $driver->read($id);

        $this->assertSame([], $read);
    }

    public function test_driver_destroys_session(): void
    {
        $driver = new FileSessionDriver($this->tempPath);
        $id = $driver->generateId();

        $driver->write($id, ['test' => 'data']);
        $this->assertSame(['test' => 'data'], $driver->read($id));

        $driver->destroy($id);
        $this->assertSame([], $driver->read($id));
    }

    public function test_driver_garbage_collects_expired_sessions(): void
    {
        $driver = new FileSessionDriver($this->tempPath);

        // Create an old session
        $oldId = $driver->generateId();
        $driver->write($oldId, ['old' => 'data']);

        // Create a new session
        $newId = $driver->generateId();
        $driver->write($newId, ['new' => 'data']);

        // Touch the old file to make it appear old
        $oldFile = $this->tempPath.'/'.$oldId;
        touch($oldFile, time() - 3600); // 1 hour ago

        // Run garbage collection with 30 minute lifetime
        $driver->gc(1800);

        // Old session should be gone
        $this->assertSame([], $driver->read($oldId));

        // New session should still exist
        $this->assertSame(['new' => 'data'], $driver->read($newId));
    }

    public function test_driver_uses_json_serialization(): void
    {
        $driver = new FileSessionDriver($this->tempPath);
        $id = $driver->generateId();

        $data = [
            'string' => 'value',
            'number' => 42,
            'boolean' => true,
            'null' => null,
            'array' => ['nested' => 'data'],
        ];

        $driver->write($id, $data);
        $read = $driver->read($id);

        $this->assertSame($data, $read);
    }

    public function test_driver_handles_concurrent_reads(): void
    {
        $driver = new FileSessionDriver($this->tempPath);
        $id = $driver->generateId();

        $data = ['counter' => 0];
        $driver->write($id, $data);

        // Multiple reads should succeed
        for ($i = 0; $i < 5; $i++) {
            $read = $driver->read($id);
            $this->assertSame($data, $read);
        }
    }

    public function test_driver_overwrites_existing_data(): void
    {
        $driver = new FileSessionDriver($this->tempPath);
        $id = $driver->generateId();

        $driver->write($id, ['old' => 'data']);
        $driver->write($id, ['new' => 'data']);

        $read = $driver->read($id);

        $this->assertSame(['new' => 'data'], $read);
    }

    public function test_driver_throws_on_invalid_id_for_read(): void
    {
        $driver = new FileSessionDriver($this->tempPath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid session ID format');

        $driver->read('invalid-id');
    }

    public function test_driver_throws_on_invalid_id_for_write(): void
    {
        $driver = new FileSessionDriver($this->tempPath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid session ID format');

        $driver->write('invalid-id', ['data']);
    }

    public function test_driver_throws_on_invalid_id_for_destroy(): void
    {
        $driver = new FileSessionDriver($this->tempPath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid session ID format');

        $driver->destroy('invalid-id');
    }

    public function test_driver_generates_unique_ids(): void
    {
        $driver = new FileSessionDriver($this->tempPath);

        $ids = [];
        for ($i = 0; $i < 100; $i++) {
            $ids[] = $driver->generateId();
        }

        $uniqueIds = array_unique($ids);

        $this->assertCount(100, $uniqueIds, 'All generated IDs should be unique');
    }

    private function cleanupDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = glob($path.'/*');
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($path);
    }
}
