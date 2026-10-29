<?php

declare(strict_types=1);

namespace Sahil\Tests\Http;

use PHPUnit\Framework\TestCase;
use Sahil\Container\Container;
use Sahil\Foundation\Application;
use Sahil\Foundation\ServiceProvider;
use Sahil\Http\Kernel;
use Sahil\Http\Middleware\MiddlewareContract;
use Sahil\Http\Request;
use Sahil\Http\Response;
use Sahil\Http\Route;
use Sahil\Http\Router;

/**
 * Phase-4 completion tests: route groups & prefixes, group middleware
 * inheritance, short-circuiting middleware, exception-handler propagation,
 * DI-resolved middleware, and controller construction through the
 * container with documented parameter supply.
 */
final class GroupsAndPipelineTest extends TestCase
{
    protected function tearDown(): void
    {
        Application::free();
    }

    private static function kernelWith(Router $router, ?callable $exceptionHandler = null): Kernel
    {
        Application::free();
        $app = Application::configure(__DIR__.'/../Fixtures/app-root');
        $app->register(new class ($app) extends ServiceProvider {});
        $app->boot();

        $kernel = new Kernel($router, $app->container(), $app);

        if ($exceptionHandler !== null) {
            $kernel = $kernel->usingExceptionHandler($exceptionHandler);
        }

        return $kernel;
    }

    private static function request(string $path, string $method = 'GET'): Request
    {
        return Request::fromGlobals(['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path], []);
    }

    public function test_group_prepends_prefix_and_routes(): void
    {
        $router = new Router();

        $router->group('/admin', static function (Router $group): void {
            $group->register(
                Route::get('/dash', static fn (): Response => Response::text('dash')),
                Route::post('/users', static fn (): Response => Response::text('created')),
            );
        });
        $router->register(Route::get('/top', static fn (): Response => Response::text('top')));

        $kernel = self::kernelWith($router);

        self::assertSame('dash', $kernel->handle(self::request('/admin/dash'))->body());
        self::assertSame('created', $kernel->handle(self::request('/admin/users', 'POST'))->body());
        self::assertSame('top', $kernel->handle(self::request('/top'))->body());

        // Untouched route outside the group keeps its own path.
        $kernel = self::kernelWith($router);
        self::assertSame(404, $kernel->handle(self::request('/dash'))->status());
    }

    public function test_group_middleware_is_inherited_and_runs_before_route_middleware(): void
    {
        $trace = new PipelineTrace();

        $groupMw = new GroupHeaderTagging($trace, 'group');
        $routeMw = new GroupHeaderTagging($trace, 'route');

        $router = new Router();
        $router->group('/admin', static function (Router $group) use ($routeMw): void {
            $group->register(
                Route::get('/dash', static fn (): Response => Response::text('dash'))
                    ->middleware($routeMw),
                Route::get('/plain', static fn (): Response => Response::text('plain')),
            );
        }, middleware: $groupMw);

        $kernel = self::kernelWith($router);

        // Route inside a group: group → route middleware.
        $dash = $kernel->handle(self::request('/admin/dash'));
        self::assertSame('dash', $dash->body());
        self::assertSame(['group.handle', 'route.handle', 'route.finalize', 'group.finalize'], $trace->events);
        self::assertSame('group,route', $dash->header('X-Handled-By'));

        // Second route in the same group inherits the group middleware alone.
        $trace->events = [];
        $plain = $kernel->handle(self::request('/admin/plain'));
        self::assertSame(['group.handle', 'group.finalize'], $trace->events);
        self::assertSame('group', $plain->header('X-Handled-By'));
    }

    public function test_group_rejects_bad_prefix_and_root_rebase(): void
    {
        $router = new Router();

        try {
            $router->group('admin', static function (Router $g): void {
                $g->register(Route::get('/x', static fn (): Response => Response::text('x')));
            });
            self::fail('prefix without leading slash must be rejected');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('admin', $error->getMessage());
        }

        try {
            $router->group('/admin', static function (Router $g): void {
                $g->register(Route::get('/', static fn (): Response => Response::text('x')));
            });
            self::fail('bare root inside a group must be rejected');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('root', $error->getMessage());
        }
    }

    public function test_middleware_can_short_circuit_without_reaching_handler(): void
    {
        $trace = new PipelineTrace();

        $gate = new GroupHeaderTagging($trace, 'gate', shortCircuit: true);

        $router = new Router();
        $router->register(Route::get('/private', static fn (): Response => Response::text('secret'))->middleware($gate));

        $kernel = self::kernelWith($router);
        $response = $kernel->handle(self::request('/private'));

        self::assertSame('blocked', $response->body());
        self::assertSame(['gate.handle'], $trace->events, 'handler and finalize block must not run');
        self::assertSame('gate', $response->header('X-Handled-By'));
    }

    public function test_middleware_exceptions_reach_the_application_handler_after_unwind(): void
    {
        $trace = new PipelineTrace();

        $outer = new GroupHeaderTagging($trace, 'outer');
        $boomer = new GroupHeaderTagging($trace, 'boomer', throwAt: true);

        $router = new Router();
        $router->register(Route::get('/x', static fn (): Response => Response::text('x'))->middleware($boomer));

        $seen = null;
        $kernel = self::kernelWith($router, static function (\Throwable $t) use (&$seen): Response {
            $seen = $t;

            return Response::json(['handled' => true], 418);
        });

        $kernel = $kernel->using($outer);
        $response = $kernel->handle(self::request('/x'));

        self::assertNotNull($seen, 'the exception must reach the application exception handler');
        self::assertStringContainsString('boom', $seen->getMessage());
        self::assertSame(418, $response->status(), 'handler response wins over the fallback');
        self::assertSame(
            ['outer.handle', 'boomer.handle', 'boomer.finalize', 'outer.finalize'],
            array_slice($trace->events, 0, 4),
            'reverse-order unwind runs finalize blocks even on failure',
        );
    }

    public function test_registered_class_string_middleware_is_resolved_through_di(): void
    {
        $app = Application::configure(__DIR__.'/../Fixtures/app-root');
        $app->register(new class ($app) extends ServiceProvider {});
        $app->boot();

        $container = new Container();
        $container->singleton(GroupDiState::class, static fn (): GroupDiState => new GroupDiState());
        $container->bind(GroupDiMiddleware::class);
        $container->bind('di-tagged', GroupDiMiddleware::class);

        $router = new Router();
        $router->register(Route::get('/tagged', static fn (): Response => Response::text('ok'))->middleware('di-tagged'));

        $container->instance(Router::class, $router);

        $kernel = new Kernel($router, $container, $app);

        $response = $kernel->handle(self::request('/tagged'));

        self::assertSame('ok', $response->body());
        self::assertSame('di', $response->header('X-Di-Mw'), 'DI-constructed middleware ran once per request slot');
    }

    public function test_controller_is_constructed_through_di_with_dependency_injection(): void
    {
        $app = Application::configure(__DIR__.'/../Fixtures/app-root');
        $app->register(new class ($app) extends ServiceProvider {});
        $app->boot();

        $container = $app->container();
        $container->singleton(GroupDiState::class);

        $router = new Router();
        $router->register(Route::get('/orders/{orderId}', [GroupDiController::class, 'show']));

        $kernel = new Kernel($router, $container, $app);

        $response = $kernel->handle(self::request('/orders/77'));

        self::assertSame(
            'order:77:state',
            $response->body(),
            'controller constructor came from the container, route params reached the action',
        );
    }

    public function test_repeated_requests_have_isolated_middleware_invocations(): void
    {
        $trace = new PipelineTrace();

        $router = new Router();
        $router->register(Route::get('/loop', static fn (): Response => Response::text('ok'))->middleware(new GroupHeaderTagging($trace, 'mw')));

        $kernel = self::kernelWith($router);

        for ($i = 0; $i < 3; $i++) {
            $response = $kernel->handle(self::request('/loop'));
            self::assertSame('ok', $response->body());
            self::assertSame(['mw.handle', 'mw.finalize'], $trace->slice($i));
        }
    }

    public function test_invalid_handlers_fail_loudly_at_registration(): void
    {
        foreach ([
            [[MissingClass::class, 'run'], 'missing class'],
            [[GroupDiController::class, 'nope'], 'missing method'],
            [[true, 'x'], 'garbage pair'],
        ] as [$handler, $label]) {
            try {
                /** @phpstan-var Closure|array{0: class-string, 1: non-empty-string} $handler */
                Route::get('/bad', $handler);

                self::fail(sprintf('Expected handler [%s] to be rejected.', $label));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('route', $e->getMessage(), 'rejection must be a clear route error');
            }
        }
    }
}

final class PipelineTrace
{
    /** @var list<string> */
    public array $events = [];

    public function record(string $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @return list<string>
     */
    public function slice(int $n = 0): array
    {
        $pair = array_slice($this->events, $n * 2, 2);

        return array_values($pair);
    }
}

final class GroupHeaderTagging implements MiddlewareContract
{
    public function __construct(
        private readonly PipelineTrace $trace,
        private readonly string $label,
        private readonly bool $shortCircuit = false,
        private readonly bool $throwAt = false,
    ) {
    }

    public function handle(Request $request, \Closure $next): Response
    {
        $this->trace->record($this->label.'.handle');

        if ($this->shortCircuit) {
            return Response::text('blocked')->withHeader('X-Handled-By', $this->label);
        }

        if ($this->throwAt) {
            try {
                throw new \RuntimeException('boom from middleware');
            } finally {
                $this->trace->record($this->label.'.finalize');
            }
        }

        try {
            /** @var Response $response */
            $response = $next($request);
        } catch (\Throwable $unwound) {
            $this->trace->record($this->label.'.finalize');
            throw $unwound;
        }

        $this->trace->record($this->label.'.finalize');

        $tags = array_filter([$this->label, (string) $response->header('X-Handled-By')]);

        return $response->withHeader('X-Handled-By', implode(',', $tags));
    }
}final class GroupDiState
{
    /** @var bool $live */
    public bool $live = true;
}

final class GroupDiMiddleware implements MiddlewareContract
{
    public function __construct()
    {
    }

    public function handle(Request $request, \Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        return $response->withHeader('X-Di-Mw', 'di');
    }
}

final class GroupDiController
{
    public function __construct(private readonly GroupDiState $state)
    {
    }

    public function show(Request $request, array $params): Response
    {
        return Response::text('order:'.($params['orderId'] ?? '').':'.($this->state->live ? 'state' : ''));
    }
}
