<?php

declare(strict_types=1);

namespace Sahil\Validation;

use ArrayAccess;
use Countable;
use JsonSerializable;

/**
 * The registry of named error bags shared with views.
 *
 * Templates reach it as `errors` — `errors.name`, `errors.first('email')` —
 * while named bags are picked with the `error()` helper:
 * `error('email', 'register')`.
 *
 * Unprefixed access always talks to the default bag, so a page with one
 * form never has to name it; pages with several forms pick a bag by name.
 *
 * @implements ArrayAccess<string, ?string>
 */
final class ViewErrorBag implements ArrayAccess, Countable, JsonSerializable
{
    public const DEFAULT_BAG = 'default';

    /** @var array<string, ErrorBag> */
    private array $bags = [];

    /** @param array<string, array<string, list<string>>|ErrorBag> $bags */
    public function __construct(array $bags = [])
    {
        foreach ($bags as $name => $bag) {
            $this->bags[(string) $name] = $bag instanceof ErrorBag ? $bag : new ErrorBag($bag);
        }
    }

    /**
     * A bag by name; the default bag when the name is null. Missing bags
     * resolve to an empty bag — templates never need an isset guard.
     */
    public function bag(?string $name = null): ErrorBag
    {
        return $this->bags[$name ?? self::DEFAULT_BAG] ?? new ErrorBag();
    }

    /**
     * Every bag name that currently holds errors.
     *
     * @return list<string>
     */
    public function bags(): array
    {
        return array_keys(array_filter($this->bags, static fn (ErrorBag $b): bool => $b->any()));
    }

    /* ---------- default-bag delegation ---------- */

    /** @return list<string> */
    public function get(string $field): array
    {
        return $this->bag()->get($field);
    }

    public function first(string $field): ?string
    {
        return $this->bag()->first($field);
    }

    public function has(string $field): bool
    {
        return $this->bag()->has($field);
    }

    /**
     * Any error in ANY bag — a page-level "something failed" check.
     */
    public function any(): bool
    {
        foreach ($this->bags as $bag) {
            if ($bag->any()) {
                return true;
            }
        }

        return false;
    }

    /* ---------- array access (default bag) ---------- */

    public function offsetExists(mixed $offset): bool
    {
        return $this->bag()->offsetExists($offset);
    }

    public function offsetGet(mixed $offset): ?string
    {
        return $this->bag()->offsetGet($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('Error bags are immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('Error bags are immutable.');
    }

    public function count(): int
    {
        return count($this->bags);
    }

    /** @return array<string, array<string, list<string>>> */
    public function jsonSerialize(): array
    {
        return array_map(static fn (ErrorBag $b): array => $b->all(), $this->bags);
    }
}
