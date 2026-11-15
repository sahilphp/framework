<?php

declare(strict_types=1);

namespace Sahil\Auth;

/**
 * Ability ceiling for a token-authenticated principal.
 *
 * A token never grants authorization on its own: it only bounds what the
 * token's owner may do for the request it authenticates. The final
 * decision is always `$token->allows($ability) && Bouncer evaluates the
 * owner`, enforced by {@see BouncerChecker::withToken()}.
 *
 * Semantics, deliberately strict:
 *
 *  - Ability names are exact and case-sensitive.
 *  - An empty ability list denies every ability.
 *  - `*` is an opt-in wildcard: it only widens the ceiling when it is a
 *    literal list entry, and it can never bypass the owner's Bouncer
 *    decision.
 */
final class TokenAuthorizer
{
    /** @var list<string> */
    private readonly array $abilities;

    /**
     * @param list<string> $abilities abilities the token was issued with
     */
    public function __construct(array $abilities)
    {
        $normalized = [];

        foreach ($abilities as $ability) {
            if (!is_string($ability) || trim($ability) === '') {
                throw new \InvalidArgumentException(
                    'Token abilities must be non-empty strings.',
                );
            }

            $normalized[] = $ability;
        }

        $this->abilities = $normalized;
    }

    /**
     * @param list<string> $abilities
     */
    public static function fromAbilities(array $abilities): self
    {
        return new self($abilities);
    }

    /**
     * A token that may attempt any ability — the owner-side Bouncer
     * decision still applies, so this is a ceiling, not a grant.
     */
    public static function wildcard(): self
    {
        return new self(['*']);
    }

    /**
     * Whether the token's declared abilities cover `$ability`.
     *
     * `true` means "the token does not block this attempt" — the owner
     * must still be authorized.
     */
    public function allows(string $ability): bool
    {
        return in_array($ability, $this->abilities, true)
            || in_array('*', $this->abilities, true);
    }

    public function denies(string $ability): bool
    {
        return !$this->allows($ability);
    }

    /**
     * @return list<string>
     */
    public function abilities(): array
    {
        return $this->abilities;
    }
}
