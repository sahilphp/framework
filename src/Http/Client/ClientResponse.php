<?php

declare(strict_types=1);

namespace Sahil\Http\Client;

use Sahil\Http\HeaderBag;

/**
 * An outbound request's HTTP response — status, headers and raw body.
 * `json()` decodes strictly; a failed `throw()` distinguishes HTTP
 * error statuses (response attached) from transport failures.
 */
final class ClientResponse
{
    private HeaderBag $headerBag;

    /**
     * @param array<string, list<string>>|HeaderBag $headers
     */
    public function __construct(
        private readonly int $status,
        array|HeaderBag $headers,
        private readonly string $body = '',
        private readonly ?string $effectiveUrl = null,
    ) {
        $this->headerBag = $headers instanceof HeaderBag ? $headers : new HeaderBag($headers);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function headers(): HeaderBag
    {
        return $this->headerBag;
    }

    public function header(string $name): ?string
    {
        return $this->headerBag->first($name);
    }

    public function body(): string
    {
        return $this->body;
    }

    public function effectiveUrl(): ?string
    {
        return $this->effectiveUrl;
    }

    /**
     * Decode the body as JSON. Empty body → `null`; malformed JSON throws
     * `HttpClientException` — never silently returns wrong data.
     *
     * @return mixed
     */
    public function json(): mixed
    {
        if ($this->body === '') {
            return null;
        }

        try {
            return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new HttpClientException(sprintf(
                'Response body is not valid JSON (%s).',
                $e->getMessage(),
            ), $this, $e);
        }
    }

    /** 2xx. */
    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function ok(): bool
    {
        return $this->status === 200;
    }

    public function created(): bool
    {
        return $this->status === 201;
    }

    public function noContent(): bool
    {
        return $this->status === 204;
    }

    public function redirect(): bool
    {
        return $this->status >= 300 && $this->status < 400;
    }

    /** 4xx. */
    public function clientError(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }

    /** 5xx. */
    public function serverError(): bool
    {
        return $this->status >= 500;
    }

    public function failed(): bool
    {
        return !$this->successful() && !$this->redirect();
    }

    public function unauthorized(): bool
    {
        return $this->status === 401;
    }

    public function forbidden(): bool
    {
        return $this->status === 403;
    }

    public function notFound(): bool
    {
        return $this->status === 404;
    }

    /**
     * Return `$this` on success; throw `HttpClientException` carrying the
     * response on 4xx/5xx.
     */
    public function throw(): self
    {
        if ($this->failed()) {
            throw new HttpClientException(sprintf(
                'HTTP request failed with status %d.',
                $this->status,
            ), $this);
        }

        return $this;
    }
}
