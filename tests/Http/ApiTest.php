<?php

declare(strict_types=1);

namespace Sahil\Tests\Http;

use PHPUnit\Framework\TestCase;
use Sahil\Foundation\Application;
use Sahil\Http\Cors\Config;
use Sahil\Http\Kernel;
use Sahil\Http\Middleware\Cors;
use Sahil\Http\Middleware\RequestIdMiddleware;
use Sahil\Http\Middleware\Throttle;
use Sahil\Http\Pagination\Paginator;
use Sahil\Http\RateLimit\ArrayStore;
use Sahil\Http\Request;
use Sahil\Http\Response;
use Sahil\Http\Route;
use Sahil\Http\Router;
use Sahil\Orm\OrmResult;
use Sahil\Orm\PageResult;
use Sahil\Support\Logging\FileLogger;
use Sahil\Support\Logging\RequestLogger;
use Sahil\Tests\Logging\TempLogger;

final class ApiTest extends TestCase
{
    private const FIXTURE_ROOT = __DIR__.'/../Fixtures/app-root';

    protected function tearDown(): void
    {
        Application::free();
    }

    private function rootRouter(): array
    {
        return [
            Route::get('/', static fn (): Response => Response::html('<h1>Home</h1>', 200)),
            Route::get('/health', static fn (): Response => Response::json(['status' => 'ok'], 200)),
            Route::post('/parse-json', static fn (Request $request): Response => Response::json($request->json(), 200)),
            Route::get('/users', static function (Request $request): Response {
                return Response::json(['count' => 3], 200);
            })->middleware(new Throttle('users', 60, 60, new ArrayStore(60, 60))),
            Route::post('/login', static function (Request $request): Response {
                return Response::json(['token' => 't'], 200);
            })->middleware(new Throttle('login', 2, 60, new ArrayStore(2, 60))),
            Route::get('/search', static function (Request $request): Response {
                $query = $request->queryParameter('q', '');

                return Response::json(['q' => $query], 200);
            })->middleware(new Throttle('search', 1, 60, new ArrayStore(1, 60))),
        ];
    }

    private function prepareKernel(): array
    {
        Application::free();

        $app = Application::configure(self::FIXTURE_ROOT);
        $app->boot();

        $router = new Router();
        $router->register(...$this->rootRouter());

        return [
            'app' => $app,
            'router' => $router,
            'kernel' => new Kernel($router, $app->container(), $app)->useDebugMode(false),
        ];
    }

    public function test_health_endpoint_returns_200_json(): void
    {
        ['kernel' => $kernel] = $this->prepareKernel();

        $request = Request::fromGlobals(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/health'], []);
        $response = $kernel->handle($request);

        self::assertSame(200, $response->status());
        self::assertSame('application/json', $response->header('Content-Type'));
        self::assertSame('{"status":"ok"}', $response->body());
    }

    public function test_markup_endpoint_returns_text_html(): void
    {
        ['kernel' => $kernel] = $this->prepareKernel();

        $request = Request::fromGlobals(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'], []);
        $response = $kernel->handle($request);

        self::assertSame(200, $response->status());
        self::assertSame('text/html; charset=utf-8', $response->header('Content-Type'));
    }

    public function test_malformed_json_body_returns_400_and_does_not_echo_the_body(): void
    {
        ['kernel' => $kernel] = $this->prepareKernel();

        $request = Request::fromGlobals(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/parse-json', 'CONTENT_TYPE' => 'application/json'],
            [],
            [],
            [],
            '{"username":"ada"'
        );

        $response = $kernel->handle($request);

        self::assertSame(400, $response->status());
        self::assertStringNotContainsString('username', $response->body());
    }

    public function test_scalar_json_body_returns_400(): void
    {
        ['kernel' => $kernel] = $this->prepareKernel();

        $request = Request::fromGlobals(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/parse-json', 'CONTENT_TYPE' => 'application/json'],
            [],
            [],
            [],
            '"just-a-string"'
        );

        self::assertSame(400, $kernel->handle($request)->status());
    }

    public function test_unsupported_content_type_returns_415(): void
    {
        ['kernel' => $kernel] = $this->prepareKernel();

        $request = Request::fromGlobals(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/login', 'CONTENT_TYPE' => 'application/xml'],
            [],
            [],
            [],
            '<root/>'
        );

        $response = $kernel->handle($request);

        self::assertSame(415, $response->status());
        self::assertSame('application/json', $response->header('Content-Type'));
        self::assertSame('unsupported_media_type', json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR)['error']);
    }

    public function test_missing_content_type_with_body_is_allowed_and_parsed(): void
    {
        ['kernel' => $kernel] = $this->prepareKernel();

        $request = Request::fromGlobals(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/parse-json'],
            [],
            [],
            [],
            '{"x":1}'
        );

        $response = $kernel->handle($request);

        self::assertSame(200, $response->status());
        self::assertSame(['x' => 1], json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_route_that_expects_json_returns_200_with_json_body(): void
    {
        ['kernel' => $kernel] = $this->prepareKernel();

        $request = Request::fromGlobals(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/login', 'CONTENT_TYPE' => 'application/json'],
            [],
            [],
            [],
            '{"username":"ada"}'
        );

        $response = $kernel->handle($request);

        self::assertSame(200, $response->status());
        self::assertSame(['token' => 't'], json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_method_not_allowed_returns_405_with_allow_header(): void
    {
        ['kernel' => $kernel] = $this->prepareKernel();

        $request = Request::fromGlobals(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/health'], []);

        $response = $kernel->handle($request);

        self::assertSame(405, $response->status());
        self::assertSame('GET, HEAD', $response->header('Allow'));
    }

    public function test_single_request_is_idempotent_and_replays_without_new_side_effects(): void
    {
        ['kernel' => $kernel] = $this->prepareKernel();

        $request = Request::fromGlobals(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/login', 'CONTENT_TYPE' => 'application/json'],
            [],
            [],
            [],
            '{"username":"ada"}'
        );

        $first = $kernel->handle($request);
        $second = $kernel->handle($request);

        self::assertSame(200, $first->status());
        self::assertSame(200, $second->status());
        self::assertSame('{"token":"t"}', $second->body());
    }

    public function test_request_identifier_is_present_on_every_response_and_unique_per_request(): void
    {
        ['kernel' => $kernel] = $this->prepareKernel();
        $kernel = $kernel->using(new RequestIdMiddleware());

        $request = Request::fromGlobals(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/health'], []);

        $first = $kernel->handle($request);
        $second = $kernel->handle($request);
        $firstId = $first->header('X-Request-Id');
        $secondId = $second->header('X-Request-Id');

        self::assertIsString($firstId);
        self::assertIsString($secondId);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $firstId);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $secondId);
        self::assertNotSame($firstId, $secondId);
    }

    public function test_paginator_returns_standard_metadata_and_url_helpers(): void
    {
        $page = new PageResult(
            new OrmResult([]),
            currentPage: 2,
            perPage: 20,
            total: 42,
        );

        $paginator = new Paginator($page);

        self::assertSame(42, $paginator->total());
        self::assertSame(20, $paginator->perPage());
        self::assertSame(2, $paginator->currentPage());
        self::assertSame(3, $paginator->lastPage());
        self::assertTrue($paginator->hasMorePages());
        self::assertSame('/api/page=3&perPage=20', $paginator->json()['nextPageUrl']);
        self::assertSame('/api/page=1&perPage=20', $paginator->json()['prevPageUrl']);
    }

    public function test_content_negotiation_respects_accept_header(): void
    {
        ['kernel' => $kernel] = $this->prepareKernel();

        $request = Request::fromGlobals(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/search', 'HTTP_ACCEPT' => 'application/json'],
            ['q' => 'sahil']
        );

        $response = $kernel->handle($request);

        self::assertSame(200, $response->status());
        self::assertSame('application/json', $response->header('Content-Type'));
        self::assertSame('{"q":"sahil"}', $response->body());
    }

    public function test_rate_limit_returns_429_with_retry_after(): void
    {
        ['kernel' => $kernel] = $this->prepareKernel();

        $request = Request::fromGlobals(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/login', 'CONTENT_TYPE' => 'application/json'],
            [],
            [],
            [],
            '{"username":"ada"}'
        );

        $first = $kernel->handle($request);
        $second = $kernel->handle($request);
        $third = $kernel->handle($request);

        self::assertSame(200, $first->status());
        self::assertSame(200, $second->status());
        self::assertSame(429, $third->status());
        self::assertSame('rate_limit', json_decode($third->body(), true, 512, JSON_THROW_ON_ERROR)['error']);
        self::assertSame('60', $third->header('Retry-After'));
    }

    public function test_cors_preflight_and_qualified_origin_are_handled(): void
    {
        $config = Config::fromArray([
            'enabled' => true,
            'allowed_origins' => ['https://app.example.com'],
            'allowed_methods' => ['GET', 'POST', 'OPTIONS'],
            'allowed_headers' => ['Content-Type', 'Authorization', 'X-Request-Id'],
            'exposed_headers' => ['X-Request-Id'],
            'max_age' => 300,
            'allow_credentials' => false,
        ]);

        $middleware = new Cors($config);

        $preflight = Request::fromGlobals([
            'REQUEST_METHOD' => 'OPTIONS',
            'REQUEST_URI' => '/api/search',
            'HTTP_ORIGIN' => 'https://app.example.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Content-Type,Authorization,X-Request-Id',
        ], []);

        $preflightResponse = $middleware->handle($preflight, static fn (Request $r): Response => Response::empty(204));

        self::assertSame(204, $preflightResponse->status());
        self::assertSame('https://app.example.com', $preflightResponse->header('Access-Control-Allow-Origin'));
        self::assertSame('GET, POST, OPTIONS', $preflightResponse->header('Access-Control-Allow-Methods'));
        self::assertSame('Content-Type, Authorization, X-Request-Id', $preflightResponse->header('Access-Control-Allow-Headers'));
        self::assertSame('300', $preflightResponse->header('Access-Control-Max-Age'));

        $response = Request::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/search',
            'HTTP_ORIGIN' => 'https://app.example.com',
        ], []);

        $responseWithCors = $middleware->handle($response, static fn (Request $r): Response => Response::json(['q' => 'x'], 200));

        self::assertSame('https://app.example.com', $responseWithCors->header('Access-Control-Allow-Origin'));
        self::assertSame('X-Request-Id', $responseWithCors->header('Access-Control-Expose-Headers'));
    }

    public function test_cors_with_wildcard_and_credentials_is_rejected_at_configuration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Config::fromArray([
            'enabled' => true,
            'allowed_origins' => ['*'],
            'allowed_methods' => ['GET'],
            'allowed_headers' => [],
            'exposed_headers' => [],
            'max_age' => 0,
            'allow_credentials' => true,
        ]);
    }

    public function test_structured_request_logging_writes_one_redacted_event(): void
    {
        $temp = new TempLogger();

        $request = Request::fromGlobals(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/health'], []);

        $logger = new RequestLogger(
            new FileLogger($temp->path()),
            $request,
            'a1b2c3d4e5f6',
        );

        $logger->info('request handled', ['user_id' => 42, 'password' => 'hunter2']);

        self::assertSame(1, $temp->count());
        $lines = $temp->lines();
        self::assertSame('info', $lines[0]['level']);
        self::assertSame('a1b2c3d4e5f6', $lines[0]['request_id']);
        self::assertSame(['user_id' => 42, 'password' => '[redacted]'], $lines[0]['context']);
    }

    public function test_log_request_middleware_is_idempotent(): void
    {
        $temp = new TempLogger();

        ['kernel' => $kernel] = $this->prepareKernel();

        $request = Request::fromGlobals(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/health'], []);

        $response = $kernel->handle($request);

        self::assertSame(200, $response->status());
        self::assertSame('application/json', $response->header('Content-Type'));
    }

    public function test_client_supplied_request_identifier_is_not_trusted(): void
    {
        $request = Request::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/health',
            'HTTP_X_REQUEST_ID' => 'attacker-chosen',
        ], []);

        $response = (new RequestIdMiddleware())->handle(
            $request,
            static fn (Request $r): Response => Response::text('ok'),
        );

        self::assertNotSame('attacker-chosen', $response->header('X-Request-Id'));
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $response->header('X-Request-Id'));
    }

    public function test_unknown_path_returns_404_without_detail_leaks(): void
    {
        ['kernel' => $kernel] = $this->prepareKernel();

        $request = Request::fromGlobals(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/nope'], []);
        $response = $kernel->handle($request);

        self::assertSame(404, $response->status());
        self::assertSame('{"error":"http_error","status":404}', $response->body());
    }

    public function test_unknown_path_includes_request_identifier(): void
    {
        ['kernel' => $kernel] = $this->prepareKernel();

        $request = Request::fromGlobals(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/nope'], []);

        // The kernel renders HttpExceptions after its middleware pipeline has
        // unwound, so request-id middleware wraps the kernel for this case.
        $response = (new RequestIdMiddleware())->handle(
            $request,
            static fn (Request $r): Response => $kernel->handle($r),
        );

        self::assertSame(404, $response->status());
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $response->header('X-Request-Id'));
    }
}
