<?php

declare(strict_types=1);

namespace Sahil\Auth;

/**
 * Gives a user model `$user->can()` / `$user->cant()` /
 * `$user->authorize()` backed by Bouncer.
 *
 *     final class User extends Model implements AuthenticatableContract
 *     {
 *         use Authorizable;
 *     }
 *
 *     $user->can('update-post', $post);
  *
 * @api — consumed by application code.
 */
trait Authorizable
{
    /**
     * @param array<array-key, mixed>|mixed $arguments
     */
    public function can(string $ability, mixed $arguments = []): bool
    {
        /** @var \Sahil\Contracts\Auth\AuthenticatableContract $this */
        // A token's ability ceiling bounds — never widens — this check.
        $token = method_exists($this, 'currentTokenAuthorizer')
            ? $this->currentTokenAuthorizer()
            : null;

        return Bouncer::forUser($this, $token)->allows($ability, $arguments);
    }

    /**
     * @param array<array-key, mixed>|mixed $arguments
     */
    public function cant(string $ability, mixed $arguments = []): bool
    {
        return !$this->can($ability, $arguments);
    }

    /**
     * @param array<array-key, mixed>|mixed $arguments
     */
    public function cannot(string $ability, mixed $arguments = []): bool
    {
        return $this->cant($ability, $arguments);
    }

    /**
     * Assert the ability for this user; denial throws a 403.
     *
     * @param array<array-key, mixed>|mixed $arguments
     */
    public function authorize(string $ability, mixed $arguments = [], ?string $message = null): void
    {
        /** @var \Sahil\Contracts\Auth\AuthenticatableContract $this */
        $token = method_exists($this, 'currentTokenAuthorizer')
            ? $this->currentTokenAuthorizer()
            : null;

        Bouncer::forUser($this, $token)->authorize($ability, $arguments, $message);
    }
}
