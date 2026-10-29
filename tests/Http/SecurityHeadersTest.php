<?php

declare(strict_types=1);

namespace Sahil\Tests\Http;

use PHPUnit\Framework\TestCase;
use Sahil\Http\Middleware\SecurityHeaders;
use Sahil\Http\Request;
use Sahil\Http\Response;

/**
 * @covers \Sahil\Http\Middleware\SecurityHeaders
 */
final class SecurityHeadersTest extends TestCase
{
    private function request(): Request
    {
        return Request::fromGlobals(server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/']);
    }

    public function test_default_headers_are_added(): void
    {
        $middleware = new SecurityHeaders();

        $response = $middleware->handle($this->request(), static fn (Request $r): Response => Response::text('ok'));

        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        self::assertSame('DENY', $response->header('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->header('Referrer-Policy'));
        self::assertSame('camera=(), microphone=(), geolocation=()', $response->header('Permissions-Policy'));
        self::assertSame('same-origin', $response->header('Cross-Origin-Opener-Policy'));
        self::assertNull($response->header('Content-Security-Policy'), 'CSP is opt-in');
    }

    public function test_existing_headers_are_never_overwritten(): void
    {
        $middleware = new SecurityHeaders();

        $response = $middleware->handle(
            $this->request(),
            static fn (Request $r): Response => Response::text('ok')->withHeader('X-Frame-Options', 'SAMEORIGIN'),
        );

        self::assertSame('SAMEORIGIN', $response->header('X-Frame-Options'));
    }

    public function test_overrides_add_headers_and_replace_defaults(): void
    {
        $middleware = new SecurityHeaders([
            'Content-Security-Policy' => "default-src 'self'",
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);

        $response = $middleware->handle($this->request(), static fn (Request $r): Response => Response::text('ok'));

        self::assertSame("default-src 'self'", $response->header('Content-Security-Policy'));
        self::assertSame('SAMEORIGIN', $response->header('X-Frame-Options'));
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
    }
}
