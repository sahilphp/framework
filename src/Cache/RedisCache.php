<?php

declare(strict_types=1);

namespace Sahil\Cache;

use Redis;
use RedisException;
use Sahil\Contracts\Cache\CacheContract;

/**
 * Redis cache driver implementing CacheContract.
 *
 * Correctness guarantees:
 * - `put`/`get` round-trip through `serialize()` — values written via
 *   `increment`/`decrement` are plain Redis integers and read back as ints.
 * - `add` is a single atomic `SET … NX EX` — no crash window that could
 *   leave a TTL-less key.
 * - `increment`/`decrement` use atomic `INCRBY`/`DECRBY` — safe for
 *   concurrent processes sharing the store.
 * - `flush` is prefix-scoped (`SCAN`+`UNLINK`), NEVER `FLUSHDB` — a
 *   shared Redis database keeps every other application's keys.
 * - `prefix` isolates this store's keys inside a shared database.
 * - One transparent reconnect+retry per call when the connection drops —
 *   long-running workers recover without corrupting state.
 *
 * Requires the phpredis extension.
 */
/** @implements CacheContract<mixed> */
final class RedisCache implements CacheContract
{
    /** @var Redis */
    private $redis;

    private readonly string $host;
    private readonly int $port;
    private readonly ?string $password;
    private readonly int $database;
    private readonly string $prefix;
    private readonly float $timeout;

    /**
     * @param array{host?:string,port?:int,password?:?string,database?:int,prefix?:string,timeout?:float} $options
     */
    public function __construct(array $options = [])
    {
        if (!extension_loaded('redis')) {
            throw new CacheException('The redis extension is required for the Redis cache driver.');
        }

        $this->host = (string) ($options['host'] ?? '127.0.0.1');
        $this->port = (int) ($options['port'] ?? 6379);
        $this->password = isset($options['password']) && $options['password'] !== ''
            ? (string) $options['password']
            : null;
        $this->database = (int) ($options['database'] ?? 0);
        $this->prefix = (string) ($options['prefix'] ?? 'sahil:cache:');
        $this->timeout = (float) ($options['timeout'] ?? 2.0);

        $this->redis = new Redis();
        $this->connect();
    }

    /** {@inheritDoc} */
    public function get(string $key): mixed
    {
        $value = $this->run(fn (): mixed => $this->redis->get($this->key($key)));

        if ($value === false || !is_string($value)) {
            return null;
        }

        $decoded = @unserialize($value);

        if ($decoded !== false || $value === serialize(false)) {
            return $decoded;
        }

        // Counters created by INCRBY are plain integers, not serialized.
        return is_numeric($value) ? (int) $value : $value;
    }

    /** {@inheritDoc} */
    public function put(string $key, mixed $value, int $ttl = 0): bool
    {
        $payload = serialize($value);

        if ($ttl > 0) {
            return (bool) $this->run(fn (): mixed => $this->redis->setex($this->key($key), $ttl, $payload));
        }

        return (bool) $this->run(fn (): mixed => $this->redis->set($this->key($key), $payload));
    }

    /** {@inheritDoc} Single atomic SET NX EX — no TTL-less window. */
    public function add(string $key, mixed $value, int $ttl = 0): bool
    {
        $options = ['nx'];

        if ($ttl > 0) {
            $options['ex'] = $ttl;
        }

        return (bool) $this->run(fn (): mixed => $this->redis->set($this->key($key), serialize($value), $options));
    }

    /** {@inheritDoc} Atomic server-side INCRBY. */
    public function increment(string $key, int $amount = 1): int
    {
        return $this->run(fn (): int => $this->redis->incrBy($this->key($key), $amount));
    }

    /** {@inheritDoc} Atomic server-side DECRBY — may go negative. */
    public function decrement(string $key, int $amount = 1): int
    {
        return $this->run(fn (): int => $this->redis->decrBy($this->key($key), $amount));
    }

    /** {@inheritDoc} */
    public function forget(string $key): bool
    {
        return $this->run(fn (): int => $this->redis->del($this->key($key))) > 0;
    }

    /** {@inheritDoc} Prefix-scoped — never FLUSHDB on a shared database. */
    public function flush(): bool
    {
        return (bool) $this->run(function (): bool {
            $iterator = null;
            $deleted = 0;

            do {
                $keys = $this->redis->scan($iterator, $this->prefix.'*', 100);

                if (is_array($keys) && $keys !== []) {
                    $deleted += $this->redis->unlink($keys);
                }
            } while ($iterator > 0);

            return true;
        });
    }

    /** {@inheritDoc} */
    public function has(string $key): bool
    {
        return $this->run(fn (): int => $this->redis->exists($this->key($key))) > 0;
    }

    /** {@inheritDoc} */
    public function ttL(string $key): int|false
    {
        $ttl = $this->run(fn (): int => $this->redis->ttl($this->key($key)));

        if ($ttl === -2) {
            return false; // Key does not exist
        }

        if ($ttl === -1) {
            return 0; // Key exists but has no expiration
        }

        return $ttl;
    }

    private function key(string $key): string
    {
        return $this->prefix.$key;
    }

    /**
     * Run a command with one transparent reconnect+retry — a dropped
     * connection (idle worker, Redis restart) must not corrupt state or
     * surface as a fake cache miss.
     *
     * @template T
     *
     * @param callable():T $operation
     *
     * @return T
     */
    private function run(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (RedisException $first) {
            try {
                $this->connect();

                return $operation();
            } catch (RedisException $second) {
                throw new CacheException(
                    sprintf('Redis cache operation failed: %s', $second->getMessage()),
                    0,
                    $second,
                );
            }
        }
    }

    private function connect(): void
    {
        try {
            if ($this->redis->isConnected()) {
                $this->redis->close();
            }
        } catch (RedisException) {
            // Already dead — proceed to a fresh connect.
        }

        try {
            $this->redis->connect($this->host, $this->port, $this->timeout);

            if ($this->password !== null) {
                $this->redis->auth($this->password);
            }

            if ($this->database > 0) {
                $this->redis->select($this->database);
            }
        } catch (RedisException $e) {
            throw new CacheException(
                sprintf('Could not connect to Redis at %s:%d: %s', $this->host, $this->port, $e->getMessage()),
                0,
                $e,
            );
        }
    }
}
