<?php

declare(strict_types=1);

namespace Sahil\Http\Client;

use RuntimeException;

/**
 * Outbound HTTP failures. `response()` carries the HTTP response when
 * the failure is an HTTP error status (via `ClientResponse::throw()`);
 * `null` means a transport-level failure (DNS, connect, TLS, timeout).
 */
final class HttpClientException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?ClientResponse $response = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function response(): ?ClientResponse
    {
        return $this->response;
    }
}
