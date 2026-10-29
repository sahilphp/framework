<?php

declare(strict_types=1);

namespace Sahil\Tests\Queue;

use PDO;
use PHPUnit\Framework\TestCase;
use Sahil\Contracts\Queue\QueueException;
use Sahil\Database\Connection;
use Sahil\Database\Drivers\MySqlDriver;
use Sahil\Queue\DatabaseQueue;
use Sahil\Queue\QueueManager;
use Sahil\Tests\Database\RecordingPdo;

/**
 * The dispatcher serializes jobs, sends them to the queue the job names,
 * and refuses a connection it cannot build.
 */
final class QueueManagerTest extends TestCase
{
    private RecordingPdo $recorder;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->recorder = new RecordingPdo();
        $recorder = $this->recorder;

        $this->connection = Connection::connect(
            new MySqlDriver(),
            'mysql:host=127.0.0.1;port=3306;dbname=test',
            'user',
            'pass',
            [],
            static fn (array $_): PDO => $recorder,
        );
    }

    /**
     * @param array<string, array<string, mixed>> $connections
     */
    private function manager(array $connections = []): QueueManager
    {
        return new QueueManager(
            $this->connection,
            $connections === [] ? ['database' => ['driver' => 'database', 'table' => 'jobs']] : $connections,
            'database',
        );
    }

    public function test_a_job_is_serialized_onto_the_queue_it_names(): void
    {
        $job = new RecordingJob('nightly', queueName: 'reports');

        $id = $this->manager()->dispatch($job);

        $execution = $this->recorder->executions[0];

        self::assertStringContainsString('INSERT INTO `jobs`', $execution['sql']);
        self::assertSame($id, $execution['params'][0]);
        self::assertSame('reports', $execution['params'][1]);

        $queued = unserialize((string) $execution['params'][2]);

        self::assertInstanceOf(RecordingJob::class, $queued);
        self::assertSame('nightly', $queued->name);
    }

    public function test_a_job_without_a_queue_uses_the_default_queue(): void
    {
        $this->manager()->dispatch(new RecordingJob('no-queue'));

        self::assertSame('default', $this->recorder->executions[0]['params'][1]);
    }

    public function test_dispatch_honours_a_delay(): void
    {
        $this->manager()->dispatch(new RecordingJob('later'), 45);

        $availableAt = $this->recorder->executions[0]['params'][4];

        self::assertGreaterThan(date('Y-m-d H:i:s'), (string) $availableAt);
    }

    public function test_push_on_places_the_job_on_the_named_queue(): void
    {
        $this->manager()->pushOn('sms', new RecordingJob('text'));

        self::assertSame('sms', $this->recorder->executions[0]['params'][1]);
    }

    public function test_push_raw_writes_the_payload_unchanged(): void
    {
        $this->manager()->pushRaw('emails', '{"raw":true}', 10);

        self::assertSame('emails', $this->recorder->executions[0]['params'][1]);
        self::assertSame('{"raw":true}', $this->recorder->executions[0]['params'][2]);
    }

    public function test_the_configured_store_is_reused(): void
    {
        $manager = $this->manager();

        self::assertSame($manager->store(), $manager->store());
        self::assertInstanceOf(DatabaseQueue::class, $manager->store());
    }

    public function test_an_unknown_connection_is_refused(): void
    {
        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('Queue connection [redis] is not configured.');

        $this->manager()->store('redis');
    }

    public function test_an_unsupported_driver_is_refused(): void
    {
        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('Unsupported queue driver [sqs]');

        $this->manager(['database' => ['driver' => 'sqs']])->store();
    }
}
