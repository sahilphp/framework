<?php

declare(strict_types=1);

namespace Sahil\Tests\Database;

use PHPUnit\Framework\TestCase;
use Sahil\Database\ConnectionException;
use Sahil\Database\ConnectionFactory;

/**
 * TRUE integration tests: run against configured test databases when
 * credentials are supplied via OS environment variables.
 *
 * Configuration format (README "Database setup"):
 *
 *   SAHIL_DB_TEST_MYSQL='mysql://user:password@127.0.0.1:3306/sahil_tests'
 *   SAHIL_DB_TEST_PGSQL='pgsql://user:password@127.0.0.1:5432/sahil_tests'
 *
 * Without the variable the test SKIPS (never fails) — on any box that
 * has a reachable server these assertions run for real, which is the
 * gate that makes the driver "supported" under development policy.
 */
final class DatabaseIntegrationTest extends TestCase
{
    /**
     * Environment-driven cases.
     *
     * @return array<string, array{string, array<string, mixed>}> url => [url, config]
     */
    public static function mysqlProvider(): array
    {
        return self::providerFrom('SAHIL_DB_TEST_MYSQL');
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function pgsqlProvider(): array
    {
        return self::providerFrom('SAHIL_DB_TEST_PGSQL');
    }

    /**
     * The provider always yields one case: PHPUnit treats an empty data set as
     * an error, and an unset variable should skip the test instead.
     *
     * @return array<string, array{string, array<string, mixed>}>
     */
    private static function providerFrom(string $variableName): array
    {
        $url = getenv($variableName);

        if ($url === false || trim($url) === '') {
            return ['not configured' => ['', []]];
        }

        return [$variableName => [$url, self::parseUrl($url)]];
    }

    /**
     * @return array<string, mixed>
     */
    public static function parseUrl(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException("Malformed database test URL: $url");
        }

        return [
            'driver' => $parts['scheme'],
            'host' => $parts['host'],
            'port' => $parts['port'] ?? null,
            'database' => isset($parts['path']) ? ltrim($parts['path'], '/') : null,
            'username' => $parts['user'] ?? null,
            'password' => $parts['pass'] ?? null,
        ];
    }

    private function connectOrSkip(string $url, array $configuration): ?\Sahil\Database\Connection
    {
        try {
            return (new ConnectionFactory())->make($configuration);
        } catch (ConnectionException $failure) {
            self::markTestSkipped(
                sprintf('%s: server reachable but %s', $url, $failure->getMessage()),
            );
            return null;
        }
    }

    /**
     * Shared exercise: create → insert → read → update → rollback → drop.
     */
    private function runLifecycle(\Sahil\Database\Connection $db, string $quoteMark, string $idStyle): void
    {
        $table = 'sahil_test_'.bin2hex(random_bytes(4));

        $setup = match ($idStyle) {
            'mysql' => "CREATE TABLE $table (
                id BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(120) NOT NULL,
                score INT NOT NULL DEFAULT 0
            )",
            'pgsql' => "CREATE TABLE $table (
                id BIGSERIAL PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                score INT NOT NULL DEFAULT 0
            )",
            default => throw new \LogicException('unknown $idStyle'),
        };

        $db->execute($setup);

        try {
            // Insert through the builder — parameterized.
            $users = $db->table($table);
            $users->insert(['name' => 'Ada', 'score' => 42]);
            $users->insert(['name' => "Ev'asive %user", 'score' => 7]);

            [$firstRow, $secondRow] = $users->select('id', 'name')->get()->rows();
            self::assertSame('Ada', $firstRow['name']);
            self::assertSame("Ev'asive %user", $secondRow['name']);

            // UPDATE + read back
            $users->where('name', '=', "Ev'asive %user")->update(['score' => 123]);
            self::assertSame(123, (int) $users->where('name', '=', "Ev'asive %user")->first()['score']);

            // Transactional write that THROWS must not persist (rollback).
            try {
                $db->transaction(static function (\Sahil\Database\Connection $connection) use ($table): void {
                    $connection->table($table)->insert(['name' => 'aborted', 'score' => 1]);
                    throw new \RuntimeException('abort the unit');
                });
                self::fail('Expected the transaction to propagate its failure.');
            } catch (\RuntimeException $caught) {
                self::assertSame('abort the unit', $caught->getMessage());
            }

            self::assertNull(
                $users->where('name', '=', 'aborted')->first(),
                'rolled-back inserts must not persist',
            );

            // Committed writes outside transactions DO persist.
            self::assertCount(2, $users->select('id')->get()->rows());
        } finally {
            $db->execute("DROP TABLE IF EXISTS $table");
        }
    }

    /**
     * @dataProvider mysqlProvider
     * @group integration
     *
     * @param array<string, mixed> $configuration
     */
    public function test_full_mysql_lifecycle(string $url = '', array $configuration = []): void
    {
        if ($url === '') {
            $url = is_string(getenv('SAHIL_DB_TEST_MYSQL')) ? (string) getenv('SAHIL_DB_TEST_MYSQL') : '';
            $configuration = $url === '' ? [] : self::parseUrl($url);
        }

        if ($url === '') {
            self::markTestSkipped(
                'Set SAHIL_DB_TEST_MYSQL="mysql://user:password@host:port/dbname" to run MySQL integration.',
            );
        }

        $db = $this->connectOrSkip($url, $configuration);

        if ($db === null) {
            return;
        }

        $this->runLifecycle($db, '`', 'mysql');
        $db->disconnect();
    }

    /**
     * @dataProvider pgsqlProvider
     * @group integration
     *
     * @param array<string, mixed> $configuration
     */
    public function test_full_pgsql_lifecycle(string $url = '', array $configuration = []): void
    {
        if ($url === '') {
            $url = is_string(getenv('SAHIL_DB_TEST_PGSQL')) ? (string) getenv('SAHIL_DB_TEST_PGSQL') : '';
            $configuration = $url === '' ? [] : self::parseUrl($url);
        }

        if ($url === '') {
            self::markTestSkipped(
                'Set SAHIL_DB_TEST_PGSQL="pgsql://user:password@host:port/dbname" to run PostgreSQL integration.',
            );
        }

        $db = $this->connectOrSkip($url, $configuration);

        if ($db === null) {
            return;
        }

        $this->runLifecycle($db, '"', 'pgsql');
        $db->disconnect();
    }
}
