<?php

declare(strict_types=1);

namespace Sahil\Tests\Database;

use PHPUnit\Framework\TestCase;
use Sahil\Database\ConnectionFactory;
use Sahil\Database\Schema\Schema;
use Sahil\Database\Schema\Table;

/**
 * SQLite is the zero-setup default database: connecting must create the
 * file, migrations must build real tables, and queries must round-trip.
 */
final class SqliteIntegrationTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The pdo_sqlite extension is not installed on this machine.');
        }

        $this->path = sys_get_temp_dir().'/sahil-sqlite-'.bin2hex(random_bytes(6)).'.sqlite';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function test_sqlite_creates_the_database_file_and_runs_schema_and_queries(): void
    {
        $connection = (new ConnectionFactory())->make([
            'driver' => 'sqlite',
            'database' => $this->path,
        ]);

        self::assertFileExists($this->path);
        self::assertSame('sqlite', $connection->driver()->grammar()->dialect());

        $schema = new Schema($connection);

        $schema->create('jobs', static function (Table $table): void {
            $table->id();
            $table->string('queue');
            $table->text('payload');
            $table->integer('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('reserved_at')->nullable();
            $table->index('queue');
        });

        self::assertTrue($schema->hasTable('jobs'));
        self::assertFalse($schema->hasTable('missing'));

        $connection->table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{"job":"demo"}',
            'attempts' => 0,
            'available_at' => '2026-10-30 00:00:00',
            'reserved_at' => null,
        ]);

        $row = $connection->table('jobs')->where('queue', '=', 'default')->first();

        self::assertIsArray($row);
        self::assertSame('default', $row['queue']);
        self::assertSame(0, (int) $row['attempts']);
        self::assertNull($row['reserved_at']);
        // `id()` is an INTEGER PRIMARY KEY AUTOINCREMENT rowid alias.
        self::assertSame(1, (int) $row['id']);

        // Plain indexes are created after CREATE TABLE.
        $indexes = $connection
            ->execute("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'jobs'")
            ->fetchAll();

        self::assertContains('jobs_queue_index', array_column($indexes, 'name'));

        $schema->dropIfExists('jobs');
        self::assertFalse($schema->hasTable('jobs'));
    }

    public function test_sqlite_relative_paths_are_created_on_demand(): void
    {
        $directory = sys_get_temp_dir().'/sahil-sqlite-dir-'.bin2hex(random_bytes(6));
        $database = $directory.'/nested/database.sqlite';

        $connection = (new ConnectionFactory())->make([
            'driver' => 'sqlite',
            'database' => $database,
        ]);

        self::assertDirectoryExists($directory.'/nested');
        $connection->execute('SELECT 1');

        @unlink($database);
        @rmdir($directory.'/nested');
        @rmdir($directory);
    }

    public function test_sqlite_requires_a_database_path(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('database');

        (new \Sahil\Database\Drivers\SqliteDriver())->dsn([]);
    }
}
