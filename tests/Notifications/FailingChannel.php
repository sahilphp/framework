<?php

declare(strict_types=1);

namespace Sahil\Tests\Notifications;

use RuntimeException;
use Sahil\Contracts\Notifications\ChannelContract;
use Sahil\Contracts\Notifications\NotifiableContract;
use Sahil\Contracts\Notifications\NotificationContract;
use Sahil\Notifications\NotificationResult;

/**
 * Channel fixture that fails with a message containing a credential, so
 * tests can assert the notifier sanitises it.
 */
final class FailingChannel implements ChannelContract
{
    public function name(): string
    {
        return 'sms';
    }

    public function send(NotifiableContract $notifiable, NotificationContract $notification): NotificationResult
    {
        throw new RuntimeException('Gateway rejected password=hunter2 for user ada.');
    }
}
