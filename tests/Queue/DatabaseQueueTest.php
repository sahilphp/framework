<?php

declare(strict_types=1);

namespace Sahil\Tests\Queue;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use Sahil\Database\Connection;
use Sahil\Database\Drivers\MySqlDriver;
use Sahil\Queue\DatabaseQueue;
use Sahil\Tests\Database\RecordingPdo;

/**
 * The database queue against a recording PDO double: insert/reserve bumps
 * the attempt count inside a transaction, every value is bound, and the
 * table name is validated before it reaches SQL.
 */
final class DatabaseQueueTest extends TestCase
{
    private const SELECT_SQL = 'SELECT `id`, `payload`, `attempts` FROM `jobs`'
        .' WHERE `queue` = ? AND `reserved_at` IS NULL AND `available_at` <= ?'
        .' ORDER BY `available_at` ASC, `id` ASC LIMIT 1 FOR UPDATE';

    private const RESERVE_SQL = 'UPDATE `jobs` SET `reserved_at` = ?, `attempts` = `attempts` + 1'
        .' WHERE `id` = ? AND `reserved_at` IS NULL';

    private RecordingPdo $recorder;

    private Connection $connection;

    private DatabaseQueue $queue;

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
            static fn (array $_): PDO => $recorder
        );

        $this->queue = new DatabaseQueue(
            $this->connection,
            'jobs',
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-28 10:00:00', new DateTimeZone('UTC'))
        );
    }

    /**
     * @return list<array{sql: string, params: list<mixed>}>
     */
    private function statements(): array
    {
        return array_values(array_filter(
            $this->recorder->executions,
            static fn (array $execution): bool => !in_array($execution['sql'], ['BEGIN', 'COMMIT', 'ROLLBACK'], true)
        ));
    }

    public function test_push_inserts_a_job_with_delay_and_bound_values(): void
    {
        $id = $this->queue->push('emails', '{"payload":1}', 30);

        [$sql, $params] = [$this->statements()[0]['sql'], $this->statements()[0]['params']];

        self::assertSame(
            'INSERT INTO `jobs` (`id`, `queue`, `payload`, `attempts`, `available_at`, `created_at`)'
            .' VALUES (?, ?, ?, ?, ?, ?)',
            $sql
        );
        self::assertSame(
            [$id, 'emails', '{"payload":1}', 0, '2026-10-28 10:00:30', '2026-10-28 10:00:00'],
            $params
        );
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
    }

    public function test_push_without_delay_is_available_immediately(): void
    {
        $this->queue->push('emails', 'payload');

        self::assertSame(
            '2026-10-28 10:00:00',
            $this->statements()[0]['params'][4]
        );
    }

    public function test_later_delegates_to_push(): void
    {
        $this->queue->later('emails', 'payload', 90);

        self::assertSame('2026-10-28 10:01:30', $this->statements()[0]['params'][4]);
    }

    public function test_pop_reserves_the_oldest_ready_job_and_counts_the_attempt(): void
    {
        $this->recorder->rowsFor[self::SELECT_SQL] = [['id' => 'job-1', 'payload' => 'work', 'attempts' => '2']];
        $this->recorder->rowsFor[self::RESERVE_SQL] = [['id' => 'job-1']];

        $reserved = $this->queue->pop('emails');

        self::assertNotNull($reserved);
        self::assertSame('job-1', $reserved->id);
        self::assertSame('emails', $reserved->queue);
        self::assertSame('work', $reserved->payload);
        self::assertSame(3, $reserved->attempts, 'the reserved attempt counts toward the budget');

        self::assertSame(
            ['BEGIN', self::SELECT_SQL, self::RESERVE_SQL, 'COMMIT'],
            array_column($this->recorder->executions, 'sql'),
            'reservation happens in one transaction'
        );
        self::assertSame(['emails', '2026-10-28 10:00:00'], $this->recorder->executions[1]['params']);
        self::assertSame(['2026-10-28 10:00:00', 'job-1'], $this->recorder->executions[2]['params']);
    }

    public function test_pop_returns_null_when_nothing_is_ready(): void
    {
        self::assertNull($this->queue->pop('emails'));

        self::assertSame(
            ['BEGIN', self::SELECT_SQL, 'COMMIT'],
            array_column($this->recorder->executions, 'sql')
        );
    }

    public function test_pop_returns_null_when_another_worker_won_the_reservation(): void
    {
        $this->recorder->rowsFor[self::SELECT_SQL] = [['id' => 'job-1', 'payload' => 'work', 'attempts' => '0']];
        // No rows for the UPDATE: rowCount() is 0, as when the conditional
        // update matched nothing.

        self::assertNull($this->queue->pop('emails'));
    }

    public function test_delete_removes_the_job_by_id(): void
    {
        $this->queue->delete('job-1');

        self::assertSame(
            [['sql' => 'DELETE FROM `jobs` WHERE `id` = ?', 'params' => ['job-1']]],
            $this->statements()
        );
    }

    public function test_release_makes_the_job_available_again(): void
    {
        $this->queue->release('job-1', 15);

        self::assertSame(
            [
                'sql' => 'UPDATE `jobs` SET `reserved_at` = NULL, `available_at` = ? WHERE `id` = ?',
                'params' => ['2026-10-28 10:00:15', 'job-1'],
            ],
            $this->statements()[0]
        );
    }

    public function test_size_counts_every_job_on_the_queue(): void
    {
        $this->recorder->rowsFor['SELECT COUNT(*) AS `total` FROM `jobs` WHERE `queue` = ?'] = [['total' => '4']];

        self::assertSame(4, $this->queue->size('emails'));
    }

    public function test_is_empty_ignores_reserved_and_delayed_jobs(): void
    {
        self::assertTrue($this->queue->isEmpty('emails'));

        self::assertSame(
            'SELECT `id` FROM `jobs` WHERE `queue` = ? AND `reserved_at` IS NULL AND `available_at` <= ? LIMIT 1',
            $this->statements()[0]['sql']
        );
        self::assertSame(['emails', '2026-10-28 10:00:00'], $this->statements()[0]['params']);
    }

    public function test_is_empty_is_false_when_a_ready_job_exists(): void
    {
        $this->recorder->rowsFor[
            'SELECT `id` FROM `jobs` WHERE `queue` = ? AND `reserved_at` IS NULL AND `available_at` <= ? LIMIT 1'
        ] = [['id' => 'job-1']];

        self::assertFalse($this->queue->isEmpty('emails'));
    }

    public function test_an_invalid_table_name_is_rejected_before_any_sql(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DatabaseQueue($this->connection, 'jobs; DROP TABLE users');
    }
}
