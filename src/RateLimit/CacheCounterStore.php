<?php

declare(strict_types=1);

namespace Sahil\RateLimit;

use Sahil\Contracts\Cache\CacheContract;

/**
 * Counter store on top of any `CacheContract` driver.
 *
 * Each counter is one cache entry `{attempts, resetAt}` — a single key
 * keeps count and deadline consistent and makes `reset()` a single
 * `forget()`. A first hit creates the entry via `add()` (atomic
 * create-if-absent); later hits extend the count while preserving the
 * original deadline.
 *
 * Atomicity mirrors the driver's increment path, which is a
 * read-modify-write on today's cache drivers: perfectly correct for a
 * single process, but concurrent workers may undercount near the
 * boundary. For a hard multi-worker guarantee use
 * {@see RedisCounterStore}, whose `INCR`/`EXPIRE` pair is atomic on the
 * server.
 */
final class CacheCounterStore implements CounterStore
{
    /**
     * @param CacheContract<mixed> $cache
     */
    public function __construct(
        private readonly CacheContract $cache,
    ) {
    }

    public function hit(string $key, int $window): array
    {
        $now = time();
        $fresh = ['attempts' => 1, 'resetAt' => $now + $window];

        if ($this->cache->add($key, $fresh, $window)) {
            return $fresh;
        }

        $state = $this->state($key);

        if ($state === null) {
            // Expired between add() and get() — open a new window.
            $this->cache->put($key, $fresh, $window);

            return $fresh;
        }

        $state['attempts']++;
        $ttl = max(1, $state['resetAt'] - $now);
        $this->cache->put($key, $state, $ttl);

        return $state;
    }

    public function state(string $key): ?array
    {
        $entry = $this->cache->get($key);

        if (!is_array($entry)
            || !isset($entry['attempts'], $entry['resetAt'])
            || !is_int($entry['attempts'])
            || !is_int($entry['resetAt'])
            || $entry['resetAt'] <= time()) {
            return null;
        }

        return $entry;
    }

    public function reset(string $key): void
    {
        $this->cache->forget($key);
    }
}
