<?php

declare(strict_types=1);

namespace Sahil\Auth\Tokens;

use DateTimeInterface;
use Sahil\Auth\TokenAuthorizer;
use Sahil\Orm\OrmResult;

/**
 * Gives an Authenticatable model first-party API tokens.
 *
 *     $new = $user->createToken('mobile', ['posts:read']);
 *     $new->plainTextToken;            // shown once, never stored
 *     $user->tokens();                 // management listing
 *     $user->revokeToken($tokenId);    // owner-scoped
 *     $user->tokenCan('posts:read');   // ability ceiling on THIS request's token
 */
trait HasApiTokens
{
    private ?PersonalAccessToken $accessToken = null;

    /**
     * @param list<string> $abilities `['*']` = unrestricted ceiling
     *        (still bounded by Bouncer), `[]` = nothing allowed
     */
    public function createToken(
        string $name,
        array $abilities = ['*'],
        ?DateTimeInterface $expiresAt = null,
    ): NewAccessToken {
        $name = trim($name);

        if ($name === '' || strlen($name) > 255) {
            throw new \InvalidArgumentException('Token name must be 1–255 characters.');
        }

        // Validates ability names up-front (non-empty strings).
        new TokenAuthorizer($abilities);

        if ($expiresAt !== null && $expiresAt <= new \DateTimeImmutable()) {
            throw new \InvalidArgumentException('Token expiration must be in the future.');
        }

        $tokenId = bin2hex(random_bytes(12));            // public lookup id
        $secret = bin2hex(random_bytes(32));             // 256-bit secret
        $now = new \DateTimeImmutable();

        /** @var PersonalAccessToken $record */
        $record = PersonalAccessToken::create([
            'tokenable_type' => static::class,
            'tokenable_id' => $this->getAuthIdentifier(),
            'name' => $name,
            'token_id' => $tokenId,
            'token_hash' => hash('sha256', $secret),
            'abilities' => json_encode(array_values($abilities)),
            'last_used_at' => null,
            'expires_at' => $expiresAt?->format('Y-m-d H:i:s'),
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);

        return new NewAccessToken($record, $tokenId.'.'.$secret);
    }

    /** Management listing — records serialize without secrets or hashes. */
    public function tokens(): OrmResult
    {
        return PersonalAccessToken::forOwner($this);
    }

    /** Find one OWNED token by public id; never another user's. */
    public function findToken(string $tokenId): ?PersonalAccessToken
    {
        $token = PersonalAccessToken::findByTokenId($tokenId);

        return $token !== null && $this->ownsToken($token) ? $token : null;
    }

    /** Owner-scoped revoke: an id belonging to another user is a no-op. */
    public function revokeToken(string $tokenId): bool
    {
        $token = $this->findToken($tokenId);

        if ($token === null) {
            return false;
        }

        $token->revoke();

        return true;
    }

    public function revokeAllTokens(): int
    {
        $count = 0;

        foreach ($this->tokens() as $token) {
            /** @var PersonalAccessToken $token */
            $token->revoke();
            $count++;
        }

        return $count;
    }

    /* ---------- request-time token context ---------- */

    /** Attach the token that authenticated this request. */
    public function withAccessToken(PersonalAccessToken $token): static
    {
        $this->accessToken = $token;

        return $this;
    }

    public function currentAccessToken(): ?PersonalAccessToken
    {
        return $this->accessToken;
    }

    /**
     * Is the ability inside THIS request's token ceiling? Session users
     * have no token — false (fail closed).
     */
    public function tokenCan(string $ability): bool
    {
        return $this->accessToken?->allows($ability) ?? false;
    }

    /** The Bouncer ceiling for the current request's token, if any. */
    public function currentTokenAuthorizer(): ?TokenAuthorizer
    {
        return $this->accessToken?->authorizer();
    }

    private function ownsToken(PersonalAccessToken $token): bool
    {
        return $token->getAttribute('tokenable_type') === static::class
            && (string) $token->getAttribute('tokenable_id') === (string) $this->getAuthIdentifier();
    }
}
