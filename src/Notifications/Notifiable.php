<?php

declare(strict_types=1);

namespace Sahil\Notifications;

use Sahil\Contracts\Notifications\NotifiableContract;
use Sahil\Contracts\Notifications\NotificationContract;

/**
 * Makes a model notifiable — `routeNotificationFor()` plus the
 * send/retrieve/manage API the database channel exposes.
 *
 *     final class User extends Model implements NotifiableContract
 *     {
 *         use Notifiable;
 *     }
 *
 * Routing defaults: `mail` → the model's `email` attribute,
 * `database` → its primary key (`id`, or `getAuthIdentifier()` when the
 * model is also an `AuthenticatableContract`). Override the
 * `notificationRouteFor*()`-style methods when a model routes
 * differently.
 */
trait Notifiable
{
    /**
     * Channel routing: `mail` resolves the recipient address, `database`
     * the recipient key. Unknown channels fall back to null — the channel
     * reports `skipped` rather than guessing.
     */
    public function routeNotificationFor(string $channel): ?string
    {
        return match ($channel) {
            'mail' => $this->notificationMailRoute(),
            'database' => $this->notificationDatabaseKey(),
            default => null,
        };
    }

    /**
     * Deliver a notification now or through the queue.
     */
    public function notify(NotificationContract $notification, bool $queue = false, int $delay = 0): NotificationReport|string
    {
        return $queue
            ? Notify::queue($this, $notification, $delay)
            : Notify::send($this, $notification);
    }

    /** @return list<DatabaseNotification> newest first */
    public function notifications(int $limit = 50, int $offset = 0): array
    {
        return $this->notificationStore()->forRecipient(
            static::class,
            (string) $this->notificationDatabaseKey(),
            $limit,
            $offset,
        );
    }

    /** @return list<DatabaseNotification> newest first */
    public function unreadNotifications(int $limit = 50): array
    {
        return $this->notificationStore()->unreadForRecipient(
            static::class,
            (string) $this->notificationDatabaseKey(),
            $limit,
        );
    }

    public function unreadNotificationsCount(): int
    {
        return $this->notificationStore()->unreadCount(
            static::class,
            (string) $this->notificationDatabaseKey(),
        );
    }

    public function markNotificationAsRead(string $id): bool
    {
        return $this->notificationStore()->markRead(
            static::class,
            (string) $this->notificationDatabaseKey(),
            $id,
        );
    }

    public function markAllNotificationsAsRead(): int
    {
        return $this->notificationStore()->markAllRead(
            static::class,
            (string) $this->notificationDatabaseKey(),
        );
    }

    public function deleteNotification(string $id): bool
    {
        return $this->notificationStore()->delete(
            static::class,
            (string) $this->notificationDatabaseKey(),
            $id,
        );
    }

    public function clearNotifications(): int
    {
        return $this->notificationStore()->deleteAll(
            static::class,
            (string) $this->notificationDatabaseKey(),
        );
    }

    /**
     * The mail route — the `email` attribute by default.
     */
    protected function notificationMailRoute(): ?string
    {
        /** @var mixed $email */
        $email = $this->email ?? (method_exists($this, 'getEmail') ? $this->getEmail() : null);

        return is_string($email) && $email !== '' ? $email : null;
    }

    /**
     * The recipient key — `getAuthIdentifier()` when available (the same
     * identity the session uses), otherwise the `id` attribute.
     */
    protected function notificationDatabaseKey(): ?string
    {
        if (method_exists($this, 'getAuthIdentifier')) {
            $id = $this->getAuthIdentifier();

            if (is_int($id) || (is_string($id) && $id !== '')) {
                return (string) $id;
            }
        }

        /** @var mixed $id */
        $id = $this->id ?? null;

        return is_scalar($id) && (string) $id !== '' ? (string) $id : null;
    }

    private function notificationStore(): NotificationStore
    {
        return Notify::store();
    }
}
