<?php

declare(strict_types=1);

namespace Sahil\Contracts\Http\Client;

use Sahil\Http\Client\ClientResponse;
use Sahil\Http\Client\PendingRequest;

/**
 * The application's outbound HTTP client — inject this contract to
 * decouple from the facade.
 */
interface HttpClientContract
{
    /** The underlying transport seam (inspection/fakes). */
    public function transport(): HttpTransportContract;

    /** A fresh immutable request builder carrying the client's defaults. */
    public function pending(): PendingRequest;

    /** Custom-method request; `array $query` becomes the query string. */
    /** @param array<string, mixed> $query */
    public function request(string $method, string $url, array $query = []): ClientResponse;

    /** Convenience verb methods — `array $query` becomes the query string. */
    /** @param array<string, mixed> $query */
    public function get(string $url, array $query = []): ClientResponse;

    /** @param array<string, mixed>|string $body */
    public function post(string $url, array|string $body = []): ClientResponse;

    /** @param array<string, mixed>|string $body */
    public function put(string $url, array|string $body = []): ClientResponse;

    /** @param array<string, mixed>|string $body */
    public function patch(string $url, array|string $body = []): ClientResponse;

    /** @param array<string, mixed> $query */
    public function delete(string $url, array $query = []): ClientResponse;

    /** @param array<string, mixed> $query */
    public function head(string $url, array $query = []): ClientResponse;

    /** @param array<string, mixed> $query */
    public function options(string $url, array $query = []): ClientResponse;
}
