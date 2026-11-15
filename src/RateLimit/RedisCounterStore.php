<?php

declare(strict_types=1);

namespace Sahil\RateLimit;

/**
 * Atomic Redis counter store.
 *
 * Each hit runs `INCR` on the counter key; when the key is new the same
 * client sets `EXPIRE` to the window, so the window opens on the first
 * hit and the reset time is simply `now + TTL`. Both operations are
 * atomic on the server — concurrent workers cannot over-admit, whatever
 * the process topology.
 *
 * Accepts a connected `Redis` instance; prefixing keeps limiter keys
 * namespaced inside a shared database.
 */
final class RedisCounterStore implements CounterStore
{
    public function __construct(
        private readonly \Redis $redis,
        private readonly string $prefix = 'sahil:rl:',
    ) {
    }

    public function hit(string $key, int $window): array
    {
        $redisKey = $this->prefix.$key;

        $attempts = $this->redis->incr($redisKey);

        if ($attempts === 1) {
            // New counter: attach the window TTL. The only failure mode is
            // a crash between INCR and EXPIRE leaving a TTL-less counter —
            // guarded by capping resetAt below.
            $this->redis->expire($redisKey, $window);
        }

        return ['attempts' => (int) $attempts, 'resetAt' => $this->resetAt($redisKey, $window)];
    }

    public function state(string $key): ?array
    {
        $redisKey = $this->prefix.$key;
        $attempts = $this->redis->get($redisKey);

        if ($attempts === false) {
            return null;
        }

        return ['attempts' => (int) $attempts, 'resetAt' => $this->resetAt($redisKey, 0)];
    }

    public function reset(string $key): void
    {
        $this->redis->del($this->prefix.$key);
    }

    private function resetAt(string $redisKey, int $window): int
    {
        $ttl = $this->redis->ttl($redisKey);

        // -2: key gone (expired between commands) — treat the window as
        // already closed. -1: no TTL (interrupted first hit) — bound it to
        // a fresh window instead of a permanent counter.
        if ($ttl === -1) {
            return time() + $window;
        }

        return time() + max(0, (int) $ttl);
    }
}
