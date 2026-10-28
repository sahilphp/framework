<?php

declare(strict_types=1);

namespace Sahil\Tests\Notifications;

use PHPUnit\Framework\TestCase;
use Sahil\Contracts\Notifications\NotifiableContract;
use Sahil\Contracts\Notifications\NotificationContract;
use Sahil\Mail\MailManager;
use Sahil\Mail\Message;
use Sahil\Mail\Transports\ArrayTransport;
use Sahil\Notifications\Channels\MailChannel;
use Sahil\Notifications\Notification;
use Sahil\Notifications\NotificationException;
use Sahil\Notifications\Notifier;
use Sahil\Tests\Mail\CollectingLogger;
use Sahil\Tests\Mail\RecordingDispatcher;

final class NotifierTest extends TestCase
{
    private function mailer(
        bool $queueEnabled = false,
        ?CollectingLogger $logger = null,
        ?RecordingDispatcher $dispatcher = null,
    ): MailManager {
        return new MailManager(
            ['array' => ['driver' => 'array']],
            'array',
            ['address' => 'no-reply@example.com', 'name' => 'SahilPHP'],
            $queueEnabled,
            'emails',
            0,
            $logger,
            $dispatcher === null ? null : static fn (): RecordingDispatcher => $dispatcher,
        );
    }

    private function arrayTransport(MailManager $mailer): ArrayTransport
    {
        $transport = $mailer->transport('array');

        self::assertInstanceOf(ArrayTransport::class, $transport);

        return $transport;
    }

    public function test_the_mail_channel_delivers_to_the_notifiable_route(): void
    {
        $mailer = $this->mailer();
        $notifier = new Notifier([new MailChannel($mailer)]);

        $report = $notifier->send(
            new TestNotifiable(['mail' => 'ada@example.com']),
            new WelcomeNotification(),
        );

        $result = $report->forChannel('mail');

        self::assertNotNull($result);
        self::assertSame('accepted', $result->status);
        self::assertTrue($result->successful());
        self::assertFalse($report->failed());
        self::assertStringContainsString('not confirmed', $result->describe());

        $messages = $this->arrayTransport($mailer)->messages();

        self::assertCount(1, $messages);
        self::assertSame('ada@example.com', $messages[0]->toAddresses()[0]->email);
        self::assertSame('no-reply@example.com', $messages[0]->sender()?->email);
    }

    public function test_an_explicitly_addressed_message_keeps_its_recipients(): void
    {
        $mailer = $this->mailer();
        $notifier = new Notifier([new MailChannel($mailer)]);

        $notification = new class () extends Notification {
            public function toMail(NotifiableContract $notifiable): Message
            {
                return Message::make()
                    ->to('billing@example.com')
                    ->subject('Invoice')
                    ->text('Attached.');
            }
        };

        $notifier->send(new TestNotifiable(['mail' => 'ada@example.com']), $notification);

        $messages = $this->arrayTransport($mailer)->messages();

        self::assertCount(1, $messages);
        self::assertSame('billing@example.com', $messages[0]->toAddresses()[0]->email);
        self::assertSame(1, $messages[0]->recipientCount());
    }

    public function test_a_queued_mail_channel_reports_queued_without_sending(): void
    {
        $dispatcher = new RecordingDispatcher();
        $mailer = $this->mailer(queueEnabled: true, dispatcher: $dispatcher);
        $notifier = new Notifier([new MailChannel($mailer, true, 15)]);

        $report = $notifier->send(
            new TestNotifiable(['mail' => 'ada@example.com']),
            new WelcomeNotification(),
        );

        $result = $report->forChannel('mail');

        self::assertNotNull($result);
        self::assertSame('queued', $result->status);
        self::assertStringContainsString('no transport has attempted it yet', (string) $result->receipt?->detail);
        self::assertSame([], $this->arrayTransport($mailer)->messages());
        self::assertCount(1, $dispatcher->pushed);
        self::assertSame(15, $dispatcher->pushed[0]['delay']);
    }

    public function test_a_missing_route_skips_the_channel(): void
    {
        $notifier = new Notifier([new MailChannel($this->mailer())]);

        $report = $notifier->send(new TestNotifiable([]), new WelcomeNotification());

        $result = $report->forChannel('mail');

        self::assertNotNull($result);
        self::assertSame('skipped', $result->status);
        self::assertTrue($result->successful());
        self::assertFalse($report->failed(), 'a missing route is a skip, not a failure');
    }

    public function test_an_unknown_channel_is_a_configuration_error(): void
    {
        $notifier = new Notifier([new MailChannel($this->mailer())]);

        try {
            $notifier->send(new TestNotifiable(['sms' => '+10000000000']), new SmsOnlyNotification());
            self::fail('An unknown channel must not be silently ignored.');
        } catch (NotificationException $error) {
            self::assertStringContainsString('[sms]', $error->getMessage());
            self::assertStringContainsString('mail', $error->getMessage());
        }
    }

    public function test_the_mail_channel_rejects_a_notification_it_cannot_compose(): void
    {
        $logger = new CollectingLogger();
        $notifier = new Notifier([new MailChannel($this->mailer())], $logger);

        $notification = new class () implements NotificationContract {
            public function via(NotifiableContract $notifiable): array
            {
                return ['mail'];
            }
        };

        $report = $notifier->send(new TestNotifiable(['mail' => 'ada@example.com']), $notification);

        self::assertTrue($report->failed());
        self::assertSame(['mail'], $report->failedChannels());
        self::assertStringContainsString('MailNotificationContract', (string) $report->forChannel('mail')?->detail);

        $entry = $logger->last();

        self::assertNotNull($entry);
        self::assertSame('error', $entry['level']);
        self::assertSame('notification.failed', $entry['message']);
        self::assertSame('mail', $entry['context']['channel']);
    }

    public function test_one_failing_channel_does_not_stop_the_others(): void
    {
        $logger = new CollectingLogger();
        $push = new RecordingChannel('push');
        $notifier = new Notifier([new FailingChannel(), $push], $logger);

        $notification = new class () implements NotificationContract {
            public function via(NotifiableContract $notifiable): array
            {
                return ['sms', 'push'];
            }
        };

        $report = $notifier->send(new TestNotifiable(['sms' => '+10000000000']), $notification);

        self::assertTrue($report->failed());
        self::assertSame(['sms'], $report->failedChannels());
        self::assertSame('sent', $report->forChannel('push')?->status);
        self::assertSame(1, $push->calls);

        $logged = json_encode($logger->entries, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('hunter2', $logged);
        self::assertStringContainsString('password=[redacted]', $logged);
    }

    public function test_duplicate_channels_are_delivered_once(): void
    {
        $push = new RecordingChannel('push');
        $notifier = new Notifier([$push]);

        $notification = new class () implements NotificationContract {
            public function via(NotifiableContract $notifiable): array
            {
                return ['push', 'push'];
            }
        };

        $report = $notifier->send(new TestNotifiable(), $notification);

        self::assertSame(1, $push->calls);
        self::assertCount(1, $report->results());
    }

    public function test_an_empty_via_list_sends_nothing(): void
    {
        $push = new RecordingChannel('push');
        $notifier = new Notifier([$push]);

        $notification = new class () implements NotificationContract {
            public function via(NotifiableContract $notifiable): array
            {
                return [];
            }
        };

        $report = $notifier->send(new TestNotifiable(), $notification);

        self::assertTrue($report->isEmpty());
        self::assertFalse($report->failed());
        self::assertSame(0, $push->calls);
    }

    public function test_a_channel_can_be_registered_after_construction(): void
    {
        $notifier = new Notifier();
        $chat = new RecordingChannel('chat');
        $notifier->extend($chat);

        $notification = new class () implements NotificationContract {
            public function via(NotifiableContract $notifiable): array
            {
                return ['chat'];
            }
        };

        $report = $notifier->send(new TestNotifiable(), $notification);

        self::assertSame('sent', $report->forChannel('chat')?->status);
        self::assertSame(1, $chat->calls);
    }
}
