<?php

declare(strict_types=1);

namespace Sahil\Notifications;

use Sahil\Contracts\Notifications\DatabaseNotificationContract;
use Sahil\Contracts\Notifications\MailNotificationContract;
use Sahil\Contracts\Notifications\NotifiableContract;
use Sahil\Mail\Message;

/**
 * Base class for notifications.
 *
 * Override `via()` to pick channels (`mail`, `database`, or any channel
 * registered on the Notifier) and override the matching `to*()` composer.
 * The base methods throw with a descriptive message, so reaching a channel
 * a notification cannot compose is a loud wiring bug — never a silent no-op.
 */
abstract class Notification implements DatabaseNotificationContract, MailNotificationContract
{
    /**
     * @return list<string>
     */
    public function via(NotifiableContract $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(NotifiableContract $notifiable): Message
    {
        throw NotificationException::missingComposer(static::class, 'mail');
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(NotifiableContract $notifiable): array
    {
        throw NotificationException::missingComposer(static::class, 'database');
    }
}
