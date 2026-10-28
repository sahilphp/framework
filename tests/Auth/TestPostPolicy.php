<?php

declare(strict_types=1);

namespace Sahil\Tests\Auth;

use Sahil\Auth\Policy;
use Sahil\Contracts\Auth\AuthenticatableContract;

/**
 * Owner-only policy fixture: the author may update/delete, admins may
 * delete, everyone else may only view published posts.
 */
final class TestPostPolicy extends Policy
{
    public function view(AuthenticatableContract $user, mixed $model): bool
    {
        return true;
    }

    public function create(AuthenticatableContract $user): bool
    {
        return $user->hasRole('author') || $user->hasRole('admin');
    }

    public function update(AuthenticatableContract $user, mixed $model): bool
    {
        return $model['author_id'] === $user->getAuthIdentifier();
    }

    public function delete(AuthenticatableContract $user, mixed $model): bool
    {
        return $user->hasRole('admin') || $model['author_id'] === $user->getAuthIdentifier();
    }
}
