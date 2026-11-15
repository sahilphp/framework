<?php

declare(strict_types=1);

namespace Sahil\Http\Middleware;

use Closure;
use Sahil\Http\Request;
use Sahil\Http\Response;

/**
 * Attach one server-generated request identifier to every response as
 * `X-Request-Id`.
 *
 * Two competing servers must never emit colliding identifiers, so the
 * identifier is generated once per request from a fresh 128-bit value and
 * is used for log/metrics/tracing cross-reference only. It is not part of
 * any authentication or authorization decision.
 */
final class RequestIdMiddleware implements MiddlewareContract
{
    /** Inbound header values must match this shape to be honored. */
    private const ID_PATTERN = '/^[A-Za-z0-9._:-]{8,128}$/';

    /**
     * @param string $header inbound correlation header to honor
     * @param bool $trustIncoming opt-in: whether a VALID inbound header
     *        value is reused (bounded validation) instead of always
     *        regenerating — enable only when a trusted upstream sets it
     */
    public function __construct(
        private readonly string $header = 'X-Request-Id',
        private readonly bool $trustIncoming = false,
        private readonly ?\Sahil\Support\Logging\LogManager $logs = null,
    ) {
    }

    /** {@inheritDoc} */
    public function handle(Request $request, Closure $next): Response
    {
        $id = $this->requestId($request);
        $logs = $this->logs;

        if ($logs !== null) {
            // request-scoped shared context — always restored on exit.
            return $logs->scope(['request_id' => $id], function () use ($request, $next, $id): Response {
                $response = $next($request);

                return $response->header('X-Request-Id')
                    ? $response
                    : $response->withRequestId($id);
            });
        }

        $response = $next($request);

        if (!$response->header('X-Request-Id')) {
            $response = $response->withRequestId($id);
        }

        return $response;
    }

    private function requestId(Request $request): string
    {
        $incoming = $request->header($this->header);

        if ($this->trustIncoming
            && is_string($incoming)
            && preg_match(self::ID_PATTERN, $incoming) === 1) {
            return $incoming;
        }

        return $this->generateRequestId();
    }

    private function generateRequestId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
