<?php

declare(strict_types=1);

namespace Sahil\Webhooks;

/**
 * System resolver: `gethostbynamel()` for A records (honours /etc/hosts,
 * so `localhost` resolves correctly) plus `dns_get_record` for AAAA.
 * Follows CNAMEs automatically through the resolver library.
 */
final class SystemDnsResolver implements DnsResolverContract
{
    public function resolve(string $host): array
    {
        $ips = [];

        $v4 = @gethostbynamel($host);

        if (is_array($v4)) {
            $ips = $v4;
        }

        $records = @dns_get_record($host, DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }
}
