<?php

declare(strict_types=1);

namespace Sahil\Notifications;

/**
 * One persisted notification, as stored by {@see NotificationStore}.
 *
 * `data` is the decoded JSON payload the notification's `toDatabase()`
 * produced; `readAt` is null until the notification is marked read.
 */
final class DatabaseNotification
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $id,
        public readonly string $notifiableType,
        public readonly string $notifiableId,
        public readonly string $type,
        public readonly array $data,
        public readonly ?int $readAt,
        public readonly int $createdAt,
    ) {
    }

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }

    public function isUnread(): bool
    {
        return $this->readAt === null;
    }
}
