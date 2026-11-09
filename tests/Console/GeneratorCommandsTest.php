<?php

declare(strict_types=1);

namespace Sahil\Tests\Console;

use PHPUnit\Framework\TestCase;
use Sahil\Console\Application;
use Sahil\Console\Command;
use Sahil\Console\CommandOutput;
use Sahil\Database\ConnectionFactory;
use Sahil\Database\Schema\Schema;
use Sahil\Foundation\ServiceProvider;
use Sahil\Http\Middleware\MiddlewareContract;
use Sahil\Http\Request;
use Sahil\Http\Response;
use Sahil\Migrations\MigrationLoader;
use Sahil\Migrations\MigrationLock;
use Sahil\Migrations\MigrationRepository;
use Sahil\Migrations\Migrator;
use Sahil\Migrations\MutationGuard;
use Sahil\Migrations\RefusingConfirmation;
use Sahil\Migrations\Seeder;

/**
 * End-to-end coverage for the `unda:*` generators and the framework console.
 *
 * The tests execute the real Application against a temporary Composer
 * application, then autoload the generated classes through their PSR-4
 * mapping and exercise the generated migration with the real Migrator.
 */
final class GeneratorCommandsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/sahil-generator-'.bin2hex(random_bytes(6));

        self::assertTrue(mkdir($this->root, 0o700, true));
        self::assertTrue(mkdir($this->root.'/src', 0o700, true));

        file_put_contents(
            $this->root.'/composer.json',
            json_encode([
                'name' => 'acme/application',
                'autoload' => [
                    'psr-4' => [
                        'Acme\\' => 'src/',
                    ],
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );

        spl_autoload_register(function (string $class): void {
            if (!str_starts_with($class, 'Acme\\')) {
                return;
            }

            $file = $this->root.'/src/'.str_replace('\\', '/', substr($class, 5)).'.php';

            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    public function test_root_help_lists_unda_commands_and_never_exposes_an_internal_path(): void
    {
        [$exit, $out, $err] = $this->runConsole([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('SahilPHP Framework', $out);
        self::assertStringContainsString('Code Generation', $out);
        self::assertStringContainsString('unda:controller', $out);
        self::assertStringContainsString('unda:model', $out);
        self::assertStringContainsString('unda:migration', $out);
        self::assertStringContainsString('unda:middleware', $out);
        self::assertStringContainsString('unda:policy', $out);
        self::assertStringContainsString('unda:provider', $out);
        self::assertStringContainsString('unda:command', $out);
        self::assertStringContainsString('unda:seeder', $out);
        self::assertStringNotContainsString('bin/sahil', $out);
        self::assertStringNotContainsString('make:controller', $out);
        self::assertStringNotContainsString('make:migration', $out);
        self::assertSame('', $err);
    }

    public function test_command_help_uses_public_invocation_and_documents_options(): void
    {
        [$exit, $out, $err] = $this->runConsole(['unda:controller', '--help']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('php sahil unda:controller', $out);
        self::assertStringContainsString('--force', $out);
        self::assertStringContainsString('--path=DIR', $out);
        self::assertStringContainsString('Admin/UserController', $out);
        self::assertStringNotContainsString('bin/sahil', $out);
        self::assertSame('', $err);

        [$exit, $out] = $this->runConsole(['help', 'unda:model']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('--migration', $out);
    }

    public function test_controller_is_namespaced_path_resolved_and_routable(): void
    {
        [$exit, $out, $err] = $this->runConsole(['unda:controller', 'UserController']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Created src/Http/Controllers/UserController.php', $out);
        self::assertStringContainsString('namespace Acme\\Http\\Controllers', $out);
        self::assertSame('', $err);

        $file = $this->root.'/src/Http/Controllers/UserController.php';

        self::assertFileExists($file);
        $this->assertLint($file);
        self::assertStringContainsString('namespace Acme\\Http\\Controllers;', (string) file_get_contents($file));

        $controller = new \Acme\Http\Controllers\UserController();
        $request = Request::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/users',
        ], [], [], [], '');

        self::assertInstanceOf(Response::class, $controller->index($request));
    }

    public function test_nested_names_use_psr4_directories_and_namespaces(): void
    {
        [$exit, $out] = $this->runConsole(['unda:controller', 'Admin/UserController']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('src/Http/Controllers/Admin/UserController.php', $out);
        self::assertStringContainsString('namespace Acme\\Http\\Controllers\\Admin', $out);
        self::assertFileExists($this->root.'/src/Http/Controllers/Admin/UserController.php');
        self::assertTrue(class_exists(\Acme\Http\Controllers\Admin\UserController::class));
    }

    public function test_model_and_explicit_migration_option_use_the_orm_contract(): void
    {
        [$exit, $out] = $this->runConsole(['unda:model', 'Billing/Invoice', '--migration']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('src/Models/Billing/Invoice.php', $out);
        self::assertStringContainsString('database/migrations/', $out);
        self::assertStringContainsString('create_invoices_table.php', $out);
        self::assertStringContainsString('namespace Acme\\Models\\Billing', $out);

        $model = $this->root.'/src/Models/Billing/Invoice.php';

        self::assertFileExists($model);
        $this->assertLint($model);
        self::assertStringContainsString('extends Model', (string) file_get_contents($model));
        self::assertStringContainsString("protected ?string \$table = 'invoices';", (string) file_get_contents($model));
        self::assertInstanceOf(\Sahil\Orm\Model::class, new \Acme\Models\Billing\Invoice());
    }

    public function test_middleware_policy_provider_command_and_seeder_implement_real_contracts(): void
    {
        [$exit, $middlewareOut] = $this->runConsole(['unda:middleware', 'Authenticate']);
        self::assertSame(0, $exit);
        self::assertStringContainsString('src/Http/Middleware/Authenticate.php', $middlewareOut);
        $middleware = new \Acme\Http\Middleware\Authenticate();
        self::assertInstanceOf(MiddlewareContract::class, $middleware);

        $request = Request::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
        ], [], [], [], '');
        $response = $middleware->handle($request, static fn (Request $next): Response => Response::text('next'));
        self::assertSame('next', $response->body());

        [$exit, $policyOut] = $this->runConsole(['unda:policy', 'UserPolicy']);
        self::assertSame(0, $exit);
        self::assertStringContainsString('src/Policies/UserPolicy.php', $policyOut);
        self::assertInstanceOf(\Sahil\Auth\Policy::class, new \Acme\Policies\UserPolicy());

        [$exit, $providerOut] = $this->runConsole(['unda:provider', 'PaymentServiceProvider']);
        self::assertSame(0, $exit);
        self::assertStringContainsString('src/Providers/PaymentServiceProvider.php', $providerOut);
        self::assertTrue(is_subclass_of(\Acme\Providers\PaymentServiceProvider::class, ServiceProvider::class));

        [$exit, $commandOut] = $this->runConsole(['unda:command', 'SendInvoice']);
        self::assertSame(0, $exit);
        self::assertStringContainsString('src/Console/Commands/SendInvoice.php', $commandOut);
        self::assertTrue(is_subclass_of(\Acme\Console\Commands\SendInvoice::class, Command::class));

        [$exit, $seederOut] = $this->runConsole(['unda:seeder', 'UserSeeder']);
        self::assertSame(0, $exit);
        self::assertStringContainsString('database/seeders/UserSeeder.php', $seederOut);
        $seeder = require $this->root.'/database/seeders/UserSeeder.php';
        self::assertInstanceOf(Seeder::class, $seeder);
    }

    public function test_generated_migrations_run_through_the_real_migrator(): void
    {
        [$exit] = $this->runConsole(['unda:migration', 'create_users_table']);
        self::assertSame(0, $exit);

        $connection = (new ConnectionFactory())->make([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $repository = new MigrationRepository($connection);
        $migrator = new Migrator(
            $connection,
            $repository,
            new MigrationLoader($this->root.'/database/migrations'),
            new MutationGuard('testing', new RefusingConfirmation()),
            new MigrationLock($connection, 'generator_test', 1),
        );

        $created = $migrator->migrate();
        self::assertCount(1, $created->applied);
        self::assertTrue((new Schema($connection))->hasTable('users'));
        self::assertSame([], $migrator->status()['pending']);

        usleep(1_100_000);

        [$exit, $out] = $this->runConsole(['unda:migration', 'add_email_to_users_table']);
        self::assertSame(0, $exit);

        $added = $migrator->migrate();
        self::assertCount(1, $added->applied);

        $columns = array_column(
            $connection->execute('PRAGMA table_info(users)')->rows(),
            'name',
        );
        self::assertContains('email', $columns);

        $connection->execute('INSERT INTO users (email) VALUES (?)', ['ada@example.test']);
        $row = $connection->execute('SELECT COUNT(*) AS total FROM users WHERE email = ?', ['ada@example.test'])->first();
        self::assertSame(1, (int) ($row['total'] ?? 0));

        $rolledBack = $migrator->rollback(force: true, steps: 1);
        self::assertCount(1, $rolledBack->rolledBack);

        $columns = array_column($connection->execute('PRAGMA table_info(users)')->rows(), 'name');
        self::assertNotContains('email', $columns);

        $dropped = $migrator->rollback(force: true, steps: 1);
        self::assertCount(1, $dropped->rolledBack);
        self::assertFalse((new Schema($connection))->hasTable('users'));
    }

    public function test_invalid_names_and_traversal_are_rejected_without_writing(): void
    {
        [$exit, , $err] = $this->runConsole(['unda:controller', '../EvilController']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('path traversal', $err);

        [$exit, , $err] = $this->runConsole(['unda:controller', '1Invalid']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('not a valid PHP identifier', $err);

        [$exit, , $err] = $this->runConsole(['unda:controller', 'class']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('reserved PHP word', $err);

        [$exit, , $err] = $this->runConsole(['unda:model']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('a name is required', $err);

        self::assertDirectoryDoesNotExist($this->root.'/src/Http/Controllers/EvilController.php');
    }

    public function test_model_with_migration_conflict_writes_neither_file(): void
    {
        self::assertTrue(mkdir($this->root.'/database/migrations', 0o700, true));
        file_put_contents(
            $this->root.'/database/migrations/2026_01_01_000000_create_accounts_table.php',
            "<?php\n",
        );

        [$exit, , $err] = $this->runConsole(['unda:model', 'Account', '--migration']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('create_accounts_table', $err);
        self::assertStringContainsString('already exists', $err);
        self::assertFileDoesNotExist($this->root.'/src/Models/Account.php');
    }

    public function test_existing_files_are_protected_and_force_creates_a_backup(): void
    {
        [$exit] = $this->runConsole(['unda:controller', 'ConflictController']);
        self::assertSame(0, $exit);

        $file = $this->root.'/src/Http/Controllers/ConflictController.php';
        $original = (string) file_get_contents($file);

        [$exit, , $err] = $this->runConsole(['unda:controller', 'ConflictController']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('already exists', $err);
        self::assertSame($original, (string) file_get_contents($file));

        [$exit, $out] = $this->runConsole(['unda:controller', 'ConflictController', '--force']);
        self::assertSame(0, $exit);
        self::assertStringContainsString('Updated src/Http/Controllers/ConflictController.php', $out);
        self::assertStringContainsString('backup', $out);
        self::assertSame($original, (string) file_get_contents($file));
    }

    public function test_unknown_commands_and_options_fail_cleanly(): void
    {
        [$exit, , $err] = $this->runConsole(['nope']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('Unknown command: nope', $err);
        self::assertStringNotContainsString('bin/sahil', $err);

        [$exit, , $err] = $this->runConsole(['unda:controller', 'Thing', '--fourch']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('unknown option [--fourch]', $err);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function runConsole(array $arguments): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');

        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $exit = (new Application($this->root, new CommandOutput($stdout, $stderr)))->run($arguments);

        rewind($stdout);
        rewind($stderr);

        return [
            $exit,
            (string) stream_get_contents($stdout),
            (string) stream_get_contents($stderr),
        ];
    }

    private function assertLint(string $file): void
    {
        $output = [];
        $status = 0;

        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1', $output, $status);

        self::assertSame(0, $status, implode("\n", $output));
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());

                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($path);
    }
}
