<?php

declare(strict_types=1);

namespace Sahil\RateLimit;

/**
 * The outcome of recording one hit against a limit.
 *
 * `remaining` never goes below zero and `resetAt` is the unix timestamp
 * the window ends — the middleware maps these straight onto the
 * `X-RateLimit-*` and `Retry-After` headers.
 */
final class Decision
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $limit,
        public readonly int $remaining,
        public readonly int $retryAfter,
        public readonly int $resetAt,
    ) {
    }

    public function denied(): bool
    {
        return !$this->allowed;
    }
}
