<?php

declare(strict_types=1);

namespace Sahil\Tests\Notifications;

use Sahil\Contracts\Notifications\ChannelContract;
use Sahil\Contracts\Notifications\NotifiableContract;
use Sahil\Contracts\Notifications\NotificationContract;
use Sahil\Notifications\NotificationResult;

/**
 * Channel fixture for testing future, non-mail channels.
 */
final class RecordingChannel implements ChannelContract
{
    public int $calls = 0;

    public function __construct(private readonly string $channel = 'sms')
    {
    }

    public function name(): string
    {
        return $this->channel;
    }

    public function send(NotifiableContract $notifiable, NotificationContract $notification): NotificationResult
    {
        $this->calls++;

        return NotificationResult::success($this->channel, 'recorded');
    }
}
