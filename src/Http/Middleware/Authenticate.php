<?php

declare(strict_types=1);

namespace Sahil\Http\Middleware;

use Closure;
use Sahil\Auth\AuthManager;
use Sahil\Auth\Tokens\TokenGuard;
use Sahil\Http\Request;
use Sahil\Http\Response;

/**
 * Authentication middleware.
 *
 * Requires the user to be authenticated. Redirects to login or returns
 * a 401 response for unauthenticated requests.
 */
final class Authenticate implements MiddlewareContract, ParameterizedMiddlewareContract
{
    public function __construct(
        private readonly AuthManager $auth,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->auth->guest()) {
            return $this->unauthenticated($request);
        }

        return $next($request);
    }

    /**
     * `auth:{guard}` route middleware. `api` authenticates the bearer
     * token and attaches the principal to THIS request only — it never
     * writes a session and never redirects.
     */
    public function handleWithParameters(Request $request, Closure $next, array $parameters): Response
    {
        $guard = $parameters[0] ?? null;

        if ($guard === 'api') {
            $user = (new TokenGuard())->authenticate($request);

            if ($user === null) {
                return Response::json([
                    'error' => 'unauthenticated',
                    'message' => 'Authentication required.',
                ], 401);
            }

            $this->auth->viaToken($user);

            return $next($request);
        }

        if ($guard === null) {
            return $this->handle($request, $next);
        }

        throw new \RuntimeException(sprintf(
            'Unknown auth guard [%s]; supported: api.',
            is_string($guard) ? $guard : get_debug_type($guard),
        ));
    }

    /**
     * Handle unauthenticated requests.
     */
    private function unauthenticated(Request $request): Response
    {
        // Check if the request expects JSON (via Accept header)
        $accept = $request->header('Accept');
        $expectsJson = $accept !== null && str_contains($accept, 'application/json');

        if ($expectsJson) {
            return Response::json([
                'error' => 'unauthenticated',
                'message' => 'Authentication required.',
            ], 401);
        }

        // For web requests, return a 401 response
        // Applications can extend this middleware to redirect to login
        return Response::json([
            'error' => 'unauthenticated',
            'message' => 'Authentication required.',
        ], 401);
    }
}
