<?php

declare(strict_types=1);

namespace Sahil\Webhooks;

use Sahil\Database\Connection;
use Sahil\Encryption\Encrypter;

/**
 * QueryBuilder-backed store. Secrets persist via {@see Encrypter}
 * (APP_KEY); diagnostic error text is bounded at 2 KiB so retries can
 * never grow rows unboundedly.
 */
final class DatabaseWebhookStore implements WebhookStore
{
    private const ERROR_LIMIT = 2048;

    public function __construct(
        private readonly Connection $db,
        private readonly ?Encrypter $encrypter = null,
        private readonly string $endpointsTable = 'webhook_endpoints',
        private readonly string $deliveriesTable = 'webhook_deliveries',
    ) {
    }

    public function createEndpoint(string $url, array $events, string $secret): WebhookEndpoint
    {
        $now = time();
        $id = bin2hex(random_bytes(16));

        $this->db->table($this->endpointsTable)->insert([
            'id' => $id,
            'url' => $url,
            'events' => json_encode(array_values($events)),
            'active' => 1,
            'secret' => $this->seal($secret),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return new WebhookEndpoint($id, $url, array_values($events), true, $secret, $now, $now);
    }

    public function endpoint(string $id): ?WebhookEndpoint
    {
        $row = $this->db->table($this->endpointsTable)->where('id', '=', $id)->first();

        return $row === null ? null : $this->endpointFrom($row);
    }

    public function endpoints(): array
    {
        return array_map(
            fn (array $row): WebhookEndpoint => $this->endpointFrom($row),
            $this->db->table($this->endpointsTable)->orderBy('created_at')->get()->rows(),
        );
    }

    public function endpointsFor(string $event): array
    {
        return array_values(array_filter(
            $this->endpoints(),
            static fn (WebhookEndpoint $e): bool => $e->active && $e->subscribes($event),
        ));
    }

    public function updateEndpoint(string $id, ?string $url, ?array $events): WebhookEndpoint
    {
        $endpoint = $this->requireEndpoint($id);
        $now = time();
        $values = ['updated_at' => $now];

        if ($url !== null) {
            $values['url'] = $url;
        }

        if ($events !== null) {
            $values['events'] = json_encode(array_values($events));
        }

        $this->db->table($this->endpointsTable)->where('id', '=', $id)->update($values);

        return new WebhookEndpoint(
            $id,
            $url ?? $endpoint->url,
            $events !== null ? array_values($events) : $endpoint->events,
            $endpoint->active,
            $endpoint->secret,
            $endpoint->createdAt,
            $now,
        );
    }

    public function setEndpointActive(string $id, bool $active): WebhookEndpoint
    {
        $endpoint = $this->requireEndpoint($id);
        $this->db->table($this->endpointsTable)->where('id', '=', $id)->update([
            'active' => $active ? 1 : 0,
            'updated_at' => time(),
        ]);

        return $endpoint->with($active);
    }

    public function rotateSecret(string $id, string $secret): WebhookEndpoint
    {
        $endpoint = $this->requireEndpoint($id);
        $this->db->table($this->endpointsTable)->where('id', '=', $id)->update([
            'secret' => $this->seal($secret),
            'updated_at' => time(),
        ]);

        return new WebhookEndpoint($id, $endpoint->url, $endpoint->events, $endpoint->active, $secret, $endpoint->createdAt, time());
    }

    public function deleteEndpoint(string $id): bool
    {
        return $this->db->table($this->endpointsTable)->where('id', '=', $id)->delete()->affected() > 0;
    }

    public function createDelivery(string $endpointId, string $event, string $payload): WebhookDelivery
    {
        $now = time();
        $id = bin2hex(random_bytes(16));

        $this->db->table($this->deliveriesTable)->insert([
            'id' => $id,
            'endpoint_id' => $endpointId,
            'event' => $event,
            'status' => WebhookDelivery::STATUS_QUEUED,
            'attempts' => 0,
            'payload' => $payload,
            'created_at' => $now,
            'completed_at' => null,
        ]);

        return new WebhookDelivery($id, $endpointId, $event, WebhookDelivery::STATUS_QUEUED, 0, null, null, $payload, $now, null);
    }

    public function delivery(string $id): ?WebhookDelivery
    {
        $row = $this->db->table($this->deliveriesTable)->where('id', '=', $id)->first();

        return $row === null ? null : $this->deliveryFrom($row);
    }

    public function markAttempt(string $id): void
    {
        $delivery = $this->delivery($id);

        if ($delivery !== null) {
            $this->db->table($this->deliveriesTable)->where('id', '=', $id)->update(['attempts' => $delivery->attempts + 1]);
        }
    }

    public function markDelivered(string $id, int $httpStatus): void
    {
        $this->db->table($this->deliveriesTable)->where('id', '=', $id)->update([
            'status' => WebhookDelivery::STATUS_DELIVERED,
            'last_status' => $httpStatus,
            'error' => null,
            'completed_at' => time(),
        ]);
    }

    public function markFailed(string $id, ?int $httpStatus, string $error): void
    {
        $this->db->table($this->deliveriesTable)->where('id', '=', $id)->update([
            'status' => WebhookDelivery::STATUS_FAILED,
            'last_status' => $httpStatus,
            'error' => substr($error, 0, self::ERROR_LIMIT),
            'completed_at' => time(),
        ]);
    }

    public function markRetry(string $id, ?int $httpStatus, string $error): void
    {
        $this->db->table($this->deliveriesTable)->where('id', '=', $id)->update([
            'status' => WebhookDelivery::STATUS_QUEUED,
            'last_status' => $httpStatus,
            'error' => substr($error, 0, self::ERROR_LIMIT),
        ]);
    }

    public function deliveriesFor(string $endpointId, int $limit = 50): array
    {
        return array_map(
            fn (array $row): WebhookDelivery => $this->deliveryFrom($row),
            $this->db->table($this->deliveriesTable)
                ->where('endpoint_id', '=', $endpointId)
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->rows(),
        );
    }

    private function requireEndpoint(string $id): WebhookEndpoint
    {
        return $this->endpoint($id) ?? throw new WebhookException(sprintf('Webhook endpoint [%s] does not exist.', $id));
    }

    private function endpointFrom(array $row): WebhookEndpoint
    {
        return new WebhookEndpoint(
            (string) $row['id'],
            (string) $row['url'],
            array_values((array) json_decode((string) $row['events'], true)),
            (int) $row['active'] === 1,
            $this->open((string) $row['secret']),
            (int) $row['created_at'],
            (int) $row['updated_at'],
        );
    }

    private function deliveryFrom(array $row): WebhookDelivery
    {
        return new WebhookDelivery(
            (string) $row['id'],
            (string) $row['endpoint_id'],
            (string) $row['event'],
            (string) $row['status'],
            (int) $row['attempts'],
            $row['last_status'] === null ? null : (int) $row['last_status'],
            $row['error'] === null ? null : (string) $row['error'],
            (string) $row['payload'],
            (int) $row['created_at'],
            $row['completed_at'] === null ? null : (int) $row['completed_at'],
        );
    }

    /** Encrypt for storage; plaintext when no encrypter is configured (documented). */
    private function seal(#[\SensitiveParameter] string $secret): string
    {
        if ($secret === '') {
            throw new WebhookException('A webhook signing secret must not be empty.');
        }

        return $this->encrypter?->encryptString($secret) ?? $secret;
    }

    private function open(string $stored): string
    {
        return $this->encrypter?->decryptString($stored) ?? $stored;
    }
}
