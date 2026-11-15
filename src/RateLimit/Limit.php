<?php

declare(strict_types=1);

namespace Sahil\RateLimit;

use Closure;
use InvalidArgumentException;
use Sahil\Contracts\Auth\AuthenticatableContract;
use Sahil\Http\Request;
use Sahil\Http\Response;

/**
 * One named rate-limit definition.
 *
 * A limit answers: at most `attempts` hits per `decay` seconds for one
 * resolved identity. Everything else — who the identity is, whether the
 * limit applies to this request, what a rejection looks like — is
 * optional configuration on the same object.
 *
 *     Limit::perMinute(5);
 *     Limit::per('30 seconds', 10)->by(fn (Request $r, $u) => $u?->getAuthIdentifier() ?? $r->clientIp());
 *     Limit::per('1 hour', 100)->when(fn (Request $r) => $r->path() === '/api');
 *
 * The `by` resolver receives the current request and the authenticated
 * user (or null for guests). It must return a non-empty scalar — arbitrary
 * client-supplied headers should never be used unless the application's
 * trusted-proxy configuration makes them trustworthy; `clientIp()`
 * already honours that configuration.
 */
final class Limit
{
    /** @var Closure(Request, ?AuthenticatableContract): (string|int) */
    private Closure $identity;

    /** @var null|Closure(Request): bool */
    private ?Closure $condition = null;

    /** @var null|Closure(Request, Decision): Response */
    private ?Closure $responseFactory = null;

    /**
     * @param int $attempts maximum hits allowed inside the decay window
     * @param int $decay    window length in seconds
     */
    private function __construct(
        public readonly int $attempts,
        public readonly int $decay,
    ) {
        $this->identity = Identity::ip();
    }

    /**
     * `Limit::per('1 minute', 5)` — attempts per human-readable window.
     */
    public static function per(string $duration, int $attempts): self
    {
        return new self(
            self::assertAttempts($attempts),
            Duration::seconds($duration),
        );
    }

    /** `Limit::perSeconds(30, 10)` — attempts per N seconds. */
    public static function perSeconds(int $seconds, int $attempts): self
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException(sprintf(
                'Rate-limit window must be at least 1 second; got %d.',
                $seconds,
            ));
        }

        return new self(self::assertAttempts($attempts), $seconds);
    }

    /** `Limit::perMinute(5)` — attempts per minute. */
    public static function perMinute(int $attempts, int $minutes = 1): self
    {
        return self::perSeconds($minutes * 60, $attempts);
    }

    /** `Limit::perHour(100)` — attempts per hour. */
    public static function perHour(int $attempts, int $hours = 1): self
    {
        return self::perSeconds($hours * 3600, $attempts);
    }

    /**
     * Set the identity resolver. The default is the request's client IP.
     *
     * @param Closure(Request, ?AuthenticatableContract): (string|int) $resolver
     */
    public function by(Closure $resolver): self
    {
        $clone = clone $this;
        $clone->identity = $resolver;

        return $clone;
    }

    /** Apply the limit only when the condition accepts the request. */
    public function when(Closure $condition): self
    {
        $clone = clone $this;
        $clone->condition = $condition;

        return $clone;
    }

    /** Apply the limit unless the condition rejects the request. */
    public function unless(Closure $condition): self
    {
        return $this->when(static fn (Request $r): bool => !$condition($r));
    }

    /**
     * Custom rejection response for hits beyond the limit.
     *
     * @param Closure(Request, Decision): Response $factory
     */
    public function respondUsing(Closure $factory): self
    {
        $clone = clone $this;
        $clone->responseFactory = $factory;

        return $clone;
    }

    /** Resolve the identity for a request; always a non-empty string. */
    public function resolveIdentity(Request $request, ?AuthenticatableContract $user): string
    {
        $identity = ($this->identity)($request, $user);

        if (!is_string($identity) && !is_int($identity)) {
            throw new InvalidArgumentException(sprintf(
                'Rate-limit identity resolvers must return a string or int; got %s.',
                get_debug_type($identity),
            ));
        }

        $identity = trim((string) $identity);

        if ($identity === '') {
            throw new InvalidArgumentException(
                'Rate-limit identity resolvers must not return an empty string.',
            );
        }

        return $identity;
    }

    /** Whether this limit applies to the request (default: always). */
    public function applies(Request $request): bool
    {
        return $this->condition === null || ($this->condition)($request) === true;
    }

    /** Whether a custom rejection response was configured. */
    public function hasCustomResponse(): bool
    {
        return $this->responseFactory !== null;
    }

    /** @internal Build the configured rejection response. */
    public function rejection(Request $request, Decision $decision): Response
    {
        return ($this->responseFactory)($request, $decision);
    }

    private static function assertAttempts(int $attempts): int
    {
        if ($attempts < 1) {
            throw new InvalidArgumentException(sprintf(
                'Rate-limit attempts must be a positive integer; got %d.',
                $attempts,
            ));
        }

        return $attempts;
    }
}
