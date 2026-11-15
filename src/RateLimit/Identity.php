<?php

declare(strict_types=1);

namespace Sahil\RateLimit;

use Closure;
use Sahil\Contracts\Auth\AuthenticatableContract;
use Sahil\Http\Request;

/**
 * Reusable identity resolvers for `Limit::by()`.
 *
 * Each factory returns a `Closure(Request, ?AuthenticatableContract):
 * string|int` the manager invokes per request. Identity is always
 * resolved against the *current* request — resolvers must never read
 * stale state, which is why they receive the request explicitly.
 *
 *     Limit::perMinute(5)->by(Identity::ip());
 *     Limit::perMinute(5)->by(Identity::userOrIp());
 *     Limit::perMinute(5)->by(Identity::userOrIp(fn ($u) => $u->email));
 */
final class Identity
{
    private function __construct()
    {
    }

    /** Immediate client IP (`REMOTE_ADDR`; forwarded headers only via the trusted-proxy config). */
    public static function ip(): Closure
    {
        return static fn (Request $request, ?AuthenticatableContract $user): string => $request->clientIp() ?? 'unknown';
    }

    /**
     * Authenticated user identifier; guests fall back to the client IP.
     * An optional mapper reshapes the identifier (e.g. normalized email).
     */
    public static function userOrIp(?Closure $userKey = null): Closure
    {
        return static function (Request $request, ?AuthenticatableContract $user) use ($userKey) {
            if ($user === null) {
                return $request->clientIp() ?? 'unknown';
            }

            $id = $userKey === null
                ? $user->getAuthIdentifier()
                : $userKey($user);

            return 'u:'.(is_scalar($id) ? (string) $id : 'unresolvable');
        };
    }

    /**
     * Authenticated API-token identity (`tok:{publicId}`) — each of a
     * user's tokens is limited independently and raw bearer secrets can
     * never become limit keys. Session users fall back to `u:{id}`,
     * guests to the client IP.
     */
    public static function tokenOrIp(): Closure
    {
        return static function (Request $request, ?AuthenticatableContract $user): string {
            if ($user === null) {
                return $request->clientIp() ?? 'unknown';
            }

            if (method_exists($user, 'currentAccessToken')) {
                $token = $user->currentAccessToken();

                if ($token !== null) {
                    return 'tok:'.$token->publicId();
                }
            }

            return 'u:'.(string) $user->getAuthIdentifier();
        };
    }

    /**
     * Account-aware identity for credential endpoints: a normalized
     * account key (e.g. the submitted email) combined with the client IP,
     * so one attacker's guesses can't lock out the real account holder
     * elsewhere — and one botnet can't concentrate attempts on an account
     * through a single source.
     */
    public static function accountAndIp(Closure $accountKey): Closure
    {
        return static function (Request $request, ?AuthenticatableContract $user) use ($accountKey): string {
            $account = strtolower(trim((string) $accountKey($request, $user)));

            return hash('xxh128', $account.'|'.($request->clientIp() ?? 'unknown'));
        };
    }
}
