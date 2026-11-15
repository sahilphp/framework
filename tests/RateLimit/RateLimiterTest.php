<?php

declare(strict_types=1);

namespace Sahil\Tests\RateLimit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sahil\Http\Request;
use Sahil\RateLimit\ArrayCounterStore;
use Sahil\RateLimit\CacheCounterStore;
use Sahil\RateLimit\Duration;
use Sahil\RateLimit\Identity;
use Sahil\RateLimit\Limit;
use Sahil\RateLimit\RateLimiter;
use Sahil\RateLimit\RateLimitManager;

final class RateLimiterTest extends TestCase
{
    protected function tearDown(): void
    {
        RateLimiter::swap(null);
    }

    private static function request(string $ip = '10.0.0.1'): Request
    {
        return Request::fromGlobals(server: [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/x',
            'REMOTE_ADDR' => $ip,
        ]);
    }

    private static function manager(): RateLimitManager
    {
        return new RateLimitManager(new ArrayCounterStore());
    }

    // ---- definitions -------------------------------------------------

    public function test_duration_parses_supported_units(): void
    {
        $this->assertSame(30, Duration::seconds('30 seconds'));
        $this->assertSame(60, Duration::seconds('1 minute'));
        $this->assertSame(300, Duration::seconds('5 minutes'));
        $this->assertSame(3600, Duration::seconds('1 hour'));
        $this->assertSame(86400, Duration::seconds('1 day'));
        $this->assertSame(90, Duration::seconds(90));
        $this->assertSame(120, Duration::seconds('2m'));
    }

    public function test_invalid_durations_are_rejected(): void
    {
        foreach (['', 'abc', '10', 'minute', '5 fortnights', '-3 minutes', '0 seconds'] as $bad) {
            try {
                Duration::seconds($bad);
                $this->fail("Expected rejection for '{$bad}'");
            } catch (InvalidArgumentException) {
            }
        }
        $this->expectException(InvalidArgumentException::class);
        Duration::seconds(0);
    }

    public function test_zero_and_negative_attempts_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Limit::perMinute(0);
    }

    public function test_duplicate_definition_throws(): void
    {
        $m = self::manager();
        $m->define('login', Limit::perMinute(5));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already defined');
        $m->define('login', Limit::perMinute(5));
    }

    public function test_forget_allows_redefinition(): void
    {
        $m = self::manager();
        $m->define('login', Limit::perMinute(5));
        $m->forget('login');
        $m->define('login', Limit::perMinute(10));

        $this->assertSame(10, $m->limit('login')->attempts);
    }

    public function test_unknown_limit_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown rate limit');
        self::manager()->hit('nope', self::request());
    }

    public function test_names_reject_colons_and_empty(): void
    {
        $m = self::manager();
        foreach (['', 'a:b'] as $bad) {
            try {
                $m->define($bad, Limit::perMinute(1));
                $this->fail("Expected rejection for '{$bad}'");
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertTrue(true);
    }

    public function test_array_definition_form(): void
    {
        $m = self::manager();
        $m->define('login', [
            'attempts' => 2,
            'per' => '1 minute',
            'by' => static fn (Request $r, $u) => 'fixed',
        ]);

        $this->assertSame(2, $m->limit('login')->attempts);
        $this->assertSame(60, $m->limit('login')->decay);
    }

    public function test_array_definition_requires_attempts_and_per(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::manager()->define('bad', ['attempts' => 5]);
    }

    public function test_identity_resolver_must_return_nonempty_scalar(): void
    {
        $m = self::manager();
        $m->define('x', Limit::perMinute(1)->by(static fn () => null));

        $this->expectException(InvalidArgumentException::class);
        $m->hit('x', self::request());
    }

    // ---- counters ----------------------------------------------------

    public function test_attempts_count_to_limit_then_deny(): void
    {
        $m = self::manager();
        $m->define('login', Limit::perMinute(3));
        $r = self::request();

        $this->assertTrue($m->hit('login', $r)->allowed);
        $this->assertTrue($m->hit('login', $r)->allowed);
        $d = $m->hit('login', $r);
        $this->assertTrue($d->allowed);
        $this->assertSame(0, $d->remaining);

        $d = $m->hit('login', $r);
        $this->assertFalse($d->allowed);
        $this->assertSame(0, $d->remaining); // never negative
        $this->assertGreaterThan(0, $d->retryAfter);
        $this->assertGreaterThan(time(), $d->resetAt);
    }

    public function test_remaining_and_retry_after_are_peeks(): void
    {
        $m = self::manager();
        $m->define('api', Limit::perMinute(2));
        $r = self::request();

        $this->assertSame(2, $m->remaining('api', $r));
        $m->hit('api', $r);
        $this->assertSame(1, $m->remaining('api', $r));
        $this->assertSame(0, $m->retryAfter('api', $r));
    }

    public function test_clear_resets_one_identity_only(): void
    {
        $m = self::manager();
        $m->define('login', Limit::perMinute(1));
        $a = self::request('10.0.0.1');
        $b = self::request('10.0.0.2');

        $m->hit('login', $a);
        $m->hit('login', $b);
        $this->assertFalse($m->hit('login', $a)->allowed);

        $m->clear('login', $a);
        $this->assertTrue($m->hit('login', $a)->allowed);
        $this->assertFalse($m->hit('login', $b)->allowed); // b untouched
    }

    public function test_named_limits_have_independent_counters(): void
    {
        $m = self::manager();
        $m->define('a', Limit::perMinute(1));
        $m->define('b', Limit::perMinute(1));
        $r = self::request();

        $m->hit('a', $r);
        $this->assertFalse($m->hit('a', $r)->allowed);
        $this->assertTrue($m->hit('b', $r)->allowed);
    }

    public function test_identities_do_not_share_counters(): void
    {
        $m = self::manager();
        $m->define('x', Limit::perMinute(1));

        $m->hit('x', self::request('10.0.0.1'));
        $this->assertFalse($m->hit('x', self::request('10.0.0.1'))->allowed);
        $this->assertTrue($m->hit('x', self::request('10.0.0.2'))->allowed);
    }

    public function test_expired_window_resets(): void
    {
        $store = new ArrayCounterStore();
        $m = new RateLimitManager($store);
        $m->define('x', Limit::perSeconds(1, 1));
        $r = self::request();

        $this->assertTrue($m->hit('x', $r)->allowed);
        $this->assertFalse($m->hit('x', $r)->allowed);

        sleep(2);
        $this->assertTrue($m->hit('x', $r)->allowed);
    }

    public function test_key_prefixes_separate_applications(): void
    {
        $store = new ArrayCounterStore();
        $a = new RateLimitManager($store, keyPrefix: 'app-a');
        $b = new RateLimitManager($store, keyPrefix: 'app-b');
        $a->define('x', Limit::perMinute(1));
        $b->define('x', Limit::perMinute(1));
        $r = self::request();

        $a->hit('x', $r);
        $this->assertFalse($a->hit('x', $r)->allowed);
        $this->assertTrue($b->hit('x', $r)->allowed);
    }

    // ---- identity & conditions ---------------------------------------

    public function test_user_or_ip_identity(): void
    {
        $user = new class () implements \Sahil\Contracts\Auth\AuthenticatableContract {
            public function getAuthIdentifier(): int|string
            {
                return 42;
            }

            public function getAuthPassword(): string
            {
                return 'x';
            }

            public function getAuthIdentifierName(): string
            {
                return 'id';
            }
        };

        $m = new RateLimitManager(new ArrayCounterStore(), static fn () => $user);
        $m->define('x', Limit::perMinute(1)->by(Identity::userOrIp()));

        // Same IP, authenticated → counter keyed by user.
        $m->hit('x', self::request('10.0.0.1'));
        $this->assertFalse($m->hit('x', self::request('10.0.0.9'))->allowed); // different IP, same user → still blocked
    }

    public function test_account_and_ip_identity_normalizes(): void
    {
        $m = self::manager();
        $m->define('login', Limit::perMinute(1)->by(Identity::accountAndIp(
            static fn (Request $r) => $r->form()['email'] ?? '',
        )));

        $post = static fn (string $email, string $ip): Request => Request::fromGlobals(
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/login', 'REMOTE_ADDR' => $ip],
            form: ['email' => $email],
        );

        $m->hit('login', $post('A@b.com', '1.1.1.1'));
        // Same account+IP blocked even with different email case.
        $this->assertFalse($m->hit('login', $post('a@B.com', '1.1.1.1'))->allowed);
        // Same account from another IP is a distinct identity.
        $this->assertTrue($m->hit('login', $post('a@b.com', '2.2.2.2'))->allowed);
    }

    public function test_when_condition_skips_limit_without_recording(): void
    {
        $m = self::manager();
        $m->define('x', Limit::perMinute(1)->when(static fn (Request $r): bool => false));
        $r = self::request();

        $this->assertTrue($m->hit('x', $r)->allowed);
        $this->assertTrue($m->hit('x', $r)->allowed); // never consumed
    }

    public function test_unless_inverts_condition(): void
    {
        $m = self::manager();
        $m->define('x', Limit::perMinute(1)->unless(static fn (): bool => true));
        $this->assertTrue($m->hit('x', self::request())->allowed);
        $this->assertTrue($m->hit('x', self::request())->allowed);
    }

    // ---- storage -----------------------------------------------------

    public function test_cache_counter_store_counts_and_expires(): void
    {
        $cache = new \Sahil\Cache\FileCache(sys_get_temp_dir().'/sahil-rl-'.uniqid());
        $m = new RateLimitManager(new CacheCounterStore($cache));
        $m->define('x', Limit::perMinute(2));
        $r = self::request();

        $this->assertTrue($m->hit('x', $r)->allowed);
        $this->assertTrue($m->hit('x', $r)->allowed);
        $d = $m->hit('x', $r);
        $this->assertFalse($d->allowed);
        $this->assertGreaterThan(0, $d->retryAfter);

        $m->clear('x', $r);
        $this->assertTrue($m->hit('x', $r)->allowed);
    }

    // ---- facade ------------------------------------------------------

    public function test_static_facade_delegates_to_swapped_manager(): void
    {
        RateLimiter::swap($m = self::manager());
        RateLimiter::define('login', Limit::perMinute(1));

        $this->assertTrue(RateLimiter::has('login'));
        $this->assertTrue(RateLimiter::hit('login', self::request())->allowed);
        $this->assertFalse(RateLimiter::hit('login', self::request())->allowed);
        RateLimiter::clear('login', self::request());
        $this->assertSame(1, RateLimiter::remaining('login', self::request()));
    }

    public function test_facade_without_manager_throws(): void
    {
        $this->expectException(RuntimeException::class);
        RateLimiter::has('x');
    }
}
