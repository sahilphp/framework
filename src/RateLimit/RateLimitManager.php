<?php

declare(strict_types=1);

namespace Sahil\RateLimit;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Sahil\Contracts\Auth\AuthenticatableContract;
use Sahil\Http\Request;

/**
 * The framework's rate-limiting engine.
 *
 * Named {@see Limit} definitions are registered once (a provider's
 * `boot()` is the obvious place) and evaluated against every request.
 * Counter state lives in a {@see CounterStore} — never in the manager —
 * so consecutive worker requests share nothing but definitions.
 *
 * A check records one hit inside a fixed window anchored to the first
 * hit of that window. Counters are keyed `prefix:name:identity`, so
 * different limits and different identities never share a counter, and
 * applications sharing one backend can pick distinct prefixes.
 */
final class RateLimitManager
{
    /** @var array<string, Limit> */
    private array $limits = [];

    /** @var Closure(): ?AuthenticatableContract */
    private readonly Closure $userResolver;

    public function __construct(
        private readonly CounterStore $store,
        ?Closure $userResolver = null,
        private readonly string $keyPrefix = 'rl',
    ) {
        $this->userResolver = $userResolver ?? static fn (): ?AuthenticatableContract => null;
    }

    /**
     * Register a named limit. Array form:
     * `['attempts' => 5, 'per' => '1 minute', 'by' => fn, 'when' => fn, 'response' => fn]`.
     *
     * @throws InvalidArgumentException On a duplicate name or bad definition
     */
    public function define(string $name, Limit|array $definition): void
    {
        $name = trim($name);

        if ($name === '' || str_contains($name, ':')) {
            throw new InvalidArgumentException(sprintf(
                'Rate-limit names must be non-empty and must not contain ":"; got "%s".',
                $name,
            ));
        }

        if (isset($this->limits[$name])) {
            throw new InvalidArgumentException(sprintf(
                'Rate limit "%s" is already defined — call forget() first to replace it.',
                $name,
            ));
        }

        $this->limits[$name] = is_array($definition)
            ? self::limitFromArray($name, $definition)
            : $definition;
    }

    public function has(string $name): bool
    {
        return isset($this->limits[$name]);
    }

    public function forget(string $name): void
    {
        unset($this->limits[$name]);
    }

    /** @return array<string, Limit> */
    public function limits(): array
    {
        return $this->limits;
    }

    /**
     * Record one hit for a request against a named limit.
     *
     * Resolves the limit's identity strategy, applies its `when`
     * condition (a skipped limit reports an always-allowed decision
     * without touching storage), and returns the resulting decision.
     *
     * @throws RuntimeException When the name was never defined
     */
    public function hit(string $name, Request $request): Decision
    {
        $limit = $this->limit($name);
        $user = ($this->userResolver)();

        if (!$limit->applies($request)) {
            return new Decision(true, $limit->attempts, $limit->attempts, 0, time());
        }

        $identity = $limit->resolveIdentity($request, $user);

        return $this->record($limit, $this->key($name, $identity));
    }

    /** Record a hit against an already-resolved limit and identity key. */
    public function hitLimit(Limit $limit, string $name, Request $request): Decision
    {
        if (!$limit->applies($request)) {
            return new Decision(true, $limit->attempts, $limit->attempts, 0, time());
        }

        return $this->record($limit, $this->key($name, $limit->resolveIdentity($request, ($this->userResolver)())));
    }

    /**
     * Attempts remaining for a request under a named limit — a read-only
     * peek that never records a hit.
     */
    public function remaining(string $name, Request $request): int
    {
        $limit = $this->limit($name);
        $state = $this->store->state($this->key($name, $limit->resolveIdentity($request, ($this->userResolver)())));

        return max(0, $limit->attempts - ($state['attempts'] ?? 0));
    }

    /** Seconds until the window resets; 0 when the request may proceed. */
    public function retryAfter(string $name, Request $request): int
    {
        $limit = $this->limit($name);
        $state = $this->store->state($this->key($name, $limit->resolveIdentity($request, ($this->userResolver)())));

        if ($state === null || $state['attempts'] < $limit->attempts) {
            return 0;
        }

        return max(0, $state['resetAt'] - time());
    }

    /** Clear one identity's counter under one named limit. */
    public function clear(string $name, Request $request): void
    {
        $limit = $this->limit($name);
        $this->store->reset($this->key($name, $limit->resolveIdentity($request, ($this->userResolver)())));
    }

    /** Resolve a definition or throw — never silently allows/denies. */
    public function limit(string $name): Limit
    {
        if (!isset($this->limits[$name])) {
            throw new RuntimeException(sprintf(
                'Unknown rate limit "%s". Define it with RateLimiter::define() during application boot.',
                $name,
            ));
        }

        return $this->limits[$name];
    }

    /** @internal Counter key — hashes the identity so IPs/keys never leak into storage listing. */
    public function key(string $name, string $identity): string
    {
        return $this->keyPrefix.':'.$name.':'.hash('xxh128', $identity);
    }

    private function record(Limit $limit, string $key): Decision
    {
        $state = $this->store->hit($key, $limit->decay);

        return new Decision(
            allowed: $state['attempts'] <= $limit->attempts,
            limit: $limit->attempts,
            remaining: max(0, $limit->attempts - $state['attempts']),
            retryAfter: max(0, $state['resetAt'] - time()),
            resetAt: $state['resetAt'],
        );
    }

    /** @param array<string, mixed> $definition */
    private static function limitFromArray(string $name, array $definition): Limit
    {
        if (!isset($definition['attempts']) || !isset($definition['per'])) {
            throw new InvalidArgumentException(sprintf(
                'Rate limit "%s" requires "attempts" and "per" keys.',
                $name,
            ));
        }

        $limit = Limit::per($definition['per'], (int) $definition['attempts']);

        if (isset($definition['by'])) {
            if (!$definition['by'] instanceof Closure) {
                throw new InvalidArgumentException(sprintf('Rate limit "%s": "by" must be a Closure.', $name));
            }
            $limit = $limit->by($definition['by']);
        }

        if (isset($definition['when'])) {
            if (!$definition['when'] instanceof Closure) {
                throw new InvalidArgumentException(sprintf('Rate limit "%s": "when" must be a Closure.', $name));
            }
            $limit = $limit->when($definition['when']);
        }

        if (isset($definition['response'])) {
            if (!$definition['response'] instanceof Closure) {
                throw new InvalidArgumentException(sprintf('Rate limit "%s": "response" must be a Closure.', $name));
            }
            $limit = $limit->respondUsing($definition['response']);
        }

        return $limit;
    }
}
