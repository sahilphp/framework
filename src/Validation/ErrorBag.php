<?php

declare(strict_types=1);

namespace Sahil\Validation;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * One named collection of validation errors: `field => [message, ...]`.
 *
 * Implements {@see ArrayAccess} so templates can read the first message of
 * a field directly — `{{ errors.name }}` resolves through
 * `offsetGet('name')` to `first('name')`.
 *
 * @implements ArrayAccess<string, ?string>
 * @implements IteratorAggregate<string, list<string>>
 */
final class ErrorBag implements ArrayAccess, IteratorAggregate, Countable, JsonSerializable
{
    /** @param array<string, list<string>> $errors */
    public function __construct(private readonly array $errors = [])
    {
    }

    /**
     * All messages for a field.
     *
     * @return list<string>
     */
    public function get(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    /**
     * The first message for a field, or null.
     */
    public function first(string $field): ?string
    {
        return $this->errors[$field][0] ?? null;
    }

    public function has(string $field): bool
    {
        return isset($this->errors[$field]);
    }

    public function any(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Every field with at least one error.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->errors);
    }

    /**
     * The raw `field => [messages]` map.
     *
     * @return array<string, list<string>>
     */
    public function all(): array
    {
        return $this->errors;
    }

    /**
     * Merge another bag (or a plain errors map) on top of this one.
     *
     * @param self|array<string, list<string>> $other
     */
    public function merge(self|array $other): self
    {
        $incoming = $other instanceof self ? $other->errors : $other;

        return new self(array_replace($this->errors, $incoming));
    }

    /* ---------- array access ---------- */

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->errors[$offset]);
    }

    /** First-message access keeps `{{ errors.field }}` a string. */
    public function offsetGet(mixed $offset): ?string
    {
        return $this->first((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('Error bags are immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('Error bags are immutable.');
    }

    /** @return Traversable<string, list<string>> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->errors);
    }

    public function count(): int
    {
        return count($this->errors);
    }

    /** @return array<string, list<string>> */
    public function jsonSerialize(): array
    {
        return $this->errors;
    }
}
