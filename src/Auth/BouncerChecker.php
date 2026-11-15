<?php

declare(strict_types=1);

namespace Sahil\Auth;

use Sahil\Contracts\Auth\AuthenticatableContract;

/**
 * Per-principal checker returned by {@see Bouncer::forUser()} — the
 * object `$user->can()` delegates to.
 *
 *     Bouncer::forUser($user)->allows('update-post', $post);
 *     Bouncer::forUser($user)->withToken($token)->authorize('update-post', $post);
 *
 * A token bounds what this checker can approve; it never widens the
 * owner's Bouncer decision.
 */
final class BouncerChecker
{
    public function __construct(
        private readonly BouncerManager $bouncer,
        private readonly AuthenticatableContract $user,
        private readonly ?TokenAuthorizer $token = null,
    ) {
    }

    /**
     * Bound this checker to a token's ability ceiling.
     */
    public function withToken(TokenAuthorizer $token): self
    {
        return new self($this->bouncer, $this->user, $token);
    }

    /**
     * @param array<array-key, mixed>|mixed $arguments
     */
    public function allows(string $ability, mixed $arguments = []): bool
    {
        return $this->bouncer->allows($ability, $arguments, $this->user, $this->token);
    }

    /**
     * @param array<array-key, mixed>|mixed $arguments
     */
    public function denies(string $ability, mixed $arguments = []): bool
    {
        return !$this->allows($ability, $arguments);
    }

    /**
     * @param list<string> $abilities
     */
    public function any(array $abilities, mixed $arguments = []): bool
    {
        return $this->bouncer->any($abilities, is_array($arguments) ? array_values($arguments) : [$arguments], $this->user, $this->token);
    }

    /**
     * Assert the ability or throw (401 cannot occur — a user is present).
     *
     * @param array<array-key, mixed>|mixed $arguments
     */
    public function authorize(string $ability, mixed $arguments = [], ?string $message = null): void
    {
        $this->bouncer->authorize($ability, $arguments, $this->user, $this->token, $message);
    }

    /**
     * The token bound to this checker, if any.
     */
    public function token(): ?TokenAuthorizer
    {
        return $this->token;
    }
}
