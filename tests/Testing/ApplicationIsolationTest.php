<?php

declare(strict_types=1);

namespace Sahil\Tests\Testing;

use PHPUnit\Framework\Attributes\Depends;
use stdClass;
use Sahil\Foundation\Application;
use Sahil\Foundation\ServiceProvider;
use Sahil\Http\Response;
use Sahil\Http\Route;
use Sahil\Http\Router;
use Sahil\Tests\FrameworkTestCase;

/**
 * Isolation is a correctness property of the test harness: one test's
 * container, configuration and environment modifications must not be visible
 * to the next test, and repeated requests must not accumulate state.
 */
final class ApplicationIsolationTest extends FrameworkTestCase
{
    protected function configurationOverrides(): array
    {
        $temporary = $this->tempDirectory('sahil-isolation');

        return [
            'session.path' => $temporary.'/sessions',
            'view.cache' => $temporary.'/views',
        ];
    }

    protected function environmentOverrides(): array
    {
        return ['APP_ENV' => 'testing'];
    }

    protected function providers(Application $application): array
    {
        return [
            ...parent::providers($application),
            new class ($application) extends ServiceProvider {
                public function register(): void
                {
                    $this->app()->config()->set(
                        'fixture.environment_at_register',
                        $this->app()->environment()->get('APP_ENV'),
                    );
                }
            },
        ];
    }

    public function test_environment_overrides_apply_before_configuration_loads(): void
    {
        self::assertSame('testing', $this->app()->environment()->get('APP_ENV'));
        self::assertSame('SahilPHP', $this->app()->config()->get('app.name'));
        self::assertSame('testing', $this->app()->config()->get('fixture.environment_at_register'));
    }

    /**
     * @return array{string, string}
     */
    public function test_application_state_can_be_mutated(): array
    {
        $this->app()->config()->set('app.name', 'Mutated by test');
        $this->app()->environment()->set('SAHIL_ISOLATION_MARKER', 'present');
        $this->container()->instance('example.isolation.marker', new stdClass());

        self::assertSame('Mutated by test', $this->app()->config()->get('app.name'));

        return ['app.name', 'example.isolation.marker'];
    }

    /**
     * @param array{string, string} $markers
     */
    #[Depends('test_application_state_can_be_mutated')]
    public function test_the_next_test_boots_a_fresh_application(array $markers): void
    {
        [$configKey, $binding] = $markers;

        self::assertSame('SahilPHP', $this->app()->config()->get($configKey));
        self::assertFalse($this->container()->bound($binding));
        self::assertFalse($this->app()->environment()->has('SAHIL_ISOLATION_MARKER'));
        self::assertSame('testing', $this->app()->environment()->get('APP_ENV'));
        self::assertSame('testing', $this->app()->config()->get('fixture.environment_at_register'));
    }

    public function test_repeated_requests_stay_independent(): void
    {
        $this->withRoutes(static function (Router $router): void {
            $router->register(
                Route::get('/health', static fn (): Response => Response::json(['status' => 'ok'], 200)),
            );
        });

        $this->get('/health')->assertOk()->assertJsonPath('status', 'ok');
        $this->get('/health')->assertOk()->assertJsonPath('status', 'ok');
        $this->get('/missing')->assertNotFound();
        $this->get('/health')->assertOk()->assertJsonPath('status', 'ok');
    }
}
