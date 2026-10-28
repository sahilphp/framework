<?php

declare(strict_types=1);

namespace Sahil\Tests\Http;

use PHPUnit\Framework\TestCase;
use Sahil\Contracts\Session\SessionContract;
use Sahil\Http\Exception\BadRequestHttpException;
use Sahil\Http\Middleware\VerifyCsrfToken;
use Sahil\Http\Request;
use Sahil\Http\Response;

final class VerifyCsrfTokenTest extends TestCase
{
    private SessionContract $session;

    protected function setUp(): void
    {
        $this->session = $this->createMock(SessionContract::class);
    }

    public function test_allows_safe_methods_without_token(): void
    {
        $middleware = new VerifyCsrfToken($this->session);

        $request = Request::fromGlobals(
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/test']
        );

        $response = $middleware->handle($request, static fn (Request $r): Response => Response::text('ok'));

        $this->assertSame(200, $response->status());
        $this->assertSame('ok', $response->body());
    }

    public function test_allows_head_without_token(): void
    {
        $middleware = new VerifyCsrfToken($this->session);

        $request = Request::fromGlobals(
            server: ['REQUEST_METHOD' => 'HEAD', 'REQUEST_URI' => '/test']
        );

        $response = $middleware->handle($request, static fn (Request $r): Response => Response::text('ok'));

        $this->assertSame(200, $response->status());
    }

    public function test_allows_options_without_token(): void
    {
        $middleware = new VerifyCsrfToken($this->session);

        $request = Request::fromGlobals(
            server: ['REQUEST_METHOD' => 'OPTIONS', 'REQUEST_URI' => '/test']
        );

        $response = $middleware->handle($request, static fn (Request $r): Response => Response::text('ok'));

        $this->assertSame(200, $response->status());
    }

    public function test_requires_token_for_post(): void
    {
        $this->session->method('validateToken')->willReturn(false);

        $middleware = new VerifyCsrfToken($this->session);

        $request = Request::fromGlobals(
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/test'],
            form: ['_token' => 'invalid']
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('CSRF token validation failed');

        $middleware->handle($request, static fn (Request $r): Response => Response::text('ok'));
    }

    public function test_requires_token_for_put(): void
    {
        $this->session->method('validateToken')->willReturn(false);

        $middleware = new VerifyCsrfToken($this->session);

        $request = Request::fromGlobals(
            server: ['REQUEST_METHOD' => 'PUT', 'REQUEST_URI' => '/test'],
            form: ['_token' => 'invalid']
        );

        $this->expectException(BadRequestHttpException::class);

        $middleware->handle($request, static fn (Request $r): Response => Response::text('ok'));
    }

    public function test_requires_token_for_patch(): void
    {
        $this->session->method('validateToken')->willReturn(false);

        $middleware = new VerifyCsrfToken($this->session);

        $request = Request::fromGlobals(
            server: ['REQUEST_METHOD' => 'PATCH', 'REQUEST_URI' => '/test'],
            form: ['_token' => 'invalid']
        );

        $this->expectException(BadRequestHttpException::class);

        $middleware->handle($request, static fn (Request $r): Response => Response::text('ok'));
    }

    public function test_requires_token_for_delete(): void
    {
        $this->session->method('validateToken')->willReturn(false);

        $middleware = new VerifyCsrfToken($this->session);

        $request = Request::fromGlobals(
            server: ['REQUEST_METHOD' => 'DELETE', 'REQUEST_URI' => '/test'],
            form: ['_token' => 'invalid']
        );

        $this->expectException(BadRequestHttpException::class);

        $middleware->handle($request, static fn (Request $r): Response => Response::text('ok'));
    }

    public function test_validates_token_from_post_input(): void
    {
        $this->session->expects($this->once())
            ->method('validateToken')
            ->with('valid-token')
            ->willReturn(true);

        $this->session->expects($this->once())
            ->method('regenerateToken');

        $middleware = new VerifyCsrfToken($this->session);

        $request = Request::fromGlobals(
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/test'],
            form: ['_token' => 'valid-token']
        );

        $response = $middleware->handle($request, static fn (Request $r): Response => Response::text('ok'));

        $this->assertSame(200, $response->status());
    }

    public function test_validates_token_from_json_body(): void
    {
        $this->session->expects($this->once())
            ->method('validateToken')
            ->with('valid-token')
            ->willReturn(true);

        $this->session->expects($this->once())
            ->method('regenerateToken');

        $middleware = new VerifyCsrfToken($this->session);

        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/test',
                'CONTENT_TYPE' => 'application/json',
            ],
            body: '{"_token":"valid-token"}'
        );

        $response = $middleware->handle($request, static fn (Request $r): Response => Response::text('ok'));

        $this->assertSame(200, $response->status());
    }

    public function test_validates_token_from_x_csrf_token_header(): void
    {
        $this->session->expects($this->once())
            ->method('validateToken')
            ->with('valid-token')
            ->willReturn(true);

        $this->session->expects($this->once())
            ->method('regenerateToken');

        $middleware = new VerifyCsrfToken($this->session);

        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/test',
                'HTTP_X_CSRF_TOKEN' => 'valid-token',
            ]
        );

        $response = $middleware->handle($request, static fn (Request $r): Response => Response::text('ok'));

        $this->assertSame(200, $response->status());
    }

    public function test_validates_token_from_x_xsrf_token_header(): void
    {
        $this->session->expects($this->once())
            ->method('validateToken')
            ->with('valid-token')
            ->willReturn(true);

        $this->session->expects($this->once())
            ->method('regenerateToken');

        $middleware = new VerifyCsrfToken($this->session);

        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/test',
                'HTTP_X_XSRF_TOKEN' => 'valid-token',
            ]
        );

        $response = $middleware->handle($request, static fn (Request $r): Response => Response::text('ok'));

        $this->assertSame(200, $response->status());
    }

    public function test_prefers_post_input_over_headers(): void
    {
        $this->session->expects($this->once())
            ->method('validateToken')
            ->with('post-token')
            ->willReturn(true);

        $middleware = new VerifyCsrfToken($this->session);

        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/test',
                'HTTP_X_CSRF_TOKEN' => 'header-token',
            ],
            form: ['_token' => 'post-token']
        );

        $response = $middleware->handle($request, static fn (Request $r): Response => Response::text('ok'));

        $this->assertSame(200, $response->status());
    }

    public function test_excludes_specified_routes(): void
    {
        $middleware = new VerifyCsrfToken($this->session, except: ['/api/webhook']);

        $request = Request::fromGlobals(
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/webhook']
        );

        $response = $middleware->handle($request, static fn (Request $r): Response => Response::text('ok'));

        $this->assertSame(200, $response->status());
    }

    public function test_does_not_exclude_unlisted_routes(): void
    {
        $this->session->method('validateToken')->willReturn(false);

        $middleware = new VerifyCsrfToken($this->session, except: ['/api/webhook']);

        $request = Request::fromGlobals(
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/other']
        );

        $this->expectException(BadRequestHttpException::class);

        $middleware->handle($request, static fn (Request $r): Response => Response::text('ok'));
    }

    public function test_excludes_multiple_routes(): void
    {
        $middleware = new VerifyCsrfToken($this->session, except: ['/api/webhook', '/stripe/callback']);

        foreach (['/api/webhook', '/stripe/callback'] as $path) {
            $request = Request::fromGlobals(
                server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => $path]
            );

            $response = $middleware->handle($request, static fn (Request $r): Response => Response::text('ok'));

            $this->assertSame(200, $response->status());
        }
    }

    public function test_regenerates_token_on_successful_validation(): void
    {
        $this->session->expects($this->once())
            ->method('validateToken')
            ->willReturn(true);

        $this->session->expects($this->once())
            ->method('regenerateToken');

        $middleware = new VerifyCsrfToken($this->session);

        $request = Request::fromGlobals(
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/test'],
            form: ['_token' => 'valid-token']
        );

        $middleware->handle($request, static fn (Request $r): Response => Response::text('ok'));
    }

    public function test_does_not_regenerate_token_on_validation_failure(): void
    {
        $this->session->expects($this->once())
            ->method('validateToken')
            ->willReturn(false);

        $this->session->expects($this->never())
            ->method('regenerateToken');

        $middleware = new VerifyCsrfToken($this->session);

        $request = Request::fromGlobals(
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/test'],
            form: ['_token' => 'invalid']
        );

        try {
            $middleware->handle($request, static fn (Request $r): Response => Response::text('ok'));
        } catch (BadRequestHttpException $e) {
            // Expected
        }
    }

    public function test_does_not_regenerate_token_for_safe_methods(): void
    {
        $this->session->expects($this->never())
            ->method('regenerateToken');

        $middleware = new VerifyCsrfToken($this->session);

        $request = Request::fromGlobals(
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/test']
        );

        $middleware->handle($request, static fn (Request $r): Response => Response::text('ok'));
    }

    public function test_rejects_missing_token_for_unsafe_methods(): void
    {
        $this->session->method('validateToken')->willReturn(false);

        $middleware = new VerifyCsrfToken($this->session);

        $request = Request::fromGlobals(
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/test']
        );

        $this->expectException(BadRequestHttpException::class);

        $middleware->handle($request, static fn (Request $r): Response => Response::text('ok'));
    }
}
