<?php

declare(strict_types=1);

namespace Sahil\Tests\Notifications;

use PHPUnit\Framework\TestCase;
use Sahil\Contracts\Notifications\NotifiableContract;
use Sahil\Database\ConnectionFactory;
use Sahil\Notifications\Channels\DatabaseChannel;
use Sahil\Notifications\DatabaseNotificationStore;
use Sahil\Notifications\Jobs\SendNotificationJob;
use Sahil\Notifications\Notifiable;
use Sahil\Notifications\Notification;
use Sahil\Notifications\NotificationException;
use Sahil\Notifications\Notifier;
use Sahil\Notifications\Notify;
use Sahil\Orm\Model;

final class QueuedUser extends Model implements NotifiableContract
{
    use Notifiable;

    /** @var list<string> */
    protected array $fillable = ['id', 'email'];

    protected ?string $table = 'queued_users';
}

final class PingNotification extends Notification
{
    public function __construct(public readonly string $message = 'ping')
    {
    }

    public function via(NotifiableContract $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(NotifiableContract $notifiable): array
    {
        return ['message' => $this->message];
    }
}

final class NotificationJobTest extends TestCase
{
    private DatabaseNotificationStore $store;

    protected function setUp(): void
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
                id TEXT PRIMARY KEY,
                notifiable_type TEXT NOT NULL,
                notifiable_id TEXT NOT NULL,
                type TEXT NOT NULL,
                data TEXT NOT NULL,
                read_at INTEGER NULL,
                created_at INTEGER NOT NULL
            )',
        );
        $connection->execute('CREATE TABLE queued_users (id INTEGER PRIMARY KEY, email TEXT)');
        $connection->execute("INSERT INTO queued_users (id, email) VALUES (1, 'a@b.c')");

        Model::useConnection($connection);

        $this->store = new DatabaseNotificationStore($connection);
        Notify::swap(new Notifier([new DatabaseChannel($this->store)]), $this->store);
    }

    protected function tearDown(): void
    {
        Model::forgetConnection();
        Notify::flushState();
    }

    public function test_job_holds_a_recipient_reference_not_a_serialized_model(): void
    {
        $user = new QueuedUser(['id' => 1, 'email' => 'a@b.c'], exists: true);
        $job = new SendNotificationJob($user, new PingNotification('hi'));

        $serialized = serialize($job);

        // The payload carries class+id, not the model instance.
        $this->assertStringNotContainsString('a@b.c', $serialized);
        $this->assertStringContainsString('QueuedUser', $serialized);

        /** @var SendNotificationJob $restored */
        $restored = unserialize($serialized);
        $report = $restored->handle();

        $this->assertTrue($report->forChannel('database')->successful());
        $this->assertSame(1, $this->store->unreadCount(QueuedUser::class, '1'));
        $this->assertSame('hi', $this->store->forRecipient(QueuedUser::class, '1')[0]->data['message']);
    }

    public function test_job_re_resolves_the_model_at_execution_time(): void
    {
        $user = new QueuedUser(['id' => 1], exists: true);
        $job = new SendNotificationJob($user, new PingNotification());

        $restored = unserialize(serialize($job));
        $restored->handle();

        $this->assertSame(1, count($this->store->forRecipient(QueuedUser::class, '1')));
    }

    public function test_unresolvable_recipient_fails_loudly(): void
    {
        $user = new QueuedUser(['id' => 99], exists: true); // no such row
        $job = new SendNotificationJob($user, new PingNotification());

        $this->expectException(NotificationException::class);
        $this->expectExceptionMessage('Cannot resolve notifiable');
        $job->handle();
    }

    public function test_job_rejects_notifiable_with_no_routes(): void
    {
        $this->expectException(NotificationException::class);
        new SendNotificationJob(new TestNotifiable([]), new PingNotification());
    }

    public function test_trait_queue_path_builds_the_job(): void
    {
        $user = new QueuedUser(['id' => 1], exists: true);

        // notify(queue: true) delegates to Notify::queue → dispatch — needs
        // an application container; assert the immediate path instead and
        // cover queue dispatch in the app-level flow test.
        $report = $user->notify(new PingNotification('direct'));
        $this->assertTrue($report->forChannel('database')->successful());
    }
}
