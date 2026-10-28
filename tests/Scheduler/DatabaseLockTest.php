<?php

declare(strict_types=1);

namespace Sahil\Tests\Scheduler;

use PDO;
use PHPUnit\Framework\TestCase;
use Sahil\Database\Connection;
use Sahil\Database\DriverContract;
use Sahil\Database\Drivers\MySqlDriver;
use Sahil\Database\Drivers\PostgresDriver;
use Sahil\Scheduler\DatabaseLock;
use Sahil\Tests\Database\RecordingPdo;

final class DatabaseLockTest extends TestCase
{
    private const MYSQL_LOCK_SQL = 'SELECT GET_LOCK(?, ?) AS acquired';

    private const MYSQL_UNLOCK_SQL = 'SELECT RELEASE_LOCK(?) AS released';

    private RecordingPdo $recorder;

    protected function setUp(): void
    {
        $this->recorder = new RecordingPdo();
    }

    private function lock(DriverContract $driver = new MySqlDriver(), string $prefix = 'sched'): DatabaseLock
    {
        $recorder = $this->recorder;

        $connection = Connection::connect(
            $driver,
            'mysql:host=127.0.0.1;port=3306;dbname=test',
            null,
            null,
            [],
            static fn (array $_): PDO => $recorder,
        );

        return new DatabaseLock($connection, $prefix);
    }

    /**
     * @return list<array{sql: string, params: list<mixed>}>
     */
    private function executions(): array
    {
        return $this->recorder->executions;
    }

    public function test_acquiring_uses_a_zero_timeout_advisory_lock(): void
    {
        $this->recorder->rowsFor[self::MYSQL_LOCK_SQL] = [['acquired' => '1']];

        self::assertTrue($this->lock()->acquire('nightly'));

        self::assertSame(self::MYSQL_LOCK_SQL, $this->executions()[0]['sql']);
        self::assertSame(['sched:nightly', 0], $this->executions()[0]['params']);
    }

    public function test_a_lock_held_elsewhere_is_reported_as_not_acquired(): void
    {
        $this->recorder->rowsFor[self::MYSQL_LOCK_SQL] = [['acquired' => '0']];

        $lock = $this->lock();

        self::assertFalse($lock->acquire('nightly'));
        self::assertFalse($lock->holds('nightly'));
    }

    public function test_the_same_instance_does_not_reacquire_its_own_lock(): void
    {
        $this->recorder->rowsFor[self::MYSQL_LOCK_SQL] = [['acquired' => '1']];

        $lock = $this->lock();

        self::assertTrue($lock->acquire('nightly'));
        self::assertFalse($lock->acquire('nightly'));
        self::assertCount(1, $this->executions(), 'the second attempt must not hit the server');
    }

    public function test_release_sends_the_unlock_statement(): void
    {
        $this->recorder->rowsFor[self::MYSQL_LOCK_SQL] = [['acquired' => '1']];

        $lock = $this->lock();
        $lock->acquire('nightly');
        $lock->release('nightly');

        self::assertSame(self::MYSQL_UNLOCK_SQL, $this->executions()[1]['sql']);
        self::assertSame(['sched:nightly'], $this->executions()[1]['params']);
        self::assertFalse($lock->holds('nightly'));
    }

    public function test_release_without_a_held_lock_does_nothing(): void
    {
        $this->lock()->release('nightly');

        self::assertSame([], $this->executions());
    }

    public function test_postgres_boolean_results_are_normalised(): void
    {
        $this->recorder->rowsFor['SELECT pg_try_advisory_lock(?) AS acquired'] = [['acquired' => true]];

        self::assertTrue($this->lock(new PostgresDriver())->acquire('nightly'));
    }
}
