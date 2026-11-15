<?php

declare(strict_types=1);

namespace Sahil\Auth;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Sahil\Contracts\Auth\AuthenticatableContract;
use Sahil\Contracts\Auth\AuthorizableContract;
use Throwable;

/**
 * Bouncer's evaluation engine.
 *
 * Applications never build one by hand — the container holds a single
 * manager that {@see Bouncer}'s static API delegates to. Ability and
 * policy definitions are application-level state: they are registered
 * once at boot and shared by every request a long-running worker serves.
 * Nothing per-request (the resolved user, token ceilings, decisions) is
 * stored here.
 *
 * Evaluation is deliberately fail-closed:
 *
 *  - Ability names are exact and case-sensitive; unknown names deny.
 *  - An empty ability name denies.
 *  - A defined ability allows only when its callback returns exactly
 *    `true`; any other return value denies.
 *  - A throwing callback is a bug, not a denial: the throwable is
 *    wrapped with ability context and propagates (HTTP 500), instead of
 *    being silently converted into a 403 that would hide it.
 *  - With no authenticated user only abilities whose callback declares a
 *    nullable user (`?User $u`) run — the explicit opt-in for public
 *    abilities; everything else denies, and {@see authorize()} answers
 *    401 before evaluation begins.
 *  - When no defined ability matches, a registered policy may answer;
 *    a missing policy method denies — never grants.
 *  - Finally, an {@see AuthorizableContract} user's stored
 *    `hasPermission()` may allow, preserving the previous Gate
 *    semantics applications relied on.
 */
final class BouncerManager
{
    /** @var array<string, Closure> */
    private array $abilities = [];

    /** @var array<class-string, Policy|class-string<Policy>> */
    private array $policies = [];

    /**
     * @param callable(): ?AuthenticatableContract $userResolver
     *        resolves the current request's principal — a callable so
     *        the manager never caches request state itself
     */
    public function __construct(
        private readonly mixed $userResolver = null,
    ) {
        if ($userResolver !== null && !is_callable($userResolver)) {
            throw new InvalidArgumentException(
                'BouncerManager expects a callable user resolver or null.',
            );
        }
    }

    /**
     * Register an authorization ability.
     *
     * The callback receives the authenticated user (or null) followed by
     * the resource arguments given at check time:
     *
     *     Bouncer::define('update-post', fn (User $user, Post $post): bool =>
     *         $user->id === $post->user_id);
     *
     * Defining a name twice throws: silently replacing a security rule
     * would make boot order decide who may do what. Remove the existing
     * definition with {@see forget()} first when redefinition is
     * genuinely intended (tests, controlled reconfiguration).
     *
     * @param Closure(AuthenticatableContract|null, mixed...): bool $callback
     */
    public function define(string $ability, Closure $callback): void
    {
        if (trim($ability) === '') {
            throw new InvalidArgumentException(
                'Bouncer abilities need a non-empty, exact name.',
            );
        }

        if (isset($this->abilities[$ability])) {
            throw new RuntimeException(sprintf(
                'Bouncer ability [%s] is already defined. Call Bouncer::forget() first to replace it deliberately.',
                $ability,
            ));
        }

        $this->abilities[$ability] = $callback;
    }

    /**
     * Whether an ability was registered through {@see define()}.
     */
    public function has(string $ability): bool
    {
        return isset($this->abilities[$ability]);
    }

    /**
     * Remove a defined ability — for tests and deliberate reconfiguration.
     */
    public function forget(string $ability): void
    {
        unset($this->abilities[$ability]);
    }

    /**
     * @return list<string> defined ability names
     */
    public function abilities(): array
    {
        return array_keys($this->abilities);
    }

    /**
     * Register a policy for a model class.
     *
     * `Bouncer::policy(Post::class, PostPolicy::class)` lets resource
     * abilities like `update-post` resolve to `PostPolicy::update()`
     * (the model's basename suffix is stripped; `updatePost` is also
     * accepted). An instance may be registered when the policy needs
     * constructor state.
     *
     * @param class-string                    $model
     * @param class-string<Policy>|Policy     $policy
     */
    public function policy(string $model, string|Policy $policy): void
    {
        if (isset($this->policies[$model])) {
            throw new RuntimeException(sprintf(
                'A policy for model [%s] is already registered with Bouncer.',
                $model,
            ));
        }

        $this->policies[$model] = $policy;
    }

    /**
     * The principal of the current request, resolved lazily.
     */
    public function user(): ?AuthenticatableContract
    {
        return $this->userResolver === null
            ? null
            : ($this->userResolver)();
    }

    /**
     * Whether the ability is allowed for the current request's user.
     *
     * @param array<array-key, mixed>|mixed $arguments resource arguments —
     *        a single non-array argument is passed positionally
     */
    public function allows(string $ability, mixed $arguments = [], ?AuthenticatableContract $user = null, ?TokenAuthorizer $token = null): bool
    {
        $user ??= $this->user();

        return $this->evaluate($ability, $user, self::argumentList($arguments), $token);
    }

    public function denies(string $ability, mixed $arguments = [], ?AuthenticatableContract $user = null, ?TokenAuthorizer $token = null): bool
    {
        return !$this->allows($ability, $arguments, $user, $token);
    }

    /**
     * Any of the given abilities allows (the route-middleware semantic).
     *
     * @param list<string>             $abilities
     * @param array<array-key, mixed>  $arguments
     */
    public function any(array $abilities, array $arguments = [], ?AuthenticatableContract $user = null, ?TokenAuthorizer $token = null): bool
    {
        $user ??= $this->user();

        foreach ($abilities as $ability) {
            if ($this->evaluate($ability, $user, $arguments, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Assert the ability or throw — 401 for guests, 403 for denials.
     *
     * @param array<array-key, mixed>|mixed $arguments
     */
    public function authorize(string $ability, mixed $arguments = [], ?AuthenticatableContract $user = null, ?TokenAuthorizer $token = null, ?string $message = null): void
    {
        $user ??= $this->user();

        if ($user === null) {
            throw new AuthenticationException();
        }

        if (!$this->evaluate($ability, $user, self::argumentList($arguments), $token)) {
            throw $message !== null
                ? new AuthorizationException($message)
                : AuthorizationException::forAbility($ability);
        }
    }

    /**
     * A per-user checker — the shape `$user->can()` delegates to and the
     * way token ceilings are applied:
     *
     *     Bouncer::forUser($user)->withToken($token)->authorize('update-post', $post);
     */
    public function forUser(AuthenticatableContract $user, ?TokenAuthorizer $token = null): BouncerChecker
    {
        return new BouncerChecker($this, $user, $token);
    }

    /**
     * The raw decision used internally and by {@see BouncerChecker}.
     *
     * @param list<mixed> $arguments
     */
    public function evaluate(string $ability, ?AuthenticatableContract $user, array $arguments, ?TokenAuthorizer $token = null): bool
    {
        // The token ceiling applies before everything else — including
        // undefined abilities — and can never substitute for the owner
        // decision below.
        if ($token !== null && $token->denies($ability)) {
            return false;
        }

        if ($ability === '') {
            return false;
        }

        if (isset($this->abilities[$ability])) {
            // Guests reach a callback only when it opts in by accepting a
            // nullable user (`fn (?User $u)`) or leaving the parameter
            // untyped — a `User $user` signature is members-only and
            // denies without running, instead of crashing on null.
            if ($user === null && !self::callbackAllowsGuests($this->abilities[$ability])) {
                return false;
            }

            // An explicit definition is authoritative over policy
            // convention: a deny only yields to a stored permission.
            if ($this->runCallback($this->abilities[$ability], $ability, $user, $arguments) === true) {
                return true;
            }
        } elseif ($this->evaluatePolicy($ability, $user, $arguments) === true) {
            return true;
        }

        // Preserved Gate semantics: a stored permission is an
        // independent grant even when a defined ability denied.
        return $user instanceof AuthorizableContract && $user->hasPermission($ability);
    }

    /**
     * @param list<mixed> $arguments
     */
    private function runCallback(Closure $callback, string $ability, ?AuthenticatableContract $user, array $arguments): mixed
    {
        try {
            return $callback($user, ...$arguments);
        } catch (Throwable $throwable) {
            if ($throwable instanceof AuthorizationException
                || $throwable instanceof AuthenticationException) {
                throw $throwable;
            }

            throw new RuntimeException(
                sprintf('Bouncer ability [%s] callback failed: %s', $ability, $throwable->getMessage()),
                previous: $throwable,
            );
        }
    }

    /**
     * Resolve a resource ability through a registered policy.
     *
     * The first object argument selects the model; `update-post` maps to
     * `PostPolicy::update()` (model-basename suffix stripped) or
     * `PostPolicy::updatePost()`. A missing policy or method returns
     * `null` — "no opinion", which fails closed downstream.
     *
     * @param list<mixed> $arguments
     */
    private function evaluatePolicy(string $ability, ?AuthenticatableContract $user, array $arguments): ?bool
    {
        // Policy methods take a non-null user — guests skip straight to
        // the permission fallback (which also cannot grant for a guest).
        if ($user === null) {
            return null;
        }

        foreach ($arguments as $argument) {
            if (!is_object($argument)) {
                continue;
            }

            $model = $argument::class;
            $definition = $this->policies[$model] ?? null;

            if ($definition === null) {
                return null;
            }

            $policy = is_string($definition) ? new $definition() : $definition;
            $method = $this->policyMethod($ability, $model, $policy);

            if ($method === null) {
                return null;
            }

            try {
                return $policy->{$method}($user, ...$arguments) === true;
            } catch (Throwable $throwable) {
                if ($throwable instanceof AuthorizationException
                    || $throwable instanceof AuthenticationException) {
                    throw $throwable;
                }

                throw new RuntimeException(
                    sprintf('Bouncer policy [%s::%s] for ability [%s] failed: %s', $policy::class, $method, $ability, $throwable->getMessage()),
                    previous: $throwable,
                );
            }
        }

        return null;
    }

    /**
     * Whether the ability callback tolerates a null user — declared by a
     * nullable first parameter or no parameter type at all.
     */
    private static function callbackAllowsGuests(Closure $callback): bool
    {
        $parameter = (new \ReflectionFunction($callback))->getParameters()[0] ?? null;
        $type = $parameter?->getType();

        // An explicit nullable type (`?User`, `User|null`) opts the
        // ability into guest evaluation; an untyped parameter is treated
        // as members-only — those callbacks assume a user and would
        // crash on `null` rather than deny cleanly.
        return $type !== null && $type->allowsNull();
    }

    /**
     * `update-post` on `Post` → `update`; `viewAdminReport` → itself.
     */
    private function policyMethod(string $ability, string $model, Policy $policy): ?string
    {
        $shortName = (new \ReflectionClass($model))->getShortName();
        $basename = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $shortName));
        $candidates = [];

        foreach (['-', '_'] as $separator) {
            $suffix = $separator.$basename;

            if (str_ends_with($ability, $suffix)) {
                $candidates[] = substr($ability, 0, -strlen($suffix));
            }
        }

        $candidates[] = lcfirst(str_replace(['-', '_'], ' ', $ability) === $ability
            ? $ability
            : str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $ability))));

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && method_exists($policy, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<mixed>
     */
    private static function argumentList(mixed $arguments): array
    {
        return is_array($arguments) ? array_values($arguments) : [$arguments];
    }
}
