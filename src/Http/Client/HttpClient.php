<?php

declare(strict_types=1);

namespace Sahil\Http\Client;

use Sahil\Contracts\Http\Client\HttpClientContract;
use Sahil\Contracts\Http\Client\HttpTransportContract;

/**
 * The outbound client — a transport plus per-verb conveniences. Each
 * call starts from a fresh immutable {@see PendingRequest}, so no
 * configuration can leak between requests.
 */
final class HttpClient implements HttpClientContract
{
    /** @var array<string, string|list<string>> */
    private array $defaultHeaders = [];

    public function __construct(
        private readonly HttpTransportContract $transport,
    ) {
    }

    /** Client-wide defaults (e.g. a shared `Accept: application/json`). */
    public function withDefaultHeaders(array $headers): self
    {
        $clone = clone $this;
        $clone->defaultHeaders = $headers;

        return $clone;
    }

    public function transport(): HttpTransportContract
    {
        return $this->transport;
    }

    public function pending(): PendingRequest
    {
        $request = new PendingRequest($this->transport);

        return $this->defaultHeaders !== [] ? $request->withHeaders($this->defaultHeaders) : $request;
    }

    /** @param array<string, mixed> $query */
    public function request(string $method, string $url, array $query = []): ClientResponse
    {
        return $this->pending()->send($method, $url, $query);
    }

    public function get(string $url, array $query = []): ClientResponse
    {
        return $this->pending()->get($url, $query);
    }

    public function post(string $url, array|string $body = []): ClientResponse
    {
        return $this->pending()->post($url, $body);
    }

    public function put(string $url, array|string $body = []): ClientResponse
    {
        return $this->pending()->put($url, $body);
    }

    public function patch(string $url, array|string $body = []): ClientResponse
    {
        return $this->pending()->patch($url, $body);
    }

    public function delete(string $url, array $query = []): ClientResponse
    {
        return $this->pending()->delete($url, $query);
    }

    public function head(string $url, array $query = []): ClientResponse
    {
        return $this->pending()->head($url, $query);
    }

    public function options(string $url, array $query = []): ClientResponse
    {
        return $this->pending()->options($url, $query);
    }
}
