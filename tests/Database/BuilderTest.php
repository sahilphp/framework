<?php

declare(strict_types=1);

namespace Sahil\Tests\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Sahil\Database\Connection;
use Sahil\Database\Drivers\MySqlDriver;
use Sahil\Database\Drivers\PostgresDriver;
use Sahil\Database\Expression;

/**
 * Query builder semantics against a recording PDO double: SQL text,
 * parameter binding, identifier validation, and injection resistance.
 */
final class BuilderTest extends TestCase
{
    private Connection $connection;

    private RecordingPdo $recorder;

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

    /** @return array{0: string, 1: list<mixed>} */
    private function plan(): array
    {
        return [$this->recorder->executions[0]['sql'], $this->recorder->executions[0]['params']];
    }

    /* ---------- SELECT composition ---------- */

    public function test_select_composes_wheres_order_limit_offset(): void
    {
        $this->connection->table('users')
            ->select('id', 'name')
            ->where('status', '=', 'active')
            ->orWhere('vip', '=', true)
            ->orderBy('id', 'DESC')
            ->limit(10)
            ->offset(5)
            ->get();

        [$sql, $params] = $this->plan();

        self::assertSame(
            'SELECT `id`, `name` FROM `users`'
            .' WHERE `status` = ? OR `vip` = ?'
            .' ORDER BY `id` DESC LIMIT 10 OFFSET 5',
            $sql,
        );
        self::assertSame(['active', true], $params);
        self::assertStringNotContainsString('active', $sql, 'values must be placeholders, not inline');
    }

    public function test_where_in_and_null_conditions(): void
    {
        $this->connection->table('users')
            ->whereIn('role', ['admin', 'dev'])
            ->whereNull('deleted_at')
            ->where('score', '<>', 0)
            ->get();

        [$sql, $params] = $this->plan();

        self::assertSame(
            'SELECT * FROM `users` WHERE `role` IN (?, ?) AND `deleted_at` IS NULL AND `score` <> ?',
            $sql,
        );
        self::assertSame(['admin', 'dev', 0], $params);
    }

    public function test_joins_render_structurally(): void
    {
        $this->connection->table('orders')->as('o')
            ->select('o.id', 'profiles.account')
            ->join('profiles', 'profiles.user_id', 'o.user_id', 'left')
            ->get();

        [$sql, $params] = $this->plan();

        self::assertSame(
            'SELECT `o`.`id`, `profiles`.`account` FROM `orders` AS `o`'
            .' LEFT JOIN `profiles` ON `profiles`.`user_id` = `o`.`user_id`',
            $sql,
        );
        self::assertSame([], $params);
    }

    public function test_pgsql_columns_quote_with_double_quotes(): void
    {
        $recorder = new RecordingPdo();

        $pgsqlConnection = Connection::connect(
            new PostgresDriver(),
            'pgsql:host=127.0.0.1;port=5432;dbname=test',
            null,
            null,
            [],
            static fn (array $_): PDO => $recorder,
        );

        $pgsqlConnection->table('users')->select('id', 'name')->where('vip', '=', true)->get();

        [$sql, $params] = [$recorder->executions[0]['sql'], $recorder->executions[0]['params']];

        self::assertSame('SELECT "id", "name" FROM "users" WHERE "vip" = ?', $sql);
        self::assertSame([true], $params);
    }

    /* ---------- writes ---------- */

    public function test_insert_binds_all_values_as_parameters(): void
    {
        $this->connection->table('users')->insert([
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'age' => 36,
        ]);

        [$sql, $params] = $this->plan();

        self::assertSame(
            'INSERT INTO `users` (`name`, `email`, `age`) VALUES (?, ?, ?)',
            $sql,
        );
        self::assertSame(['Ada', 'ada@example.test', 36], $params);
    }

    public function test_update_requires_where(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('WHERE');

        $this->connection->table('users')->update(['name' => 'nope']);
    }

    public function test_update_composes_set_and_where(): void
    {
        $this->connection->table('users')
            ->where('id', '=', 7)
            ->update(['name' => 'Dev', 'score' => 42]);

        [$sql, $params] = $this->plan();

        self::assertSame(
            'UPDATE `users` SET `name` = ?, `score` = ? WHERE `id` = ?',
            $sql,
        );
        self::assertSame(['Dev', 42, 7], $params);
    }

    public function test_delete_requires_where(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->connection->table('users')->delete();
    }

    public function test_delete_composes_where_with_params(): void
    {
        $this->connection->table('users')
            ->whereIn('id', [1, 2, 3])
            ->delete();

        [$sql, $params] = $this->plan();

        self::assertSame('DELETE FROM `users` WHERE `id` IN (?, ?, ?)', $sql);
        self::assertSame([1, 2, 3], $params);
    }

    /* ---------- injection resistance ---------- */

    public function test_malicious_values_never_reach_the_sql_text(): void
    {
        $payloads = [
            "x' OR 1=1--",
            "x'; DROP TABLE users;--",
            "'; UPDATE users SET role='admin' WHERE '1'='1",
            'zero'."\x00".'byte',
            '1) UNION SELECT password FROM admins --',
        ];

        foreach ($payloads as $index => $payload) {
            $this->recorder->executions = [];

            $this->connection->table('users')
                ->where('name', '=', $payload)
                ->get();

            [$sql, $params] = [$this->recorder->executions[0]['sql'], $this->recorder->executions[0]['params']];

            self::assertStringNotContainsString($payload, $sql, "payload #$index must be bound, not inlined");
            self::assertSame([$payload], $params, "payload #$index travels as a binding");
            self::assertStringNotContainsString('DROP', $sql);
            self::assertStringNotContainsString('UNION', $sql);
        }
    }

    public function test_malicious_identifiers_are_refused(): void
    {
        try {
            $this->connection->table('users`; DROP TABLE users; --');
            self::fail('Expected an identifier rejection.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('identifier', $exception->getMessage());
        }
    }

    public function test_unknown_table_alias_prefixes_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown table/alias');

        $this->connection->table('users')->where('ghost.col', '=', 1)->get();
    }

    public function test_expression_is_the_only_raw_channel(): void
    {
        $this->connection->table('stats')
            ->select(new Expression('COUNT(*) AS total'), 'name')
            ->whereRaw(new Expression('LENGTH(name) > ?'), [3])
            ->get();

        [$sql, $params] = $this->plan();

        self::assertSame(
            'SELECT (COUNT(*) AS total), `name` FROM `stats` WHERE (LENGTH(name) > ?)',
            $sql,
        );
        self::assertSame([3], $params);
    }

    public function test_expression_where_is_refused_by_plain_where(): void
    {
        try {
            $this->connection->table('stats')->where('1=1')->get();
            self::fail('Raw SQL fragments must be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('identifier', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('whereRaw');

        $this->connection->table('stats')->where(new Expression('1=1'))->get();
    }

    /* ---------- operator & argument validation ---------- */

    public function test_operators_and_directions_are_allowlisted(): void
    {
        try {
            $this->connection->table('users')->where('id', 'BETWEEN 1 AND 2');
            self::fail('Expected operator rejection.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('operator', $exception->getMessage());
        }

        try {
            $this->connection->table('users')->orderBy('id', 'DESC; DROP TABLE x');
            self::fail('Expected direction rejection.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('direction', $exception->getMessage());
        }
    }

    public function test_empty_where_in_refuses_degenerate_sql(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->connection->table('users')->whereIn('id', []);
    }

    public function test_array_bindings_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be an array');

        $this->connection->table('users')->insert(['tags' => ['a', 'b']]);
    }
}
