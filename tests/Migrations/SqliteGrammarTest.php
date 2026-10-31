<?php

declare(strict_types=1);

namespace Sahil\Tests\Migrations;

use PHPUnit\Framework\TestCase;
use Sahil\Database\Drivers\SqliteDriver;
use Sahil\Database\Schema\SchemaException;
use Sahil\Database\Schema\SqliteGrammar;
use Sahil\Database\Schema\Table;

/**
 * SQLite DDL compilation — no database or `pdo_sqlite` extension required.
 *
 * SQLite differs from the server dialects in three places that matter:
 * the auto-increment key is one clause in a fixed order, plain indexes are
 * separate statements, and keys cannot be altered on an existing table.
 */
final class SqliteGrammarTest extends TestCase
{
    private function sqlite(): SqliteGrammar
    {
        return new SqliteGrammar(new SqliteDriver());
    }

    public function test_create_compiles_an_autoincrement_primary_key_and_separate_indexes(): void
    {
        $table = new Table('users', Table::CREATE);
        $table->id();
        $table->string('email', 190)->unique();
        $table->boolean('active')->default(true);
        $table->integer('score')->default(0);
        $table->foreignId('team_id')->nullable();
        $table->timestamps();
        $table->index(['email', 'score']);

        $statements = $this->sqlite()->compileCreate($table);
        $sql = implode("\n", $statements);

        self::assertStringContainsString('CREATE TABLE "users" (', $sql);
        self::assertStringContainsString('"id" INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
        self::assertStringNotContainsString('AUTOINCREMENT PRIMARY KEY', $sql);
        self::assertStringContainsString('"email" VARCHAR(190) NOT NULL UNIQUE', $sql);
        self::assertStringContainsString('"active" BOOLEAN NOT NULL DEFAULT 1', $sql);
        self::assertStringContainsString('"team_id" INTEGER NULL', $sql);

        // Plain indexes become a follow-up statement, not a table clause.
        self::assertStringNotContainsString('INDEX "users_email_score_index" (', $sql);
        self::assertStringContainsString(
            'CREATE INDEX "users_email_score_index" ON "users" ("email", "score")',
            $sql,
        );

        // IDs are quoted with double quotes like PostgreSQL.
        self::assertSame('"users"', (new SqliteDriver())->quoteIdentifier('users'));
    }

    public function test_table_existence_uses_sqlite_master(): void
    {
        [$sql, $bindings] = $this->sqlite()->compileTableExists('users');

        self::assertSame("SELECT 1 AS found FROM sqlite_master WHERE type = 'table' AND name = ?", $sql);
        self::assertSame(['users'], $bindings);
    }

    public function test_drop_index_is_standalone(): void
    {
        $table = new Table('users', Table::ALTER);
        $table->dropIndex('users_email_index');

        self::assertContains('DROP INDEX "users_email_index"', $this->sqlite()->compileAlter($table));
    }

    public function test_unsigned_columns_are_refused(): void
    {
        $table = new Table('counters', Table::CREATE);
        $table->bigInteger('total')->unsigned();

        $this->expectException(SchemaException::class);
        $this->sqlite()->compileCreate($table);
    }

    public function test_dropping_a_foreign_key_explains_the_sqlite_limitation(): void
    {
        $table = new Table('members', Table::ALTER);
        $table->dropForeign('members_team_id_foreign');

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('SQLite cannot drop a foreign key');

        $this->sqlite()->compileAlter($table);
    }

    public function test_adding_a_primary_key_explains_the_sqlite_limitation(): void
    {
        $table = new Table('members', Table::ALTER);
        $table->primary('uuid');

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('SQLite cannot add a primary key');

        $this->sqlite()->compileAlter($table);
    }
}
