<?php

declare(strict_types=1);

namespace Sahil\Tests\Migrations;

use PHPUnit\Framework\TestCase;
use Sahil\Database\Drivers\MySqlDriver;
use Sahil\Database\Drivers\PostgresDriver;
use Sahil\Database\Schema\ForeignKeyDefinition;
use Sahil\Database\Schema\IndexDefinition;
use Sahil\Database\Schema\MySqlGrammar;
use Sahil\Database\Schema\PostgresGrammar;
use Sahil\Database\Schema\SchemaException;
use Sahil\Database\Schema\Table;

/**
 * DDL compilation per dialect — no database required.
 *
 * The point of these tests is that every driver difference is EXPLICIT:
 * quoting, boolean literals, default escaping, where an index can be
 * declared, and which features the dialect refuses outright.
 */
final class SchemaGrammarTest extends TestCase
{
    private function mysql(): MySqlGrammar
    {
        return new MySqlGrammar(new MySqlDriver());
    }

    private function pgsql(): PostgresGrammar
    {
        return new PostgresGrammar(new PostgresDriver());
    }

    /* ---------- CREATE ---------- */

    public function test_mysql_create_compiles_columns_indexes_and_foreign_keys(): void
    {
        $table = new Table('users', Table::CREATE);
        $table->id();
        $table->string('email', 190)->unique();
        $table->boolean('active')->default(true);
        $table->integer('score')->default(0);
        $table->foreignId('team_id')->nullable();
        $table->timestamps();
        $table->index(['email', 'score']);
        $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');

        self::assertSame([
            'CREATE TABLE `users` ('
            .'`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY'
            .', `email` VARCHAR(190) NOT NULL UNIQUE'
            .', `active` TINYINT(1) NOT NULL DEFAULT 1'
            .', `score` INT NOT NULL DEFAULT 0'
            .', `team_id` BIGINT UNSIGNED NULL'
            .', `created_at` DATETIME NULL, `updated_at` DATETIME NULL'
            .', INDEX `users_email_score_index` (`email`, `score`)'
            .', CONSTRAINT `users_team_id_foreign` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE'
            .')',
        ], $this->mysql()->compileCreate($table));
    }

    public function test_pgsql_create_splits_plain_indexes_into_separate_statements(): void
    {
        $table = new Table('users', Table::CREATE);
        $table->id();
        $table->string('email', 190)->unique();
        $table->boolean('active')->default(true);
        $table->index(['email'], 'users_email_idx');

        $statements = $this->pgsql()->compileCreate($table);

        self::assertCount(2, $statements, 'PostgreSQL cannot declare a plain index inside CREATE TABLE');
        self::assertSame(
            'CREATE TABLE "users" ('
            .'"id" BIGSERIAL NOT NULL PRIMARY KEY'
            .', "email" VARCHAR(190) NOT NULL UNIQUE'
            .', "active" BOOLEAN NOT NULL DEFAULT TRUE'
            .')',
            $statements[0]
        );
        self::assertSame('CREATE INDEX "users_email_idx" ON "users" ("email")', $statements[1]);
    }

    public function test_create_if_not_exists_is_expressed_per_dialect(): void
    {
        $table = new Table('users', Table::CREATE);
        $table->id();
        $table->ifNotExists();

        self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS `users`', $this->mysql()->compileCreate($table)[0]);
        self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS "users"', $this->pgsql()->compileCreate($table)[0]);
    }

    public function test_a_table_without_columns_is_refused(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('declares no columns');

        $this->mysql()->compileCreate(new Table('empty', Table::CREATE));
    }

    /* ---------- defaults ---------- */

    public function test_string_defaults_are_escaped_per_dialect(): void
    {
        $mysql = new Table('t', Table::CREATE);
        $mysql->string('note')->default("back\\slash and 'quote'");

        $pgsql = new Table('t', Table::CREATE);
        $pgsql->string('note')->default("back\\slash and 'quote'");

        // MySQL treats backslashes as escapes; PostgreSQL does not.
        self::assertStringContainsString(
            '`note` VARCHAR(255) NOT NULL DEFAULT \'back\\\\slash and \'\'quote\'\'\'',
            $this->mysql()->compileCreate($mysql)[0]
        );
        self::assertStringContainsString(
            '"note" VARCHAR(255) NOT NULL DEFAULT \'back\\slash and \'\'quote\'\'\'',
            $this->pgsql()->compileCreate($pgsql)[0]
        );
    }

    public function test_boolean_defaults_render_as_the_dialect_spells_them(): void
    {
        $mysql = new Table('t', Table::CREATE);
        $mysql->boolean('on')->default(true);
        $mysql->boolean('off')->default(false);

        $pgsql = new Table('t', Table::CREATE);
        $pgsql->boolean('on')->default(true);
        $pgsql->boolean('off')->default(false);

        self::assertStringContainsString('DEFAULT 1', $this->mysql()->compileCreate($mysql)[0]);
        self::assertStringContainsString('DEFAULT 0', $this->mysql()->compileCreate($mysql)[0]);
        self::assertStringContainsString('DEFAULT TRUE', $this->pgsql()->compileCreate($pgsql)[0]);
        self::assertStringContainsString('DEFAULT FALSE', $this->pgsql()->compileCreate($pgsql)[0]);
    }

    public function test_a_nul_byte_in_a_default_is_refused(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('NUL byte');

        $table = new Table('t', Table::CREATE);
        $table->string('x')->default("bad\0value");

        $this->mysql()->compileCreate($table);
    }

    public function test_expression_defaults_pass_through_raw(): void
    {
        $table = new Table('t', Table::CREATE);
        $table->timestamp('created_at')->defaultExpression('CURRENT_TIMESTAMP');

        self::assertStringContainsString('DEFAULT CURRENT_TIMESTAMP', $this->mysql()->compileCreate($table)[0]);
    }

    /* ---------- dialect capability differences ---------- */

    public function test_unsigned_integers_are_refused_where_the_dialect_lacks_them(): void
    {
        $mysql = new Table('t', Table::CREATE);
        $mysql->bigInteger('n')->unsigned();

        self::assertStringContainsString('`n` BIGINT UNSIGNED', $this->mysql()->compileCreate($mysql)[0]);

        $pgsql = new Table('t', Table::CREATE);
        $pgsql->bigInteger('n')->unsigned();

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('no unsigned integer types');

        $this->pgsql()->compileCreate($pgsql);
    }

    /* ---------- ALTER ordering ---------- */

    public function test_alter_drops_constraints_before_columns_and_adds_afterwards(): void
    {
        $table = new Table('users', Table::ALTER);
        $table->string('nickname', 60)->nullable();
        $table->index(['nickname'], 'users_nickname_idx');
        $table->foreign('team_id')->references('id')->on('teams');
        $table->dropForeign('users_team_id_foreign');
        $table->dropIndex('users_email_idx');
        $table->dropColumn('legacy');

        self::assertSame([
            'ALTER TABLE `users` DROP FOREIGN KEY `users_team_id_foreign`',
            'ALTER TABLE `users` DROP INDEX `users_email_idx`',
            'ALTER TABLE `users` DROP COLUMN `legacy`',
            'ALTER TABLE `users` ADD COLUMN `nickname` VARCHAR(60) NULL',
            'ALTER TABLE `users` ADD INDEX `users_nickname_idx` (`nickname`)',
            'ALTER TABLE `users` ADD CONSTRAINT `users_team_id_foreign` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`)',
        ], $this->mysql()->compileAlter($table));
    }

    public function test_pgsql_alter_uses_its_own_index_and_constraint_syntax(): void
    {
        $table = new Table('users', Table::ALTER);
        $table->unique(['email'], 'users_email_unique_idx');
        $table->dropIndex('users_email_idx');
        $table->dropForeign('users_team_id_foreign');
        $table->dropColumn('legacy');

        self::assertSame([
            'ALTER TABLE "users" DROP CONSTRAINT "users_team_id_foreign"',
            'DROP INDEX "users_email_idx"',
            'ALTER TABLE "users" DROP COLUMN "legacy"',
            'CREATE UNIQUE INDEX "users_email_unique_idx" ON "users" ("email")',
        ], $this->pgsql()->compileAlter($table));
    }

    public function test_an_empty_alteration_is_refused(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('changes nothing');

        $this->mysql()->compileAlter(new Table('users', Table::ALTER));
    }

    /* ---------- drops & existence probe ---------- */

    public function test_drop_rendering_per_dialect(): void
    {
        self::assertSame('DROP TABLE `users`', $this->mysql()->compileDrop('users', false));
        self::assertSame('DROP TABLE IF EXISTS `users`', $this->mysql()->compileDrop('users', true));
        self::assertSame('DROP TABLE "users"', $this->pgsql()->compileDrop('users', false));
        self::assertSame('DROP TABLE IF EXISTS "users"', $this->pgsql()->compileDrop('users', true));
    }

    public function test_exists_probe_binds_the_table_name(): void
    {
        foreach ([$this->mysql(), $this->pgsql()] as $grammar) {
            [$sql, $parameters] = $grammar->compileTableExists('users');

            self::assertStringContainsString('table_name = ?', $sql);
            self::assertSame(['users'], $parameters, 'the table name is a binding, never SQL text');
            self::assertStringNotContainsString('users', $sql);
        }
    }

    /* ---------- misuse ---------- */

    public function test_malicious_identifiers_never_reach_the_ddl(): void
    {
        foreach (['users; DROP TABLE x', 'users`', 'users"', "users'", '1users', 'a.b.c', ''] as $identifier) {
            try {
                new Table($identifier, Table::CREATE);
                self::fail(sprintf('Identifier [%s] must be refused.', $identifier));
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_incomplete_foreign_keys_are_refused(): void
    {
        $table = new Table('users', Table::CREATE);
        $table->id();
        $table->foreignId('team_id');
        $table->foreign('team_id')->references('id'); // no on(<table>)

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('incomplete');

        $this->mysql()->compileCreate($table);
    }

    public function test_unsupported_referential_actions_are_refused(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('referential action');

        new ForeignKeyDefinition('team_id')->onDelete('cascade; DROP TABLE users');
    }

    public function test_constraint_names_stay_within_the_mysql_identifier_limit(): void
    {
        $table = new Table('a_very_long_table_name_for_the_limit', Table::CREATE);
        $table->id();
        $table->index(
            ['a_very_long_column_name_here', 'another_very_long_column_name', 'third_long_column_name'],
            null
        );

        $mysql = $this->mysql()->compileCreate($table)[0];

        self::assertSame(1, preg_match('/INDEX `([A-Za-z0-9_]+)`/', $mysql, $matches));
        self::assertLessThanOrEqual(
            \Sahil\Database\Identifier::MAX_SEGMENT_LENGTH,
            strlen($matches[1]),
            'the derived name must satisfy the identifier validator'
        );
    }

    public function test_explicit_index_names_are_respected(): void
    {
        $table = new Table('users', Table::CREATE);
        $table->id();
        $table->string('email', 100);
        $table->unique(['email'], 'custom_unique_name');

        self::assertStringContainsString('UNIQUE KEY `custom_unique_name`', $this->mysql()->compileCreate($table)[0]);
    }

    public function test_composite_primary_key_is_expressed_per_dialect(): void
    {
        $mysql = new Table('user_roles', Table::CREATE);
        $mysql->foreignId('user_id');
        $mysql->foreignId('role_id');
        $mysql->primary(['user_id', 'role_id']);

        self::assertStringContainsString('PRIMARY KEY (`user_id`, `role_id`)', $this->mysql()->compileCreate($mysql)[0]);

        $pgsql = new Table('user_roles', Table::CREATE);
        $pgsql->foreignId('user_id');
        $pgsql->foreignId('role_id');
        $pgsql->primary(['user_id', 'role_id'], 'user_roles_pkey');

        self::assertStringContainsString(
            'CONSTRAINT "user_roles_pkey" PRIMARY KEY ("user_id", "role_id")',
            $this->pgsql()->compileCreate($pgsql)[0]
        );
    }

    public function test_index_kinds_are_validated(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('at least one column');

        new IndexDefinition([]);
    }
}
