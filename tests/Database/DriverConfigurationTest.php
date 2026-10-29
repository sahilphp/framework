<?php

declare(strict_types=1);

namespace Sahil\Tests\Database;

use PHPUnit\Framework\TestCase;
use Sahil\Database\ConnectionException;
use Sahil\Database\ConnectionFactory;
use Sahil\Database\Drivers\MySqlDriver;
use Sahil\Database\Drivers\PostgresDriver;

/**
 * Driver dialect and configuration behavior — no live server needed.
 */
final class DriverConfigurationTest extends TestCase
{
    public function test_mysql_dsn_composition(): void
    {
        $driver = new MySqlDriver();

        self::assertSame(
            'mysql:host=db.internal;port=3306;dbname=sahil;charset=utf8mb4',
            $driver->dsn(['host' => 'db.internal', 'port' => 3306, 'database' => 'sahil']),
        );

        // missing port and charset fall back safely
        self::assertSame(
            'mysql:host=127.0.0.1;port=;dbname=x;charset=utf8mb4',
            $driver->dsn(['host' => '127.0.0.1', 'database' => 'x', 'charset' => null]),
        );
    }

    public function test_mysql_dsn_charset_is_constrained(): void
    {
        $driver = new MySqlDriver();
        $dsn = $driver->dsn([
            'host' => 'h',
            'database' => 'db',
            'charset' => 'utf8;DROP SCHEMA x',
        ]);

        self::assertStringNotContainsString('DROP', $dsn);
        self::assertStringEndsWith('charset=utf8mb4', $dsn);
    }

    public function test_missing_configuration_gets_specific_errors(): void
    {
        $driver = new MySqlDriver();

        try {
            $driver->dsn(['host' => 'h']);
            self::fail('Expected database requirement error.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('database', $e->getMessage());
        }

        try {
            $driver->dsn(['database' => 'x']);
            self::fail('Expected host requirement error.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('host', $e->getMessage());
        }

        try {
            $driver->dsn(['host' => 'h', 'database' => 'x', 'port' => 70000]);
            self::fail('Expected port error.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('port', $e->getMessage());
        }
    }

    public function test_pgsql_dsn_composition(): void
    {
        self::assertSame(
            'pgsql:host=127.0.0.1;port=5432;dbname=sahil',
            (new PostgresDriver())->dsn(['host' => '127.0.0.1', 'port' => 5432, 'database' => 'sahil']),
        );
    }

    public function test_identifier_quoting_per_dialect(): void
    {
        self::assertSame('`users`', (new MySqlDriver())->quoteIdentifier('users'));
        self::assertSame('`p`.`account`', (new MySqlDriver())->quoteIdentifier('p.account'));
        self::assertSame('"users"', (new PostgresDriver())->quoteIdentifier('users'));
        self::assertSame('"p"."account"', (new PostgresDriver())->quoteIdentifier('p.account'));
    }

    public function test_bad_identifiers_fail_loudly(): void
    {
        $driver = new MySqlDriver();

        $evils = [
            '',
            'a.b.c',
            'drop table',
            'a`b',
            'a-b',
            '9ab',
            'a b',
            "a\x01b",
        ];

        foreach ($evils as $evil) {
            try {
                $driver->quoteIdentifier($evil);
                self::fail("Expected failure for [$evil].");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsStringIgnoringCase('identifier', $e->getMessage());
            }
        }
    }

    public function test_factory_rejects_unknown_drivers(): void
    {
        try {
            (new ConnectionFactory())->make(['driver' => 'oracle']);
            self::fail('Expected driver rejection.');
        } catch (ConnectionException $e) {
            self::assertStringContainsString('oracle', $e->getMessage());
            self::assertStringContainsString('mysql, pgsql', $e->getMessage());
        }
    }
}
