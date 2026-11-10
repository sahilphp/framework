<?php

declare(strict_types=1);

namespace Sahil\Tests\Support;

use Sahil\Config\Repository;
use Sahil\Session\FileSessionDriver;
use Sahil\Storage\StorageManager;
use Sahil\Tests\FrameworkTestCase;

/**
 * Configured filesystem paths are application-relative: config defaults are
 * built with storage_path() and the generated .env mirrors that shape (for
 * example `storage/framework/sessions`).
 *
 * Native filesystem calls resolve a relative path against the process working
 * directory, which a web SAPI sets to the document root. Resolving them that
 * way publishes live session files over HTTP and relocates private uploads
 * into the served tree, so every consumer must anchor them to the application
 * base instead — regardless of where the process happens to be started from.
 */
final class ConfiguredPathResolutionTest extends FrameworkTestCase
{
    private string $originalWorkingDirectory = '';

    /** @var list<string> directories created beneath the application base */
    private array $createdUnderBasePath = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalWorkingDirectory = (string) getcwd();
    }

    protected function tearDown(): void
    {
        // Always leave the configured directory before the application is
        // freed, so nothing else resolves paths against a stale cwd.
        if ($this->originalWorkingDirectory !== '') {
            chdir($this->originalWorkingDirectory);
        }

        foreach ($this->createdUnderBasePath as $directory) {
            $this->removeDirectory($directory);
        }

        parent::tearDown();
    }

    public function test_absolute_path_anchors_relative_values_to_the_application_base(): void
    {
        $this->app();

        $documentRoot = $this->tempDirectory('sahil-docroot');
        chdir($documentRoot);

        self::assertSame(
            base_path('storage/framework/sessions'),
            absolute_path('storage/framework/sessions'),
        );
        self::assertSame(base_path('public/storage'), absolute_path('public/storage'));

        // Absolute paths are returned untouched, and so is the empty string.
        self::assertSame($documentRoot, absolute_path($documentRoot));
        self::assertSame('', absolute_path(''));

        // A relative path must never be reported as living under the working
        // directory just because the process was started there.
        self::assertStringNotContainsString($documentRoot, absolute_path('storage/framework/sessions'));
    }

    public function test_session_directory_is_created_under_the_application_not_the_document_root(): void
    {
        $this->app();

        $documentRoot = $this->tempDirectory('sahil-docroot');
        chdir($documentRoot);

        $relative = 'storage/session_resolution_'.bin2hex(random_bytes(6));
        $expected = base_path($relative);
        $this->createdUnderBasePath[] = $expected;

        new FileSessionDriver($relative);

        self::assertDirectoryExists($expected);
        self::assertDirectoryDoesNotExist($documentRoot.'/'.$relative);
    }

    public function test_private_disk_root_is_anchored_to_the_application_base(): void
    {
        $this->app();

        $documentRoot = $this->tempDirectory('sahil-docroot');
        chdir($documentRoot);

        $relative = 'storage/private_resolution_'.bin2hex(random_bytes(6));
        $expected = base_path($relative);
        $this->createdUnderBasePath[] = $expected;

        $manager = new StorageManager(new Repository([
            'storage' => [
                'default' => 'private',
                'disks' => [
                    'private' => [
                        'driver' => 'local',
                        'root' => $relative,
                        'public' => false,
                        'permissions' => ['file' => 0o600, 'directory' => 0o700],
                    ],
                    'public' => [
                        'driver' => 'local',
                        'root' => 'public/storage',
                        'public' => true,
                        'url' => 'https://example.test/storage',
                        'permissions' => ['file' => 0o644, 'directory' => 0o755],
                    ],
                ],
                'uploads' => [
                    'max_size' => 1024,
                    'allowed_mimes' => ['text/plain'],
                ],
            ],
        ]));

        // The private disk must resolve under the application base; anchoring
        // it there is also what keeps the document-root guard truthful.
        $disk = $manager->disk('private');

        self::assertStringStartsWith(base_path('storage/'), $disk->root());
        self::assertDirectoryExists($expected);
        self::assertDirectoryDoesNotExist($documentRoot.'/'.$relative);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = glob($directory.'/{*,.[!.]*}', GLOB_BRACE);

        foreach ($entries === false ? [] : $entries as $entry) {
            is_dir($entry) ? $this->removeDirectory($entry) : @unlink($entry);
        }

        @rmdir($directory);
    }
}
