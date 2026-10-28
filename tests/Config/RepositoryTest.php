<?php

declare(strict_types=1);

namespace Sahil\Tests\Config;

use PHPUnit\Framework\TestCase;
use Sahil\Config\Repository;

final class RepositoryTest extends TestCase
{
    public function test_reads_nested_values_by_dotted_key(): void
    {
        $repository = new Repository([
            'app' => [
                'name' => 'SahilPHP',
                'debug' => true,
            ],
        ]);

        self::assertSame('SahilPHP', $repository->get('app.name'));
        self::assertTrue($repository->get('app.debug'));
        self::assertSame('fallback', $repository->get('app.missing', 'fallback'));
    }

    public function test_set_creates_intermediate_arrays(): void
    {
        $repository = new Repository();

        $repository->set('services.cache.driver', 'redis');

        self::assertSame('redis', $repository->get('services.cache.driver', ''));
    }

    public function test_set_overwrites_existing_scalar_value(): void
    {
        $repository = new Repository(['cache' => ['driver' => 'file']]);

        $repository->set('cache.driver', 'redis');

        self::assertSame('redis', $repository->get('cache.driver'));
    }

    public function test_has_is_a_key_existence_check(): void
    {
        $repository = new Repository(['optional' => null, 'nested' => ['leaf' => 1]]);

        self::assertTrue($repository->has('optional'));
        self::assertFalse($repository->has('missing'));
        self::assertFalse($repository->has('optional.missing'));
        self::assertTrue($repository->has('nested.leaf'));
    }

    public function test_deep_merge_preserves_unrelated_branches(): void
    {
        $repository = new Repository(['a' => ['x' => 1, 'y' => 2]]);

        $repository->merge(['a' => ['y' => 3], 'b' => ['z' => 4]]);

        self::assertSame(1, $repository->get('a.x'));
        self::assertSame(3, $repository->get('a.y'));
        self::assertSame(4, $repository->get('b.z'));
    }

    public function test_malformed_keys_are_rejected(): void
    {
        $repository = new Repository();

        $this->expectException(\InvalidArgumentException::class);

        $repository->get('login..user');
    }

    public function test_array_access_read_and_write(): void
    {
        $repository = new Repository();

        $repository['services.db'] = 'mysql';

        self::assertSame('mysql', $repository['services.db']);
        self::assertArrayNotHasKey('nope', $repository);
    }

    public function test_unset_via_array_access_is_rejected(): void
    {
        $repository = new Repository(['a' => ['b' => 1]]);

        $this->expectException(\LogicException::class);

        unset($repository['a.b']);
    }
}
