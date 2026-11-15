<?php

declare(strict_types=1);

namespace Sahil\Http\Client;

/**
 * One fully prepared outbound request — what the transport sends.
 * Immutable value object; `PendingRequest` builds it.
 */
final class OutgoingRequest
{
    /**
     * @param array<string, list<string>> $headers name => values
     * @param resource|null $sink writable stream for the response body
     * @param list<string>|null $resolve validated IP literals the
     *        connection must be pinned to (`CURLOPT_RESOLVE`) — DNS
     *        re-resolution is skipped and proxies are bypassed so a
     *        validated address cannot be swapped out (TOCTOU/SSRF).
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly string $body,
        public readonly float $connectTimeout,
        public readonly float $timeout,
        public readonly int $maxRedirects,
        public readonly mixed $sink = null,
        public readonly ?array $resolve = null,
    ) {
    }

    /** Headers with sensitive names stripped — used on cross-origin redirects. */
    public function withoutSensitiveHeaders(): self
    {
        $headers = $this->headers;

        foreach (['Authorization', 'Cookie', 'X-Api-Key', 'Proxy-Authorization'] as $sensitive) {
            foreach (array_keys($headers) as $name) {
                if (strcasecmp($name, $sensitive) === 0) {
                    unset($headers[$name]);
                }
            }
        }

        return new self(
            $this->method,
            $this->url,
            $headers,
            $this->body,
            $this->connectTimeout,
            $this->timeout,
            $this->maxRedirects,
            $this->sink,
            $this->resolve,
        );
    }

    public function withUrl(string $url): self
    {
        return new self(
            $this->method,
            $url,
            $this->headers,
            $this->body,
            $this->connectTimeout,
            $this->timeout,
            $this->maxRedirects,
            $this->sink,
            $this->resolve,
        );
    }

    /** New request with a changed method/body (redirect rewrites). */
    public function rewritten(string $method, string $url, string $body = ''): self
    {
        return new self(
            $method,
            $url,
            $this->headers,
            $body,
            $this->connectTimeout,
            $this->timeout,
            $this->maxRedirects,
            $this->sink,
            $this->resolve,
        );
    }
}
