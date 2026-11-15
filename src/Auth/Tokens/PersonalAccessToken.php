<?php

declare(strict_types=1);

namespace Sahil\Auth\Tokens;

use DateTimeImmutable;
use DateTimeInterface;
use Sahil\Auth\TokenAuthorizer;
use Sahil\Contracts\Auth\AuthenticatableContract;
use Sahil\Orm\Model;
use Sahil\Orm\OrmResult;

/**
 * A first-party API token record.
 *
 * Security model:
 *  - The plaintext secret is returned ONCE by `createToken()` and is never
 *    persisted — only `hash('sha256', $secret)` is stored.
 *  - `token_id` is a public lookup identifier, not a credential; the wire
 *    format is `{token_id}.{secret}`.
 *  - Revocation deletes the row — a revoked token can never re-authenticate.
 *  - `token_hash` is in `$hidden` so accidental serialization never leaks it.
 */
final class PersonalAccessToken extends Model
{
    protected ?string $table = 'personal_access_tokens';

    protected array $fillable = [
        'tokenable_type', 'tokenable_id', 'name', 'token_id', 'token_hash',
        'abilities', 'last_used_at', 'expires_at', 'created_at', 'updated_at',
    ];

    protected array $hidden = ['token_hash'];

    /** @param array<string, mixed> $attributes */
    public function __construct(array $attributes = [], bool $exists = false)
    {
        if ($exists) {
            foreach ($attributes as $key => $value) {
                $this->setRawAttribute((string) $key, $value, false);
            }

            return;
        }

        parent::__construct($attributes);
    }

    /* ---------- lookup ---------- */

    public static function findByTokenId(string $tokenId): ?self
    {
        /** @var self|null */
        return static::query()->where('token_id', '=', $tokenId)->first();
    }

    /** Tokens owned by one authenticatable. */
    public static function forOwner(AuthenticatableContract $owner): OrmResult
    {
        return static::query()
            ->where('tokenable_type', '=', $owner::class)
            ->where('tokenable_id', '=', $owner->getAuthIdentifier())
            ->orderBy('id', 'asc')
            ->get();
    }

    /** Hard-delete every expired token. Returns the count removed. */
    public static function pruneExpired(?DateTimeInterface $now = null): int
    {
        $now ??= new DateTimeImmutable();
        $count = 0;

        foreach (static::query()->get() as $token) {
            /** @var self $token */
            if ($token->isExpired($now)) {
                $token->delete();
                $count++;
            }
        }

        return $count;
    }

    /* ---------- accessors ---------- */

    /** The public lookup id embedded in the wire token — safe to expose. */
    public function publicId(): string
    {
        return (string) $this->getAttribute('token_id');
    }

    /** @return list<string> */
    public function abilities(): array
    {
        $raw = $this->getAttribute('abilities');
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_string'));
    }

    public function name(): string
    {
        return (string) $this->getAttribute('name');
    }

    public function isExpired(?DateTimeInterface $now = null): bool
    {
        $expires = $this->getAttribute('expires_at');

        if ($expires === null) {
            return false;
        }

        $now ??= new DateTimeImmutable();
        $expiry = $expires instanceof DateTimeInterface
            ? $expires
            : new DateTimeImmutable((string) $expires);

        return $expiry <= $now;
    }

    public function lastUsedAt(): ?DateTimeImmutable
    {
        $raw = $this->getAttribute('last_used_at');

        return $raw === null ? null : new DateTimeImmutable((string) $raw);
    }

    /** Does this token's ability ceiling include the ability? */
    public function allows(string $ability): bool
    {
        return $this->authorizer()->allows($ability);
    }

    public function authorizer(): TokenAuthorizer
    {
        try {
            return new TokenAuthorizer($this->abilities());
        } catch (\InvalidArgumentException) {
            // Corrupt stored abilities fail closed.
            return new TokenAuthorizer([]);
        }
    }

    /** Resolve the owning user; null when the class or record is gone. */
    public function resolveTokenable(): ?AuthenticatableContract
    {
        $type = $this->getAttribute('tokenable_type');
        $id = $this->getAttribute('tokenable_id');

        if (!is_string($type) || !class_exists($type) || !is_subclass_of($type, Model::class)) {
            return null;
        }

        $user = $type::find($id);

        return $user instanceof AuthenticatableContract ? $user : null;
    }

    /**
     * Record use — throttled so a burst of requests costs at most one
     * write per {@see self::LAST_USED_THROTTLE_SECONDS} per token.
     */
    public const LAST_USED_THROTTLE_SECONDS = 60;

    public function touchLastUsed(?DateTimeInterface $now = null): void
    {
        $now ??= new DateTimeImmutable();
        $last = $this->lastUsedAt();

        if ($last !== null && ($now->getTimestamp() - $last->getTimestamp()) < self::LAST_USED_THROTTLE_SECONDS) {
            return;
        }

        static::query()
            ->where('id', '=', $this->getAttribute('id'))
            ->update(['last_used_at' => $now->format('Y-m-d H:i:s')]);
    }

    /** Revoke = delete: the token can never re-authenticate. */
    public function revoke(): void
    {
        $this->delete();
    }

    /** Token-management serialization — never secrets or hashes. */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->publicId(),
            'name' => $this->name(),
            'abilities' => $this->abilities(),
            'last_used_at' => $this->getAttribute('last_used_at'),
            'expires_at' => $this->getAttribute('expires_at'),
            'created_at' => $this->getAttribute('created_at'),
        ];
    }
}
