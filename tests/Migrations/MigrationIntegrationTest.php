<?php

declare(strict_types=1);

namespace Sahil\Tests\Migrations;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sahil\Database\Connection;
use Sahil\Database\ConnectionException;
use Sahil\Database\ConnectionFactory;
use Sahil\Database\QueryException;
use Sahil\Migrations\ConfirmationContract;
use Sahil\Migrations\DestructiveOperationException;
use Sahil\Migrations\MigrationException;
use Sahil\Migrations\MigrationLoader;
use Sahil\Migrations\MigrationLock;
use Sahil\Migrations\MigrationRepository;
use Sahil\Migrations\Migrator;
use Sahil\Migrations\MutationGuard;
use Sahil\Migrations\SeederLoader;
use Sahil\Migrations\SeederRunner;

/**
 * Phase-9 integration on REAL test databases.
 *
 * These are the tests that make the claims in the README true: fresh
 * installation, repeat execution, rollback, failure behaviour (including the
 * dialect difference for transactional DDL), concurrency locking, the
 * destructive-operation gate, and seeding.
 *
 * Gated on the same environment URLs as the Phase-7/8 suites:
 *
 *   SAHIL_DB_TEST_MYSQL='mysql://root@127.0.0.1:33306/sahil_tests'
 *   SAHIL_DB_TEST_PGSQL='pgsql://jinx@127.0.0.1:55432/sahil_tests'
 *
 * Missing/unreachable env → explicit SKIP, never a silent pass.
 */
final class MigrationIntegrationTest extends TestCase
{
    use TempDirectory;

    private const LEDGER = 'sahil_it_migrations';

    private const FIXTURE_TABLES = ['it_users', 'it_teams', 'it_partial', 'it_audit'];

    /** @var array<string, mixed>|null */
    private ?array $configuration = null;

    private string $dialect = '';

    protected function tearDown(): void
    {
        if ($this->configuration !== null) {
            $connection = $this->connect();

            if ($connection !== null) {
                $this->dropEverything($connection);
                $connection->disconnect();
            }
        }

        $this->removeTempDirectories();
        $this->configuration = null;
    }

    /**
     * @return array<string, array{0: string, 1: ?array<string, mixed>}>
     */
    public static function databaseProvider(): array
    {
        $cases = [];

        foreach (['mysql' => 'SAHIL_DB_TEST_MYSQL', 'pgsql' => 'SAHIL_DB_TEST_PGSQL'] as $label => $variable) {
            $url = getenv($variable);
            $cases[$label] = [$label, is_string($url) && trim($url) !== '' ? self::configuration($label, $url) : null];
        }

        return $cases;
    }

    /**
     * @return array<string, mixed>
     */
    private static function configuration(string $driver, string $url): array
    {
        $parts = parse_url($url) ?: [];

        return [
            'driver' => $driver,
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => $parts['port'] ?? ($driver === 'mysql' ? 3306 : 5432),
            'database' => ltrim($parts['path'] ?? '/sahil_tests', '/'),
            'username' => $parts['user'] ?? '',
            'password' => $parts['pass'] ?? '',
        ];
    }

    /**
     * @param array<string, mixed>|null $configuration
     */
    private function boot(string $label, ?array $configuration): void
    {
        if ($configuration === null) {
            $variable = $label === 'mysql' ? 'SAHIL_DB_TEST_MYSQL' : 'SAHIL_DB_TEST_PGSQL';

            self::markTestSkipped("Set {$variable} to run the {$label} migrations integration suite.");
        }

        $this->dialect = $label;
        $this->configuration = $configuration;

        $connection = $this->connect();

        if ($connection === null) {
            self::markTestSkipped(sprintf('[%s unreachable]', $label));
        }

        $this->dropEverything($connection);
        $connection->disconnect();
    }

    private function connect(): ?Connection
    {
        if ($this->configuration === null) {
            return null;
        }

        try {
            return (new ConnectionFactory())->make($this->configuration);
        } catch (ConnectionException $failure) {
            self::markTestSkipped('[unreachable] '.$failure->getMessage());

            return null;
        }
    }

    private function dropEverything(Connection $connection): void
    {
        foreach ([...self::FIXTURE_TABLES, self::LEDGER] as $table) {
            try {
                (new \Sahil\Database\Schema\Schema($connection))->dropIfExists($table);
            } catch (QueryException) {
                // Nothing to drop on a fresh database.
            }
        }
    }

    /**
     * @return array{migrator: Migrator, repository: MigrationRepository, connection: Connection}
     */
    private function services(Connection $connection, string $directory, string $environment = 'development', bool $confirm = true): array
    {
        $repository = new MigrationRepository($connection, self::LEDGER);

        $confirmation = $confirm
            ? new class () implements ConfirmationContract {
                public function confirm(string $question): bool
                {
                    return true;
                }
            }
        : new class () implements ConfirmationContract {
            public function confirm(string $question): bool
            {
                return false;
            }
        };

        $lockName = 'sahil_it_lock:'.(string) ($this->configuration['database'] ?? 'test');

        return [
            'connection' => $connection,
            'repository' => $repository,
            'migrator' => new Migrator(
                $connection,
                $repository,
                new MigrationLoader($directory),
                new MutationGuard($environment, $confirmation),
                new MigrationLock($connection, $lockName, 1),
            ),
        ];
    }

    private function schema(Connection $connection): \Sahil\Database\Schema\Schema
    {
        return new \Sahil\Database\Schema\Schema($connection);
    }

    /* ---------- fixtures ---------- */

    private function writeCoreMigrations(string $directory): void
    {
        $this->writeFixture($directory, '2026_01_01_000000_create_it_teams.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Sahil\Database\Schema\Schema;
            use Sahil\Database\Schema\Table;
            use Sahil\Migrations\Migration;

            return new class extends Migration {
                public function up(Schema $schema): void
                {
                    $schema->create('it_teams', function (Table $table): void {
                        $table->id();
                        $table->string('name', 80)->unique();
                    });
                }

                public function down(Schema $schema): void
                {
                    $schema->dropIfExists('it_teams');
                }
            };
            PHP);

        $this->writeFixture($directory, '2026_01_02_000000_create_it_users.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Sahil\Database\Schema\Schema;
            use Sahil\Database\Schema\Table;
            use Sahil\Migrations\Migration;

            return new class extends Migration {
                public function up(Schema $schema): void
                {
                    $schema->create('it_users', function (Table $table): void {
                        $table->id();
                        $table->string('email', 150);
                        $table->foreignId('team_id')->nullable();
                        $table->timestamps();
                        $table->unique(['email']);
                        $table->index(['email', 'team_id']);
                        $table->foreign('team_id')->references('id')->on('it_teams')->onDelete('set null');
                    });
                }

                public function down(Schema $schema): void
                {
                    $schema->dropIfExists('it_users');
                }
            };
            PHP);
    }

    /* ---------- scenarios ---------- */

    #[DataProvider('databaseProvider')]
    public function test_fresh_installation_repeat_run_and_rollback(string $label, ?array $configuration): void
    {
        $this->boot($label, $configuration);

        $directory = $this->makeTempDirectory();
        $this->writeCoreMigrations($directory);

        $connection = $this->connect();
        self::assertNotNull($connection);

        $services = $this->services($connection, $directory);
        $migrator = $services['migrator'];
        $repository = $services['repository'];

        // ---- fresh install: status is read-only and creates nothing ----
        $status = $migrator->status();
        self::assertCount(2, $status['pending']);
        self::assertSame([], $status['applied']);
        self::assertFalse($repository->exists(), 'status must not create the ledger on a fresh database');
        self::assertFalse($this->schema($connection)->hasTable('it_users'));

        // ---- first migrate applies everything in one batch ----
        $report = $migrator->migrate();
        self::assertSame(
            ['2026_01_01_000000_create_it_teams', '2026_01_02_000000_create_it_users'],
            $report->applied
        );
        self::assertTrue($report->changed());
        self::assertTrue($this->schema($connection)->hasTable('it_users'));

        $batches = $repository->batches();
        self::assertSame(1, $batches['2026_01_01_000000_create_it_teams']);
        self::assertSame(1, $batches['2026_01_02_000000_create_it_users']);
        self::assertSame(2, $repository->nextBatch());

        // ---- repeat run: nothing pending, nothing written ----
        $second = $migrator->migrate();
        self::assertSame([], $second->applied);
        self::assertFalse($second->changed());
        self::assertCount(2, $migrator->status()['applied'], 'a repeat run must not duplicate ledger rows');

        // ---- the constraints the migration declared are really enforced ----
        $connection->table('it_teams')->insert(['name' => 'Core']);
        $connection->table('it_users')->insert(['email' => 'ada@example.test', 'team_id' => 1]);

        try {
            $connection->table('it_teams')->insert(['name' => 'Core']);
            self::fail('the unique constraint must be enforced');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        try {
            $connection->table('it_users')->insert(['email' => 'ghost@example.test', 'team_id' => 999]);
            self::fail('the foreign key must be enforced');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        // ---- rollback reverses the batch (newest first) ----
        $rolledBack = $migrator->rollback(force: true);
        self::assertSame(
            ['2026_01_02_000000_create_it_users', '2026_01_01_000000_create_it_teams'],
            $rolledBack->rolledBack
        );
        self::assertFalse($this->schema($connection)->hasTable('it_users'), 'rollback drops what up() created');
        self::assertFalse($this->schema($connection)->hasTable('it_teams'));
        self::assertSame([], $repository->batches(), 'the ledger is emptied by a rollback');

        // ---- rolling back again is a no-op, not an error ----
        $again = $migrator->rollback(force: true);
        self::assertSame([], $again->rolledBack);
        self::assertFalse($again->changed());

        $connection->disconnect();
    }

    #[DataProvider('databaseProvider')]
    public function test_a_failed_migration_is_reported_and_not_recorded(string $label, ?array $configuration): void
    {
        $this->boot($label, $configuration);

        $directory = $this->makeTempDirectory();
        $this->writeCoreMigrations($directory);
        $this->writeFixture($directory, '2026_01_03_000000_failing.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Sahil\Database\Schema\Schema;
            use Sahil\Database\Schema\Table;
            use Sahil\Migrations\Migration;

            return new class extends Migration {
                public function up(Schema $schema): void
                {
                    $schema->create('it_partial', function (Table $table): void {
                        $table->id();
                        $table->string('x', 20);
                    });

                    throw new RuntimeException('intentional failure after DDL');
                }

                public function down(Schema $schema): void
                {
                    $schema->dropIfExists('it_partial');
                }
            };
            PHP);

        $connection = $this->connect();
        self::assertNotNull($connection);

        $services = $this->services($connection, $directory);
        $repository = $services['repository'];

        try {
            $services['migrator']->migrate();
            self::fail('a failing migration must propagate');
        } catch (MigrationException $failure) {
            self::assertStringContainsString('2026_01_03_000000_failing', $failure->getMessage());
            self::assertStringContainsString('intentional failure after DDL', $failure->getMessage(), 'the driver/original message is kept');
        }

        // The successful migrations before the failure stayed applied.
        self::assertArrayHasKey('2026_01_01_000000_create_it_teams', $repository->batches());
        self::assertArrayHasKey('2026_01_02_000000_create_it_users', $repository->batches());

        // ...but the failed one was NEVER recorded, so it stays pending.
        self::assertArrayNotHasKey('2026_01_03_000000_failing', $repository->batches());
        self::assertContains('2026_01_03_000000_failing', $services['migrator']->status()['pending']);

        // The dialect difference, asserted explicitly rather than assumed:
        // PostgreSQL rolls the DDL back with the transaction, MySQL cannot.
        if ($connection->driver()->supportsTransactionalDdl()) {
            self::assertFalse(
                $this->schema($connection)->hasTable('it_partial'),
                'transactional DDL must leave no partial schema behind'
            );
        } else {
            self::assertTrue(
                $this->schema($connection)->hasTable('it_partial'),
                'MySQL cannot roll DDL back — the partial table survives, which is exactly why the report warns about it'
            );
        }

        $connection->disconnect();
    }

    #[DataProvider('databaseProvider')]
    public function test_concurrent_runs_are_serialized_by_the_advisory_lock(string $label, ?array $configuration): void
    {
        $this->boot($label, $configuration);

        $directory = $this->makeTempDirectory();
        $this->writeCoreMigrations($directory);

        $connection = $this->connect();
        $other = $this->connect();
        self::assertNotNull($connection);
        self::assertNotNull($other);

        $services = $this->services($connection, $directory);

        $lockName = 'sahil_it_lock:'.(string) ($this->configuration['database'] ?? 'test');

        // A different session holds the lock (short timeout so the test is quick).
        $holder = new MigrationLock($other, $lockName, 1);
        self::assertTrue($holder->acquire(), 'the test must actually hold the lock');

        try {
            $services['migrator']->migrate();
            self::fail('a second process must not migrate concurrently');
        } catch (MigrationException $failure) {
            self::assertStringContainsString('Another process is running migrations', $failure->getMessage());
        }

        // Nothing ran while the lock was held.
        self::assertFalse($this->schema($connection)->hasTable('it_teams'));

        // Once released, the same migrator succeeds — and re-acquiring works.
        $holder->release();

        $report = $services['migrator']->migrate();
        self::assertCount(2, $report->applied);
        self::assertTrue($this->schema($connection)->hasTable('it_teams'));

        $connection->disconnect();
        $other->disconnect();
    }

    #[DataProvider('databaseProvider')]
    public function test_destructive_operations_require_deliberate_operator_intent(string $label, ?array $configuration): void
    {
        $this->boot($label, $configuration);

        $directory = $this->makeTempDirectory();
        $this->writeCoreMigrations($directory);

        $connection = $this->connect();
        self::assertNotNull($connection);

        // production + a declining/unattended operator
        $production = $this->services($connection, $directory, environment: 'production', confirm: false);

        try {
            $production['migrator']->migrate();
            self::fail('production must require --force');
        } catch (DestructiveOperationException $refusal) {
            self::assertStringContainsString('--force', $refusal->getMessage());
        }

        self::assertFalse(
            $this->schema($connection)->hasTable('it_teams'),
            'a refused run must not touch the database at all'
        );

        // production + the documented force flag
        $forced = $production['migrator']->migrate(force: true);
        self::assertCount(2, $forced->applied);

        // development + a declining/unattended operator: rollback still asks
        // first, and a refusal leaves the schema alone.
        $development = $this->services($connection, $directory, confirm: false);

        try {
            $development['migrator']->rollback();
            self::fail('an unattended rollback must be refused');
        } catch (DestructiveOperationException $refusal) {
            self::assertStringContainsString('not confirmed', $refusal->getMessage());
        }

        self::assertTrue($this->schema($connection)->hasTable('it_teams'));

        $connection->disconnect();
    }

    #[DataProvider('databaseProvider')]
    public function test_forward_only_migrations_are_never_rolled_back_silently(string $label, ?array $configuration): void
    {
        $this->boot($label, $configuration);

        $directory = $this->makeTempDirectory();
        $this->writeCoreMigrations($directory);
        // Forward-only: no down() at all.
        $this->writeFixture($directory, '2026_01_03_000000_audit.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Sahil\Database\Schema\Schema;
            use Sahil\Database\Schema\Table;
            use Sahil\Migrations\Migration;

            return new class extends Migration {
                public function up(Schema $schema): void
                {
                    $schema->create('it_audit', function (Table $table): void {
                        $table->id();
                        $table->string('event', 40);
                    });
                }
            };
            PHP);

        $connection = $this->connect();
        self::assertNotNull($connection);

        $services = $this->services($connection, $directory);
        self::assertCount(3, $services['migrator']->migrate()->applied, 'all three land in one batch');

        // Without the force flag the whole rollback is refused, and the
        // refusal names the migration that cannot be reversed.
        try {
            $services['migrator']->rollback();
            self::fail('a forward-only migration must block an implicit rollback');
        } catch (DestructiveOperationException $refusal) {
            self::assertStringContainsString('2026_01_03_000000_audit', $refusal->getMessage());
            self::assertStringContainsString('forward-only', $refusal->getMessage());
            self::assertStringContainsString('--force', $refusal->getMessage());
        }

        self::assertTrue($this->schema($connection)->hasTable('it_users'), 'a refused rollback must change nothing');
        self::assertTrue($this->schema($connection)->hasTable('it_teams'));
        self::assertCount(3, $services['repository']->batches(), 'the ledger is untouched by a refusal');

        $connection->disconnect();
    }

    #[DataProvider('databaseProvider')]
    public function test_rollback_reverses_only_reversible_migrations_when_forced(string $label, ?array $configuration): void
    {
        $this->boot($label, $configuration);

        $directory = $this->makeTempDirectory();

        // Two batches, so rollback order is unambiguous: batch 1 = teams,
        // batch 2 = (reversible) users + (forward-only) audit.
        $this->writeFixture($directory, '2026_01_01_000000_create_it_teams.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Sahil\Database\Schema\Schema;
            use Sahil\Database\Schema\Table;
            use Sahil\Migrations\Migration;

            return new class extends Migration {
                public function up(Schema $schema): void
                {
                    $schema->create('it_teams', function (Table $table): void {
                        $table->id();
                        $table->string('name', 80);
                    });
                }

                public function down(Schema $schema): void
                {
                    $schema->dropIfExists('it_teams');
                }
            };
            PHP);

        $connection = $this->connect();
        self::assertNotNull($connection);

        $services = $this->services($connection, $directory);
        self::assertCount(1, $services['migrator']->migrate()->applied);

        $this->writeFixture($directory, '2026_01_02_000000_create_it_users.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Sahil\Database\Schema\Schema;
            use Sahil\Database\Schema\Table;
            use Sahil\Migrations\Migration;

            return new class extends Migration {
                public function up(Schema $schema): void
                {
                    $schema->create('it_users', function (Table $table): void {
                        $table->id();
                        $table->string('email', 120);
                    });
                }

                public function down(Schema $schema): void
                {
                    $schema->dropIfExists('it_users');
                }
            };
            PHP);

        $this->writeFixture($directory, '2026_01_03_000000_create_it_audit.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Sahil\Database\Schema\Schema;
            use Sahil\Database\Schema\Table;
            use Sahil\Migrations\Migration;

            return new class extends Migration {
                public function up(Schema $schema): void
                {
                    $schema->create('it_audit', function (Table $table): void {
                        $table->id();
                        $table->string('event', 40);
                    });
                }
            };
            PHP);

        self::assertCount(2, $services['migrator']->migrate()->applied, 'batch 2');

        // Batch 2 contains a forward-only migration: refused without force.
        try {
            $services['migrator']->rollback();
            self::fail('the forward-only migration must block the rollback');
        } catch (DestructiveOperationException $refusal) {
            self::assertStringContainsString('2026_01_03_000000_create_it_audit', $refusal->getMessage());
        }

        self::assertTrue($this->schema($connection)->hasTable('it_users'), 'a refused rollback changes nothing');

        // Forced: reversible ones are reversed, the forward-only one is left applied.
        $report = $services['migrator']->rollback(force: true);

        self::assertSame(['2026_01_02_000000_create_it_users'], $report->rolledBack);
        self::assertCount(1, $report->skipped);
        self::assertStringContainsString('forward-only', $report->skipped[0]);
        self::assertFalse($this->schema($connection)->hasTable('it_users'));
        self::assertTrue($this->schema($connection)->hasTable('it_audit'), 'the forward-only migration stays applied');
        self::assertTrue($this->schema($connection)->hasTable('it_teams'), 'batch 1 is untouched');
        self::assertArrayHasKey('2026_01_03_000000_create_it_audit', $services['repository']->batches());

        $connection->disconnect();
    }

    #[DataProvider('databaseProvider')]
    public function test_an_orphaned_ledger_record_blocks_rollback_until_forced(string $label, ?array $configuration): void
    {
        $this->boot($label, $configuration);

        $directory = $this->makeTempDirectory();
        $this->writeCoreMigrations($directory);
        // A third, reversible migration so the orphaned record is the NEWEST
        // entry — the case an operator actually hits when a deploy ships the
        // ledger but not the file.
        $this->writeFixture($directory, '2026_01_03_000000_create_it_audit.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Sahil\Database\Schema\Schema;
            use Sahil\Database\Schema\Table;
            use Sahil\Migrations\Migration;

            return new class extends Migration {
                public function up(Schema $schema): void
                {
                    $schema->create('it_audit', function (Table $table): void {
                        $table->id();
                        $table->string('event', 40);
                    });
                }

                public function down(Schema $schema): void
                {
                    $schema->dropIfExists('it_audit');
                }
            };
            PHP);

        $connection = $this->connect();
        self::assertNotNull($connection);

        $services = $this->services($connection, $directory);
        self::assertCount(3, $services['migrator']->migrate()->applied);

        // The file disappears (bad deploy, partial checkout) while the ledger
        // still records it as applied.
        unlink($directory.'/2026_01_03_000000_create_it_audit.php');

        try {
            $services['migrator']->rollback();
            self::fail('an unverifiable rollback must be refused');
        } catch (MigrationException $failure) {
            self::assertStringContainsString('no file on disk', $failure->getMessage());
            self::assertStringContainsString('2026_01_03_000000_create_it_audit', $failure->getMessage());
        }

        self::assertTrue($this->schema($connection)->hasTable('it_audit'), 'the refusal changed nothing');

        // The documented escape hatch: --force forgets the unverifiable
        // record and reverses everything else it can, in dependency-safe order.
        $report = $services['migrator']->rollback(force: true);

        self::assertCount(1, $report->skipped);
        self::assertStringContainsString('ledger record forgotten', $report->skipped[0]);
        self::assertSame(
            ['2026_01_02_000000_create_it_users', '2026_01_01_000000_create_it_teams'],
            $report->rolledBack
        );
        self::assertSame([], $services['repository']->batches(), 'the ledger is emptied');
        self::assertFalse($this->schema($connection)->hasTable('it_users'));
        self::assertFalse($this->schema($connection)->hasTable('it_teams'));

        $connection->disconnect();
    }

    #[DataProvider('databaseProvider')]
    public function test_seeding_against_a_real_database(string $label, ?array $configuration): void
    {
        $this->boot($label, $configuration);

        $directory = $this->makeTempDirectory();
        $this->writeCoreMigrations($directory);

        $seederDirectory = $this->makeTempDirectory();
        $this->writeFixture($seederDirectory, 'TeamsSeeder.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Sahil\Migrations\SeedContext;
            use Sahil\Migrations\Seeder;

            return new class extends Seeder {
                public function run(SeedContext $context): void
                {
                    $context->connection->table('it_teams')->insert(['name' => 'Platform']);
                    $context->call('UsersSeeder');
                }
            };
            PHP);
        $this->writeFixture($seederDirectory, 'UsersSeeder.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Sahil\Migrations\SeedContext;
            use Sahil\Migrations\Seeder;

            return new class extends Seeder {
                public function run(SeedContext $context): void
                {
                    $context->connection->table('it_users')->insert(['email' => 'ada@example.test']);
                }
            };
            PHP);

        $connection = $this->connect();
        self::assertNotNull($connection);

        $services = $this->services($connection, $directory);
        $services['migrator']->migrate();

        $runner = new SeederRunner($connection, new SeederLoader($seederDirectory));
        $report = $runner->runAll();

        self::assertSame(['TeamsSeeder', 'UsersSeeder'], $report->applied, 'the composite seeder pulls the child in exactly once');
        self::assertSame('Platform', $connection->table('it_teams')->first()['name']);
        self::assertSame('ada@example.test', $connection->table('it_users')->first()['email']);

        // A failing seeder must leave nothing behind (transaction rolled back).
        $failingDirectory = $this->makeTempDirectory();
        $this->writeFixture($failingDirectory, 'ExplodingSeeder.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Sahil\Migrations\SeedContext;
            use Sahil\Migrations\Seeder;

            return new class extends Seeder {
                public function run(SeedContext $context): void
                {
                    $context->connection->table('it_users')->insert(['email' => 'rolled-back@example.test']);

                    throw new RuntimeException('seeder failed on purpose');
                }
            };
            PHP);

        try {
            (new SeederRunner($connection, new SeederLoader($failingDirectory)))->runAll();
            self::fail('a failing seeder must propagate');
        } catch (MigrationException $failure) {
            self::assertStringContainsString('seeder failed on purpose', $failure->getMessage());
        }

        self::assertNull(
            $connection->table('it_users')->where('email', '=', 'rolled-back@example.test')->first(),
            'a failed seeder must not persist partial rows'
        );

        $connection->disconnect();
    }
}
