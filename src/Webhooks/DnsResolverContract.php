<?php

declare(strict_types=1);

namespace Sahil\Webhooks;

/**
 * Narrow DNS seam — resolves a hostname to IP literals. Injectable so
 * tests control answers deterministically; not a general DNS framework.
 */
interface DnsResolverContract
{
    /**
     * All A and AAAA answers for `$host` as IP literals.
     *
     * @return list<string> empty on failure — callers must fail closed
     */
    public function resolve(string $host): array;
}
