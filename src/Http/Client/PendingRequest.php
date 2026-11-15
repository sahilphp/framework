<?php

declare(strict_types=1);

namespace Sahil\Http\Client;

use Sahil\Contracts\Http\Client\HttpTransportContract;

/**
 * Immutable request builder — every `with*()` returns a clone, so
 * configuration can never leak into a sibling request or the next
 * worker cycle.
 */
final class PendingRequest
{
    /** @var array<string, list<string>> */
    private array $headers = [];

    /** @var array<string, mixed> */
    private array $query = [];

    private ?string $body = null;

    /** 'json'|'form'|'raw' — how `array` bodies encode */
    private string $bodyFormat = 'json';

    private float $connectTimeout = 10.0;

    private float $timeout = 30.0;

    private int $maxRedirects = 5;

    /** @var resource|null */
    private mixed $sink = null;

    /** @var list<string>|null validated IPs to pin the connection to */
    private ?array $resolve = null;

    public function __construct(
        private readonly HttpTransportContract $transport,
    ) {
    }

    /** @param array<string, string|list<string>> $headers */
    public function withHeaders(array $headers): self
    {
        $clone = clone $this;

        foreach ($headers as $name => $values) {
            $clone->headers[$name] = array_values((array) $values);
        }

        return $clone;
    }

    public function withHeader(string $name, string $value): self
    {
        return $this->withHeaders([$name => $value]);
    }

    public function withToken(string $token): self
    {
        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    public function withBasicAuth(string $username, string $password): self
    {
        return $this->withHeader('Authorization', 'Basic '.base64_encode($username.':'.$password));
    }

    /** @param array<string, mixed> $query */
    public function withQueryParameters(array $query): self
    {
        $clone = clone $this;
        $clone->query = $query + $clone->query;

        return $clone;
    }

    public function timeout(float $seconds): self
    {
        $clone = clone $this;
        $clone->timeout = $seconds;

        return $clone;
    }

    public function connectTimeout(float $seconds): self
    {
        $clone = clone $this;
        $clone->connectTimeout = $seconds;

        return $clone;
    }

    public function redirects(int $max): self
    {
        $clone = clone $this;
        $clone->maxRedirects = $max;

        return $clone;
    }

    public function withoutRedirecting(): self
    {
        return $this->redirects(0);
    }

    /** Encode `array` bodies as JSON (default). */
    public function asJson(): self
    {
        $clone = clone $this;
        $clone->bodyFormat = 'json';

        return $clone;
    }

    /** Encode `array` bodies as `application/x-www-form-urlencoded`. */
    public function asForm(): self
    {
        $clone = clone $this;
        $clone->bodyFormat = 'form';

        return $clone;
    }

    /** Raw body string; sets `Content-Type` only when given. */
    public function withBody(string $body, ?string $contentType = null): self
    {
        $clone = clone $this;
        $clone->body = $body;
        $clone->bodyFormat = 'raw';

        if ($contentType !== null) {
            $clone = $clone->withHeader('Content-Type', $contentType);
        }

        return $clone;
    }

    /**
     * Stream the response body into `$stream` instead of buffering it —
     * the caller keeps ownership (the stream is not closed).
     *
     * @param resource $stream
     */
    public function sink(mixed $stream): self
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new HttpClientException('sink() expects an open writable stream resource.');
        }

        $clone = clone $this;
        $clone->sink = $stream;

        return $clone;
    }

    /**
     * Pin the connection to pre-validated IP addresses — the transport
     * uses `CURLOPT_RESOLVE` so no second DNS lookup happens (TOCTOU)
     * and proxies are bypassed for a guaranteed direct connection.
     * Intended for security-sensitive callers (e.g. webhook delivery)
     * that have already validated every address.
     *
     * @param list<string> $ips
     */
    public function withResolvedAddresses(array $ips): self
    {
        if ($ips === []) {
            throw new HttpClientException('withResolvedAddresses() expects at least one IP literal.');
        }

        foreach ($ips as $ip) {
            if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                throw new HttpClientException('withResolvedAddresses() expects valid IP literals.');
            }
        }

        $clone = clone $this;
        $clone->resolve = array_values($ips);

        return $clone;
    }

    /** @param array<string, mixed> $query */
    public function get(string $url, array $query = []): ClientResponse
    {
        return $this->send('GET', $url, $query);
    }

    /** @param array<string, mixed> $query */
    public function head(string $url, array $query = []): ClientResponse
    {
        return $this->send('HEAD', $url, $query);
    }

    /** @param array<string, mixed> $query */
    public function options(string $url, array $query = []): ClientResponse
    {
        return $this->send('OPTIONS', $url, $query);
    }

    /** @param array<string, mixed> $query */
    public function delete(string $url, array $query = []): ClientResponse
    {
        return $this->send('DELETE', $url, $query);
    }

    /** @param array<string, mixed>|string $body */
    public function post(string $url, array|string $body = []): ClientResponse
    {
        return $this->send('POST', $url, [], $body);
    }

    /** @param array<string, mixed>|string $body */
    public function put(string $url, array|string $body = []): ClientResponse
    {
        return $this->send('PUT', $url, [], $body);
    }

    /** @param array<string, mixed>|string $body */
    public function patch(string $url, array|string $body = []): ClientResponse
    {
        return $this->send('PATCH', $url, [], $body);
    }

    /** @param array<string, mixed> $query */
    public function request(string $method, string $url, array $query = []): ClientResponse
    {
        return $this->send($method, $url, $query);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|string|null $body
     */
    public function send(string $method, string $url, array $query = [], array|string|null $body = null): ClientResponse
    {
        if (!preg_match('#^https?://#i', $url)) {
            throw new HttpClientException(sprintf('Request URL [%s] must be an absolute http(s) URL.', $url));
        }

        $queryString = http_build_query($query !== [] ? $query : $this->query);

        if ($queryString !== '') {
            $url .= (str_contains($url, '?') ? '&' : '?').$queryString;
        }

        $headers = $this->headers;

        if (($body !== null && $body !== [] || $this->body !== null) && !self::hasHeader($headers, 'Content-Type')) {
            $contentType = $this->contentTypeFor(null);

            if ($contentType !== null) {
                $headers['Content-Type'] = [$contentType];
            }
        }

        return $this->transport->send(new OutgoingRequest(
            strtoupper($method),
            $url,
            $headers,
            $this->encodeBody($body),
            $this->connectTimeout,
            $this->timeout,
            $this->maxRedirects,
            $this->sink,
            $this->resolve,
        ));
    }

    /**
     * @param array<string, mixed>|string|null $body
     */
    private function encodeBody(array|string|null $body): string
    {
        if (is_string($body)) {
            return $body;
        }

        if ($body !== null && $body !== []) {
            return match ($this->bodyFormat) {
                'form' => http_build_query($body),
                'raw' => throw new HttpClientException('A raw body must be given as a string.'),
                default => $this->encodeJson($body),
            };
        }

        return $this->body ?? '';
    }

    /**
     * @param array<string, mixed> $body
     */
    private function encodeJson(array $body): string
    {
        try {
            return json_encode($body, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new HttpClientException('Request body could not be encoded as JSON: '.$e->getMessage(), null, $e);
        }
    }

    /** Resolved Content-Type headers applied at send time (JSON/form). */
    public function contentTypeFor(?string $explicit): ?string
    {
        return $explicit ?? match ($this->bodyFormat) {
            'form' => 'application/x-www-form-urlencoded',
            'json' => 'application/json',
            default => null,
        };
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private static function hasHeader(array $headers, string $name): bool
    {
        foreach (array_keys($headers) as $existing) {
            if (strcasecmp($existing, $name) === 0) {
                return true;
            }
        }

        return false;
    }
}
