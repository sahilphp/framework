<?php

declare(strict_types=1);

namespace Sahil\Auth\Tokens;

use Sahil\Contracts\Auth\AuthenticatableContract;
use Sahil\Http\Request;

/**
 * Bearer-token request authenticator for `Authorization: Bearer {id}.{secret}`.
 *
 * Every failure path returns null — the guard never reveals whether the
 * id existed, the secret mismatched, or the token expired. A miss NEVER
 * falls back to a previously authenticated user.
 */
final class TokenGuard
{
    /**
     * Resolve the bearer principal for this request, or null.
     * On success the user carries the token (`withAccessToken`) so
     * `tokenCan()` and the Bouncer ceiling see it.
     */
    public function authenticate(Request $request): ?AuthenticatableContract
    {
        $header = $request->header('Authorization');

        if (!is_string($header) || !str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $plain = trim(substr($header, 7));
        $dot = strrpos($plain, '.');

        if ($dot === false) {
            return null;
        }

        $tokenId = substr($plain, 0, $dot);
        $secret = substr($plain, $dot + 1);

        if ($tokenId === '' || $secret === '' || !ctype_xdigit($tokenId)) {
            return null;
        }

        $token = PersonalAccessToken::findByTokenId($tokenId);

        if ($token === null) {
            return null;
        }

        // Constant-time comparison of the stored digest.
        $hash = $token->getAttribute('token_hash');

        if (!is_string($hash) || !hash_equals($hash, hash('sha256', $secret))) {
            return null;
        }

        if ($token->isExpired()) {
            return null;
        }

        $user = $token->resolveTokenable();

        if ($user === null) {
            return null;
        }

        if (method_exists($user, 'withAccessToken')) {
            $user->withAccessToken($token);
        }

        $token->touchLastUsed();

        return $user;
    }
}
