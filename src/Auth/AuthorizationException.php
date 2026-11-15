<?php

declare(strict_types=1);

namespace Sahil\Auth;

use Sahil\Http\Exception\HttpException;
use Throwable;

/**
 * An authenticated principal failed an authorization check → HTTP 403.
 *
 * The message is safe to show to clients: it describes the denial, never
 * the callback or data that produced it. The kernel renders it through
 * the ordinary HTTP-exception path, so HTML and JSON clients each get
 * the appropriate 403 response.
 */
final class AuthorizationException extends HttpException
{
    public function __construct(
        string $message = 'This action is unauthorized.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(403, $message, previous: $previous);
    }

    public static function forAbility(string $ability, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('This action is unauthorized. Ability [%s] was denied.', $ability),
            $previous,
        );
    }
}
