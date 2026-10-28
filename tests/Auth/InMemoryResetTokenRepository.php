<?php

declare(strict_types=1);

namespace Sahil\Tests\Auth;

use Sahil\Contracts\Auth\PasswordResetTokenRepositoryContract;

/**
 * In-memory reset-token store. Records `created_at` as an integer clock
 * the test controls, so token expiry can be exercised without sleeping.
 */
final class InMemoryResetTokenRepository implements PasswordResetTokenRepositoryContract
{
    /** @var array<string, array{user_id: int|string, token_hash: string, created_at: int}> */
    private array $records = [];

    private int $now;

    public function __construct(?int $now = null)
    {
        $this->now = $now ?? time();
    }

    /** Control the clock the repository stamps records with. */
    public function setNow(int $now): void
    {
        $this->now = $now;
    }

    /** The current clock value. */
    public function now(): int
    {
        return $this->now;
    }

    public function create(int|string $userId, string $token, int $expirationMinutes): void
    {
        $this->records[sprintf('%s|%s', $userId, $token)] = [
            'user_id' => $userId,
            // Only a digest of the token is retained at rest.
            'token_hash' => hash('sha256', $token),
            'created_at' => $this->now,
        ];
    }

    public function find(int|string $userId, string $token): ?array
    {
        $record = $this->records[sprintf('%s|%s', $userId, $token)] ?? null;

        if ($record === null || !hash_equals($record['token_hash'], hash('sha256', $token))) {
            return null;
        }

        return ['created_at' => $record['created_at']];
    }

    public function isExpired(array $record): bool
    {
        return ($this->now - $record['created_at']) >= 3600;
    }

    public function delete(int|string $userId, string $token): void
    {
        unset($this->records[sprintf('%s|%s', $userId, $token)]);
    }

    public function deleteUserTokens(int|string $userId): void
    {
        foreach ($this->records as $key => $record) {
            if ($record['user_id'] === $userId) {
                unset($this->records[$key]);
            }
        }
    }

    public function deleteExpired(): void
    {
        foreach ($this->records as $key => $record) {
            if (($this->now - $record['created_at']) >= 3600) {
                unset($this->records[$key]);
            }
        }
    }
}
