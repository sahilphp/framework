<?php

declare(strict_types=1);

namespace Sahil\Notifications;

/**
 * Persistence for the `database` notification channel.
 *
 * Every operation is scoped to a `(type, id)` recipient pair — the store
 * never crosses recipient boundaries, which is what keeps one user's
 * notifications unreadable by another. Implementations must use
 * parameterized queries and must not load a full result set just to
 * count it.
 */
interface NotificationStore
{
    /**
     * Persist a notification for a recipient and return its id.
     *
     * @param array<string, mixed> $data JSON-serializable payload
     */
    public function create(string $notifiableType, string $notifiableId, string $type, array $data): string;

    /**
     * A recipient's notifications, newest first.
     *
     * @return list<DatabaseNotification>
     */
    public function forRecipient(string $notifiableType, string $notifiableId, int $limit = 50, int $offset = 0): array;

    /**
     * A recipient's unread notifications, newest first.
     *
     * @return list<DatabaseNotification>
     */
    public function unreadForRecipient(string $notifiableType, string $notifiableId, int $limit = 50): array;

    public function unreadCount(string $notifiableType, string $notifiableId): int;

    /**
     * Mark one of the recipient's notifications read. Returns false when
     * the id does not exist *for that recipient*.
     */
    public function markRead(string $notifiableType, string $notifiableId, string $id): bool;

    /** @return int number of notifications marked read */
    public function markAllRead(string $notifiableType, string $notifiableId): int;

    /** Same scoping rule as {@see markRead()}. */
    public function delete(string $notifiableType, string $notifiableId, string $id): bool;

    /** @return int number of notifications deleted */
    public function deleteAll(string $notifiableType, string $notifiableId): int;
}
