<?php

declare(strict_types=1);

namespace Sahil\Tests\Migrations;

use PDO;
use PHPUnit\Framework\TestCase;
use Sahil\Database\Connection;
use Sahil\Database\Drivers\MySqlDriver;
use Sahil\Migrations\MigrationException;
use Sahil\Migrations\SeederLoader;
use Sahil\Migrations\SeederRunner;
use Sahil\Tests\Database\RecordingPdo;

/**
 * Seeding: registration by file name, one run per execution, ordering,
 * transactional all-or-nothing, and loud failures.
 */
final class SeederTest extends TestCase
{
    use TempDirectory;

    private RecordingPdo $recorder;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->recorder = new RecordingPdo();
        $recorder = $this->recorder;

        $this->connection = Connection::connect(
            new MySqlDriver(),
            'mysql:host=x',
            null,
            null,
            [],
            static fn (array $_): PDO => $recorder
        );
    }

    protected function tearDown(): void
    {
        $this->removeTempDirectories();
    }

    private function insertSeeder(string $label): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            use Sahil\\Migrations\\SeedContext;
            use Sahil\\Migrations\\Seeder;

            return new class extends Seeder {
                public function run(SeedContext \$context): void
                {
                    \$context->connection->table('probe')->insert(['label' => '{$label}']);
                }
            };
            PHP;
    }

    /** @return list<string> */
    private function seededLabels(): array
    {
        $labels = [];

        foreach ($this->recorder->executions as $execution) {
            if (str_starts_with($execution['sql'], 'INSERT INTO')) {
                $labels[] = (string) ($execution['params'][0] ?? '');
            }
        }

        return $labels;
    }

    public function test_seeders_are_registered_by_file_name_and_run_in_order(): void
    {
        $directory = $this->makeTempDirectory();
        $this->writeFixture($directory, 'UsersSeeder.php', $this->insertSeeder('users'));
        $this->writeFixture($directory, 'AuditSeeder.php', $this->insertSeeder('audit'));

        $loader = new SeederLoader($directory);

        self::assertSame(['AuditSeeder', 'UsersSeeder'], $loader->names(), 'registration is sorted by name');
        self::assertTrue($loader->has('UsersSeeder'));
        self::assertFalse($loader->has('NopeSeeder'));

        $report = (new SeederRunner($this->connection, $loader))->runAll();

        self::assertSame(['AuditSeeder', 'UsersSeeder'], $report->applied);
        self::assertSame(['audit', 'users'], $this->seededLabels());
        self::assertTrue($report->changed());
    }

    public function test_a_missing_seeders_directory_is_not_an_error(): void
    {
        $loader = new SeederLoader(sys_get_temp_dir().'/sahil-no-seeders-'.bin2hex(random_bytes(4)));

        self::assertSame([], $loader->names());
        self::assertSame([], (new SeederRunner($this->connection, $loader))->runAll()->applied);
    }

    public function test_a_seeder_runs_at_most_once_even_when_reached_repeatedly(): void
    {
        $directory = $this->makeTempDirectory();
        $this->writeFixture($directory, 'SelfReferencingSeeder.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Sahil\Migrations\SeedContext;
            use Sahil\Migrations\Seeder;

            return new class extends Seeder {
                public function run(SeedContext $context): void
                {
                    $context->connection->table('probe')->insert(['label' => 'once']);

                    // A cycle must terminate: the runner skips what it is
                    // already running.
                    $context->call('SelfReferencingSeeder');
                    $context->call('SelfReferencingSeeder');
                }
            };
            PHP);

        $report = (new SeederRunner($this->connection, new SeederLoader($directory)))->runAll();

        self::assertSame(['SelfReferencingSeeder'], $report->applied);
        self::assertSame(['once'], $this->seededLabels(), 'the recursive call must not insert twice');
    }

    public function test_a_composite_seeder_can_call_others(): void
    {
        $directory = $this->makeTempDirectory();

        // The parent sorts FIRST so that the run genuinely reaches the child
        // through the composite call rather than through the name loop.
        $this->writeFixture($directory, 'AllSeeder.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Sahil\Migrations\SeedContext;
            use Sahil\Migrations\Seeder;

            return new class extends Seeder {
                public function run(SeedContext $context): void
                {
                    $context->connection->table('probe')->insert(['label' => 'root']);
                    $context->call('ZebraSeeder');
                }
            };
            PHP);
        $this->writeFixture($directory, 'ZebraSeeder.php', $this->insertSeeder('child'));

        // The name loop later reaches ZebraSeeder, which has already run.
        $report = (new SeederRunner($this->connection, new SeederLoader($directory)))->runAll();

        self::assertSame(['AllSeeder', 'ZebraSeeder'], $report->applied);
        self::assertSame(['root', 'child'], $this->seededLabels(), 'the child must run exactly once, via the composite call');
    }

    public function test_running_an_unknown_seeder_fails_loudly(): void
    {
        $directory = $this->makeTempDirectory();

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('No seeder named [GhostSeeder]');

        (new SeederRunner($this->connection, new SeederLoader($directory)))->runOne('GhostSeeder');
    }

    public function test_a_file_that_is_not_a_seeder_fails_loudly(): void
    {
        $directory = $this->makeTempDirectory();
        $this->writeFixture($directory, 'BrokenSeeder.php', "<?php\n\nreturn 42;\n");

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('must return a');

        (new SeederRunner($this->connection, new SeederLoader($directory)))->runOne('BrokenSeeder');
    }

    public function test_a_failing_seeder_rolls_back_and_is_reported(): void
    {
        $directory = $this->makeTempDirectory();
        $this->writeFixture($directory, 'FailingSeeder.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Sahil\Migrations\SeedContext;
            use Sahil\Migrations\Seeder;

            return new class extends Seeder {
                public function run(SeedContext $context): void
                {
                    $context->connection->table('probe')->insert(['label' => 'half-done']);

                    throw new RuntimeException('seeder exploded');
                }
            };
            PHP);

        try {
            (new SeederRunner($this->connection, new SeederLoader($directory)))->runOne('FailingSeeder');
            self::fail('a failing seeder must propagate');
        } catch (MigrationException $failure) {
            self::assertStringContainsString('FailingSeeder', $failure->getMessage());
            self::assertStringContainsString('seeder exploded', $failure->getMessage());
            self::assertStringContainsString('rolled back', $failure->getMessage());
            self::assertInstanceOf(\RuntimeException::class, $failure->getPrevious());
        }

        self::assertContains(
            'ROLLBACK',
            array_column($this->recorder->executions, 'sql'),
            'a failed seeder must not leave half-inserted rows'
        );
    }
}
