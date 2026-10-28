<?php

declare(strict_types=1);

namespace Sahil\Tests\Migrations;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sahil\Database\Connection;
use Sahil\Database\Drivers\MySqlDriver;
use Sahil\Database\Drivers\PostgresDriver;
use Sahil\Migrations\MigrationException;
use Sahil\Migrations\MigrationLock;
use Sahil\Tests\Database\RecordingPdo;

/**
 * The lock's driver-facing behaviour: what the acquire statement looks
 * like, how each driver's "acquired" value is normalised, and that the lock
 * is always released.
 */
final class MigrationLockTest extends TestCase
{
    private function connect(RecordingPdo $recorder, string $driver = 'mysql'): Connection
    {
        return Connection::connect(
            $driver === 'mysql' ? new MySqlDriver() : new PostgresDriver(),
            $driver === 'mysql' ? 'mysql:host=x' : 'pgsql:host=x',
            null,
            null,
            [],
            static fn (array $_): PDO => $recorder
        );
    }

    public function test_mysql_acquire_is_parameterized_and_uses_get_lock(): void
    {
        $recorder = new RecordingPdo();
        $recorder->rowsFor = ['SELECT GET_LOCK(?, ?) AS acquired' => [['acquired' => '1']]];

        $lock = new MigrationLock($this->connect($recorder), 'sahil_migrations:test', 10);

        self::assertTrue($lock->acquire());
        self::assertSame('SELECT GET_LOCK(?, ?) AS acquired', $recorder->executions[0]['sql']);
        self::assertSame(['sahil_migrations:test', 10], $recorder->executions[0]['params']);
    }

    public function test_mysql_reports_contention_when_get_lock_returns_zero(): void
    {
        $recorder = new RecordingPdo();
        $recorder->rowsFor = ['SELECT GET_LOCK(?, ?) AS acquired' => [['acquired' => '0']]];

        self::assertFalse((new MigrationLock($this->connect($recorder)))->acquire());
    }

    public function test_postgresql_normalises_the_boolean_forms_both_ways(): void
    {
        foreach ([['t', true], ['f', false], [true, true], [false, false], ['1', true], ['0', false], [null, false]] as [$returned, $expected]) {
            $recorder = new RecordingPdo();
            $recorder->rowsFor = ['SELECT pg_try_advisory_lock(?) AS acquired' => [['acquired' => $returned]]];

            $lock = new MigrationLock($this->connect($recorder, 'pgsql'), 'sahil_migrations');

            self::assertSame(
                $expected,
                $lock->acquire(),
                sprintf('pg_try_advisory_lock returning %s must mean %s', var_export($returned, true), var_export($expected, true))
            );
        }
    }

    public function test_postgresql_uses_a_bigint_advisory_key(): void
    {
        $recorder = new RecordingPdo();
        $recorder->rowsFor = ['SELECT pg_try_advisory_lock(?) AS acquired' => [['acquired' => true]]];

        (new MigrationLock($this->connect($recorder, 'pgsql'), 'sahil_migrations'))->acquire();

        $params = $recorder->executions[0]['params'];
        self::assertIsInt($params[0], 'the advisory key binds as an integer');
    }

    public function test_synchronized_releases_the_lock_on_success(): void
    {
        $recorder = new RecordingPdo();
        $recorder->rowsFor = [
            'SELECT GET_LOCK(?, ?) AS acquired' => [['acquired' => '1']],
            'SELECT RELEASE_LOCK(?) AS released' => [['released' => '1']],
        ];

        $result = (new MigrationLock($this->connect($recorder)))->synchronized(static fn (): string => 'done');

        self::assertSame('done', $result);
        self::assertSame(
            ['SELECT GET_LOCK(?, ?) AS acquired', 'SELECT RELEASE_LOCK(?) AS released'],
            array_column($recorder->executions, 'sql')
        );
    }

    public function test_synchronized_releases_the_lock_even_when_the_work_throws(): void
    {
        $recorder = new RecordingPdo();
        $recorder->rowsFor = [
            'SELECT GET_LOCK(?, ?) AS acquired' => [['acquired' => '1']],
            'SELECT RELEASE_LOCK(?) AS released' => [['released' => '1']],
        ];

        $lock = new MigrationLock($this->connect($recorder));

        try {
            $lock->synchronized(static function (): void {
                throw new RuntimeException('work failed');
            });
            self::fail('the failure must propagate');
        } catch (RuntimeException $failure) {
            self::assertSame('work failed', $failure->getMessage());
        }

        self::assertContains('SELECT RELEASE_LOCK(?) AS released', array_column($recorder->executions, 'sql'));
    }

    public function test_synchronized_fails_loudly_when_another_process_holds_the_lock(): void
    {
        $recorder = new RecordingPdo();
        $recorder->rowsFor = ['SELECT GET_LOCK(?, ?) AS acquired' => [['acquired' => '0']]];

        $lock = new MigrationLock($this->connect($recorder), 'sahil_migrations:test');

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('sahil_migrations:test');

        $lock->synchronized(static fn (): string => 'never runs');
    }

    public function test_a_release_failure_never_masks_the_work_result(): void
    {
        $recorder = new RecordingPdo();
        $recorder->rowsFor = ['SELECT GET_LOCK(?, ?) AS acquired' => [['acquired' => '1']]];
        $recorder->failOnSql = 'SELECT RELEASE_LOCK(?) AS released';

        $lock = new MigrationLock($this->connect($recorder));

        self::assertSame('ok', $lock->synchronized(static fn (): string => 'ok'));
    }
}
