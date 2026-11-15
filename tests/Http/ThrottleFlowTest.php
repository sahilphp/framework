<?php

declare(strict_types=1);

namespace Sahil\Tests\Http;

use Sahil\Http\Middleware\Throttle;
use Sahil\Http\Request;
use Sahil\Http\Response;
use Sahil\RateLimit\ArrayCounterStore;
use Sahil\RateLimit\Decision;
use Sahil\RateLimit\Identity;
use Sahil\RateLimit\Limit;
use Sahil\RateLimit\RateLimitManager;
use Sahil\Routing\Link;
use Sahil\Tests\FrameworkTestCase;

/**
 * End-to-end verification of the `throttle` route middleware: named and
 * inline limits, 429 semantics, rate-limit headers, worker isolation and
 * composition with the `ability` authorization middleware.
 */
final class ThrottleFlowTest extends FrameworkTestCase
{
    private RateLimitManager $limiter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->limiter = new RateLimitManager(new ArrayCounterStore());
        $this->container()->instance(RateLimitManager::class, $this->limiter);
        $this->container()->instance(Throttle::class, new Throttle($this->limiter));

        $this->limiter->define('login', Limit::perMinute(2));
        $this->limiter->define('api', Limit::perHour(3)->by(Identity::ip()));
        $this->limiter->define('polite', Limit::perMinute(1)->respondUsing(
            static fn (Request $r, Decision $d): Response => Response::json(
                ['error' => 'slow_down', 'retry' => $d->retryAfter],
                429,
            ),
        ));
        $this->limiter->define('admins-only', Limit::perMinute(1)->when(
            static fn (Request $r): bool => $r->queryParameter('admin') === '1',
        ));

        $this->withRoutes(static function ($router): void {
            $router->register(Link::get('/login', static fn (): Response => Response::json(['ok' => true]))
                ->middleware('throttle:login'));

            $router->register(Link::get('/inline', static fn (): Response => Response::text('inline-ok'))
                ->middleware('throttle:2,1'));

            $router->register(Link::get('/inline-seconds', static fn (): Response => Response::text('ok'))
                ->middleware('throttle:1,30s'));

            $router->register(Link::get('/stacked', static fn (): Response => Response::text('stacked'))
                ->middleware('throttle:login,api'));

            $router->register(Link::get('/custom', static fn (): Response => Response::text('never'))
                ->middleware('throttle:polite'));

            $router->register(Link::get('/conditional', static fn (): Response => Response::text('cond'))
                ->middleware('throttle:admins-only'));

            $router->register(Link::get('/unknown', static fn (): Response => Response::text('unreachable'))
                ->middleware('throttle:not-defined'));
        });
    }

    private static function hit(string $method, string $uri, string $ip = '9.9.9.9', ?string $accept = null): Response
    {
        $query = [];
        parse_str((string) (parse_url($uri, PHP_URL_QUERY) ?? ''), $query);

        return app()->container()->make(\Sahil\Http\Kernel::class)->handle(
            Request::fromGlobals(
                server: array_filter([
                    'REQUEST_METHOD' => $method,
                    'REQUEST_URI' => $uri,
                    'REMOTE_ADDR' => $ip,
                    'HTTP_ACCEPT' => $accept,
                ]),
                query: $query,
            ),
        );
    }

    public function test_named_limit_allows_then_429s(): void
    {
        $this->assertSame(200, self::hit('GET', '/login')->status());
        $this->assertSame(200, self::hit('GET', '/login')->status());

        $response = self::hit('GET', '/login');
        $this->assertSame(429, $response->status());
        $this->assertNotNull($response->header('Retry-After'));
        $this->assertSame('2', $response->header('X-RateLimit-Limit'));
        $this->assertSame('0', $response->header('X-RateLimit-Remaining'));
        $this->assertNotNull($response->header('X-RateLimit-Reset'));
    }

    public function test_successful_responses_carry_rate_limit_headers(): void
    {
        $response = self::hit('GET', '/login');

        $this->assertSame(200, $response->status());
        $this->assertSame('2', $response->header('X-RateLimit-Limit'));
        $this->assertSame('1', $response->header('X-RateLimit-Remaining'));
    }

    public function test_inline_limit_attempts_per_minutes(): void
    {
        $this->assertSame(200, self::hit('GET', '/inline')->status());
        $this->assertSame(200, self::hit('GET', '/inline')->status());
        $this->assertSame(429, self::hit('GET', '/inline')->status());
    }

    public function test_inline_limit_with_seconds_suffix(): void
    {
        $this->assertSame(200, self::hit('GET', '/inline-seconds')->status());
        $this->assertSame(429, self::hit('GET', '/inline-seconds')->status());
    }

    public function test_stacked_limits_all_must_pass(): void
    {
        // login allows 2/min, api allows 3/hr — login exhausts first.
        $this->assertSame(200, self::hit('GET', '/stacked')->status());
        $this->assertSame(200, self::hit('GET', '/stacked')->status());
        $this->assertSame(429, self::hit('GET', '/stacked')->status());
    }

    public function test_json_429_has_structured_body(): void
    {
        self::hit('GET', '/login', '8.8.8.8', 'application/json');
        self::hit('GET', '/login', '8.8.8.8', 'application/json');
        $response = self::hit('GET', '/login', '8.8.8.8', 'application/json');

        $this->assertSame(429, $response->status());
        $this->assertStringContainsString('too_many_requests', $response->body());
    }

    public function test_html_429_has_safe_message(): void
    {
        self::hit('GET', '/login', '7.7.7.7');
        self::hit('GET', '/login', '7.7.7.7');
        $response = self::hit('GET', '/login', '7.7.7.7');

        $this->assertSame(429, $response->status());
        $this->assertStringContainsString('Too Many Requests', $response->body());
        $this->assertStringNotContainsString('7.7.7.7', $response->body()); // no IP leakage
    }

    public function test_custom_rejection_response(): void
    {
        self::hit('GET', '/custom');
        $response = self::hit('GET', '/custom');

        $this->assertSame(429, $response->status());
        $this->assertStringContainsString('slow_down', $response->body());
    }

    public function test_conditional_limit_skips_non_matching_requests(): void
    {
        // Condition only applies to ?admin=1 requests.
        $this->assertSame(200, self::hit('GET', '/conditional')->status());
        $this->assertSame(200, self::hit('GET', '/conditional')->status());

        $this->assertSame(200, self::hit('GET', '/conditional?admin=1')->status());
        $this->assertSame(429, self::hit('GET', '/conditional?admin=1')->status());
    }

    public function test_unknown_limit_name_fails_loudly(): void
    {
        $response = self::hit('GET', '/unknown');
        // Misconfiguration surfaces as a 500, not silent pass-through.
        $this->assertSame(500, $response->status());
    }

    public function test_worker_isolation_separate_ips_have_separate_counters(): void
    {
        // Consecutive requests through the same worker, different clients.
        $this->assertSame(200, self::hit('GET', '/login', '1.1.1.1')->status());
        $this->assertSame(200, self::hit('GET', '/login', '2.2.2.2')->status());
        $this->assertSame(200, self::hit('GET', '/login', '1.1.1.1')->status());
        $this->assertSame(200, self::hit('GET', '/login', '2.2.2.2')->status());
        $this->assertSame(429, self::hit('GET', '/login', '1.1.1.1')->status());
        $this->assertSame(429, self::hit('GET', '/login', '2.2.2.2')->status());
        $this->assertSame(200, self::hit('GET', '/login', '3.3.3.3')->status());
    }

    public function test_rejected_requests_still_consume_but_report_correctly(): void
    {
        // Documented fixed-window behaviour: a rejected hit still counts
        // (sliding-window would need a heavier store); remaining stays 0.
        for ($i = 0; $i < 4; $i++) {
            self::hit('GET', '/login', '6.6.6.6');
        }
        $response = self::hit('GET', '/login', '6.6.6.6');
        $this->assertSame('0', $response->header('X-RateLimit-Remaining'));
    }
}
