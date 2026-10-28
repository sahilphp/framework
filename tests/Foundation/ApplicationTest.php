<?php

declare(strict_types=1);

namespace Sahil\Tests\Foundation;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sahil\Config\Repository;
use Sahil\Container\Container;
use Sahil\Foundation\Application;
use Sahil\Foundation\ServiceProvider;
use Sahil\Support\Environment;

final class ApplicationTest extends TestCase
{
    protected function tearDown(): void
    {
        Application::free();
    }

    public function test_configure_creates_application_with_core_bindings(): void
    {
        $app = Application::configure(__DIR__.'/../Fixtures/app-root');

        self::assertSame($app, $app->container()->get(Application::class));
        self::assertSame($app, $app->container()->get('app'));
        self::assertInstanceOf(Container::class, $app->container());
        self::assertInstanceOf(Repository::class, $app->config());
        self::assertInstanceOf(Environment::class, $app->environment());
    }

    public function test_double_configure_is_rejected_until_freed(): void
    {
        Application::configure(__DIR__.'/../Fixtures/app-root');

        try {
            Application::configure(__DIR__.'/../Fixtures/app-root');
            self::fail('Expected double configuration to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('already configured', $exception->getMessage());
            Application::free();
        }

        $fresh = Application::configure(__DIR__.'/../Fixtures/app-root');
        self::assertInstanceOf(Application::class, $fresh);
    }

    public function test_nonexistent_base_path_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        Application::configure(__DIR__.'/../Fixtures/does-not-exist');
    }

    public function test_providers_register_then_boot_in_order(): void
    {
        $app = Application::configure(__DIR__.'/../Fixtures/app-root');

        $trace = new Trace();
        $app->register(new ProviderA($trace));
        $app->register(new ProviderB($trace));

        self::assertSame(['A.register', 'B.register'], $trace->events, 'boot must not have run yet');
        self::assertFalse($app->isBooted());

        $app->boot();

        self::assertSame(['A.register', 'B.register', 'A.boot', 'B.boot'], $trace->events);
        self::assertTrue($app->isBooted());
    }

    public function test_boot_is_idempotent(): void
    {
        $app = Application::configure(__DIR__.'/../Fixtures/app-root');

        $trace = new Trace();
        $app->register(new ProviderA($trace));
        $app->boot();
        $app->boot();

        self::assertCount(1, array_filter($trace->events, static fn (string $e): bool => $e === 'A.boot'));
    }

    public function test_loadEnvironment_reads_dotenv_file(): void
    {
        $app = Application::configure(__DIR__.'/../Fixtures/app-root');
        $app->loadEnvironment('app.env');

        self::assertSame('SahilPHP Fixture', $app->environment()->get('APP_NAME'));
    }

    public function test_loadEnvironment_ignores_absent_file_silently(): void
    {
        $app = Application::configure(__DIR__.'/../Fixtures/app-root');

        $app->loadEnvironment('there-is-no-such.env');

        self::assertNull($app->environment()->get('NOTHING_HERE'));
    }

    public function test_loadConfiguration_reads_and_namespaces_files(): void
    {
        $app = Application::configure(__DIR__.'/../Fixtures/app-root');
        $app->loadConfiguration();

        self::assertSame('SahilPHP', $app->config()->get('app.name'));
        self::assertSame('file', $app->config()->get('cache.default'));
    }

    public function test_loadConfiguration_rejects_non_array_files(): void
    {
        $app = Application::configure(__DIR__.'/../Fixtures/app-root');

        try {
            $app->loadConfiguration('broken');
            self::fail('Expected non-array config file to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('must return an array', $exception->getMessage());
        }
    }

    public function test_reset_flushes_container_and_reboots_providers(): void
    {
        $app = Application::configure(__DIR__.'/../Fixtures/app-root');

        $trace = new Trace();
        $app->register(new ProviderA($trace));
        $app->boot();

        $app->container()->bind('ephemeral.value', static fn (): \stdClass => new \stdClass());
        $app->container()->get('ephemeral.value');

        $app->reset();

        self::assertSame(['A.register', 'A.boot', 'A.register', 'A.boot'], $trace->events);
        self::assertFalse($app->container()->bound('ephemeral.value'));
        self::assertSame($app, $app->container()->get('app'));
    }

    public function test_path_helpers(): void
    {
        $app = Application::configure(__DIR__.'/../Fixtures/app-root');
        $base = realpath(__DIR__.'/../Fixtures/app-root');

        self::assertSame($base, $app->basePath());
        self::assertSame($base.'/config', $app->configPath());
        self::assertSame($base.'/storage/logs', $app->storagePath('logs'));
        self::assertSame($base.'/.env', $app->environmentPath());
    }
}

final class Trace
{
    /** @var list<string> */
    public array $events = [];

    public function record(string $event): void
    {
        $this->events[] = $event;
    }
}

final class ProviderA extends ServiceProvider
{
    public function __construct(private readonly Trace $trace)
    {
    }

    public function register(): void
    {
        $this->trace->record('A.register');
    }

    public function boot(): void
    {
        $this->trace->record('A.boot');
    }
}

final class ProviderB extends ServiceProvider
{
    public function __construct(private readonly Trace $trace)
    {
    }

    public function register(): void
    {
        $this->trace->record('B.register');
    }

    public function boot(): void
    {
        $this->trace->record('B.boot');
    }
}
