<?php

declare(strict_types=1);

namespace Sahil\Tests\Notifications;

use PHPUnit\Framework\TestCase;
use Sahil\Contracts\Notifications\NotifiableContract;
use Sahil\Database\Connection;
use Sahil\Database\ConnectionFactory;
use Sahil\Notifications\Channels\DatabaseChannel;
use Sahil\Notifications\DatabaseNotificationStore;
use Sahil\Notifications\Notifiable;
use Sahil\Notifications\Notification;
use Sahil\Notifications\NotificationException;
use Sahil\Notifications\Notifier;

final class TraitNotifiable implements NotifiableContract
{
    use Notifiable;

    public function __construct(private readonly string $key)
    {
    }

    protected function notificationDatabaseKey(): ?string
    {
        return $this->key;
    }
}

final class DatabaseChannelTest extends TestCase
{
    private Connection $connection;

    private DatabaseNotificationStore $store;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite not installed.');
        }

        $this->connection = (new ConnectionFactory())->make([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $this->connection->execute(
            'CREATE TABLE notifications (
                id TEXT PRIMARY KEY,
                notifiable_type TEXT NOT NULL,
                notifiable_id TEXT NOT NULL,
                type TEXT NOT NULL,
                data TEXT NOT NULL,
                read_at INTEGER NULL,
                created_at INTEGER NOT NULL
            )',
        );

        $this->store = new DatabaseNotificationStore($this->connection);
    }

    private static function user(string $id): TestNotifiable
    {
        return new TestNotifiable(['database' => $id]);
    }

    private static function notification(array $data = ['title' => 'Hi']): Notification
    {
        return new class ($data) extends Notification {
            public function __construct(private readonly array $data)
            {
            }

            public function via(NotifiableContract $notifiable): array
            {
                return ['database'];
            }

            public function toDatabase(NotifiableContract $notifiable): array
            {
                return $this->data;
            }
        };
    }

    private function channel(): DatabaseChannel
    {
        return new DatabaseChannel($this->store);
    }

    // ---- persistence --------------------------------------------------

    public function test_database_channel_persists_payload(): void
    {
        $report = (new Notifier([$this->channel()]))->send(
            self::user('7'),
            self::notification(['title' => 'Welcome', 'level' => 3]),
        );

        $this->assertTrue($report->forChannel('database')->successful());

        $all = $this->store->forRecipient(TestNotifiable::class, '7');
        $this->assertCount(1, $all);
        $this->assertSame('Welcome', $all[0]->data['title']);
        $this->assertSame(TestNotifiable::class, $all[0]->notifiableType);
        $this->assertTrue($all[0]->isUnread());
        $this->assertSame(get_class(self::notification()), $all[0]->type);
    }

    public function test_notification_without_database_composer_fails_loudly(): void
    {
        $notifier = new Notifier([$this->channel()]);

        // Notification declares ['database'] via() but the store sees the
        // exception surface through the report (per-channel independence).
        $notification = new class () extends Notification {
            public function via(NotifiableContract $notifiable): array
            {
                return ['mail']; // will hit the channel() check below
            }
        };

        // via() asks for mail but only database is registered → loud error.
        $this->expectException(NotificationException::class);
        $notifier->send(self::user('1'), $notification);
    }

    public function test_unregistered_channel_name_throws(): void
    {
        $this->expectException(NotificationException::class);
        $this->expectExceptionMessage('not registered');
        (new Notifier([]))->send(self::user('1'), self::notification());
    }

    public function test_skips_notifiable_without_database_route(): void
    {
        $report = (new Notifier([$this->channel()]))->send(
            new TestNotifiable([]),
            self::notification(),
        );

        $this->assertSame('skipped', $report->forChannel('database')->status);
    }

    // ---- retrieval ----------------------------------------------------

    public function test_recipient_scoping_isolates_users(): void
    {
        $this->channel()->send(self::user('1'), self::notification(['n' => 'u1']));
        $this->channel()->send(self::user('2'), self::notification(['n' => 'u2']));

        $this->assertCount(1, $this->store->forRecipient(TestNotifiable::class, '1'));
        $this->assertSame('u1', $this->store->forRecipient(TestNotifiable::class, '1')[0]->data['n']);

        // User 2 cannot touch user 1's notification through any store method.
        $foreignId = $this->store->forRecipient(TestNotifiable::class, '1')[0]->id;
        $this->assertFalse($this->store->markRead(TestNotifiable::class, '2', $foreignId));
        $this->assertFalse($this->store->delete(TestNotifiable::class, '2', $foreignId));
        // User 2's markAllRead touches only user 2's own rows.
        $this->assertSame(1, $this->store->markAllRead(TestNotifiable::class, '2'));
        $this->assertTrue($this->store->forRecipient(TestNotifiable::class, '1')[0]->isUnread());
    }

    public function test_unread_retrieval_count_and_marking(): void
    {
        $user = self::user('9');
        $n = self::notification();
        $this->channel()->send($user, $n);
        $this->channel()->send($user, self::notification(['t' => 2]));
        $this->channel()->send($user, self::notification(['t' => 3]));

        $this->assertSame(3, $this->store->unreadCount(TestNotifiable::class, '9'));
        $this->assertCount(3, $this->store->unreadForRecipient(TestNotifiable::class, '9'));

        $first = $this->store->forRecipient(TestNotifiable::class, '9')[0];
        $this->assertTrue($this->store->markRead(TestNotifiable::class, '9', $first->id));
        $this->assertSame(2, $this->store->unreadCount(TestNotifiable::class, '9'));

        // Idempotent: already-read stays read.
        $this->assertFalse($this->store->markRead(TestNotifiable::class, '9', $first->id));

        $this->assertSame(2, $this->store->markAllRead(TestNotifiable::class, '9'));
        $this->assertSame(0, $this->store->unreadCount(TestNotifiable::class, '9'));
    }

    public function test_missing_notification_id_is_safe(): void
    {
        $this->assertFalse($this->store->markRead(TestNotifiable::class, '1', 'nope'));
        $this->assertFalse($this->store->delete(TestNotifiable::class, '1', 'nope'));
    }

    public function test_bounded_retrieval(): void
    {
        $user = self::user('5');
        for ($i = 0; $i < 7; $i++) {
            $this->channel()->send($user, self::notification(['i' => $i]));
        }

        $this->assertCount(3, $this->store->forRecipient(TestNotifiable::class, '5', limit: 3));
        $this->assertCount(4, $this->store->forRecipient(TestNotifiable::class, '5', limit: 50, offset: 3));
    }

    public function test_delete_operations(): void
    {
        $user = self::user('4');
        $this->channel()->send($user, self::notification());
        $this->channel()->send($user, self::notification());

        $first = $this->store->forRecipient(TestNotifiable::class, '4')[0];
        $this->assertTrue($this->store->delete(TestNotifiable::class, '4', $first->id));
        $this->assertSame(1, $this->store->deleteAll(TestNotifiable::class, '4'));
        $this->assertSame([], $this->store->forRecipient(TestNotifiable::class, '4'));
    }

    // ---- trait-facing API ----------------------------------------------

    public function test_notifiable_trait_exposes_the_store(): void
    {
        \Sahil\Notifications\Notify::swap(
            new Notifier([$this->channel()]),
            $this->store,
        );

        try {
            $user = new TraitNotifiable('77');

            $user->notify(self::notification(['via' => 'store']));

            $this->assertSame(1, $user->unreadNotificationsCount());
            $this->assertSame('store', $user->unreadNotifications()[0]->data['via']);

            $id = $user->notifications()[0]->id;
            $this->assertTrue($user->markNotificationAsRead($id));
            $this->assertSame(0, $user->unreadNotificationsCount());
            $this->assertTrue($user->deleteNotification($id));
            $this->assertSame([], $user->notifications());
        } finally {
            \Sahil\Notifications\Notify::flushState();
        }
    }
}
