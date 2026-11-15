<?php

declare(strict_types=1);

namespace Sahil\Http\Middleware;

use Closure;
use Sahil\Auth\AuthManager;
use Sahil\Auth\AuthorizationException;
use Sahil\Http\Request;
use Sahil\Http\Response;

/**
 * `token:{ability...}` — route-level API-token ability check.
 *
 * This is the token CEILING check, not application authorization: it
 * never substitutes for Bouncer. Every named ability must be granted by
 * the request's token or the request is denied. Session users have no
 * token → fail closed.
 */
final class RequireTokenAbility implements MiddlewareContract, ParameterizedMiddlewareContract
{
    public function __construct(
        private readonly AuthManager $auth,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        throw new \RuntimeException(
            'The token middleware requires at least one ability: token:{ability}.',
        );
    }

    public function handleWithParameters(Request $request, Closure $next, array $parameters): Response
    {
        if ($parameters === []) {
            throw new \RuntimeException(
                'The token middleware requires at least one ability: token:{ability}.',
            );
        }

        $user = $this->auth->user();

        if ($user === null) {
            return Response::json([
                'error' => 'unauthenticated',
                'message' => 'Authentication required.',
            ], 401);
        }

        foreach ($parameters as $ability) {
            if (!is_string($ability) || trim($ability) === '') {
                throw new \RuntimeException('token: abilities must be non-empty strings.');
            }

            // No tokenCan() → the user can't carry a token → fail closed.
            if (!method_exists($user, 'tokenCan') || !$user->tokenCan($ability)) {
                throw new AuthorizationException('This token does not have the required ability.');
            }
        }

        return $next($request);
    }
}
