<?php

declare(strict_types=1);

namespace Sahil\Tests\Http;

use DateTimeImmutable;
use Generator;
use PHPUnit\Framework\TestCase;
use Sahil\Http\Resources\Resource;
use Sahil\Http\Resources\ResourceCollection;

final class UserResource extends Resource
{
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->when(($this->showEmail ?? false) === true, $this->email),
            'posts' => PostResource::collection($this->posts ?? []),
        ];
    }
}

final class PostResource extends Resource
{
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
        ];
    }
}

final class ScalarResource extends Resource
{
    public function toArray(): mixed
    {
        return ['wrapped' => $this->resource];
    }
}

final class ResourceTest extends TestCase
{
    public function test_single_resource_from_array(): void
    {
        $r = UserResource::make(['id' => 1, 'name' => 'Asha', 'email' => 'a@x', 'showEmail' => true]);
        self::assertSame(
            ['id' => 1, 'name' => 'Asha', 'email' => 'a@x', 'posts' => []],
            $r->jsonSerialize(),
        );
    }

    public function test_resource_from_object_and_missing_attributes_read_null(): void
    {
        $r = UserResource::make((object) ['id' => 2, 'name' => 'Jo']);
        self::assertSame(['id' => 2, 'name' => 'Jo', 'posts' => []], $r->jsonSerialize());
        // missing 'email' key dropped entirely — not serialized as null
        self::assertArrayNotHasKey('email', $r->jsonSerialize());
    }

    public function test_source_is_not_mutated_and_unlisted_fields_stay_private(): void
    {
        $source = ['id' => 1, 'name' => 'X', 'password_hash' => 'secret', 'token' => 'tk'];
        $snapshot = $source;
        $out = UserResource::make($source)->jsonSerialize();
        self::assertSame($snapshot, $source);
        self::assertStringNotContainsString('secret', json_encode($out));
        self::assertStringNotContainsString('tk', json_encode($out));
    }

    public function test_null_source_serializes_null(): void
    {
        self::assertNull(UserResource::make(null)->jsonSerialize());
    }

    public function test_nested_resources_and_collections(): void
    {
        $r = UserResource::make([
            'id' => 1, 'name' => 'A', 'showEmail' => true, 'email' => 'a@x',
            'posts' => [['id' => 9, 'title' => 'T1'], ['id' => 10, 'title' => 'T2']],
        ]);
        self::assertSame(
            [['id' => 9, 'title' => 'T1'], ['id' => 10, 'title' => 'T2']],
            $r->jsonSerialize()['posts'],
        );
    }

    public function test_collection_order_types_and_traversable(): void
    {
        $gen = function (): Generator {
            yield ['id' => 3, 'name' => 'C'];
            yield ['id' => 1, 'name' => 'A'];
        };
        $out = UserResource::collection($gen())->jsonSerialize();
        self::assertSame([3, 1], array_column($out, 'id')); // order preserved, no sort
        self::assertCount(1, UserResource::collection([1 => ['id' => 1, 'name' => 'x']]));
        self::assertSame([], UserResource::collection([])->jsonSerialize());
    }

    public function test_when_and_whenNotNull(): void
    {
        $r = new class ([]) extends Resource {
            public function toArray(): array
            {
                return [
                    'yes' => $this->when(true, 'v'),
                    'no' => $this->when(false, 'v'),
                    'withDefault' => $this->when(false, 'v', 'fallback'),
                    'nullIn' => $this->whenNotNull(null),
                    'legitNull' => null,
                ];
            }
        };
        $out = $r->jsonSerialize();
        self::assertSame(['yes' => 'v', 'withDefault' => 'fallback', 'legitNull' => null], $out);
    }

    public function test_whenLoaded_relationship(): void
    {
        $r = new class (['author' => ['id' => 7, 'name' => 'Au'], 'comments' => []]) extends Resource {
            public function toArray(): array
            {
                return [
                    'author' => $this->whenLoaded($this->author, UserResource::class),
                    'missing' => $this->whenLoaded($this->editor ?? null, UserResource::class),
                ];
            }
        };
        $out = $r->jsonSerialize();
        self::assertSame('Au', $out['author']['name']);
        self::assertArrayNotHasKey('missing', $out);
    }

    public function test_datetime_serializes_iso8601(): void
    {
        $r = ScalarResource::make(['wrapped' => new DateTimeImmutable('2024-01-02 03:04:05', new \DateTimeZone('UTC'))]);
        // scalar resource wraps array — use array source directly instead
        $r2 = new class (['at' => new DateTimeImmutable('2024-01-02T03:04:05+00:00')]) extends Resource {
            public function toArray(): array
            {
                return ['at' => $this->at];
            }
        };
        self::assertSame('2024-01-02T03:04:05+00:00', $r2->jsonSerialize()['at']);
    }

    public function test_toResponse_status_headers_and_envelope(): void
    {
        $res = UserResource::make(['id' => 1, 'name' => 'A'])
            ->withMeta(['meta' => ['v' => 2]])
            ->toResponse(201, ['X-Tag' => 'users']);
        self::assertSame(201, $res->status());
        self::assertSame('application/json', $res->header('Content-Type'));
        self::assertSame('users', $res->header('X-Tag'));
        $body = json_decode($res->body(), true);
        self::assertSame(1, $body['data']['id']);
        self::assertSame(2, $body['meta']['v']);
    }

    public function test_bare_response_has_no_forced_envelope(): void
    {
        $res = UserResource::make(['id' => 1, 'name' => 'A'])->toResponse();
        $body = json_decode($res->body(), true);
        self::assertSame(1, $body['id']);
        self::assertArrayNotHasKey('data', $body);
    }

    public function test_collection_response_with_paginator(): void
    {
        $page = new \Sahil\Orm\PageResult(
            result: new \Sahil\Orm\OrmResult([['id' => 1], ['id' => 2]]),
            currentPage: 2,
            perPage: 2,
            total: 5,
        );
        $res = UserResource::collection([['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']])
            ->paginate($page)
            ->toResponse();
        $body = json_decode($res->body(), true);
        self::assertCount(2, $body['data']);
        self::assertSame(5, $body['meta']['total']);
        self::assertSame(2, $body['meta']['currentPage']);
        self::assertSame(3, $body['meta']['lastPage']);
        self::assertTrue($body['meta']['hasMorePages']);
    }

    public function test_invalid_utf8_throws_instead_of_empty_body(): void
    {
        $r = new class (['bad' => "\xB1\x31"]) extends Resource {
            public function toArray(): array
            {
                return ['bad' => $this->bad];
            }
        };
        $this->expectException(\InvalidArgumentException::class);
        $r->toResponse();
    }

    public function test_repeated_serialization_is_stable_and_stateless(): void
    {
        $r = UserResource::make(['id' => 1, 'name' => 'A']);
        self::assertSame($r->jsonSerialize(), $r->jsonSerialize());
        $other = UserResource::make(['id' => 2, 'name' => 'B']);
        self::assertSame(1, $r->jsonSerialize()['id']);
        self::assertSame(2, $other->jsonSerialize()['id']);
    }

    public function test_controller_can_return_a_resource_directly(): void
    {
        \Sahil\Foundation\Application::free();
        $app = \Sahil\Foundation\Application::configure(__DIR__.'/../Fixtures/app-root');
        $router = new \Sahil\Http\Router();
        $router->register(\Sahil\Routing\Link::get('/users/1', static fn () => UserResource::make(['id' => 1, 'name' => 'Asha'])));
        $kernel = new \Sahil\Http\Kernel($router, $app->container(), $app);
        $res = $kernel->handle(\Sahil\Http\Request::fromGlobals(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/users/1'], []));
        \Sahil\Foundation\Application::free();
        self::assertSame(200, $res->status());
        self::assertSame('application/json', $res->header('Content-Type'));
        self::assertSame('Asha', json_decode($res->body(), true)['name']);
    }

    public function test_collection_count_and_iterable(): void
    {
        $c = UserResource::collection([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']]);
        self::assertInstanceOf(ResourceCollection::class, $c);
        self::assertCount(2, $c);
        $items = iterator_to_array($c);
        self::assertContainsOnlyInstancesOf(UserResource::class, $items);
    }
}
