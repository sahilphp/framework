<?php

declare(strict_types=1);

namespace Sahil\Routing;

use InvalidArgumentException;

/**
 * Normalized API version identifier — a tiny immutable value used to tag
 * versioned routes (see {@see Link::version()}).
 *
 * Identifiers are explicit and stable, not semantic versions: `v1`, `v2`,
 * `v2.5` are valid; `1`/`V2` normalize to `v1`/`v2`. Anything else is
 * rejected at registration time rather than silently producing odd paths.
 */
final class Version
{
    private function __construct(private readonly string $id)
    {
    }

    /**
     * Normalize and validate an application-supplied identifier.
     *
     * @throws InvalidArgumentException on empty or malformed identifiers
     */
    public static function of(string $id): self
    {
        return new self(self::normalize($id));
    }

    /**
     * `'1'`/`'V1'`/`'/v1/'` → `'v1'`, `'2.5'` → `'v2.5'`.
     *
     * @throws InvalidArgumentException
     */
    public static function normalize(string $id): string
    {
        $id = strtolower(trim(trim($id), '/'));

        if (str_starts_with($id, 'v')) {
            $id = substr($id, 1);
        }

        if (preg_match('/^\d+(\.\d+)*$/', $id) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Routing: API version must look like "v1" or "v2.5", got [%s].',
                $id === '' ? '(empty)' : $id,
            ));
        }

        return 'v'.$id;
    }

    public static function isValid(string $id): bool
    {
        try {
            self::normalize($id);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /** The normalized identifier, e.g. `v1`. */
    public function id(): string
    {
        return $this->id;
    }

    public function equals(Version|string $other): bool
    {
        return $this->id === ($other instanceof Version ? $other->id : self::normalize($other));
    }

    public function __toString(): string
    {
        return $this->id;
    }
}
