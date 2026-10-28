<?php

declare(strict_types=1);

namespace Sahil\Tests\Notifications;

use Sahil\Contracts\Notifications\NotifiableContract;
use Sahil\Mail\Message;
use Sahil\Notifications\Notification;

/**
 * Mail notification fixture that leaves the recipient to the channel.
 */
final class WelcomeNotification extends Notification
{
    public function toMail(NotifiableContract $notifiable): Message
    {
        return Message::make()
            ->subject('Welcome to SahilPHP')
            ->text("Hello,\n\nYour account is ready.");
    }
}
