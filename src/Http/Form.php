<?php

declare(strict_types=1);

namespace Sahil\Http;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Sahil\Validation\ValidationException;
use Sahil\Validation\Validator;
use Traversable;

/**
 * The request's form body — sanitized string-keyed, string-valued input.
 *
 * Behaves like the array it replaces: `isset($form['email'])`,
 * `$form['name']`, `foreach`, `[...$form, 'x' => 'y']`, `count()` and
 * `json_encode()` all keep working. On top of that it carries the native
 * validation entry point:
 *
 *     $data = $request->form()->validate([
 *         'email'    => 'required|email',
 *         'password' => 'required|string|password|confirmed',
 *     ], bag: 'login');
 *
 * `validate()` throws {@see ValidationException} on failure; the HTTP
 * kernel turns it into a redirect back with flashed errors + old input
 * for browsers, or a 422 JSON payload for API clients. On success it
 * returns ONLY the validated fields.
 *
 * @implements ArrayAccess<string, string>
 * @implements IteratorAggregate<string, string>
 */
final class Form implements ArrayAccess, IteratorAggregate, Countable, JsonSerializable
{
    /** @param array<string, string> $data */
    public function __construct(private readonly array $data)
    {
    }

    /**
     * The full sanitized map, as a plain array.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Fields listed in `$keys` only.
     *
     * @param list<string> $keys
     *
     * @return array<string, string>
     */
    public function only(array $keys): array
    {
        return array_intersect_key($this->data, array_flip($keys));
    }

    /**
     * All fields except those listed in `$keys`.
     *
     * @param list<string> $keys
     *
     * @return array<string, string>
     */
    public function except(array $keys): array
    {
        return array_diff_key($this->data, array_flip($keys));
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * Validate the form fields and return the validated data.
     *
     * @param array<string, string|array<int, mixed>> $rules
     * @param array<string, string> $messages custom messages
     *     (`field.rule` → `field` → bare `rule`)
     * @param string|null $bag error bag the failures flash into; null
     *     uses the default bag
     * @param array<int, string>|string $normalize opt-in input
     *     normalization (`trim`)
     *
     * @return array<string, mixed>
     */
    public function validate(
        array $rules,
        array $messages = [],
        ?string $bag = null,
        array|string $normalize = [],
    ): array {
        $validator = new Validator($rules, $messages);

        if ($normalize !== []) {
            $validator = $validator->normalize($normalize);
        }

        $result = $validator->validate($this->data);

        if ($result->fails()) {
            throw ValidationException::forResult($result, $bag, $this->data);
        }

        return $result->data();
    }

    /* ---------- array compatibility ---------- */

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet(mixed $offset): ?string
    {
        return $this->data[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('Form input is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('Form input is immutable.');
    }

    /** @return Traversable<string, string> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->data);
    }

    public function count(): int
    {
        return count($this->data);
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return $this->data;
    }
}
