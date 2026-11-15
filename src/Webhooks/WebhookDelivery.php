<?php

declare(strict_types=1);

namespace Sahil\Webhooks;

/**
 * One logical delivery — `id` is the receiver's deduplication key and
 * stays stable across queue retries; `attempts` counts executions.
 *
 * Statuses: `queued` → `delivered` | `failed` (attempts exhausted).
 */
final class WebhookDelivery
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        public readonly string $id,
        public readonly string $endpointId,
        public readonly string $event,
        public readonly string $status,
        public readonly int $attempts,
        public readonly ?int $lastStatus,
        public readonly ?string $error,
        public readonly string $payload,
        public readonly int $createdAt = 0,
        public readonly ?int $completedAt = null,
    ) {
    }
}
