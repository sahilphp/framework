<?php

declare(strict_types=1);

namespace Sahil\Http\Resources;

use ArrayAccess;
use DateTimeInterface;
use JsonSerializable;
use Sahil\Http\Pagination\Paginator;
use Sahil\Http\Response;
use Sahil\Orm\PageResult;
use Traversable;

/**
 * Base API resource — the explicit boundary between application data and
 * the wire.
 *
 *     final class UserResource extends Resource
 *     {
 *         public function toArray(): array
 *         {
 *             return [
 *                 'id'    => $this->id,
 *                 'name'  => $this->name,
 *                 'email' => $this->when($this->canSeeEmail(), $this->email),
 *             ];
 *         }
 *     }
 *
 *     return UserResource::make($user)->toResponse();
 *
 * Only what `toArray()` names is ever serialized — source properties are
 * read, never enumerated, so nothing sensitive can leak by accident.
 *
 * Sources: arrays, {@see ArrayAccess}, plain objects (public properties
 * and `getAttribute()`, which ORM models implement). A `null` source
 * serializes to JSON `null`, which is how null relationships read.
 */
abstract class Resource implements JsonSerializable
{
    /** @var array<string, mixed>|object|null */
    protected mixed $resource;

    /** @var array<string, mixed> extra top-level keys for the response body */
    private array $meta = [];

    /** @var list<string>|null top-level key allowlist for `only()` */
    private ?array $only = null;

    /**
     * @param array<string, mixed>|object|null $resource
     */
    final public function __construct(mixed $resource)
    {
        $this->resource = $resource;
    }

    /**
     * OPTIONAL static wire-schema declaration used by OpenAPI generation.
     * `toArray()` is code and is never executed during docs generation;
     * resources that document themselves override this and return a JSON
     * Schema. `null` (the default) emits a generic object + a diagnostic.
     *
     * @return array<string, mixed>|null
     */
    public static function apiSchema(): ?array
    {
        return null;
    }

    /**
     * The API representation. Keys whose value is {@see MissingValue} are
     * dropped; `Resource` and `ResourceCollection` values serialize nested.
     *
     * @return array<string, mixed>|scalar|null
     */
    abstract public function toArray(): mixed;

    /**
     * @param array<string, mixed>|object|null $resource
     */
    public static function make(mixed $resource): static
    {
        return new static($resource);
    }

    /**
     * Wrap an iterable of records, one resource per item.
     *
     * @param iterable<array<string, mixed>|object> $items
     */
    public static function collection(iterable $items): ResourceCollection
    {
        return new ResourceCollection(static::class, $items);
    }

    /**
     * Restrict the serialized top-level keys — e.g. for a `?fields=`
     * selection intersected with the resource's own allowlist. This only
     * ever NARROWS `toArray()` output; it can never expose more.
     *
     * @param list<string>|null $fields null restores the full shape
     */
    public function only(?array $fields): static
    {
        $clone = clone $this;
        $clone->only = $fields === null ? null : array_values($fields);

        return $clone;
    }

    /**
     * Serialize. A null source is JSON null; an array-shaped `toArray()`
     * result is resolved recursively (dropping MissingValue keys).
     */
    public function jsonSerialize(): mixed
    {
        if ($this->resource === null) {
            return null;
        }

        $resolved = self::resolveValue($this->toArray());

        if ($this->only !== null && is_array($resolved)) {
            $resolved = array_intersect_key($resolved, array_flip($this->only));
        }

        return $resolved;
    }

    /**
     * Render as a {@see Response}: `{data: ...}` when meta was attached,
     * or the bare representation otherwise.
     */
    public function toResponse(int $status = 200, array $headers = []): Response
    {
        $body = $this->meta === []
            ? $this
            : ['data' => $this, ...$this->meta];

        $response = Response::json($body, $status);

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * Attach top-level response keys alongside `data` — e.g.
     * `->withMeta(['meta' => Paginator::...->toArray()])` or
     * `->withMeta(['links' => [...]])`.
     *
     * @param array<string, mixed> $meta
     */
    public function withMeta(array $meta): static
    {
        $clone = clone $this;
        $clone->meta = [...$this->meta, ...$meta];

        return $clone;
    }

    /**
     * Convenience for a paginated response: transforms the page's items
     * and attaches the existing {@see Paginator} metadata under `meta`.
     *
     *     UserResource::collection($page->result)->paginate($page)
     *
     * @return ResourceCollection
     */
    public static function paginated(iterable $items, PageResult|Paginator $page): ResourceCollection
    {
        return static::collection($items)->paginate($page);
    }

    /* ---------- conditional helpers ---------- */

    /**
     * Include the value only when the condition holds; omit the key
     * entirely otherwise.
     */
    protected function when(bool $condition, mixed $value, mixed $default = null): mixed
    {
        return $condition ? $value : ($default === null ? new MissingValue() : $default);
    }

    /** Include the value only when it is not null. */
    protected function whenNotNull(mixed $value): mixed
    {
        return $value === null ? new MissingValue() : $value;
    }

    /**
     * Include a nested resource only when the relationship source is
     * present — an unloaded or null relation omits the key rather than
     * serializing `null`.
     */
    protected function whenLoaded(mixed $relationship, ?string $resourceClass = null): mixed
    {
        if ($relationship === null) {
            return new MissingValue();
        }

        $class = $resourceClass ?? static::class;

        // A list (or Traversable) is a has-many; anything else — an
        // associative array or an object — is a single related record.
        if (is_iterable($relationship)
            && (!is_array($relationship) || array_is_list($relationship))
        ) {
            return $class::collection($relationship);
        }

        return $class::make($relationship);
    }

    /* ---------- source access ---------- */

    /**
     * Read one attribute from the source: array key, ArrayAccess offset,
     * `getAttribute()` (ORM models), then a public property. A missing
     * attribute reads as null — the resource decides how to present it.
     */
    public function __get(string $key): mixed
    {
        return self::read($this->resource, $key);
    }

    public function __isset(string $key): bool
    {
        return self::read($this->resource, $key) !== null;
    }

    private static function read(mixed $source, string $key): mixed
    {
        if (is_array($source)) {
            return $source[$key] ?? null;
        }

        if ($source instanceof ArrayAccess) {
            return $source[$key] ?? null;
        }

        if (is_object($source)) {
            if (method_exists($source, 'getAttribute')) {
                return $source->getAttribute($key);
            }

            return $source->{$key} ?? null;
        }

        return null;
    }

    /* ---------- recursive resolution ---------- */

    /**
     * Resolve a `toArray()` value to plain JSON-safe data: nested
     * resources serialize, MissingValue keys drop, DateTimeInterface
     * renders ISO-8601, iterables recurse.
     */
    public static function resolveValue(mixed $value): mixed
    {
        if ($value instanceof MissingValue) {
            return new MissingValue();
        }

        if ($value instanceof JsonSerializable) {
            return $value->jsonSerialize();
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof Traversable) {
            $value = iterator_to_array($value);
        }

        if (is_array($value)) {
            $out = [];

            foreach ($value as $key => $item) {
                $resolved = self::resolveValue($item);

                if ($resolved instanceof MissingValue) {
                    continue;
                }

                $out[$key] = $resolved;
            }

            return $out;
        }

        return $value;
    }
}
