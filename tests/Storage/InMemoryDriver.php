<?php

declare(strict_types=1);

namespace Sahil\Tests\Storage;

use Sahil\Contracts\Storage\StorageDriverContract;
use Sahil\Storage\StorageException;

/**
 * Storage driver double with no filesystem behind it: stands in for a remote
 * adapter (S3 and friends) so the upload pipeline can be exercised through
 * the driver contract only.
 */
final class InMemoryDriver implements StorageDriverContract
{
    /** @var array<string, string> */
    public array $files = [];

    /** @var array<string, string> forced MIME type per stored path, or '*' for all */
    public array $mimeOverrides = [];

    /** @var list<string> */
    public array $directories = [];

    public function root(): string
    {
        return 'memory://bucket';
    }

    public function isPublic(): bool
    {
        return false;
    }

    public function path(string $path): string
    {
        return 'memory://bucket/'.$path;
    }

    public function put(string $path, $contents): void
    {
        $this->files[$path] = is_string($contents) ? $contents : (string) stream_get_contents($contents);
    }

    public function get(string $path): string
    {
        if (!isset($this->files[$path])) {
            throw new StorageException(sprintf('File not found: %s.', $path));
        }

        return $this->files[$path];
    }

    public function readStream(string $path)
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new StorageException('Unable to open a stream.');
        }

        fwrite($handle, $this->get($path));
        rewind($handle);

        return $handle;
    }

    public function has(string $path): bool
    {
        return isset($this->files[$path]);
    }

    public function size(string $path): int
    {
        return strlen($this->get($path));
    }

    public function mimeType(string $path): string
    {
        if (isset($this->mimeOverrides[$path])) {
            return $this->mimeOverrides[$path];
        }

        if (isset($this->mimeOverrides['*'])) {
            return $this->mimeOverrides['*'];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return 'application/octet-stream';
        }

        try {
            $detected = finfo_buffer($finfo, $this->get($path));
        } finally {
            finfo_close($finfo);
        }

        return $detected === false ? 'application/octet-stream' : $detected;
    }

    public function lastModified(string $path): int
    {
        return 0;
    }

    public function delete(string $path): bool
    {
        if (!isset($this->files[$path])) {
            return false;
        }

        unset($this->files[$path]);

        return true;
    }

    public function copy(string $from, string $to): void
    {
        $this->files[$to] = $this->get($from);
    }

    public function move(string $from, string $to): void
    {
        $this->copy($from, $to);
        $this->delete($from);
    }

    public function makeDirectory(string $path, bool $recursive = false): void
    {
        $this->directories[] = $path;
    }

    public function deleteDirectory(string $path): void
    {
        foreach (array_keys($this->files) as $file) {
            if (str_starts_with($file, $path.'/')) {
                unset($this->files[$file]);
            }
        }
    }

    /** @return list<array{path: string, type: 'file'|'dir', size: int, mime_type: string|null, last_modified: int|null}> */
    public function listContents(string $path = ''): array
    {
        return [];
    }

    public function url(string $path): ?string
    {
        return null;
    }
}
