<?php

declare(strict_types=1);

namespace Sahil\Tests\Notifications;

use Sahil\Contracts\Notifications\NotifiableContract;
use Sahil\Contracts\Notifications\NotificationContract;

/**
 * Notification fixture that asks for a non-mail channel only.
 */
final class SmsOnlyNotification implements NotificationContract
{
    /**
     * @return list<string>
     */
    public function via(NotifiableContract $notifiable): array
    {
        return ['sms'];
    }
}
