<?php

declare(strict_types=1);

namespace Sahil\Tests\Storage;

use PHPUnit\Framework\TestCase;
use Sahil\Config\Repository;
use Sahil\Storage\Kapu;
use Sahil\Storage\StorageException;
use Sahil\Storage\StorageManager;

/**
 * The public Kapu facade over a real StorageManager + LocalDriver pair
 * on isolated temporary roots.
 */
final class KapuTest extends TestCase
{
    private string $localRoot;

    private string $privateRoot;

    protected function setUp(): void
    {
        $this->localRoot = sys_get_temp_dir().'/kapu-local-'.bin2hex(random_bytes(6));
        $this->privateRoot = sys_get_temp_dir().'/kapu-private-'.bin2hex(random_bytes(6));

        Kapu::swap(new StorageManager(new Repository([
            'storage' => [
                'default' => 'local',
                'disks' => [
                    'local' => ['driver' => 'local', 'root' => $this->localRoot],
                    'private' => ['driver' => 'local', 'root' => $this->privateRoot],
                ],
                'uploads' => ['max_size' => 10_485_760, 'allowed_mimes' => []],
            ],
        ])));
    }

    protected function tearDown(): void
    {
        Kapu::flushState();
        $this->rmdir($this->localRoot);
        $this->rmdir($this->privateRoot);
    }

    public function test_put_get_exists_delete_on_default_disk(): void
    {
        Kapu::put('documents/report.txt', 'Report contents');

        self::assertTrue(Kapu::exists('documents/report.txt'));
        self::assertFalse(Kapu::missing('documents/report.txt'));
        self::assertSame('Report contents', Kapu::get('documents/report.txt'));
        self::assertTrue(Kapu::delete('documents/report.txt'));
        self::assertTrue(Kapu::missing('documents/report.txt'));
    }

    public function test_named_disk_isolation(): void
    {
        Kapu::disk('private')->put('contracts/a.txt', 'secret');

        self::assertTrue(Kapu::disk('private')->has('contracts/a.txt'));
        self::assertFalse(Kapu::exists('contracts/a.txt'));
        self::assertFileExists($this->privateRoot.'/contracts/a.txt');
    }

    public function test_binary_content_round_trips(): void
    {
        $binary = random_bytes(1024);
        Kapu::put('bin/blob', $binary);

        self::assertSame($binary, Kapu::get('bin/blob'));
        self::assertSame(1024, Kapu::size('bin/blob'));
    }

    public function test_copy_move_and_metadata(): void
    {
        Kapu::put('a.txt', 'hello');
        Kapu::copy('a.txt', 'b.txt');
        self::assertSame('hello', Kapu::get('b.txt'));

        Kapu::move('b.txt', 'sub/c.txt');
        self::assertTrue(Kapu::missing('b.txt'));
        self::assertSame('hello', Kapu::get('sub/c.txt'));

        self::assertGreaterThan(0, Kapu::lastModified('a.txt'));
        self::assertSame('text/plain', Kapu::mimeType('a.txt'));
    }

    public function test_directories_and_listings(): void
    {
        Kapu::put('docs/a.txt', '1');
        Kapu::put('docs/nested/b.txt', '2');
        Kapu::makeDirectory('docs/empty');

        self::assertSame(['docs/a.txt'], Kapu::files('docs'));
        self::assertSame(
            ['docs/empty', 'docs/nested'],
            Kapu::directories('docs'),
        );

        $all = Kapu::allFiles('docs');
        sort($all);
        self::assertSame(['docs/a.txt', 'docs/nested/b.txt'], $all);

        Kapu::deleteDirectory('docs');
        self::assertTrue(Kapu::missing('docs/a.txt'));

        // Listing a gone directory fails descriptively, like get().
        try {
            Kapu::files('docs');
            self::fail('expected');
        } catch (StorageException) {
            self::assertTrue(true);
        }
    }

    public function test_stream_writes_and_reads_without_buffering(): void
    {
        $payload = str_repeat('x', 1_000_000);
        $in = fopen('php://temp', 'r+');
        fwrite($in, $payload);
        rewind($in);

        Kapu::writeStream('big.bin', $in);
        self::assertSame(1_000_000, Kapu::size('big.bin'));

        $out = Kapu::readStream('big.bin');
        self::assertSame($payload, stream_get_contents($out));
        fclose($in);
        fclose($out);
    }

    public function test_writeStream_rejects_non_streams(): void
    {
        $this->expectException(StorageException::class);
        Kapu::writeStream('x.txt', 'not-a-stream');
    }

    public function test_path_traversal_and_absolute_paths_rejected(): void
    {
        foreach (['../outside.txt', 'a/../../b.txt', '/etc/passwd', "nul\0byte"] as $bad) {
            try {
                Kapu::put($bad, 'x');
                self::fail($bad.' should be rejected');
            } catch (StorageException) {
            }
        }

        // Nothing escaped the disk root.
        self::assertFileDoesNotExist(dirname($this->localRoot).'/outside.txt');
    }

    public function test_symlink_outside_root_rejected(): void
    {
        Kapu::put('seed.txt', 'x'); // materializes the disk root
        $outside = sys_get_temp_dir().'/kapu-outside-'.bin2hex(random_bytes(4)).'.txt';
        file_put_contents($outside, 'secret');
        symlink($outside, $this->localRoot.'/link.txt');

        try {
            Kapu::get('link.txt');
            self::fail('symlink should be rejected');
        } catch (StorageException $e) {
            self::assertStringContainsString('link.txt', $e->getMessage());
        } finally {
            unlink($outside);
        }
    }

    public function test_missing_file_get_throws_descriptively(): void
    {
        $this->expectException(StorageException::class);
        Kapu::get('does/not/exist.txt');
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
