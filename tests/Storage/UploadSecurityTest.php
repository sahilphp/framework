<?php

declare(strict_types=1);

namespace Sahil\Tests\Storage;

use PHPUnit\Framework\TestCase;
use Sahil\Config\Repository;
use Sahil\Storage\StorageManager;
use Sahil\Storage\StoredFile;
use Sahil\Storage\UploadException;

final class UploadSecurityTest extends TestCase
{
    private string $privateRoot;
    private string $publicRoot;
    private array $previousFiles = [];
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->previousFiles = $_FILES ?? [];
        $_FILES = [];

        $this->privateRoot = sys_get_temp_dir().'/sahil_upload_private_'.bin2hex(random_bytes(8));
        $this->publicRoot = sys_get_temp_dir().'/sahil_upload_public_'.bin2hex(random_bytes(8));
        @mkdir($this->privateRoot, 0o700, true);
        @mkdir($this->publicRoot, 0o700, true);
    }

    protected function tearDown(): void
    {
        $_FILES = $this->previousFiles;

        foreach ($this->tempFiles as $tempFile) {
            @unlink($tempFile);
        }

        $this->recursiveRemove($this->privateRoot);
        $this->recursiveRemove($this->publicRoot);
    }

    private function manager(array $overrides = []): StorageManager
    {
        $config = [
            'storage' => array_replace_recursive([
                'default' => 'private',
                'disks' => [
                    'private' => [
                        'driver' => 'local',
                        'root' => $this->privateRoot,
                        'public' => false,
                        'permissions' => ['file' => 0o600, 'directory' => 0o700],
                    ],
                    'public' => [
                        'driver' => 'local',
                        'root' => $this->publicRoot,
                        'public' => true,
                        'url' => 'https://example.com/storage',
                        'permissions' => ['file' => 0o644, 'directory' => 0o755],
                    ],
                ],
                'uploads' => [
                    'max_size' => 10_485_760,
                    'allowed_mimes' => ['image/jpeg', 'image/png', 'text/plain'],
                ],
            ], $overrides),
        ];

        return new StorageManager(new Repository($config));
    }

    private function uploadTemp(string $content, string $filename = 'photo.jpg'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sahil_upload_');
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    /** @param array<string, mixed> $overrides */
    private function fileEntry(array $overrides = []): array
    {
        return array_replace([
            'name' => 'photo.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_OK,
            'size' => 0,
        ], $overrides);
    }

    private function assertUploadFailed(callable $upload, string $message): void
    {
        try {
            $upload();
            $this->fail(sprintf('Expected UploadException: %s.', $message));
        } catch (UploadException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    public function test_valid_upload_stores_file_under_private_disk(): void
    {
        $content = "\xFF\xD8\xFF\xE0JFIF".str_repeat("\0", 100);
        $tmp = $this->uploadTemp($content, 'sunset.jpg');

        $_FILES['avatar'] = $this->fileEntry([
            'tmp_name' => $tmp,
            'size' => strlen($content),
        ]);

        $result = $this->manager()->upload('avatar', [
            'max_size' => 100_000,
            'allowed_mimes' => ['image/jpeg'],
        ]);

        $this->assertInstanceOf(StoredFile::class, $result);
        $this->assertSame('private', $result->disk);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\.jpg$/', $result->storedName);
        $this->assertSame('photo.jpg', $result->originalName);
        $this->assertSame(strlen($content), $result->size);
        $this->assertSame('image/jpeg', $result->mimeType);
        $this->assertNull($result->url);

        $disk = $this->manager()->disk('private');
        $this->assertTrue($disk->has($result->path));
        $this->assertSame($content, $disk->get($result->path));
        $this->assertSame(0o600, fileperms($disk->path($result->path)) & 0o777);
    }

    public function test_upload_to_public_disk_is_publicly_urlable(): void
    {
        $content = base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==", true).str_repeat("\0", 50);
        $tmp = $this->uploadTemp($content, 'chart.png');

        $_FILES['avatar'] = $this->fileEntry([
            'tmp_name' => $tmp,
            'size' => strlen($content),
        ]);

        $config = [
            'storage' => [
                'default' => 'public',
                'disks' => [
                    'public' => [
                        'driver' => 'local',
                        'root' => $this->publicRoot,
                        'public' => true,
                        'url' => 'https://example.com/storage',
                        'permissions' => ['file' => 0o644, 'directory' => 0o755],
                    ],
                ],
            ],
        ];

        $result = (new StorageManager(new Repository($config)))->upload('avatar', [
            'max_size' => 100_000,
            'allowed_mimes' => ['image/png'],
        ]);

        $this->assertStringStartsWith('https://example.com/storage/', $result->url);
        $this->assertSame(0o644, fileperms($this->publicRoot.'/'.$result->path) & 0o777);
    }

    public function test_client_supplied_mime_type_is_ignored(): void
    {
        $content = '<?php system($_GET["x"]); //';
        $tmp = $this->uploadTemp($content, 'shell.jpg');

        $_FILES['avatar'] = $this->fileEntry([
            'tmp_name' => $tmp,
            'size' => strlen($content),
            'type' => 'image/jpeg',
        ]);

        $this->assertUploadFailed(
            fn () => $this->manager()->upload('avatar', [
                'max_size' => 100_000,
                'allowed_mimes' => ['image/jpeg'],
            ]),
            'are not permitted'
        );

        $this->assertSame([], $this->listRoot($this->privateRoot));
    }

    public function test_client_supplied_extension_is_ignored(): void
    {
        $content = base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==", true);
        $tmp = $this->uploadTemp($content, 'image.jpg.php');

        $_FILES['avatar'] = $this->fileEntry([
            'tmp_name' => $tmp,
            'size' => strlen($content),
            'name' => 'image.jpg.php',
        ]);

        $result = $this->manager()->upload('avatar', [
            'max_size' => 100_000,
            'allowed_mimes' => ['image/png'],
        ]);

        $this->assertStringEndsWith('.png', $result->storedName);
        $this->assertStringNotContainsString('.php', $result->storedName);
    }

    public function test_oversized_file_is_rejected(): void
    {
        $content = str_repeat('x', 2048);
        $tmp = $this->uploadTemp($content, 'big.txt');

        $_FILES['avatar'] = $this->fileEntry([
            'tmp_name' => $tmp,
            'size' => strlen($content),
        ]);

        $this->assertUploadFailed(
            fn () => $this->manager()->upload('avatar', [
                'max_size' => 1024,
                'allowed_mimes' => ['text/plain'],
            ]),
            'exceeds the maximum allowed size of 1024 bytes'
        );
    }

    public function test_declared_size_mismatch_is_rejected(): void
    {
        $content = str_repeat('x', 100);
        $tmp = $this->uploadTemp($content, 'note.txt');

        $_FILES['avatar'] = $this->fileEntry([
            'tmp_name' => $tmp,
            'size' => 10,
        ]);

        $this->assertUploadFailed(
            fn () => $this->manager()->upload('avatar', [
                'max_size' => 100_000,
                'allowed_mimes' => ['text/plain'],
            ]),
            'size cannot be verified'
        );
    }

    public function test_upload_errors_are_rejected(): void
    {
        $tmp = $this->uploadTemp('data', 'photo.jpg');

        $cases = [
            'no file' => [
                $this->fileEntry(['error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => '', 'size' => 0]),
                 'No file was uploaded',
            ],
            'exceeds ini size' => [
                $this->fileEntry(['error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => $tmp, 'size' => 0]),
                'exceeds the maximum allowed size',
            ],
            'exceeds form size' => [
                $this->fileEntry(['error' => UPLOAD_ERR_FORM_SIZE, 'tmp_name' => $tmp, 'size' => 0]),
                'exceeds the maximum allowed size',
            ],
            'partial upload' => [
                $this->fileEntry(['error' => UPLOAD_ERR_PARTIAL, 'tmp_name' => $tmp, 'size' => 0]),
                'partially uploaded',
            ],
            'missing input' => [
                null,
                 'No file was uploaded',
            ],
            'bad temporary file' => [
                $this->fileEntry(['error' => UPLOAD_ERR_OK, 'tmp_name' => '/nonexistent/upload.tmp', 'size' => 10]),
                'not available',
            ],
            'empty upload' => [
                $this->fileEntry(['tmp_name' => $this->uploadTemp('', 'empty.txt'), 'size' => 0]),
                'empty',
            ],
        ];

        foreach ($cases as $name => [$entry, $message]) {
            if ($entry === null) {
                unset($_FILES['avatar']);
            } else {
                $_FILES['avatar'] = $entry;
            }

            $this->assertUploadFailed(
                fn () => $this->manager()->upload('avatar', [
                    'max_size' => 100_000,
                    'allowed_mimes' => ['image/jpeg'],
                ]),
                $message,
            );
        }
    }

    public function test_upload_subdirectory_path_traversal_is_rejected(): void
    {
        $tmp = $this->uploadTemp('data', 'photo.jpg');

        $_FILES['avatar'] = $this->fileEntry([
            'tmp_name' => $tmp,
            'size' => 4,
        ]);

        $this->expectException(\Sahil\Storage\StorageException::class);
        $this->manager()->upload('avatar', [
            'max_size' => 100_000,
            'allowed_mimes' => ['text/plain'],
            'path' => '../../../etc',
        ]);
    }

    public function test_executable_extension_is_rejected_by_default(): void
    {
        $content = '<?php phpinfo();';
        $tmp = $this->uploadTemp($content, 'shell.php');

        $_FILES['avatar'] = $this->fileEntry([
            'tmp_name' => $tmp,
            'size' => strlen($content),
        ]);

        $this->assertUploadFailed(
            fn () => $this->manager()->upload('avatar', [
                'max_size' => 100_000,
                'allowed_mimes' => ['text/x-php'],
            ]),
            'extension [php] are not permitted'
        );
    }

    public function test_executable_extension_cannot_be_enabled_by_options(): void
    {
        $content = '<?php phpinfo();';
        $tmp = $this->uploadTemp($content, 'shell.php');

        $_FILES['avatar'] = $this->fileEntry([
            'tmp_name' => $tmp,
            'size' => strlen($content),
        ]);

        // Historical option name: it must have no effect on the outcome.
        $this->assertUploadFailed(
            fn () => $this->manager()->upload('avatar', [
                'max_size' => 100_000,
                'allowed_mimes' => ['text/x-php'],
                'allow_executable_extensions' => true,
            ]),
            'extension [php] are not permitted'
        );

        $this->assertSame([], $this->listRoot($this->privateRoot));
    }

    public function test_upload_is_verified_through_the_driver_before_it_is_reported(): void
    {
        $content = base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==", true);
        $tmp = $this->uploadTemp($content, 'chart.png');

        $_FILES['avatar'] = $this->fileEntry([
            'tmp_name' => $tmp,
            'size' => strlen($content),
        ]);

        $driver = new InMemoryDriver();

        $manager = new StorageManager(new Repository([
            'storage' => [
                'default' => 'remote',
                'disks' => [
                    'remote' => ['driver' => 'memory', 'public' => false],
                ],
            ],
        ]));
        $manager->extend('memory', static fn (): InMemoryDriver => $driver);

        $result = $manager->upload('avatar', [
            'max_size' => 100_000,
            'allowed_mimes' => ['image/png'],
        ]);

        $this->assertSame('remote', $result->disk);
        $this->assertSame('image/png', $result->mimeType);
        $this->assertSame(strlen($content), $result->size);
        $this->assertTrue($driver->has($result->path));
        $this->assertSame($content, $driver->get($result->path));
    }

    public function test_a_driver_that_reports_different_content_is_rejected_and_cleaned_up(): void
    {
        $content = base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==", true);
        $tmp = $this->uploadTemp($content, 'chart.png');

        $_FILES['avatar'] = $this->fileEntry([
            'tmp_name' => $tmp,
            'size' => strlen($content),
        ]);

        $driver = new InMemoryDriver();
        // Simulate a backend that mangles content on the way in.
        $driver->mimeOverrides = ['*' => 'text/plain'];

        $manager = new StorageManager(new Repository([
            'storage' => [
                'default' => 'remote',
                'disks' => [
                    'remote' => ['driver' => 'memory', 'public' => false],
                ],
            ],
        ]));
        $manager->extend('memory', static fn (): InMemoryDriver => $driver);

        $this->assertUploadFailed(
            static fn () => $manager->upload('avatar', [
                'max_size' => 100_000,
                'allowed_mimes' => ['image/png'],
            ]),
            'failed verification'
        );

        $this->assertSame([], $driver->files, 'a rejected upload must not be left behind');
    }

    public function test_client_file_name_is_sanitized(): void
    {
        $content = 'hello';
        $tmp = $this->uploadTemp($content, 'irrelevant.txt');

        $_FILES['avatar'] = $this->fileEntry([
            'tmp_name' => $tmp,
            'size' => strlen($content),
            'name' => '../../../evil <> name.txt',
        ]);

        $result = $this->manager()->upload('avatar', [
            'max_size' => 100_000,
            'allowed_mimes' => ['text/plain'],
        ]);

        $this->assertSame('evil-name.txt', $result->originalName);
        $this->assertSame($content, $this->manager()->disk('private')->get($result->path));
    }

    public function test_missing_client_file_name_defaults(): void
    {
        $content = 'hello';
        $tmp = $this->uploadTemp($content, 'irrelevant.txt');

        $_FILES['avatar'] = $this->fileEntry([
            'tmp_name' => $tmp,
            'size' => strlen($content),
            'name' => '',
        ]);

        $result = $this->manager()->upload('avatar', [
            'max_size' => 100_000,
            'allowed_mimes' => ['text/plain'],
        ]);

        $this->assertSame('upload', $result->originalName);
    }

    /** @return list<string> */
    private function listRoot(string $root): array
    {
        $files = glob($root.'/*');

        return $files === false ? [] : $files;
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
