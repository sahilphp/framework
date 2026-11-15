<?php

declare(strict_types=1);

namespace Sahil\Contracts\Events;

/**
 * Marker contract: a listener implementing this runs on the queue instead
 * of synchronously during dispatch.
 *
 * The distinction is visible on the listener itself — no registration-time
 * flags to disagree with the class.
 */
interface QueueableContract
{
    /** The named queue the listener job lands on; `null` = default. */
    public function queueName(): ?string;

    /** Seconds before the job becomes available. */
    public function queueDelay(): int;
}
