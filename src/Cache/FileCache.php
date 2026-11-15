<?php

declare(strict_types=1);

namespace Sahil\Cache;

use Sahil\Contracts\Cache\CacheContract;

/**
 * File-based cache driver with expiration support.
 *
 * Each entry is `cache_{sha256}.php` containing a `<?php die; ?>` guard
 * followed by a `serialize()`d `['value'=>…,'expires'=>…]` payload —
 * values are *data*, never `require`d, so corrupted or tampered files
 * cannot execute code and any object type round-trips.
 *
 * Guarantees:
 * - Writes are atomic per file (tmp + `rename()` under `LOCK_EX`).
 * - `add`/`increment`/`decrement` hold an exclusive flock over the
 *   read-modify-write — atomic on a single host (NFS/multi-host
 *   file mounts are NOT; use Redis there).
 * - `increment`/`decrement` preserve the entry's existing TTL.
 * - Corrupted files are treated as a miss and removed.
 *
 * For multi-process/multi-host deployments use Redis.
 */
/** @implements CacheContract<mixed> */
final class FileCache implements CacheContract
{
    private const GUARD = "<?php die; ?>\n";

    private readonly string $cachePath;

    public function __construct(string $cachePath)
    {
        $this->cachePath = rtrim($cachePath, '/').'/';

        if (!is_dir($this->cachePath) && !@mkdir($this->cachePath, 0755, true) && !is_dir($this->cachePath)) {
            throw new CacheException(sprintf('Could not create cache directory: %s', $this->cachePath));
        }
    }

    /** {@inheritDoc} */
    public function get(string $key): mixed
    {
        $entry = $this->readEntry($key);

        return $entry['value'] ?? null;
    }

    /** {@inheritDoc} */
    public function put(string $key, mixed $value, int $ttl = 0): bool
    {
        return $this->writeEntry($key, $value, $ttl);
    }

    /** {@inheritDoc} Atomic under the entry lock (single host). */
    public function add(string $key, mixed $value, int $ttl = 0): bool
    {
        return $this->mutate($key, function (?array $existing) use ($value, $ttl): ?array {
            if ($existing !== null) {
                return null; // exists (and unexpired) — add() must not write
            }

            return ['value' => $value, 'expires' => $ttl === 0 ? 0 : time() + $ttl];
        }) !== null;
    }

    /** {@inheritDoc} Atomic under the entry lock; preserves TTL. */
    public function increment(string $key, int $amount = 1): mixed
    {
        $result = null;

        $this->mutate($key, function (?array $existing) use ($amount, &$result): ?array {
            $current = $existing['value'] ?? 0;

            if (!is_numeric($current)) {
                return null;
            }

            $result = (int) $current + $amount;

            return ['value' => $result, 'expires' => $existing['expires'] ?? 0];
        });

        return $result ?? false;
    }

    /** {@inheritDoc} Atomic under the entry lock; preserves TTL. */
    public function decrement(string $key, int $amount = 1): mixed
    {
        $result = null;

        $this->mutate($key, function (?array $existing) use ($amount, &$result): ?array {
            $current = $existing['value'] ?? 0;

            if (!is_numeric($current)) {
                return null;
            }

            $result = (int) $current - $amount;

            return ['value' => $result, 'expires' => $existing['expires'] ?? 0];
        });

        return $result ?? false;
    }

    /** {@inheritDoc} */
    public function forget(string $key): bool
    {
        $file = $this->cacheFile($key);

        return file_exists($file) && @unlink($file);
    }

    /** {@inheritDoc} */
    public function flush(): bool
    {
        $files = glob($this->cachePath.'cache_*.php');

        if ($files === false) {
            return true;
        }

        foreach ($files as $file) {
            @unlink($file);
        }

        return true;
    }

    /** {@inheritDoc} */
    public function has(string $key): bool
    {
        return $this->readEntry($key) !== null;
    }

    /** {@inheritDoc} */
    public function ttL(string $key): int|false
    {
        $entry = $this->readEntry($key);

        if ($entry === null) {
            return false;
        }

        $expires = (int) ($entry['expires'] ?? 0);

        if ($expires === 0) {
            return 0; // no expiration
        }

        return max(0, $expires - time());
    }

    /**
     * Read a live (unexpired) entry, or null. Corrupted files are
     * removed and reported as a miss — never as a valid value, never
     * as a fatal.
     *
     * @return array{value:mixed,expires:int}|null
     */
    private function readEntry(string $key): ?array
    {
        $file = $this->cacheFile($key);

        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);

        if (!is_string($raw) || !str_starts_with($raw, self::GUARD)) {
            @unlink($file);

            return null;
        }

        $data = @unserialize(substr($raw, strlen(self::GUARD)));

        if (!is_array($data) || !array_key_exists('value', $data)) {
            @unlink($file);

            return null;
        }

        $expires = (int) ($data['expires'] ?? 0);

        if ($expires !== 0 && time() > $expires) {
            @unlink($file);

            return null;
        }

        return $data;
    }

    private function writeEntry(string $key, mixed $value, int $ttl): bool
    {
        $payload = self::GUARD.serialize([
            'value' => $value,
            'expires' => $ttl === 0 ? 0 : time() + $ttl,
        ]);

        // Atomic publish: write a sibling tmp file then rename — readers
        // can never observe a truncated entry.
        $file = $this->cacheFile($key);
        $tmp = $file.'.'.bin2hex(random_bytes(6)).'.tmp';
        $written = @file_put_contents($tmp, $payload, LOCK_EX);

        if ($written === false || !@rename($tmp, $file)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    /**
     * Exclusive-lock read-modify-write over the entry file.
     *
     * `$mutator` receives the current live entry (or null) and returns
     * the entry to store, or null to write nothing.
     *
     * @param callable(?array{value:mixed,expires:int}):?array $mutator
     *
     * @return array{value:mixed,expires:int}|null the written entry
     */
    private function mutate(string $key, callable $mutator): ?array
    {
        $file = $this->cacheFile($key);
        $handle = @fopen($file, 'c+b');

        if ($handle === false) {
            throw new CacheException(sprintf('Could not open cache file for update: %s', $file));
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new CacheException(sprintf('Could not lock cache file: %s', $file));
            }

            $existing = $this->readFromHandle($handle);
            $next = $mutator($existing);

            if ($next === null) {
                return null;
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, self::GUARD.serialize($next));
            fflush($handle);

            return $next;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     *
     * @return array{value:mixed,expires:int}|null
     */
    private function readFromHandle($handle): ?array
    {
        rewind($handle);
        $raw = stream_get_contents($handle);

        if (!is_string($raw) || !str_starts_with($raw, self::GUARD)) {
            return null;
        }

        $data = @unserialize(substr($raw, strlen(self::GUARD)));

        if (!is_array($data) || !array_key_exists('value', $data)) {
            return null;
        }

        $expires = (int) ($data['expires'] ?? 0);

        if ($expires !== 0 && time() > $expires) {
            return null;
        }

        return $data;
    }

    private function cacheFile(string $key): string
    {
        return $this->cachePath.'cache_'.hash('sha256', $key).'.php';
    }
}
