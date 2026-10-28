<?php

declare(strict_types=1);

namespace Sahil\Tests\Storage;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sahil\Storage\LocalDriver;
use Sahil\Storage\StorageException;

final class LocalDriverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/sahil_storage_test_'.bin2hex(random_bytes(8));

        if (!@mkdir($this->root, 0o700, true) && !is_dir($this->root)) {
            throw new RuntimeException(sprintf('Failed to create temp directory: %s', $this->root));
        }
    }

    protected function tearDown(): void
    {
        $this->recursiveRemove($this->root);
    }

    public function test_put_get_delete_round_trip(): void
    {
        $driver = new LocalDriver($this->root);

        $driver->put('folder/file.txt', 'hello world');

        $this->assertTrue($driver->has('folder/file.txt'));
        $this->assertSame('hello world', $driver->get('folder/file.txt'));
        $this->assertSame(11, $driver->size('folder/file.txt'));
        $this->assertTrue($driver->delete('folder/file.txt'));
        $this->assertFalse($driver->has('folder/file.txt'));
        $this->assertFalse($driver->delete('folder/file.txt'));
    }

    public function test_put_overwrites_existing_file(): void
    {
        $driver = new LocalDriver($this->root);

        $driver->put('file.txt', 'first');
        $driver->put('file.txt', 'second');

        $this->assertSame('second', $driver->get('file.txt'));
    }

    public function test_reading_missing_file_throws(): void
    {
        $driver = new LocalDriver($this->root);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('File not found');

        $driver->get('does-not-exist.txt');
    }

    public function test_mime_type_is_detected_server_side(): void
    {
        $driver = new LocalDriver($this->root);

        // Client-supplied name claims an image; content is plain text.
        $driver->put('photo.jpg', 'this is not an image');

        $this->assertSame('text/plain', $driver->mimeType('photo.jpg'));
    }

    public function test_files_have_no_execute_permissions(): void
    {
        $driver = new LocalDriver($this->root);

        $driver->put('file.txt', 'data');

        $perms = fileperms($this->root.'/file.txt') & 0o777;

        $this->assertSame(0o600, $perms, 'Stored files must not be executable.');
    }

    public function test_directories_have_restricted_permissions(): void
    {
        $driver = new LocalDriver($this->root);

        $driver->makeDirectory('nested/folder', true);

        $perms = fileperms($this->root.'/nested/folder') & 0o777;

        $this->assertSame(0o700, $perms);
    }

    public function test_make_directory_and_list_contents(): void
    {
        $driver = new LocalDriver($this->root);

        $driver->makeDirectory('photos', true);
        $driver->put('photos/a.jpg', "\xFF\xD8\xFF\xE0JFIF");
        $driver->put('photos/b.txt', 'text');
        $driver->makeDirectory('photos/album', true);

        $contents = $driver->listContents('photos');

        $this->assertSame(
            ['photos/a.jpg', 'photos/album', 'photos/b.txt'],
            array_map(static fn (array $entry): string => $entry['path'], $contents)
        );
        $this->assertSame('file', $contents[0]['type']);
        $this->assertSame('image/jpeg', $contents[0]['mime_type']);
        $this->assertSame('dir', $contents[1]['type']);
        $this->assertNull($contents[1]['mime_type']);
        $this->assertSame('file', $contents[2]['type']);
        $this->assertSame('text/plain', $contents[2]['mime_type']);
    }

    public function test_list_contents_of_root(): void
    {
        $driver = new LocalDriver($this->root);

        $driver->put('top.txt', 'data');

        $paths = array_map(
            static fn (array $entry): string => $entry['path'],
            $driver->listContents(),
        );

        $this->assertSame(['top.txt'], $paths);
    }

    public function test_delete_directory_removes_contents_recursively(): void
    {
        $driver = new LocalDriver($this->root);

        $driver->makeDirectory('deep/one/two', true);
        $driver->put('deep/one/two/file.txt', 'data');
        $driver->put('deep/one/other.txt', 'data');

        $driver->deleteDirectory('deep');

        $this->assertFalse($driver->has('deep'));
    }

    public function test_copy_and_move_stay_inside_root(): void
    {
        $driver = new LocalDriver($this->root);

        $driver->put('source.txt', 'data');
        $driver->copy('source.txt', 'copied.txt');
        $this->assertSame('data', $driver->get('copied.txt'));
        $this->assertTrue($driver->has('source.txt'));

        $driver->move('source.txt', 'moved.txt');
        $this->assertSame('data', $driver->get('moved.txt'));
        $this->assertFalse($driver->has('source.txt'));
    }
    public function test_absolute_path_is_rejected(): void
    {
        $driver = new LocalDriver($this->root);

        $this->expectException(StorageException::class);
        $driver->put('/etc/passwd', 'x');
    }

    public function test_parent_traversal_is_rejected(): void
    {
        $driver = new LocalDriver($this->root);

        $this->expectException(StorageException::class);
        $driver->put('../../../etc/passwd', 'x');
    }

    public function test_nested_traversal_is_rejected(): void
    {
        $driver = new LocalDriver($this->root);

        $this->expectException(StorageException::class);
        $driver->put('folder/../../secret.txt', 'x');
    }

    public function test_dot_segments_are_rejected(): void
    {
        $driver = new LocalDriver($this->root);

        $this->expectException(StorageException::class);
        $driver->put('./file.txt', 'x');
    }

    public function test_empty_and_null_paths_are_rejected(): void
    {
        $driver = new LocalDriver($this->root);

        $this->expectException(StorageException::class);
        $driver->put('', 'x');

        $this->expectException(StorageException::class);
        $driver->put("a\0b", 'x');
    }

    public function test_double_slash_segments_are_rejected(): void
    {
        $driver = new LocalDriver($this->root);

        $this->expectException(StorageException::class);
        $driver->put('folder//file.txt', 'x');
    }

    public function test_overlong_segment_is_rejected(): void
    {
        $driver = new LocalDriver($this->root);

        $this->expectException(StorageException::class);
        $driver->put(str_repeat('a', 256).'/file.txt', 'x');
    }

    public function test_control_characters_are_rejected(): void
    {
        $driver = new LocalDriver($this->root);

        $this->expectException(StorageException::class);
        $driver->put("file\x1b.txt", 'x');
    }
    public function test_symlink_is_never_followed_for_read(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'sahil_out');
        file_put_contents($outside, 'secret');

        try {
            @symlink($outside, $this->root.'/link');

            $driver = new LocalDriver($this->root);

            $this->expectException(StorageException::class);
            $driver->get('link');

            $this->assertFalse($driver->has('link'));
        } finally {
            @unlink($outside);
        }
    }

    public function test_symlink_is_never_followed_for_write(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'sahil_out');

        try {
            @symlink($outside, $this->root.'/link');

            $driver = new LocalDriver($this->root);

            $this->expectException(StorageException::class);
            $driver->put('link', 'overwrite-outside');
        } finally {
            @unlink($outside);
        }
    }

    public function test_symlink_inside_delete_directory_is_rejected(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'sahil_out');

        try {
            @mkdir($this->root.'/sub');
            @symlink($outside, $this->root.'/sub/link');

            $driver = new LocalDriver($this->root);

            $this->expectException(StorageException::class);
            $driver->deleteDirectory('sub');
        } finally {
            @unlink($outside);
        }
    }

    public function test_list_contents_skips_symlinks(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'sahil_out');

        try {
            @symlink($outside, $this->root.'/link');

            $driver = new LocalDriver($this->root);
            $driver->put('real.txt', 'data');

            $contents = $driver->listContents();

            $this->assertSame(['real.txt'], array_map(
                static fn (array $entry): string => $entry['path'],
                $contents,
            ));
        } finally {
            @unlink($outside);
        }
    }

    public function test_non_writable_root_is_rejected(): void
    {
        $unwritable = sys_get_temp_dir().'/sahil_unwritable_'.bin2hex(random_bytes(8));
        @mkdir($unwritable, 0o500, true);

        try {
            $this->expectException(StorageException::class);
            new LocalDriver($unwritable);
        } finally {
            @chmod($unwritable, 0o700);
            $this->recursiveRemove($unwritable);
        }
    }

    public function test_public_driver_exposes_url_and_permissions(): void
    {
        $driver = new LocalDriver(
            $this->root.'/public',
            isPublic: true,
            fileMode: 0o644,
            directoryMode: 0o755,
            publicUrl: 'https://example.com/storage',
        );

        $this->assertTrue($driver->isPublic());
        $this->assertSame('https://example.com/storage/folder/file.txt', $driver->url('folder/file.txt'));
        $this->assertSame('https://example.com/storage/file%20name.txt', $driver->url('file name.txt'));

        $driver->put('folder/file.txt', 'data');

        $this->assertSame(0o644, fileperms($this->root.'/public/folder/file.txt') & 0o777);
        $this->assertSame(0o755, fileperms($this->root.'/public/folder') & 0o777);
    }

    public function test_private_driver_has_no_public_url(): void
    {
        $driver = new LocalDriver($this->root);

        $this->assertFalse($driver->isPublic());
        $this->assertNull($driver->url('file.txt'));
    }

    public function test_public_url_rejects_unsafe_paths(): void
    {
        $driver = new LocalDriver(
            $this->root.'/public',
            isPublic: true,
            publicUrl: 'https://example.com/storage',
        );

        $this->expectException(StorageException::class);

        $driver->url('../secret.txt');
    }

    public function test_delete_directory_refuses_the_storage_root(): void
    {
        $driver = new LocalDriver($this->root);

        $this->expectException(StorageException::class);

        $driver->deleteDirectory('');
    }

    /** @param string $path */
    private function recursiveRemove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }
}
