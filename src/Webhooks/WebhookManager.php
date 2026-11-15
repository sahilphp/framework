<?php

declare(strict_types=1);

namespace Sahil\Webhooks;

use Sahil\Webhooks\Jobs\DeliverWebhookJob;

/**
 * Endpoint registry + dispatch orchestration. `deliver()` creates one
 * queued delivery per matching active endpoint and enqueues a
 * {@see DeliverWebhookJob} carrying only the delivery ID — no secrets,
 * models or service state enter the queue payload.
 */
final class WebhookManager
{
    public function __construct(
        private readonly WebhookStore $store,
        private readonly WebhookUrlValidator $urls,
        private readonly string $queue = 'default',
    ) {
    }

    /**
     * Register an endpoint. `secret` is optional — a strong random one is
     * generated; the *plaintext is returned only here*, via
     * `WebhookEndpoint::secret`. Rotate with {@see rotateSecret()}.
     *
     * @param list<string> $events event names, or `['*']` for all
     */
    public function register(string $url, array $events, ?string $secret = null): WebhookEndpoint
    {
        if ($events === []) {
            throw new WebhookException('A webhook endpoint must subscribe to at least one event name.');
        }

        $url = $this->urls->validate($url);
        $secret ??= bin2hex(random_bytes(32));

        return $this->store->createEndpoint($url, array_values($events), $secret);
    }

    public function endpoint(string $id): ?WebhookEndpoint
    {
        return $this->store->endpoint($id);
    }

    /** @return list<array<string,mixed>> listing-safe rows — secrets excluded */
    public function endpoints(): array
    {
        return array_map(static fn (WebhookEndpoint $e): array => $e->publicArray(), $this->store->endpoints());
    }

    /** @param list<string>|null $events */
    public function update(string $id, ?string $url = null, ?array $events = null): WebhookEndpoint
    {
        return $this->store->updateEndpoint($id, $url === null ? null : $this->urls->validate($url), $events);
    }

    public function enable(string $id): WebhookEndpoint
    {
        return $this->store->setEndpointActive($id, true);
    }

    public function disable(string $id): WebhookEndpoint
    {
        return $this->store->setEndpointActive($id, false);
    }

    /**
     * Rotate the signing secret. The new plaintext is returned on the
     * endpoint — share it with the receiver over a secure channel; it is
     * stored encrypted and never listed or logged.
     */
    public function rotateSecret(string $id): WebhookEndpoint
    {
        return $this->store->rotateSecret($id, bin2hex(random_bytes(32)));
    }

    public function unregister(string $id): bool
    {
        return $this->store->deleteEndpoint($id);
    }

    /**
     * Dispatch a named event payload to every matching active endpoint.
     * Returns the created deliveries (status `queued`); each carries a
     * stable ID that survives retries — receivers should deduplicate on
     * `X-Sahil-Delivery`.
     *
     * @return list<WebhookDelivery>
     */
    public function deliver(string $event, array $payload): array
    {
        $body = json_encode($payload);

        if ($body === false) {
            throw new WebhookException(sprintf('Webhook payload for [%s] is not JSON-serializable.', $event));
        }

        $deliveries = [];

        foreach ($this->store->endpointsFor($event) as $endpoint) {
            $delivery = $this->store->createDelivery($endpoint->id, $event, $body);
            (new DeliverWebhookJob($delivery->id))->onQueue($this->queue)->dispatch();
            $deliveries[] = $delivery;
        }

        return $deliveries;
    }

    public function delivery(string $id): ?WebhookDelivery
    {
        return $this->store->delivery($id);
    }

    /** @return list<WebhookDelivery> */
    public function deliveriesFor(string $endpointId, int $limit = 50): array
    {
        return $this->store->deliveriesFor($endpointId, $limit);
    }

    public function store(): WebhookStore
    {
        return $this->store;
    }
}
