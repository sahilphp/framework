<?php

declare(strict_types=1);

namespace Sahil\Tests\Storage;

use PHPUnit\Framework\TestCase;
use Sahil\Config\Repository;
use Sahil\Contracts\Storage\StorageDriverContract;
use Sahil\Storage\LocalDriver;
use Sahil\Storage\StorageException;
use Sahil\Storage\StorageManager;

final class StorageManagerTest extends TestCase
{
    private string $privateRoot;
    private string $publicRoot;

    protected function setUp(): void
    {
        $this->privateRoot = sys_get_temp_dir().'/sahil_manager_private_'.bin2hex(random_bytes(8));
        $this->publicRoot = sys_get_temp_dir().'/sahil_manager_public_'.bin2hex(random_bytes(8));

        @mkdir($this->privateRoot, 0o700, true);
        @mkdir($this->publicRoot, 0o700, true);
    }

    protected function tearDown(): void
    {
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
                    'allowed_mimes' => ['image/jpeg', 'image/png'],
                ],
            ], $overrides),
        ];

        return new StorageManager(new Repository($config));
    }

    public function test_disk_resolves_configured_local_driver(): void
    {
        $disk = $this->manager()->disk('private');

        $this->assertInstanceOf(LocalDriver::class, $disk);
        $this->assertSame($this->privateRoot, $disk->root());
        $this->assertFalse($disk->isPublic());
    }

    public function test_public_disk_is_marked_public(): void
    {
        $disk = $this->manager()->disk('public');

        $this->assertTrue($disk->isPublic());
        $this->assertSame($this->publicRoot, $disk->root());
    }

    public function test_disk_without_name_uses_default_disk(): void
    {
        $manager = $this->manager();

        $this->assertSame($manager->disk('private'), $manager->disk());
    }

    public function test_disks_are_cached(): void
    {
        $manager = $this->manager();

        $this->assertSame($manager->disk('private'), $manager->disk('private'));
    }

    public function test_private_disk_inside_a_public_disk_is_refused(): void
    {
        $manager = $this->manager([
            'disks' => [
                'private' => ['root' => $this->publicRoot.'/private'],
            ],
        ]);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('must never be web-accessible');

        $manager->disk('private');
    }

    public function test_private_disk_inside_the_document_root_is_refused(): void
    {
        $manager = new StorageManager(
            new Repository([
                'storage' => [
                    'default' => 'private',
                    'disks' => [
                        'private' => [
                            'driver' => 'local',
                            'root' => $this->publicRoot.'/uploads',
                            'public' => false,
                        ],
                    ],
                ],
            ]),
            $this->publicRoot,
        );

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('must never be web-accessible');

        $manager->disk('private');
    }

    public function test_private_disk_outside_the_document_root_is_allowed(): void
    {
        $manager = new StorageManager(
            new Repository([
                'storage' => [
                    'default' => 'private',
                    'disks' => [
                        'private' => [
                            'driver' => 'local',
                            'root' => $this->privateRoot,
                            'public' => false,
                        ],
                    ],
                ],
            ]),
            $this->publicRoot,
        );

        $this->assertInstanceOf(LocalDriver::class, $manager->disk('private'));
    }

    public function test_unknown_disk_is_rejected(): void
    {
        $manager = $this->manager();

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Storage disk [offsite] is not defined.');

        $manager->disk('offsite');
    }

    public function test_methods_delegate_to_the_default_disk(): void
    {
        $manager = $this->manager();

        $manager->put('note.txt', 'hello');

        $this->assertTrue($manager->has('note.txt'));
        $this->assertSame('hello', $manager->get('note.txt'));
        $this->assertSame(5, $manager->size('note.txt'));
        $this->assertTrue($manager->delete('note.txt'));
        $this->assertFalse($manager->has('note.txt'));
    }

    public function test_delegate_methods_accept_an_explicit_disk(): void
    {
        $manager = $this->manager();

        $manager->put('shared.txt', 'data', 'public');

        $this->assertTrue($manager->has('shared.txt', 'public'));
        $this->assertFalse($manager->has('shared.txt', 'private'));
    }

    public function test_extend_registers_a_custom_driver_backend(): void
    {
        $capture = new class () {
            public array $calls = [];
        };

        $mock = new class ($capture) implements StorageDriverContract {
            public function __construct(public readonly object $capture)
            {
            }

            public function root(): string
            {
                return 's3://bucket';
            }

            public function isPublic(): bool
            {
                return false;
            }

            public function path(string $path): string
            {
                return 's3://bucket/'.$path;
            }

            public function put(string $path, $contents): void
            {
                $this->capture->calls[] = ['put', $path, is_string($contents) ? $contents : 'stream'];
            }

            public function get(string $path): string
            {
                return '';
            }

            public function readStream(string $path)
            {
                return fopen('php://temp', 'r+');
            }

            public function has(string $path): bool
            {
                return false;
            }

            public function size(string $path): int
            {
                return 0;
            }

            public function mimeType(string $path): string
            {
                return 'application/octet-stream';
            }

            public function lastModified(string $path): int
            {
                return 0;
            }

            public function delete(string $path): bool
            {
                return true;
            }

            public function copy(string $from, string $to): void
            {
            }

            public function move(string $from, string $to): void
            {
            }

            public function makeDirectory(string $path, bool $recursive = false): void
            {
            }

            public function deleteDirectory(string $path): void
            {
            }

            /** @return list<array> */
            public function listContents(string $path = ''): array
            {
                return [];
            }

            public function url(string $path): ?string
            {
                return null;
            }
        };

        $config = [
            'storage' => [
                'default' => 'remote',
                'disks' => [
                    'remote' => ['driver' => 's3', 'root' => 'ignored'],
                ],
            ],
        ];
        $manager = new StorageManager(new Repository($config));
        $manager->extend('s3', static fn (): StorageDriverContract => $mock);
        $remote = $manager->disk('remote');

        $remote->put('key.txt', 'value');

        $this->assertSame($remote, $mock);
        $this->assertSame([['put', 'key.txt', 'value']], $mock->capture->calls);
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

                continue;
            }

            @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
