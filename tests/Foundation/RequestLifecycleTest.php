<?php

declare(strict_types=1);

namespace Sahil\Tests\Foundation;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sahil\Container\BindingException;
use Sahil\Contracts\Foundation\ApplicationContract;
use Sahil\Foundation\Application;

final class RequestLifecycleTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../Fixtures/app-root';

    protected function tearDown(): void
    {
        Application::free();
    }

    public function test_isolated_request_returns_work_result(): void
    {
        $app = Application::configure(self::FIXTURE);

        $result = $app->isolatedRequest(static fn (): string => 'payload');

        self::assertSame('payload', $result);
        self::assertFalse($app->container()->hasOpenScopes());
    }

    public function test_scoped_instances_never_leak_between_sequential_same_process_requests(): void
    {
        $app = Application::configure(self::FIXTURE);
        $container = $app->container();
        $container->singleton(WorkerCounter::class);
        $container->scoped(WorkerJob::class);

        // Simulate 3 sequential request iterations inside this one process.
        $keptJobs = [];

        foreach ([1, 2, 3] as $iteration) {
            $app->isolatedRequest(static function () use ($container, $iteration, &$keptJobs): void {
                /** @var WorkerCounter $counter */
                $counter = $container->make(WorkerCounter::class);
                $counter->jobs[] = $iteration;

                /** @var WorkerJob $job */
                $job = $container->make(WorkerJob::class);
                $job->run($iteration);

                // Keep the job referenced so a GC'd object id cannot fake
                // uniqueness; assert identity across iterations below.
                $keptJobs[] = $job;
            });
        }

        // Each iteration built a new request job...
        self::assertCount(3, $keptJobs);
        self::assertNotSame($keptJobs[0], $keptJobs[1]);
        self::assertNotSame($keptJobs[1], $keptJobs[2]);
        self::assertNotSame($keptJobs[0], $keptJobs[2], 'scoped service must be recreated per request');
        // ...while the process-level singleton accumulated every iteration.
        /** @var WorkerCounter $counter */
        $counter = $container->make(WorkerCounter::class);
        self::assertSame([1, 2, 3], $counter->jobs, 'singleton accumulated across iterations');
    }

    public function test_finally_scope_teardown_when_handler_throws(): void
    {
        $app = Application::configure(self::FIXTURE);
        $container = $app->container();
        $container->scoped(WorkerJob::class);

        try {
            $app->isolatedRequest(static function () use ($container): void {
                $container->make(WorkerJob::class);

                throw new RuntimeException('handler exploded');
            });
            self::fail('Expected the handler exception to propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('handler exploded', $exception->getMessage());
        }

        self::assertFalse($container->hasOpenScopes(), 'scope must have been torn down in finally');
    }

    public function test_scoped_binding_unreachable_between_requests_fails_loudly(): void
    {
        $app = Application::configure(self::FIXTURE);
        $container = $app->container();
        $container->scoped(WorkerJob::class);

        // First, a successful scope proves the lifecycle works.
        $app->isolatedRequest(static fn () => $container->make(WorkerJob::class));

        try {
            $container->make(WorkerJob::class);
            self::fail('Scoped binding resolved outside any request scope.');
        } catch (BindingException $exception) {
            self::assertStringContainsString('no scope is active', $exception->getMessage());
        }
    }

    public function test_application_reset_drops_scopes_and_transient_state(): void
    {
        $app = Application::configure(self::FIXTURE);
        $container = $app->container();
        $container->scoped(WorkerJob::class);
        $container->singleton(WorkerCounter::class);

        $app->isolatedRequest(static fn ($scope) => $scope);

        $app->reset();

        self::assertFalse($container->hasOpenScopes());
        self::assertFalse($container->bound(WorkerJob::class));
        self::assertSame($app, $container->make('app'));
    }

    public function test_application_implements_the_public_contract(): void
    {
        $app = Application::configure(self::FIXTURE);

        self::assertInstanceOf(ApplicationContract::class, $app);
    }
}

final class WorkerCounter
{
    /** @var list<int> */
    public array $jobs = [];
}

final class WorkerJob
{
    /** @var list<int> */
    public array $handledJobs = [];

    public function run(int $iteration): void
    {
        $this->handledJobs[] = $iteration;
    }
}
