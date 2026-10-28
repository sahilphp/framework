<?php

declare(strict_types=1);

namespace Sahil\Tests\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sahil\Database\Connection;
use Sahil\Database\ConnectionException;
use Sahil\Database\Drivers\MySqlDriver;

/**
 * Transaction lifecycle against the recording double: nesting via
 * savepoints, rollback on exceptions, worker teardown rules.
 */
final class TransactionTest extends TestCase
{
    private Connection $connection;

    private RecordingPdo $recorder;

    protected function setUp(): void
    {
        $this->recorder = new RecordingPdo();
        $recorder = $this->recorder;

        $this->connection = Connection::connect(
            new MySqlDriver(),
            'mysql:host=DB;dbname=d',
            null,
            null,
            [],
            static fn (array $_): PDO => $recorder
        );
    }

    /**
     * Sentences issued through transaction(), in order.
     *
     * @return list<string>
     */
    private function statements(): array
    {
        return array_map(
            static fn (array $execution): string => (string) $execution['sql'],
            $this->recorder->executions
        );
    }

    public function test_successful_transaction_commits(): void
    {
        $result = $this->connection->transaction(static function (Connection $db): string {
            $db->execute('SELECT 1');

            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(['BEGIN', 'SELECT 1', 'COMMIT'], $this->statements());
        self::assertSame(0, $this->connection->transactionDepth());
    }

    public function test_exception_rolls_back_and_propagates_unaltered(): void
    {
        $failure = new RuntimeException('app blew up');

        try {
            $this->connection->transaction(static function (Connection $db) use ($failure): void {
                $db->execute('INSERT example');
                throw $failure;
            });
            self::fail('Expected the original exception.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught, 'no exception laundering');
        }

        self::assertSame(['BEGIN', 'INSERT example', 'ROLLBACK'], $this->statements());
        self::assertSame(0, $this->connection->transactionDepth());
    }

    public function test_nested_transactions_use_savepoints(): void
    {
        $this->connection->transaction(static function (Connection $outer): void {
            $outer->execute('OUTER');

            $outer->transaction(static function (Connection $inner): void {
                $inner->execute('INNER');
            });
        });

        self::assertSame(
            ['BEGIN', 'OUTER', 'SAVEPOINT sahil_sp2', 'INNER', 'RELEASE SAVEPOINT sahil_sp2', 'COMMIT'],
            $this->statements()
        );
    }

    public function test_inner_failure_rolls_back_to_savepoint_only(): void
    {
        $innerHandled = false;

        try {
            $this->connection->transaction(static function (Connection $outer) use (&$innerHandled): void {
                $outer->execute('OUTER');

                try {
                    $outer->transaction(static function (Connection $inner): void {
                        $inner->execute('INNER');
                        throw new RuntimeException('inner fail');
                    });
                } catch (RuntimeException) {
                    $innerHandled = true; // outer scope may continue
                }

                $outer->execute('OUTER-AFTER');
            });
        } catch (RuntimeException) {
            self::fail('outer must survive when the inner failure is handled');
        }

        self::assertTrue($innerHandled);

        self::assertSame(
            ['BEGIN', 'OUTER', 'SAVEPOINT sahil_sp2', 'INNER', 'ROLLBACK TO SAVEPOINT sahil_sp2',
                'RELEASE SAVEPOINT sahil_sp2', 'OUTER-AFTER', 'COMMIT'],
            $this->statements()
        );
    }

    public function test_depth_is_nested_consistently(): void
    {
        self::assertSame(0, $this->connection->transactionDepth());

        $this->connection->transaction(static function (Connection $db): void {
            self::assertSame(1, $db->transactionDepth());

            $db->transaction(static function (Connection $inner): void {
                self::assertSame(2, $inner->transactionDepth());
            });

            self::assertSame(1, $db->transactionDepth());
        });

        self::assertSame(0, $this->connection->transactionDepth());
    }

    public function test_disconnect_rolls_back_still_open_transaction(): void
    {
        $depth = new \ReflectionProperty(Connection::class, 'transactionDepth');
        $depth->setAccessible(true);
        $depth->setValue($this->connection, 1);

        $this->connection->disconnect();

        self::assertSame(0, $this->connection->transactionDepth());
        self::assertFalse($this->connection->isConnected());
        self::assertContains('ROLLBACK', $this->statements());
    }

    public function test_uncaught_inner_failure_unwinds_the_full_scope(): void
    {
        try {
            $this->connection->transaction(static function (Connection $outer): void {
                $outer->execute('OUTER');

                $outer->transaction(static function (Connection $inner): void {
                    $inner->execute('INNER');
                    throw new RuntimeException('uncaught inner');
                });

                self::fail('unreachable');
            });
            self::fail('Expected the inner exception to surface.');
        } catch (RuntimeException $caught) {
            self::assertSame('uncaught inner', $caught->getMessage());
        }

        self::assertSame(
            ['BEGIN', 'OUTER', 'SAVEPOINT sahil_sp2', 'INNER', 'ROLLBACK TO SAVEPOINT sahil_sp2',
                'RELEASE SAVEPOINT sahil_sp2', 'ROLLBACK'],
            $this->statements()
        );
        self::assertSame(0, $this->connection->transactionDepth());
    }

    public function test_reconnect_provides_a_fresh_handle(): void
    {
        $this->connection->reconnect([]);

        self::assertTrue($this->connection->isConnected());
        self::assertSame(0, $this->connection->transactionDepth());
    }

    public function test_execution_on_disconnected_connection_is_a_clear_error(): void
    {
        $this->connection->disconnect();

        try {
            $this->connection->execute('SELECT 1');
            self::fail('Expected a clear connection-state error.');
        } catch (ConnectionException $e) {
            self::assertStringContainsString('disconnected', $e->getMessage());
        }
    }

    public function test_ping_survives_dead_and_live_handles(): void
    {
        $this->recorder->failPrepare = true;
        self::assertFalse($this->connection->ping());

        $this->recorder->failPrepare = false;
        self::assertTrue($this->connection->ping());
    }
}
