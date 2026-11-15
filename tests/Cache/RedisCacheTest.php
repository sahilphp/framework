<?php

declare(strict_types=1);

namespace Sahil\Tests\Cache;

use PHPUnit\Framework\TestCase;
use Sahil\Cache\RedisCache;

/**
 * REAL Redis integration at 127.0.0.1:6379 — skipped only when the
 * server is genuinely unreachable; nothing is simulated.
 */
final class RedisCacheTest extends TestCase
{
    private const PREFIX = 'sahil:test:cache:';

    private static bool $available;
    private RedisCache $cache;

    public static function setUpBeforeClass(): void
    {
        self::$available = false;

        try {
            $redis = new \Redis();
            $redis->connect('127.0.0.1', 6379, 0.5);
            $redis->ping();
            self::$available = true;
        } catch (\Throwable) {
        }
    }

    protected function setUp(): void
    {
        if (!self::$available) {
            self::markTestSkipped('Redis not reachable at 127.0.0.1:6379.');
        }

        $this->cache = new RedisCache(['prefix' => self::PREFIX]);
        $this->cache->flush();
    }

    public function test_get_unserializes_put_values(): void
    {
        // THE original defect: get() returned raw `s:5:"v";` strings.
        $this->cache->put('k', 'v');
        self::assertSame('v', $this->cache->get('k'));

        $this->cache->put('arr', ['a' => 1]);
        self::assertSame(['a' => 1], $this->cache->get('arr'));

        $this->cache->put('obj', (object) ['x' => 1]);
        self::assertEquals((object) ['x' => 1], $this->cache->get('obj'));

        $this->cache->put('false', false);
        self::assertFalse($this->cache->get('false'));

        $this->cache->put('zero', 0);
        self::assertSame(0, $this->cache->get('zero'));
    }

    public function test_ttl_expiry(): void
    {
        $this->cache->put('k', 'v', 60);
        self::assertTrue($this->cache->has('k'));
        $ttl = $this->cache->ttL('k');
        self::assertIsInt($ttl);
        self::assertGreaterThan(0, $ttl);
        self::assertLessThanOrEqual(60, $ttl);

        $this->cache->put('forever', 'v');
        self::assertSame(0, $this->cache->ttL('forever')); // no expiry → 0
    }

    public function test_add_is_atomic_set_nx_ex(): void
    {
        self::assertTrue($this->cache->add('k', 'v1', 60));
        self::assertFalse($this->cache->add('k', 'v2'));
        self::assertSame('v1', $this->cache->get('k'));
        self::assertGreaterThan(0, $this->cache->ttL('k')); // TTL applied atomically, no TTL-less window
    }

    public function test_increment_decrement_are_atomic_and_raw(): void
    {
        self::assertSame(3, $this->cache->increment('n', 3));
        self::assertSame(8, $this->cache->increment('n', 5));
        self::assertSame(2, $this->cache->decrement('n', 6));
        self::assertSame(-1, $this->cache->decrement('n', 3)); // decrby goes negative

        // Incremented counters read back as ints (not serialized).
        self::assertSame(-1, $this->cache->get('n'));
    }

    public function test_prefix_isolation_and_scoped_flush(): void
    {
        $other = new RedisCache(['prefix' => 'sahil:other:']);
        $other->flush();

        $this->cache->put('shared-key', 'mine');
        $other->put('shared-key', 'theirs');
        $other->put('foreign', 'keep-me');

        self::assertSame('mine', $this->cache->get('shared-key'));
        self::assertSame('theirs', $other->get('shared-key'));

        // THE original defect: flush() ran FLUSHDB — destroyed every key
        // in the database. Now prefix-scoped.
        $this->cache->flush();
        self::assertNull($this->cache->get('shared-key'));
        self::assertSame('theirs', $other->get('shared-key'));
        self::assertSame('keep-me', $other->get('foreign'));

        $other->flush();
    }

    public function test_reconnect_after_connection_drop(): void
    {
        $this->cache->put('k', 'v');

        // Kill the connection underneath — a worker idle gap or restart.
        $ref = new \ReflectionProperty($this->cache, 'redis');
        $ref->getValue($this->cache)->close();

        self::assertSame('v', $this->cache->get('k')); // transparent reconnect+retry
    }

    public function test_connection_failure_is_a_descriptive_exception(): void
    {
        $this->expectException(\Sahil\Cache\CacheException::class);
        new RedisCache(['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.2]);
    }
}
