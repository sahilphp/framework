<?php

declare(strict_types=1);

namespace Sahil\Notifications;

use Sahil\Database\Connection;
use Sahil\Database\QueryBuilder;

/**
 * `NotificationStore` on the framework's query builder — every query is
 * parameterized and recipient-scoped.
 *
 * Table (see the starter's `create_notifications_table` migration):
 * `id` text pk, `notifiable_type`, `notifiable_id`, `type`, `data` (JSON),
 * `read_at` nullable int, `created_at` int.
 */
final class DatabaseNotificationStore implements NotificationStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table = 'notifications',
    ) {
    }

    public function create(string $notifiableType, string $notifiableId, string $type, array $data): string
    {
        $id = bin2hex(random_bytes(16));

        $encoded = json_encode($data, JSON_THROW_ON_ERROR);

        $this->query()->insert([
            'id' => $id,
            'notifiable_type' => $notifiableType,
            'notifiable_id' => $notifiableId,
            'type' => $type,
            'data' => $encoded,
            'read_at' => null,
            'created_at' => time(),
        ]);

        return $id;
    }

    public function forRecipient(string $notifiableType, string $notifiableId, int $limit = 50, int $offset = 0): array
    {
        $rows = $this->for($notifiableType, $notifiableId)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->offset($offset)
            ->get()
            ->rows();

        return array_map(self::hydrate(...), $rows);
    }

    public function unreadForRecipient(string $notifiableType, string $notifiableId, int $limit = 50): array
    {
        $rows = $this->for($notifiableType, $notifiableId)
            ->whereNull('read_at')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get()
            ->rows();

        return array_map(self::hydrate(...), $rows);
    }

    public function unreadCount(string $notifiableType, string $notifiableId): int
    {
        [$sql, $params] = $this->for($notifiableType, $notifiableId)
            ->whereNull('read_at')
            ->countSql();

        $row = $this->connection->execute($sql, $params)->first();

        return (int) ($row['aggregate'] ?? 0);
    }

    public function markRead(string $notifiableType, string $notifiableId, string $id): bool
    {
        $result = $this->for($notifiableType, $notifiableId)
            ->where('id', '=', $id)
            ->whereNull('read_at')
            ->update(['read_at' => time()]);

        return $result->affected() > 0;
    }

    public function markAllRead(string $notifiableType, string $notifiableId): int
    {
        return $this->for($notifiableType, $notifiableId)
            ->whereNull('read_at')
            ->update(['read_at' => time()])
            ->affected();
    }

    public function delete(string $notifiableType, string $notifiableId, string $id): bool
    {
        return $this->for($notifiableType, $notifiableId)
            ->where('id', '=', $id)
            ->delete()
            ->affected() > 0;
    }

    public function deleteAll(string $notifiableType, string $notifiableId): int
    {
        return $this->for($notifiableType, $notifiableId)
            ->delete()
            ->affected();
    }

    private function query(): QueryBuilder
    {
        return $this->connection->table($this->table);
    }

    private function for(string $notifiableType, string $notifiableId): QueryBuilder
    {
        return $this->query()
            ->where('notifiable_type', '=', $notifiableType)
            ->where('notifiable_id', '=', $notifiableId);
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): DatabaseNotification
    {
        $decoded = json_decode((string) $row['data'], true);

        return new DatabaseNotification(
            id: (string) $row['id'],
            notifiableType: (string) $row['notifiable_type'],
            notifiableId: (string) $row['notifiable_id'],
            type: (string) $row['type'],
            data: is_array($decoded) ? $decoded : [],
            readAt: isset($row['read_at']) ? (int) $row['read_at'] : null,
            createdAt: (int) $row['created_at'],
        );
    }
}
