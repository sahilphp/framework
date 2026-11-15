<?php

declare(strict_types=1);

namespace Sahil\Http;

use Sahil\Contracts\Http\Client\HttpClientContract;
use Sahil\Foundation\Application;
use Sahil\Http\Client\ClientResponse;
use Sahil\Http\Client\CurlTransport;
use Sahil\Http\Client\FakeTransport;
use Sahil\Http\Client\HttpClient;
use Sahil\Http\Client\HttpClientException;
use Sahil\Http\Client\OutgoingRequest;
use Sahil\Http\Client\PendingRequest;

/**
 * Http — the outbound HTTP client facade (same convention as
 * `Bouncer`/`Notify`/`Render`/`Event`/`Kapu`).
 *
 *     Http::get('https://api.example.com/users', ['page' => 1]);
 *     Http::withToken($t)->timeout(15)->post($url, ['name' => 'x']);
 *     Http::fake(['api.example.com/*' => Http::response(['ok' => 1])]);
 *
 * Verb statics return {@see ClientResponse}; `with*` statics start an
 * immutable {@see PendingRequest} — configuration can't leak into
 * unrelated requests or the next worker cycle.
 */
final class Http
{
    private static ?HttpClientContract $instance = null;

    private function __construct()
    {
    }

    // ---- Verb conveniences -------------------------------------------------

    public static function get(string $url, array $query = []): ClientResponse
    {
        return self::client()->get($url, $query);
    }

    public static function post(string $url, array|string $body = []): ClientResponse
    {
        return self::client()->post($url, $body);
    }

    public static function put(string $url, array|string $body = []): ClientResponse
    {
        return self::client()->put($url, $body);
    }

    public static function patch(string $url, array|string $body = []): ClientResponse
    {
        return self::client()->patch($url, $body);
    }

    public static function delete(string $url, array $query = []): ClientResponse
    {
        return self::client()->delete($url, $query);
    }

    public static function head(string $url, array $query = []): ClientResponse
    {
        return self::client()->head($url, $query);
    }

    public static function options(string $url, array $query = []): ClientResponse
    {
        return self::client()->options($url, $query);
    }

    /** @param array<string, mixed> $query */
    public static function request(string $method, string $url, array $query = []): ClientResponse
    {
        return self::client()->request($method, $url, $query);
    }

    // ---- Builder entry points (return immutable PendingRequest) -----------

    public static function pending(): PendingRequest
    {
        return self::client()->pending();
    }

    public static function withHeaders(array $headers): PendingRequest
    {
        return self::pending()->withHeaders($headers);
    }

    public static function withHeader(string $name, string $value): PendingRequest
    {
        return self::pending()->withHeader($name, $value);
    }

    public static function withToken(string $token): PendingRequest
    {
        return self::pending()->withToken($token);
    }

    public static function withBasicAuth(string $username, string $password): PendingRequest
    {
        return self::pending()->withBasicAuth($username, $password);
    }

    public static function timeout(float $seconds): PendingRequest
    {
        return self::pending()->timeout($seconds);
    }

    public static function connectTimeout(float $seconds): PendingRequest
    {
        return self::pending()->connectTimeout($seconds);
    }

    public static function redirects(int $max): PendingRequest
    {
        return self::pending()->redirects($max);
    }

    public static function withoutRedirecting(): PendingRequest
    {
        return self::pending()->withoutRedirecting();
    }

    public static function asForm(): PendingRequest
    {
        return self::pending()->asForm();
    }

    public static function sink(mixed $stream): PendingRequest
    {
        return self::pending()->sink($stream);
    }

    // ---- Fakes & assertions ------------------------------------------------

    /**
     * Stub responses by URL pattern and install a FakeTransport —
     * unstubbed requests throw (no stray network traffic).
     *
     * @param array<string, ClientResponse|array{status:int,headers?:array,body?:string}|string> $stubs
     */
    public static function fake(array $stubs = []): FakeTransport
    {
        $transport = new FakeTransport($stubs);
        self::swap(new HttpClient($transport));

        return $transport;
    }

    /** Build a response for `Http::fake()` stubs. */
    public static function response(array|string $body = '', int $status = 200, array $headers = []): ClientResponse
    {
        return new ClientResponse($status, $headers, is_array($body) ? json_encode($body) : $body);
    }

    /** @return list<OutgoingRequest> requests the fake transport saw */
    public static function recorded(): array
    {
        $transport = self::client()->transport();

        return $transport instanceof FakeTransport ? $transport->recorded() : [];
    }

    /**
     * Assert at least one recorded request matches — e.g.
     * `Http::assertSent(fn ($r) => str_contains($r->url, 'users'))`.
     *
     * @param callable(OutgoingRequest): bool $predicate
     */
    public static function assertSent(callable $predicate): void
    {
        $transport = self::client()->transport();

        if (!$transport instanceof FakeTransport || $transport->countMatching($predicate) === 0) {
            throw new HttpClientException('The expected outbound request was not sent.');
        }
    }

    // ---- Client resolution --------------------------------------------------

    public static function client(): HttpClientContract
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $application = Application::peek();

        if ($application !== null && $application->container()->has(HttpClientContract::class)) {
            /** @var HttpClientContract $client */
            $client = $application->container()->make(HttpClientContract::class);

            return $client;
        }

        return new HttpClient(new CurlTransport());
    }

    /** Install the client the facade delegates to — tests and non-application contexts. */
    public static function swap(?HttpClientContract $client): void
    {
        self::$instance = $client;
    }

    /** Drop the swapped instance — resets fakes between tests. */
    public static function flushState(): void
    {
        self::$instance = null;
    }
}
