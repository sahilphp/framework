<?php

declare(strict_types=1);

namespace Sahil\Tests\Notifications;

use PHPUnit\Framework\TestCase;
use Sahil\Contracts\Notifications\NotifiableContract;
use Sahil\Database\ConnectionFactory;
use Sahil\Mail\MailManager;
use Sahil\Mail\Message;
use Sahil\Mail\Transports\ArrayTransport;
use Sahil\Notifications\Channels\DatabaseChannel;
use Sahil\Notifications\Channels\MailChannel;
use Sahil\Notifications\DatabaseNotificationStore;
use Sahil\Notifications\MailMessage;
use Sahil\Notifications\Notifiable;
use Sahil\Notifications\Notification;
use Sahil\Notifications\Notifier;
use Sahil\Notifications\Notify;

final class ApiNotifiable implements NotifiableContract
{
    use Notifiable;

    public function __construct(
        private readonly string $key,
        private readonly ?string $mail = null,
    ) {
    }

    protected function notificationDatabaseKey(): ?string
    {
        return $this->key;
    }

    protected function notificationMailRoute(): ?string
    {
        return $this->mail;
    }
}

final class MultiChannelNotification extends Notification
{
    public function via(NotifiableContract $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(NotifiableContract $notifiable): array
    {
        return ['title' => 'Welcome', 'message' => 'Your account is ready.'];
    }

    public function toMail(NotifiableContract $notifiable): Message
    {
        return MailMessage::make()
            ->subject('Welcome')
            ->greeting('Welcome!')
            ->line('Your account is ready.')
            ->action('Open dashboard', 'https://app.test/dashboard')
            ->closing('The team')
            ->toMessage();
    }
}

final class NotificationApiTest extends TestCase
{
    protected function tearDown(): void
    {
        Notify::flushState();
    }

    private function mailer(): MailManager
    {
        return new MailManager(
            ['array' => ['driver' => 'array']],
            'array',
            ['address' => 'no-reply@example.com', 'name' => 'SahilPHP'],
        );
    }

    private function store(): DatabaseNotificationStore
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite not installed.');
        }

        $connection = (new ConnectionFactory())->make([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $connection->execute(
            'CREATE TABLE notifications (
                id TEXT PRIMARY KEY, notifiable_type TEXT NOT NULL,
                notifiable_id TEXT NOT NULL, type TEXT NOT NULL,
                data TEXT NOT NULL, read_at INTEGER NULL, created_at INTEGER NOT NULL
            )',
        );

        return new DatabaseNotificationStore($connection);
    }

    public function test_mail_message_composes_transport_message(): void
    {
        $message = MailMessage::make()
            ->subject('Hi')
            ->greeting('Hello!')
            ->line('Line one.')
            ->line('Line two.')
            ->action('Go', 'https://x.test')
            ->closing('Bye')
            ->toMessage();

        $this->assertSame('Hi', $message->subjectLine());
        $body = $message->textBody();
        $this->assertStringContainsString('Hello!', $body);
        $this->assertStringContainsString('Line one.', $body);
        $this->assertStringContainsString('Go: https://x.test', $body);
        $this->assertStringContainsString('Bye', $body);
    }

    public function test_mail_message_carries_attachments_into_the_message(): void
    {
        $message = MailMessage::make()
            ->subject('Invoice')
            ->line('See attached.')
            ->attachData('PDF-BYTES', 'invoice.pdf', 'application/pdf')
            ->attach('/tmp/report.pdf')
            ->toMessage();

        $attachments = $message->attachments();
        $this->assertCount(2, $attachments);
        $this->assertSame('invoice.pdf', $attachments[0]->name);
        $this->assertSame('PDF-BYTES', $attachments[0]->contents());
        $this->assertSame('/tmp/report.pdf', $attachments[1]->path);
    }

    public function test_multi_channel_notification_delivers_each_channel(): void
    {
        $store = $this->store();
        $mailer = $this->mailer();

        Notify::swap(new Notifier([
            new MailChannel($mailer),
            new DatabaseChannel($store),
        ]), $store);

        $user = new ApiNotifiable('5', 'u@x.test');
        $report = $user->notify(new MultiChannelNotification());

        $this->assertTrue($report->forChannel('mail')->successful());
        $this->assertTrue($report->forChannel('database')->successful());
        $this->assertSame(1, $user->unreadNotificationsCount());
        $this->assertSame('Welcome', $user->notifications()[0]->data['title']);

        // Mail transport received exactly one message to the routed address.
        $transport = $mailer->transport('array');
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $this->assertCount(1, $transport->messages());
        $this->assertSame('u@x.test', $transport->messages()[0]->toAddresses()[0]->email);
    }

    public function test_partial_channel_failure_does_not_erase_other_channels(): void
    {
        $store = $this->store();
        $mailer = $this->mailer();

        Notify::swap(new Notifier([
            new MailChannel($mailer),
            new DatabaseChannel($store),
        ]), $store);

        // No mail route → mail reports skipped, database still persists.
        $user = new ApiNotifiable('8', null);
        $report = $user->notify(new MultiChannelNotification());

        $this->assertSame('skipped', $report->forChannel('mail')->status);
        $this->assertTrue($report->forChannel('database')->successful());
        $this->assertSame(1, $user->unreadNotificationsCount());
    }

    public function test_send_to_multiple_recipients(): void
    {
        $store = $this->store();
        Notify::swap(new Notifier([new DatabaseChannel($store)]), $store);

        $reports = Notify::send(
            [new ApiNotifiable('1'), new ApiNotifiable('2')],
            new class () extends Notification {
                public function via(NotifiableContract $n): array
                {
                    return ['database'];
                }

                public function toDatabase(NotifiableContract $n): array
                {
                    return ['t' => 1];
                }
            },
        );

        $this->assertCount(2, $reports);
        $this->assertSame(1, $store->unreadCount(ApiNotifiable::class, '1'));
        $this->assertSame(1, $store->unreadCount(ApiNotifiable::class, '2'));
    }
}
