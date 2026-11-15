<?php

declare(strict_types=1);

namespace Sahil\Storage;

use Sahil\Contracts\Storage\StorageContract;
use Sahil\Contracts\Storage\StorageDriverContract;
use Sahil\Foundation\Application;

/**
 * Kapu — the official public filesystem API (Swahili for *bucket*).
 *
 *     Kapu::put('documents/report.txt', $contents);
 *     $content = Kapu::get('documents/report.txt');
 *     Kapu::disk('private')->put('contracts/a.pdf', $pdf);
 *
 * Thin facade over the container-bound {@see StorageContract}
 * (`StorageManager`); DI stays available through that contract — same
 * convention as `Bouncer`/`Notify`/`Render`/`Event`. Local disks only;
 * remote drivers are a separate future capability.
 */
final class Kapu
{
    private static ?StorageContract $instance = null;

    private function __construct()
    {
    }

    /** The named disk, or the configured default when `$name` is null. */
    public static function disk(?string $name = null): StorageDriverContract
    {
        return self::storage()->disk($name);
    }

    /** @param string|resource $contents */
    public static function put(string $path, mixed $contents, ?string $disk = null): void
    {
        self::storage()->put($path, $contents, $disk);
    }

    public static function get(string $path, ?string $disk = null): string
    {
        return self::storage()->get($path, $disk);
    }

    /** @return resource */
    public static function readStream(string $path, ?string $disk = null)
    {
        return self::storage()->readStream($path, $disk);
    }

    /**
     * Write an open readable stream to `$path` — never buffers the whole
     * file; the caller keeps ownership of `$stream` (it is not closed).
     *
     * @param resource $stream
     */
    public static function writeStream(string $path, mixed $stream, ?string $disk = null): void
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new StorageException('writeStream() expects an open stream resource.');
        }

        self::storage()->put($path, $stream, $disk);
    }

    public static function exists(string $path, ?string $disk = null): bool
    {
        return self::storage()->has($path, $disk);
    }

    public static function missing(string $path, ?string $disk = null): bool
    {
        return !self::exists($path, $disk);
    }

    public static function delete(string $path, ?string $disk = null): bool
    {
        return self::storage()->delete($path, $disk);
    }

    public static function copy(string $from, string $to, ?string $disk = null): void
    {
        self::storage()->copy($from, $to, $disk);
    }

    public static function move(string $from, string $to, ?string $disk = null): void
    {
        self::storage()->move($from, $to, $disk);
    }

    public static function size(string $path, ?string $disk = null): int
    {
        return self::storage()->size($path, $disk);
    }

    public static function lastModified(string $path, ?string $disk = null): int
    {
        return self::storage()->lastModified($path, $disk);
    }

    public static function mimeType(string $path, ?string $disk = null): string
    {
        return self::storage()->mimeType($path, $disk);
    }

    public static function makeDirectory(string $path, bool $recursive = true, ?string $disk = null): void
    {
        self::storage()->makeDirectory($path, $recursive, $disk);
    }

    public static function deleteDirectory(string $path, ?string $disk = null): void
    {
        self::storage()->deleteDirectory($path, $disk);
    }

    /** @return list<string> file paths directly inside `$path` */
    public static function files(string $path = '', ?string $disk = null): array
    {
        return self::entries($path, 'file', false, $disk);
    }

    /** @return list<string> directory paths directly inside `$path` */
    public static function directories(string $path = '', ?string $disk = null): array
    {
        return self::entries($path, 'dir', false, $disk);
    }

    /** @return list<string> every file path below `$path`, recursively */
    public static function allFiles(string $path = '', ?string $disk = null): array
    {
        return self::entries($path, 'file', true, $disk);
    }

    /**
     * The storage manager — providers may bind a preconfigured instance;
     * `swap()` installs one for tests.
     */
    public static function storage(): StorageContract
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $application = Application::peek();

        if ($application !== null && $application->container()->has(StorageContract::class)) {
            /** @var StorageContract $storage */
            $storage = $application->container()->make(StorageContract::class);

            return $storage;
        }

        throw new StorageException(
            'Kapu needs a configured application with a bound StorageContract — or Kapu::swap() for tests.',
        );
    }

    /** Install the storage service the facade delegates to — tests and non-application contexts. */
    public static function swap(?StorageContract $storage): void
    {
        self::$instance = $storage;
    }

    /** Drop the swapped instance. */
    public static function flushState(): void
    {
        self::$instance = null;
    }

    /**
     * @return list<string>
     */
    private static function entries(string $path, string $type, bool $recursive, ?string $disk): array
    {
        $driver = self::storage()->disk($disk);
        $found = [];
        $pending = [$path];

        while ($pending !== []) {
            $current = array_shift($pending);

            foreach ($driver->listContents($current) as $entry) {
                if ($entry['type'] === $type) {
                    $found[] = $entry['path'];
                }

                if ($recursive && $entry['type'] === 'dir') {
                    $pending[] = $entry['path'];
                }
            }
        }

        return $found;
    }
}
