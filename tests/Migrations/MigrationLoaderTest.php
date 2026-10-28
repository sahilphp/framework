<?php

declare(strict_types=1);

namespace Sahil\Tests\Migrations;

use PHPUnit\Framework\TestCase;
use Sahil\Migrations\MigrationException;
use Sahil\Migrations\MigrationLoader;

/**
 * Discovery rules: what is a migration, in what order they run, and how a
 * malformed file fails.
 */
final class MigrationLoaderTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->removeTempDirectories();
    }

    private function migrationFixture(string $table): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            use Sahil\\Database\\Schema\\Schema;
            use Sahil\\Database\\Schema\\Table;
            use Sahil\\Migrations\\Migration;

            return new class extends Migration {
                public function up(Schema \$schema): void
                {
                    \$schema->create('{$table}', function (Table \$table): void {
                        \$table->id();
                    });
                }

                public function down(Schema \$schema): void
                {
                    \$schema->dropIfExists('{$table}');
                }
            };
            PHP;
    }

    public function test_missing_directory_is_not_an_error(): void
    {
        $loader = new MigrationLoader(sys_get_temp_dir().'/sahil-does-not-exist-'.bin2hex(random_bytes(4)));

        self::assertSame([], $loader->load());
    }

    public function test_migrations_are_discovered_in_name_order(): void
    {
        $directory = $this->makeTempDirectory();

        $this->writeFixture($directory, '2026_01_03_000000_third.php', $this->migrationFixture('third'));
        $this->writeFixture($directory, '2026_01_01_000000_first.php', $this->migrationFixture('first'));
        $this->writeFixture($directory, '2026_01_02_000000_second.php', $this->migrationFixture('second'));

        $loaded = (new MigrationLoader($directory))->load();

        self::assertSame(
            ['2026_01_01_000000_first', '2026_01_02_000000_second', '2026_01_03_000000_third'],
            array_map(static fn ($migration): string => $migration->name, $loaded),
            'file names are the execution order'
        );
    }

    public function test_non_php_and_hidden_files_are_ignored(): void
    {
        $directory = $this->makeTempDirectory();

        $this->writeFixture($directory, '2026_01_01_000000_real.php', $this->migrationFixture('real'));
        $this->writeFixture($directory, 'README.md', '# notes');
        $this->writeFixture($directory, '.gitkeep', '');
        $this->writeFixture($directory, '2026_01_01_000000_real.php.bak', 'backup');

        $loaded = (new MigrationLoader($directory))->load();

        self::assertCount(1, $loaded);
        self::assertSame('2026_01_01_000000_real', $loaded[0]->name);
    }

    public function test_a_file_that_is_not_a_migration_fails_loudly_and_names_the_file(): void
    {
        $directory = $this->makeTempDirectory();
        $this->writeFixture($directory, '2026_01_01_000000_broken.php', "<?php\n\nreturn 'not a migration';\n");

        try {
            (new MigrationLoader($directory))->load();
            self::fail('A non-migration file must not be silently skipped.');
        } catch (MigrationException $failure) {
            self::assertStringContainsString('2026_01_01_000000_broken.php', $failure->getMessage());
            self::assertStringContainsString('must return a', $failure->getMessage());
        }
    }

    public function test_a_file_that_throws_while_loading_reports_the_file(): void
    {
        $directory = $this->makeTempDirectory();
        $this->writeFixture($directory, '2026_01_01_000000_explodes.php', "<?php\n\nthrow new RuntimeException('boom while loading');\n");

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('boom while loading');

        (new MigrationLoader($directory))->load();
    }

    public function test_loading_a_named_migration_that_does_not_exist_fails(): void
    {
        $directory = $this->makeTempDirectory();

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('2026_01_01_000000_absent');

        (new MigrationLoader($directory))->loadNamed('2026_01_01_000000_absent');
    }

    public function test_reversibility_is_derived_from_implementing_down(): void
    {
        $directory = $this->makeTempDirectory();

        $this->writeFixture($directory, '2026_01_01_000000_reversible.php', $this->migrationFixture('reversible'));
        $this->writeFixture($directory, '2026_01_02_000000_forward_only.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Sahil\Database\Schema\Schema;
            use Sahil\Migrations\Migration;

            return new class extends Migration {
                public function up(Schema $schema): void
                {
                }
            };
            PHP);

        $loaded = (new MigrationLoader($directory))->load();

        self::assertTrue($loaded[0]->migration->isReversible(), 'a migration with down() is reversible');
        self::assertFalse($loaded[1]->migration->isReversible(), 'a migration without down() is forward-only');
    }
}
