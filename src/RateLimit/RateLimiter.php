<?php

declare(strict_types=1);

namespace Sahil\RateLimit;

use Closure;
use RuntimeException;
use Sahil\Foundation\Application;
use Sahil\Http\Request;

/**
 * RateLimiter — named rate limits for routes and application operations.
 *
 *     RateLimiter::define('login', ['attempts' => 5, 'per' => '1 minute']);
 *     RateLimiter::define('api', Limit::perMinute(100)->by(Identity::userOrIp()));
 *
 *     RateLimiter::hit('login', $request);        // Decision — records a hit
 *     RateLimiter::remaining('login', $request);  // peek, no hit recorded
 *     RateLimiter::clear('login', $request);      // reset one identity
 *
 * The static API delegates to the container-bound
 * {@see RateLimitManager} — same convention as `Sahil\Auth\Bouncer`.
 * Definitions live in the manager; counters live in the configured
 * {@see CounterStore}; neither ever caches a request's identity.
 */
final class RateLimiter
{
    private static ?RateLimitManager $instance = null;

    private function __construct()
    {
    }

    /**
     * Register a named limit. Throws on duplicates — call {@see forget()}
     * first for a deliberate replacement.
     *
     * @param Limit|array{attempts: int, per: string, by?: Closure, when?: Closure, response?: Closure} $definition
     */
    public static function define(string $name, Limit|array $definition): void
    {
        self::manager()->define($name, $definition);
    }

    public static function has(string $name): bool
    {
        return self::manager()->has($name);
    }

    public static function forget(string $name): void
    {
        self::manager()->forget($name);
    }

    /** @return array<string, Limit> */
    public static function limits(): array
    {
        return self::manager()->limits();
    }

    /** Record one hit for the request under a named limit. */
    public static function hit(string $name, Request $request): Decision
    {
        return self::manager()->hit($name, $request);
    }

    /** Attempts remaining — a peek that records nothing. */
    public static function remaining(string $name, Request $request): int
    {
        return self::manager()->remaining($name, $request);
    }

    /** Seconds until the window resets; 0 when the request may proceed. */
    public static function retryAfter(string $name, Request $request): int
    {
        return self::manager()->retryAfter($name, $request);
    }

    /** Clear one identity's counter under one named limit. */
    public static function clear(string $name, Request $request): void
    {
        self::manager()->clear($name, $request);
    }

    /**
     * The backing manager — for providers binding it and tests resetting it.
     */
    public static function manager(): RateLimitManager
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $application = Application::peek();

        if ($application === null) {
            throw new RuntimeException(
                'RateLimiter needs a configured application or a manager installed via RateLimiter::swap().',
            );
        }

        /** @var RateLimitManager $manager */
        $manager = $application->container()->make(RateLimitManager::class);

        return $manager;
    }

    /** Install the manager the static API delegates to — tests and non-application contexts. */
    public static function swap(?RateLimitManager $manager): void
    {
        self::$instance = $manager;
    }

    /** Drop the swapped instance. */
    public static function flushState(): void
    {
        self::$instance = null;
    }
}
