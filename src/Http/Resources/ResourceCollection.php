<?php

declare(strict_types=1);

namespace Sahil\Http\Resources;

use Countable;
use IteratorAggregate;
use JsonSerializable;
use Sahil\Http\Pagination\Paginator;
use Sahil\Http\Response;
use Sahil\Orm\PageResult;
use Traversable;

/**
 * One resource per iterable item — `UserResource::collection($users)`.
 *
 * Iteration is lazy: the resource instance is built when each element is
 * read, so large traversables are not materialized twice. `jsonSerialize`
 * still produces a full list because JSON is one document; streaming a
 * response body is outside this layer's contract.
 *
 * @implements IteratorAggregate<int, Resource>
 */
final class ResourceCollection implements Countable, IteratorAggregate, JsonSerializable
{
    /** @var array<string, mixed> */
    private array $meta = [];

    /** @var Paginator|null */
    private ?Paginator $paginator = null;

    /** @var list<string>|null per-item top-level key allowlist */
    private ?array $only = null;

    /**
     * @param class-string<Resource> $resourceClass
     * @param iterable<array<string, mixed>|object> $items
     */
    public function __construct(
        private readonly string $resourceClass,
        private readonly iterable $items,
    ) {
    }

    /** @return Traversable<int, Resource> */
    public function getIterator(): Traversable
    {
        foreach ($this->items as $item) {
            yield ($this->resourceClass)::make($item);
        }
    }

    public function count(): int
    {
        if (is_array($this->items) || $this->items instanceof Countable) {
            return count($this->items);
        }

        return iterator_count($this->getIterator());
    }

    /**
     * Restrict each item's top-level keys (see {@see Resource::only()}).
     *
     * @param list<string>|null $fields
     */
    public function only(?array $fields): self
    {
        $clone = clone $this;
        $clone->only = $fields === null ? null : array_values($fields);

        return $clone;
    }

    /** @return list<mixed> */
    public function jsonSerialize(): array
    {
        $out = [];

        foreach ($this as $resource) {
            $out[] = $resource->only($this->only)->jsonSerialize();
        }

        return $out;
    }

    /** All items as plain arrays. */
    public function toArray(): array
    {
        return $this->jsonSerialize();
    }

    /**
     * @param array<string, mixed> $meta top-level response keys alongside `data`
     */
    public function withMeta(array $meta): self
    {
        $clone = clone $this;
        $clone->meta = [...$this->meta, ...$meta];

        return $clone;
    }

    /**
     * Attach pagination metadata from a {@see PageResult} (ORM) or an
     * already-built {@see Paginator} — its `toArray()` becomes `meta`.
     */
    public function paginate(PageResult|Paginator $page): self
    {
        $clone = clone $this;
        $clone->paginator = $page instanceof Paginator ? $page : new Paginator($page);

        return $clone;
    }

    /**
     * `{data: [...]}` when meta/pagination is attached, bare list
     * otherwise — same convention as {@see Resource::toResponse()}.
     */
    public function toResponse(int $status = 200, array $headers = []): Response
    {
        $meta = $this->meta;

        if ($this->paginator !== null) {
            $meta = ['meta' => $this->paginator->toArray(), ...$meta];
        }

        $body = $meta === [] ? $this : ['data' => $this, ...$meta];
        $response = Response::json($body, $status);

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
