<?php

declare(strict_types=1);

namespace Sahil\Notifications\Channels;

use Sahil\Contracts\Notifications\ChannelContract;
use Sahil\Contracts\Notifications\DatabaseNotificationContract;
use Sahil\Contracts\Notifications\NotifiableContract;
use Sahil\Contracts\Notifications\NotificationContract;
use Sahil\Notifications\NotificationException;
use Sahil\Notifications\NotificationResult;
use Sahil\Notifications\NotificationStore;

/**
 * Persists notifications for later retrieval (bell-icon/inbox use-cases).
 *
 * The recipient is resolved the same way every channel resolves its route:
 * `routeNotificationFor('database')` supplies the recipient key and the
 * notifiable's class supplies the type, so different model classes can
 * share one table without colliding.
 */
final class DatabaseChannel implements ChannelContract
{
    public function __construct(
        private readonly NotificationStore $store,
    ) {
    }

    public function name(): string
    {
        return 'database';
    }

    public function send(NotifiableContract $notifiable, NotificationContract $notification): NotificationResult
    {
        if (!$notification instanceof DatabaseNotificationContract) {
            throw new NotificationException(sprintf(
                'Notification [%s] asks for the [database] channel but does not implement %s.',
                $notification::class,
                DatabaseNotificationContract::class,
            ));
        }

        $route = $notifiable->routeNotificationFor('database');

        if ($route === null || trim($route) === '') {
            return NotificationResult::skipped('database', 'The notifiable has no database route.');
        }

        $payload = $notification->toDatabase($notifiable);

        // Persisting an unserializable payload must fail loudly, not store garbage.
        json_encode($payload, JSON_THROW_ON_ERROR);

        $id = $this->store->create(
            $notifiable::class,
            $route,
            $notification::class,
            $payload,
        );

        return NotificationResult::success('database', 'Stored notification '.$id.'.');
    }
}
