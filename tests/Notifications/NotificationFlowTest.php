<?php

declare(strict_types=1);

namespace Sahil\Tests\Notifications;

use Sahil\Contracts\Queue\DispatcherContract;
use Sahil\Database\Connection;
use Sahil\Database\ConnectionFactory;
use Sahil\Notifications\Channels\DatabaseChannel;
use Sahil\Notifications\DatabaseNotificationStore;
use Sahil\Notifications\Jobs\SendNotificationJob;
use Sahil\Notifications\NotificationStore;
use Sahil\Notifications\Notifier;
use Sahil\Notifications\Notify;
use Sahil\Orm\Model;
use Sahil\Tests\FrameworkTestCase;
use Sahil\Tests\Mail\RecordingDispatcher;

/**
 * Application-level verification: a queued notification travels through
 * the real container, lands on the dispatcher as a job, and executes in a
 * later "worker" context re-resolving its recipient from the database.
 */
final class NotificationFlowTest extends FrameworkTestCase
{
    private Connection $connection;

    private DatabaseNotificationStore $store;

    private RecordingDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite not installed.');
        }

        $this->connection = (new ConnectionFactory())->make([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $this->connection->execute(
            'CREATE TABLE notifications (
                id TEXT PRIMARY KEY, notifiable_type TEXT NOT NULL,
                notifiable_id TEXT NOT NULL, type TEXT NOT NULL,
                data TEXT NOT NULL, read_at INTEGER NULL, created_at INTEGER NOT NULL
            )',
        );
        $this->connection->execute('CREATE TABLE queued_users (id INTEGER PRIMARY KEY, email TEXT)');
        $this->connection->execute("INSERT INTO queued_users (id, email) VALUES (1, 'q@x.test')");

        $this->store = new DatabaseNotificationStore($this->connection);
        $this->dispatcher = new RecordingDispatcher();

        $container = $this->container();
        $container->instance(NotificationStore::class, $this->store);
        $container->instance(Notifier::class, new Notifier([new DatabaseChannel($this->store)]));
        $container->instance(DispatcherContract::class, $this->dispatcher);

        Model::useConnection($this->connection);
    }

    protected function tearDown(): void
    {
        Model::forgetConnection();
        parent::tearDown();
    }

    public function test_notify_queue_dispatches_a_job_that_delivers_later(): void
    {
        $user = new QueuedUser(['id' => 1], exists: true);

        $jobId = $user->notify(new PingNotification('welcome aboard'), queue: true);

        $this->assertSame('queued-1', $jobId);
        $this->assertCount(1, $this->dispatcher->pushed);

        $job = $this->dispatcher->pushed[0]['job'];
        $this->assertInstanceOf(SendNotificationJob::class, $job);

        // Simulate the worker: serialize → restore → execute.
        $restored = unserialize(serialize($job));
        $report = $restored->handle();

        $this->assertTrue($report->forChannel('database')->successful());
        $this->assertSame('welcome aboard', $this->store->forRecipient(QueuedUser::class, '1')[0]->data['message']);
    }

    public function test_immediate_notify_uses_the_bound_notifier(): void
    {
        $user = new QueuedUser(['id' => 1], exists: true);

        $report = $user->notify(new PingNotification('now'));

        $this->assertTrue($report->forChannel('database')->successful());
        $this->assertSame(1, $user->unreadNotificationsCount());
        $this->assertSame([], $this->dispatcher->pushed);
    }

    public function test_notify_facade_resolves_services_from_the_container(): void
    {
        $user = new QueuedUser(['id' => 1], exists: true);

        Notify::send($user, new PingNotification('facade'));

        $this->assertSame(1, $this->store->unreadCount(QueuedUser::class, '1'));
        $this->assertSame(1, $user->unreadNotificationsCount());
    }
}
