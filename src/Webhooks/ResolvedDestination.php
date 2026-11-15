<?php

declare(strict_types=1);

namespace Sahil\Webhooks;

/**
 * A validated webhook destination: the original URL plus the exact IP
 * literals the connection must be pinned to (TOCTOU-safe).
 */
final class ResolvedDestination
{
    /**
     * @param list<string> $ips validated public addresses for the host
     */
    public function __construct(
        public readonly string $url,
        public readonly array $ips,
    ) {
    }
}
