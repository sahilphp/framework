<?php

declare(strict_types=1);

namespace Sahil\Contracts\Events;

/**
 * A subscriber registers several related listeners in one place.
 *
 *     public function subscribe(): array
 *     {
 *         return [
 *             UserRegistered::class => [
 *                 SendWelcomeNotification::class,
 *                 RecordRegistration::class,
 *             ],
 *         ];
 *     }
 *
 * @return array<string, list<string|callable>> event class => listeners
 */
interface EventSubscriberContract
{
    /** @return array<string, list<string|callable>> */
    public function subscribe(): array;
}
