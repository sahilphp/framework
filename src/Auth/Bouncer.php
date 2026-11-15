<?php

declare(strict_types=1);

namespace Sahil\Auth;

use Closure;
use RuntimeException;
use Sahil\Contracts\Auth\AuthenticatableContract;
use Sahil\Foundation\Application;

/**
 * Bouncer — SahilPHP's authorization authority.
 *
 * One question, one answer: is this user allowed to perform this action
 * on this resource? Abilities are defined once during application boot
 * (typically in a service provider's `boot()`) and checked anywhere:
 *
 *     Bouncer::define('update-post', fn (User $u, Post $p): bool =>
     *         $u->id === $p->user_id || $u->isAdmin());
 *
 *     Bouncer::allows('update-post', $post);      // bool, current user
 *     Bouncer::denies('update-post', $post);      // !allows()
 *     Bouncer::authorize('update-post', $post);   // throws 401/403
 *
 * The static API delegates to the container-bound
 * {@see BouncerManager}; outside an application (unit tests) a manager
 * may be installed directly with {@see swap()}. No authorization
 * decision is ever cached — every check re-resolves the current
 * request's user, so consecutive worker requests cannot leak state.
 */
final class Bouncer
{
    private static ?BouncerManager $instance = null;

    private function __construct()
    {
    }

    /**
     * Register an ability. Throws if the name is already defined —
     * call {@see forget()} first for a deliberate replacement.
     *
     * @param Closure(AuthenticatableContract|null, mixed...): bool $callback
     */
    public static function define(string $ability, Closure $callback): void
    {
        self::manager()->define($ability, $callback);
    }

    public static function has(string $ability): bool
    {
        return self::manager()->has($ability);
    }

    public static function forget(string $ability): void
    {
        self::manager()->forget($ability);
    }

    /**
     * @return list<string>
     */
    public static function abilities(): array
    {
        return self::manager()->abilities();
    }

    /**
     * @param class-string                $model
     * @param class-string<Policy>|Policy $policy
     */
    public static function policy(string $model, string|Policy $policy): void
    {
        self::manager()->policy($model, $policy);
    }

    /**
     * Whether the current request's user may perform the ability.
     *
     * @param array<array-key, mixed>|mixed $arguments resource arguments
     */
    public static function allows(string $ability, mixed $arguments = []): bool
    {
        return self::manager()->allows($ability, $arguments);
    }

    /**
     * @param array<array-key, mixed>|mixed $arguments
     */
    public static function denies(string $ability, mixed $arguments = []): bool
    {
        return self::manager()->denies($ability, $arguments);
    }

    /**
     * Assert the ability for the current request's user:
     * unauthenticated → 401, denied → 403.
     *
     * @param array<array-key, mixed>|mixed $arguments
     */
    public static function authorize(string $ability, mixed $arguments = [], ?string $message = null): void
    {
        self::manager()->authorize($ability, $arguments, message: $message);
    }

    /**
     * Check against an explicit principal — what `$user->can()` uses
     * (via the {@see Authorizable} trait) and where token ceilings
     * attach:
     *
     *     Bouncer::forUser($user)->withToken($token)->authorize('update-post', $post);
     */
    public static function forUser(AuthenticatableContract $user, ?TokenAuthorizer $token = null): BouncerChecker
    {
        return self::manager()->forUser($user, $token);
    }

    /**
     * The backing manager — mostly for providers binding it and tests
     * resetting it.
     */
    public static function manager(): BouncerManager
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $application = Application::peek();

        if ($application === null) {
            throw new RuntimeException(
                'Bouncer needs a configured application or a manager installed via Bouncer::swap().',
            );
        }

        /** @var BouncerManager $manager */
        $manager = $application->container()->make(BouncerManager::class);

        return $manager;
    }

    /**
     * Install the manager the static API delegates to — tests and
     * non-application contexts.
     */
    public static function swap(?BouncerManager $manager): void
    {
        self::$instance = $manager;
    }

    /**
     * Drop the swapped instance (and nothing else — the container's own
     * manager keeps its definitions until the app rebuilds it).
     */
    public static function flushState(): void
    {
        self::$instance = null;
    }
}
