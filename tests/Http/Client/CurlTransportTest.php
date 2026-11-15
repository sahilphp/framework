<?php

declare(strict_types=1);

namespace Sahil\Tests\Http\Client;

use PHPUnit\Framework\TestCase;
use Sahil\Http\Client\CurlTransport;
use Sahil\Http\Client\HttpClient;
use Sahil\Http\Client\HttpClientException;
use Sahil\Http\Http;

/**
 * Real `CurlTransport` requests against PHP's built-in web server
 * (`php -S`) when the `HTTP_CLIENT_TEST_SERVER` env var is set — the
 * suite boots one when run through the standard harness; skipped
 * cleanly when no stub is reachable.
 */
final class CurlTransportTest extends TestCase
{
    private static string $base = 'http://127.0.0.1:18923';

    protected function setUp(): void
    {
        if (!self::reachable()) {
            self::markTestSkipped('HTTP client stub server not running on :18923.');
        }

        Http::swap(new HttpClient(new CurlTransport()));
    }

    protected function tearDown(): void
    {
        Http::flushState();
    }

    private static function reachable(): bool
    {
        $ctx = stream_context_create(['http' => ['timeout' => 0.5]]);

        return @file_get_contents(self::$base.'/json', false, $ctx) !== false;
    }

    public function test_real_get_json(): void
    {
        $response = Http::get(self::$base.'/json');

        self::assertTrue($response->ok());
        self::assertSame(['ok' => true, 'method' => 'GET'], $response->json());
        self::assertSame('application/json', $response->header('Content-Type'));
    }

    public function test_real_post_body_round_trip(): void
    {
        $response = Http::post(self::$base.'/echo-body', 'payload-42');

        self::assertSame('payload-42', $response->body());
    }

    public function test_real_redirect_followed_with_method_rewrite(): void
    {
        // 303 → POST becomes GET on the target.
        $response = Http::pending()->post(self::$base.'/redirect-post', ['x' => 1]);

        self::assertTrue($response->ok());
        self::assertSame(['ok' => true, 'method' => 'GET'], $response->json());
    }

    public function test_redirect_limit_respected(): void
    {
        // One hop, zero allowed → the 302 response surfaces as-is.
        $response = Http::withoutRedirecting()->get(self::$base.'/redirect');

        self::assertTrue($response->redirect());
        self::assertSame(302, $response->status());
    }

    public function test_authorization_header_sent(): void
    {
        $response = Http::withToken('tok-123')->get(self::$base.'/auth');

        self::assertSame('Bearer tok-123', $response->body());
    }

    public function test_404_is_not_a_transport_error(): void
    {
        $response = Http::get(self::$base.'/status404');

        self::assertSame(404, $response->status());
        self::assertTrue($response->notFound());
        self::assertSame('missing', $response->body());
    }

    public function test_timeout_raises_transport_exception(): void
    {
        $this->expectException(HttpClientException::class);
        Http::timeout(0.3)->get(self::$base.'/slow');
    }

    public function test_connection_refused_is_descriptive(): void
    {
        $this->expectException(HttpClientException::class);
        Http::connectTimeout(0.5)->get('http://127.0.0.1:19/json');
    }
}
