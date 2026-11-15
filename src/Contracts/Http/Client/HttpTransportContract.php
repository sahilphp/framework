<?php

declare(strict_types=1);

namespace Sahil\Contracts\Http\Client;

use Sahil\Http\Client\ClientResponse;
use Sahil\Http\Client\OutgoingRequest;

/**
 * The internal transport seam — how one prepared request becomes a
 * response. `CurlTransport` is production; `FakeTransport` is tests.
 * Implementations must throw `HttpClientException` for transport
 * failures and never swallow HTTP error statuses.
 */
interface HttpTransportContract
{
    public function send(OutgoingRequest $request): ClientResponse;
}
