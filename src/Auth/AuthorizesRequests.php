<?php

declare(strict_types=1);

namespace Sahil\Auth;

use Sahil\Contracts\Auth\AuthenticatableContract;

/**
 * Controller-side authorization shortcut:
 *
 *     final class PostController
 *     {
 *         use AuthorizesRequests;
 *
 *         public function update(Request $request): Response
 *         {
 *             $post = ...;
 *             $this->authorize('update-post', $post);
 *         }
 *     }
 *
 * Guests get 401, denied users get 403 — both rendered by the kernel's
 * ordinary HTTP-exception path.
  *
 * @api — consumed by application code.
 */
trait AuthorizesRequests
{
    /**
     * @param array<array-key, mixed>|mixed $arguments
     */
    protected function authorize(string $ability, mixed $arguments = [], ?string $message = null): void
    {
        Bouncer::authorize($ability, $arguments, $message);
    }

    /**
     * @param array<array-key, mixed>|mixed $arguments
     */
    protected function authorizeForUser(AuthenticatableContract $user, string $ability, mixed $arguments = [], ?string $message = null): void
    {
        Bouncer::forUser($user)->authorize($ability, $arguments, $message);
    }
}
