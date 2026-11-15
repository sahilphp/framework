<?php

declare(strict_types=1);

namespace Sahil\RateLimit;

/**
 * Storage contract for rate-limit counters.
 *
 * The manager asks a store to record a hit inside a fixed window; the
 * store reports the resulting attempt count and the unix timestamp the
 * window ends. Implementations must keep each namespaced key independent
 * and expire data deterministically.
 *
 * Atomicity is a property of the concrete store, not of this contract:
 * {@see RedisCounterStore} increments atomically via `INCR`/`EXPIRE`,
 * {@see CacheCounterStore} is only as atomic as the cache driver it
 * wraps, and {@see ArrayCounterStore} is process-local development
 * storage. The guarantees each backend provides are documented on the
 * store itself — pick accordingly for multi-worker deployments.
 */
interface CounterStore
{
    /**
     * Record one hit for `$key` inside a `$window`-second window and
     * return `array{attempts: int, resetAt: int}`. A first hit starts the
     * window; the counter must expire no later than `resetAt`.
     */
    public function hit(string $key, int $window): array;

    /**
     * Read the counter without recording a hit:
     * `array{attempts: int, resetAt: int}` or null when the key is idle.
     */
    public function state(string $key): ?array;

    /** Clear the counter for one key. Other keys are untouched. */
    public function reset(string $key): void;
}
