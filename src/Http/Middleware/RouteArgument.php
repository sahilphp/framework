<?php

declare(strict_types=1);

namespace Sahil\Http\Middleware;

/**
 * A middleware parameter that was resolved from a route placeholder.
 *
 * `ability:update-post,{post}` declares the literal ability name plus a
 * reference to the `{post}` route parameter; the kernel replaces the
 * reference with this value object so parameterized middleware can tell
 * "argument to hand to the ability" apart from another ability name —
 * a distinction plain strings could not express.
 */
final class RouteArgument
{
    public function __construct(
        private readonly string $name,
        private readonly mixed $value,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function value(): mixed
    {
        return $this->value;
    }
}
