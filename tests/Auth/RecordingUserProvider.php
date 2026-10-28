<?php

declare(strict_types=1);

namespace Sahil\Tests\Auth;

use Sahil\Auth\Password;
use Sahil\Contracts\Auth\AuthenticatableContract;
use Sahil\Contracts\Auth\UserProviderContract;

/**
 * In-memory user provider: maps credentials to users, verifies with the
 * password API, and records rehash/update calls so tests can observe them.
 */
final class RecordingUserProvider implements UserProviderContract
{
    /** @var array<string, AuthUser> key: identifier as string */
    private array $users = [];

    /** @var array<string, int> identifier => number of recorded writes */
    private array $writes = [];

    public function add(AuthUser $user): void
    {
        $this->users[(string) $user->getAuthIdentifier()] = $user;
    }

    /** @return int number of recorded password writes for the identifier */
    public function writesFor(int|string $identifier): int
    {
        return $this->writes[(string) $identifier] ?? 0;
    }

    public function retrieveByIdentifier(int|string $identifier): ?AuthenticatableContract
    {
        return $this->users[(string) $identifier] ?? null;
    }

    public function retrieveByCredentials(array $credentials): ?AuthenticatableContract
    {
        $identifier = $credentials['email'] ?? $credentials['username'] ?? null;

        if (!is_string($identifier)) {
            return null;
        }

        foreach ($this->users as $user) {
            if ($user->get('email') === $identifier || $user->get('username') === $identifier) {
                return $user;
            }
        }

        return null;
    }

    public function validateCredentials(AuthenticatableContract $user, array $credentials): bool
    {
        $password = $credentials['password'] ?? null;

        if (!is_string($password)) {
            return false;
        }

        return Password::verify($password, $user->getAuthPassword());
    }

    public function rehashPasswordIfRequired(AuthenticatableContract $user, string $password, array $options): void
    {
        if (Password::needsRehash($user->getAuthPassword(), $options)) {
            $this->updatePassword($user, $password);
        }
    }

    public function updatePassword(AuthenticatableContract $user, string $password): void
    {
        // Replace the stored hash in place (the test double owns storage).
        $fresh = new AuthUser([
            'id' => $user->getAuthIdentifier(),
            'email' => $user->get('email'),
            'username' => $user->get('username'),
            'password' => Password::hash($password),
        ]);

        $this->users[(string) $user->getAuthIdentifier()] = $fresh;
        $this->writes[(string) $user->getAuthIdentifier()] = $this->writesFor($user->getAuthIdentifier()) + 1;
    }
}
