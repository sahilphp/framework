<?php

declare(strict_types=1);

namespace Sahil\Tests\Examples;

use Closure;
use RuntimeException;
use Sahil\Http\Middleware\MiddlewareContract;
use Sahil\Http\Request;
use Sahil\Http\Response;
use Sahil\Http\Route;
use Sahil\Http\Router;
use Sahil\Tests\FrameworkTestCase;

/**
 * Example HTTP FEATURE test.
 *
 * Boots the framework fixture graph from bootstrap/providers.php, registers
 * a few illustrative routes, and drives them through the HTTP kernel without
 * a server. Session files and compiled views are redirected to a temporary
 * directory so the test leaves the fixture untouched.
 */
final class HttpFeatureExampleTest extends FrameworkTestCase
{
    protected function configurationOverrides(): array
    {
        $temporary = $this->tempDirectory('sahil-http-example');

        return [
            'app.debug' => true,
            'session.path' => $temporary.'/sessions',
            'view.cache' => $temporary.'/views',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withRoutes(static function (Router $router): void {
            $router->register(Route::post('/examples/echo', static function (Request $request): Response {
                $payload = $request->hasJsonBody() ? $request->json() : $request->form();

                return Response::json(['received' => $payload], 201);
            }));

            $router->register(Route::get('/examples/stamped', static fn (): Response => Response::json(['ok' => true]))
                ->middleware(new class () implements MiddlewareContract {
                    public function handle(Request $request, Closure $next): Response
                    {
                        return $next($request)->withHeader('X-Example-Middleware', 'ran');
                    }
                }));

            $router->register(Route::get('/examples/explode', static function (): Response {
                throw new RuntimeException('Intentional example failure.');
            }));
        });
    }

    public function test_health_endpoint_returns_json(): void
    {
        $this->get('/health')
            ->assertOk()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('booted', true);
    }

    public function test_home_page_renders_and_escapes_template_data(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('SahilPHP templates')
            ->assertDontSee('<script>alert(1)</script>');
    }

    public function test_unknown_route_is_a_404(): void
    {
        $this->get('/examples/missing')->assertNotFound();
    }

    public function test_state_changing_request_without_a_valid_token_is_rejected(): void
    {
        $this->post('/examples/echo', ['name' => 'Buffy'], ['X-CSRF-TOKEN' => 'wrong-token'])
            ->assertStatus(400);
    }

    public function test_form_request_with_a_session_token_is_handled(): void
    {
        // Unsafe requests receive a valid X-CSRF-TOKEN header automatically
        // from the live session, so CSRF stays enabled in feature tests.
        $this->post('/examples/echo', ['name' => 'Buffy'])
            ->assertCreated()
            ->assertJsonPath('received.name', 'Buffy');
    }

    public function test_json_request_assertions(): void
    {
        $this->jsonRequest('POST', '/examples/echo', ['tags' => ['php', 'testing']])
            ->assertCreated()
            ->assertJsonCount(2, 'received.tags')
            ->assertJsonPath('received.tags.0', 'php');
    }

    public function test_route_middleware_runs(): void
    {
        $this->get('/examples/stamped')
            ->assertOk()
            ->assertHeader('X-Example-Middleware', 'ran');
    }

    public function test_exceptions_can_be_rethrown_for_diagnostics(): void
    {
        $this->withoutExceptionHandling();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Intentional example failure.');

        $this->get('/examples/explode');
    }
}
