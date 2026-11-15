<?php

declare(strict_types=1);

namespace Sahil\Tests\Cache;

use PHPUnit\Framework\TestCase;
use Sahil\Cache\FileCache;

final class FileCacheTest extends TestCase
{
    private string $dir;
    private FileCache $cache;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/sahil-cache-'.bin2hex(random_bytes(6));
        $this->cache = new FileCache($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
    }

    public function test_round_trip_all_value_types(): void
    {
        $values = [
            'string' => 'hello',
            'empty-string' => '',
            'int' => 42,
            'zero' => 0,
            'false' => false,
            'null' => null,
            'float' => 1.5,
            'array' => ['a' => [1, 2]],
            'object' => (object) ['x' => 1],
        ];

        foreach ($values as $key => $value) {
            self::assertTrue($this->cache->put($key, $value));
        }

        self::assertSame('hello', $this->cache->get('string'));
        self::assertSame('', $this->cache->get('empty-string'));
        self::assertSame(42, $this->cache->get('int'));
        self::assertSame(0, $this->cache->get('zero'));
        self::assertFalse($this->cache->get('false'));
        self::assertNull($this->cache->get('null'));
        self::assertSame(1.5, $this->cache->get('float'));
        self::assertSame(['a' => [1, 2]], $this->cache->get('array'));
        self::assertEquals((object) ['x' => 1], $this->cache->get('object')); // objects round-trip — old var_export fatals
    }

    public function test_ttl_expiry_and_ttl_introspection(): void
    {
        $this->cache->put('k', 'v', 60);
        self::assertTrue($this->cache->has('k'));
        self::assertGreaterThan(0, $this->cache->ttL('k'));
        self::assertLessThanOrEqual(60, $this->cache->ttL('k'));

        $this->cache->put('forever', 'v');
        self::assertSame(0, $this->cache->ttL('forever'));

        // Force expiry: rewrite the entry with a past expiry.
        $this->cache->put('k', 'v', 300);
        $file = $this->dir.'/cache_'.hash('sha256', 'k').'.php';
        $raw = file_get_contents($file);
        $entry = unserialize(substr($raw, strlen("<?php die; ?>\n")));
        $entry['expires'] = time() - 10;
        file_put_contents($file, "<?php die; ?>\n".serialize($entry));

        self::assertFalse($this->cache->has('k'));
        self::assertNull($this->cache->get('k'));
        self::assertFalse($this->cache->ttL('k'));
        self::assertFileDoesNotExist($file); // expired entries are reaped on read
    }

    public function test_corrupted_file_is_a_miss_not_a_fatal(): void
    {
        $this->cache->put('k', 'v');
        $file = glob($this->dir.'/cache_*')[0];
        file_put_contents($file, 'garbage-not-php-not-serialize');

        self::assertNull($this->cache->get('k'));
        self::assertFalse($this->cache->has('k'));
        self::assertFileDoesNotExist($file);
    }

    public function test_tampered_serialized_payload_is_rejected(): void
    {
        $this->cache->put('k', 'v');
        $file = glob($this->dir.'/cache_*')[0];
        file_put_contents($file, "<?php die; ?>\n".'a:99:{broken');

        self::assertNull($this->cache->get('k'));
        self::assertFalse($this->cache->has('k'));
    }

    public function test_add_semantics(): void
    {
        self::assertTrue($this->cache->add('k', 'v1'));
        self::assertFalse($this->cache->add('k', 'v2')); // exists — unchanged
        self::assertSame('v1', $this->cache->get('k'));
    }

    public function test_increment_decrement_preserve_ttl_and_types(): void
    {
        // Fresh counter like Redis INCRBY.
        self::assertSame(5, $this->cache->increment('counter', 5));
        self::assertSame(8, $this->cache->increment('counter', 3));
        self::assertSame(6, $this->cache->decrement('counter', 2));

        // TTL survives mutation (old code reset expiry to never).
        $this->cache->put('t', 10, 300);
        self::assertSame(11, $this->cache->increment('t'));
        self::assertGreaterThan(0, $this->cache->ttL('t'));

        // Non-numeric stays false.
        $this->cache->put('s', 'abc');
        self::assertFalse($this->cache->increment('s'));
    }

    public function test_decrement_goes_below_zero_consistently(): void
    {
        $this->cache->put('n', 1);
        self::assertSame(-2, $this->cache->decrement('n', 3)); // aligned with Redis decrby
    }

    public function test_forget_flush_and_missing(): void
    {
        $this->cache->put('a', 1);
        $this->cache->put('b', 2);
        self::assertTrue($this->cache->forget('a'));
        self::assertFalse($this->cache->has('a'));
        self::assertFalse($this->cache->forget('a'));

        self::assertTrue($this->cache->flush());
        self::assertNull($this->cache->get('b'));
        self::assertNull($this->cache->get('missing'));
        self::assertFalse($this->cache->has('missing'));
        self::assertFalse($this->cache->ttL('missing'));
    }
}
