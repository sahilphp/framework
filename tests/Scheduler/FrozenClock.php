<?php

declare(strict_types=1);

namespace Sahil\Tests\Scheduler;

use DateTimeImmutable;
use Sahil\Contracts\Scheduler\ClockContract;

/**
 * Clock pinned to one instant: scheduling decisions become deterministic.
 */
final class FrozenClock implements ClockContract
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function set(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }
}
