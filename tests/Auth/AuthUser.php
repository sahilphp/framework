<?php

declare(strict_types=1);

namespace Sahil\Tests\Auth;

use Sahil\Auth\SimpleUser;
use Sahil\Contracts\Auth\AuthenticatableContract;
use Sahil\Contracts\Auth\AuthorizableContract;
use Sahil\Contracts\Auth\MustVerifyEmailContract;

/**
 * In-memory user for auth tests: carries the hashed password plus the
 * roles, permissions and email-verification state the tests need.
 */
final class AuthUser implements AuthenticatableContract, AuthorizableContract, MustVerifyEmailContract
{
    private SimpleUser $user;

    /** @var list<string> */
    private array $roles;

    /** @var list<string> */
    private array $permissions;

    private bool $verified;

    /** @var array<string, mixed> $attributes */
    public function __construct(
        array $attributes = [],
        array $roles = [],
        array $permissions = [],
        bool $verified = true,
    ) {
        $this->user = new SimpleUser($attributes);
        $this->roles = $roles;
        $this->permissions = $permissions;
        $this->verified = $verified;
    }

    public function getAuthIdentifier(): int|string
    {
        return $this->user->getAuthIdentifier();
    }

    public function getAuthPassword(): string
    {
        return $this->user->getAuthPassword();
    }

    public function getAuthIdentifierName(): string
    {
        return $this->user->getAuthIdentifierName();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->user->get($key, $default);
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return $this->roles;
    }

    /** @return list<string> */
    public function getPermissions(): array
    {
        return $this->permissions;
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->verified;
    }

    public function markEmailAsVerified(): void
    {
        $this->verified = true;
    }

    public function sendEmailVerificationNotification(): void
    {
        // Tests assert on state, never on delivery.
    }

    public function getEmailForVerification(): string
    {
        return (string) $this->user->get('email', '');
    }
}
