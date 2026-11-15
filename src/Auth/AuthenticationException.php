<?php

declare(strict_types=1);

namespace Sahil\Auth;

use Sahil\Http\Exception\HttpException;
use Throwable;

/**
 * An authorization check ran without an authenticated principal → HTTP 401.
 *
 * Distinct from {@see AuthorizationException}: 403 means "we know who you
 * are and the answer is no"; 401 means "authenticate first".
 */
final class AuthenticationException extends HttpException
{
    public function __construct(
        string $message = 'Authentication required.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(401, $message, previous: $previous);
    }
}
