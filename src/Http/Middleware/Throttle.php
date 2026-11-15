<?php

declare(strict_types=1);

namespace Sahil\Http\Middleware;

use Closure;
use RuntimeException;
use Sahil\Http\ErrorRenderer;
use Sahil\Http\RateLimit\StoreInterface;
use Sahil\Http\Request;
use Sahil\Http\Response;
use Sahil\RateLimit\Decision;
use Sahil\RateLimit\Limit;
use Sahil\RateLimit\RateLimitManager;

/**
 * Route middleware enforcing named or inline rate limits.
 *
 *     ->middleware('throttle:login')     // a defined limit
 *     ->middleware('throttle:5,1')       // 5 attempts per minute, inline
 *     ->middleware('throttle:100,1h')    // 100 per hour, inline
 *
 * Several parameters mean "every listed limit must pass"; the first
 * denial short-circuits with a 429. Unknown names throw rather than
 * silently passing — a misconfigured route must fail closed for
 * operators to notice, and the error surfaces in development.
 *
 * The middleware only transports the request: identity resolution,
 * counting, and window math all live in {@see RateLimitManager}. The
 * deprecated `new Throttle('key', $store)` construction still works
 * for existing applications; it keeps its original fixed-key semantics.
 */
final class Throttle implements MiddlewareContract, ParameterizedMiddlewareContract
{
    public function __construct(
        private readonly RateLimitManager|string $limiterOrKey,
        private readonly ?StoreInterface $legacyStore = null,
    ) {
    }

    /** {@inheritDoc} */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->limiterOrKey instanceof RateLimitManager) {
            return $next($request);
        }

        // Legacy fixed-key mode (deprecated construction) — preserves its
        // original JSON body and header contract.
        $result = $this->legacyStore()->increment($this->limiterOrKey);

        if (!$result->allowed) {
            return Response::json([
                'error' => 'rate_limit',
                'message' => 'Too many requests. Try again later.',
                'remaining' => $result->remaining,
            ], 429)->withHeader('Retry-After', (string) $result->retryAfter);
        }

        return $next($request);
    }

    /**
     * @param array<array-key, mixed> $parameters limit names or `attempts[,duration]`
     *
     * @throws RuntimeException When a parameter is neither a defined name nor a valid inline limit
     */
    public function handleWithParameters(Request $request, Closure $next, array $parameters): Response
    {
        $manager = $this->manager();

        // `throttle:5,1` reaches us as two parameters because the comma
        // is also the middleware separator — rejoin purely numeric
        // parameter lists into one inline spec ("5,1", "10,30s").
        if (count($parameters) > 1
            && count(array_filter($parameters, static fn (mixed $p): bool
                => is_string($p) && preg_match('/^\d+[smhd]?$/', $p) === 1)) === count($parameters)) {
            $parameters = [implode(',', $parameters)];
        }

        $decision = null;

        foreach ($parameters as $parameter) {
            if (!is_string($parameter) || trim($parameter) === '') {
                throw new RuntimeException(sprintf(
                    'Throttle middleware parameters must be non-empty strings (a limit name or "attempts,duration"); got %s.',
                    get_debug_type($parameter),
                ));
            }

            [$limit, $name] = $this->resolveSpec($parameter, $manager);
            $decision = $manager->hitLimit($limit, $name, $request);

            if ($decision->denied()) {
                return $this->rejectDecision($request, $limit, $decision);
            }
        }

        $response = $next($request);

        return $decision === null ? $response : self::withHeaders($response, $decision);
    }

    /**
     * A parameter is either a defined name or an inline `attempts[,duration]`
     * spec ("5", "5,1", "10,30s", "100,1h" — bare numbers are minutes).
     *
     * @return array{Limit, string}
     */
    private function resolveSpec(string $parameter, RateLimitManager $manager): array
    {
        if ($manager->has($parameter)) {
            return [$manager->limit($parameter), $parameter];
        }

        if (preg_match('/^(\d+)(?:,(\d+)([smhd])?)?$/', $parameter, $m) === 1) {
            $duration = '1 minute';

            if (isset($m[2]) && $m[2] !== '') {
                $unit = match ($m[3] ?? '') {
                    's' => 'seconds', 'h' => 'hours', 'd' => 'days',
                    default => 'minutes',
                };
                $duration = $m[2].' '.$unit;
            }

            return [Limit::per($duration, (int) $m[1]), 'inline:'.$parameter];
        }

        throw new RuntimeException(sprintf(
            'Unknown rate limit "%s" — define it with RateLimiter::define() or use an inline "attempts,duration" spec.',
            $parameter,
        ));
    }

    private function rejectDecision(Request $request, Limit $limit, Decision $decision): Response
    {
        $response = $limit->hasCustomResponse()
            ? $limit->rejection($request, $decision)
            : self::reject($request, $decision->retryAfter);

        return self::withHeaders($response, $decision);
    }

    private static function reject(Request $request, int $retryAfter): Response
    {
        if (ErrorRenderer::expectsJson($request)) {
            return Response::json([
                'error' => 'too_many_requests',
                'message' => 'Too many requests. Try again later.',
            ], 429)->withHeader('Retry-After', (string) $retryAfter);
        }

        return Response::html(sprintf(
            '<!DOCTYPE html><title>Too Many Requests</title><h1>429 — Too Many Requests</h1><p>Try again in %d seconds.</p>',
            $retryAfter,
        ), 429)->withHeader('Retry-After', (string) $retryAfter);
    }

    private static function withHeaders(Response $response, Decision $decision): Response
    {
        return $response
            ->withHeader('X-RateLimit-Limit', (string) $decision->limit)
            ->withHeader('X-RateLimit-Remaining', (string) $decision->remaining)
            ->withHeader('X-RateLimit-Reset', (string) $decision->resetAt);
    }

    private function manager(): RateLimitManager
    {
        if (!$this->limiterOrKey instanceof RateLimitManager) {
            throw new RuntimeException(
                'Parameterized throttle requires a RateLimitManager — the legacy new Throttle($key, $store) form only supports fixed-key usage.',
            );
        }

        return $this->limiterOrKey;
    }

    private function legacyStore(): StoreInterface
    {
        if ($this->legacyStore === null) {
            throw new RuntimeException('The string Throttle key form requires a StoreInterface second argument.');
        }

        return $this->legacyStore;
    }
}
