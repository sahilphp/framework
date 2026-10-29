<?php

declare(strict_types=1);

namespace Sahil\Tests\Http;

use PHPUnit\Framework\TestCase;
use Sahil\Foundation\Application;
use Sahil\Foundation\ServiceProvider;
use Sahil\Http\Kernel;
use Sahil\Http\Middleware\MiddlewareContract;
use Sahil\Http\Request;
use Sahil\Http\Response;
use Sahil\Http\Route;
use Sahil\Http\Router;

final class KernelTest extends TestCase
{
    protected function tearDown(): void
    {
        Application::free();
    }

    private static function applicationBinding(): Application
    {
        return Application::configure(__DIR__.'/../Fixtures/app-root');
    }

    private static function kernelWith(array $routes, ?bool $debug = false, array $middleware = []): Kernel
    {
        $app = self::applicationBinding();
        $app->register(new class ($app) extends ServiceProvider {});
        $app->boot();

        $router = new Router();
        $router->register(...$routes);

        $kernel = new Kernel($router, $app->container(), $app)->useDebugMode((bool) $debug);

        return $kernel->using(...array_filter($middleware));
    }

    private static function request(string $path, string $method = 'GET'): Request
    {
        return Request::fromGlobals(['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path], []);
    }

    public function test_kernel_dispatches_controller_and_returns_response(): void
    {
        $kernel = self::kernelWith([
            Route::get('/ping', static fn (): Response => Response::text('pong')),
        ]);

        $response = $kernel->handle(self::request('/ping'));

        self::assertSame(200, $response->status());
        self::assertSame('pong', $response->body());
    }

    public function test_controller_class_resolved_through_container_with_route_params(): void
    {
        $kernel = self::kernelWith([
            Route::get('/greet/{name}', [EchoController::class, 'greet']),
        ]);

        $response = $kernel->handle(self::request('/greet/sahil'));

        self::assertSame(200, $response->status());
        self::assertSame('sahil', $response->body());
    }

    public function test_global_middleware_runs_outermost_and_in_order(): void
    {
        $trace = new Trace();

        $kernel = self::kernelWith(
            [Route::get('/inner', static fn (Request $request): Response => Response::text('done'))],
            middleware: [new HeaderTagging($trace, 'outer'), new HeaderTagging($trace, 'inner')],
        );

        $response = $kernel->handle(self::request('/inner'));

        self::assertSame('done', $response->body());
        self::assertSame(['outer.handle', 'inner.handle', 'inner.finalize', 'outer.finalize'], $trace->events);
        self::assertSame('outer,inner', $response->header('X-Handled-By'));
    }

    public function test_route_level_middleware_runs_after_global(): void
    {
        $trace = new Trace();

        $kernel = self::kernelWith(
            [Route::get('/inner', static fn (): Response => Response::text('done'))->middleware(new HeaderTagging($trace, 'route'))],
            middleware: [new HeaderTagging($trace, 'global')],
        );

        $response = $kernel->handle(self::request('/inner'));

        self::assertSame('global,route', $response->header('X-Handled-By'), 'outermost middleware tags first');
        self::assertSame(['global.handle', 'route.handle', 'route.finalize', 'global.finalize'], $trace->events);
    }

    public function test_scoped_services_resolve_inside_kernel_and_drop_after(): void
    {
        $app = self::applicationBinding();
        $app->register(new class ($app) extends ServiceProvider {});
        $app->boot();

        $container = $app->container();
        $container->scoped(RequestJob::class);

        $jobIds = [];
        $router = new Router();
        $router->register(Route::get('/jobs', static function (Request $request) use ($container, &$jobIds): Response {
            $first = spl_object_id($container->make(RequestJob::class));
            $second = spl_object_id($container->make(RequestJob::class));

            self::assertSame($first, $second, 'scoped binding is ONE instance per request scope');
            $jobIds[] = $first;

            return Response::text('ok');
        }));

        $kernel = new Kernel($router, $container, $app);

        $response = $kernel->handle(self::request('/jobs'));
        self::assertSame(200, $response->status());

        // Second sequential request in the same process: fresh scope, fresh instance.
        $kernel->handle(self::request('/jobs'));

        self::assertCount(2, $jobIds, 'both requests completed');
        self::assertNotSame($jobIds[0], $jobIds[1], 'scoped instances never survive across requests');
        self::assertFalse($container->hasOpenScopes(), 'scope must be closed after every handle()');
    }

    public function test_http_exceptions_render_with_status_and_headers(): void
    {
        $kernel = self::kernelWith([
            Route::get('/gone', static function (): never {
                throw new \Sahil\Http\Exception\NotFoundHttpException('Gone away.');
            }),
        ]);

        $response = $kernel->handle(self::request('/gone'));

        self::assertSame(404, $response->status());
        self::assertStringContainsString('http_error', $response->body());
        self::assertStringNotContainsString('Gone away.', $response->body(), 'non-debug: exception messages are suppressed');
    }

    public function test_uncaught_exceptions_render_safe_500(): void
    {
        $kernel = self::kernelWith([
            Route::get('/boom', static function (): never {
                throw new \RuntimeException('database fallen over');
            }),
        ]);

        $response = $kernel->handle(self::request('/boom'));

        self::assertSame(500, $response->status());
        self::assertSame('{"error":"server_error"}', $response->body());
    }

    public function test_debug_mode_exposes_messages_only_via_explicit_optin(): void
    {
        $kernel = self::kernelWith([
            Route::get('/boom', static function (): never {
                throw new \RuntimeException('database fallen over');
            }),
        ], debug: true);

        $response = $kernel->handle(self::request('/boom'));

        self::assertSame(500, $response->status());
        self::assertSame('{"error":"server_error","message":"database fallen over"}', $response->body());
    }

    public function test_kernel_is_reentrant_for_worker_simulation(): void
    {
        $kernel = self::kernelWith([
            Route::get('/workers', static fn (): Response => Response::json(['ok' => true])),
        ]);

        foreach (range(1, 3) as $iteration) {
            $response = $kernel->handle(self::request('/workers'));

            self::assertSame(200, $response->status(), "iterative request iter=$iteration must behave identically");
            self::assertSame('{"ok":true}', $response->body());
        }
    }
}

final class EchoController
{
    public function greet(Request $request, array $params): Response
    {
        return Response::text((string) ($params['name'] ?? ''));
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

final class HeaderTagging implements MiddlewareContract
{
    public function __construct(private readonly Trace $trace, private readonly string $label)
    {
    }

    public function handle(Request $request, \Closure $next): Response
    {
        $this->trace->record($this->label.'.handle');

        /** @var Response $response */
        $response = $next($request);

        $this->trace->record($this->label.'.finalize');

        $tags = array_filter([$this->label, (string) $response->header('X-Handled-By')]);

        return $response->withHeader('X-Handled-By', implode(',', $tags));
    }
}

final class RequestJob
{
    /** @var list<string> */
    public array $state = [];
}
