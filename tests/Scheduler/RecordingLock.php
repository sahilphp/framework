<?php

declare(strict_types=1);

namespace Sahil\Tests\Scheduler;

use Sahil\Contracts\Scheduler\LockContract;

/**
 * Lock double: records the acquire/release sequence, and can simulate
 * "another run is still holding it" by pre-holding a name.
 */
final class RecordingLock implements LockContract
{
    /** @var list<string> */
    public array $acquired = [];

    /** @var list<string> */
    public array $released = [];

    /** @var array<string, true> */
    private array $held = [];

    public function acquire(string $name): bool
    {
        if (isset($this->held[$name])) {
            return false;
        }

        $this->held[$name] = true;
        $this->acquired[] = $name;

        return true;
    }

    public function release(string $name): void
    {
        if (!isset($this->held[$name])) {
            return;
        }

        unset($this->held[$name]);
        $this->released[] = $name;
    }

    public function hold(string $name): void
    {
        $this->held[$name] = true;
    }

    public function holds(string $name): bool
    {
        return isset($this->held[$name]);
    }
}
