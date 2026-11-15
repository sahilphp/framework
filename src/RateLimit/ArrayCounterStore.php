<?php

declare(strict_types=1);

namespace Sahil\RateLimit;

/**
 * Process-local counter store — development and tests only.
 *
 * Counters live in PHP memory: they are NOT shared across PHP-FPM
 * workers, long-running worker processes, or application instances, and
 * they vanish on restart. Use Redis (or another shared `CounterStore`)
 * anywhere the limit must actually hold.
 */
final class ArrayCounterStore implements CounterStore
{
    /** @var array<string, array{attempts: int, resetAt: int}> */
    private array $counters = [];

    public function hit(string $key, int $window): array
    {
        $now = time();
        $entry = $this->counters[$key] ?? null;

        if ($entry === null || $entry['resetAt'] <= $now) {
            $entry = ['attempts' => 0, 'resetAt' => $now + $window];
        }

        $entry['attempts']++;
        $this->counters[$key] = $entry;

        return $entry;
    }

    public function state(string $key): ?array
    {
        $entry = $this->counters[$key] ?? null;

        return $entry !== null && $entry['resetAt'] > time() ? $entry : null;
    }

    public function reset(string $key): void
    {
        unset($this->counters[$key]);
    }
}
