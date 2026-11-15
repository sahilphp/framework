<?php

declare(strict_types=1);

namespace Sahil\Webhooks;

/**
 * Endpoint + delivery persistence. Secrets are stored encrypted — the
 * store accepts and returns plaintext only at the {@see WebhookEndpoint}
 * boundary.
 */
interface WebhookStore
{
    /** @param list<string> $events */
    public function createEndpoint(string $url, array $events, string $secret): WebhookEndpoint;

    public function endpoint(string $id): ?WebhookEndpoint;

    /** @return list<WebhookEndpoint> every endpoint (secrets decrypted in memory only) */
    public function endpoints(): array;

    /** @return list<WebhookEndpoint> active endpoints subscribed to `$event` */
    public function endpointsFor(string $event): array;

    /** @param list<string>|null $events null keeps current subscriptions */
    public function updateEndpoint(string $id, ?string $url, ?array $events): WebhookEndpoint;

    public function setEndpointActive(string $id, bool $active): WebhookEndpoint;

    /** Rotate the signing secret; returns the updated endpoint. */
    public function rotateSecret(string $id, string $secret): WebhookEndpoint;

    public function deleteEndpoint(string $id): bool;

    /** Record a new queued delivery and return it. */
    public function createDelivery(string $endpointId, string $event, string $payload): WebhookDelivery;

    public function delivery(string $id): ?WebhookDelivery;

    /** Increment the attempt counter — called per execution. */
    public function markAttempt(string $id): void;

    public function markDelivered(string $id, int $httpStatus): void;

    /** @param string $error bounded safe error text */
    public function markFailed(string $id, ?int $httpStatus, string $error): void;

    /** Back to queued after a non-final failure (retry pending). */
    public function markRetry(string $id, ?int $httpStatus, string $error): void;

    /** @return list<WebhookDelivery> newest first */
    public function deliveriesFor(string $endpointId, int $limit = 50): array;
}
