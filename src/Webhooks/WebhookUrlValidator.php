<?php

declare(strict_types=1);

namespace Sahil\Webhooks;

/**
 * Outbound destination policy for webhook endpoints.
 *
 * Two levels:
 * - {@see validate()} — structural + literal-IP policy, used at
 *   registration/update time.
 * - {@see resolve()} — runs immediately before each delivery attempt:
 *   re-validates the URL, resolves A/AAAA records, classifies *every*
 *   answer, and returns the validated IPs for connection-level pinning.
 *   Any non-public answer rejects the whole hostname. Resolution
 *   failures, empty results and malformed answers fail closed.
 *
 * Options: `allowHttp`/`allowPrivateHosts` are explicit development
 * overrides — they relax scheme/IP policy only; they never skip
 * structural validation, DNS resolution or pinning.
 */
final class WebhookUrlValidator
{
    public function __construct(
        private readonly bool $allowHttp = false,
        private readonly bool $allowPrivateHosts = false,
        private readonly ?DnsResolverContract $resolver = null,
    ) {
    }

    /** Structural + literal-IP policy check (registration-time). */
    public function validate(string $url): string
    {
        $parts = $this->parts($url);
        $host = $parts['host'];

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $this->checkIp($host, $url);
        } elseif (!$this->allowPrivateHosts && $this->isLocalName($host)) {
            throw new WebhookException(sprintf(
                'Webhook URL [%s] targets a local hostname.',
                $url,
            ));
        }

        return $url;
    }

    /**
     * Delivery-time: validate the URL, resolve the host, classify every
     * answer, and return the pins for the actual connection. Throws on
     * any violation — callers must not emit an HTTP request after a
     * throw and must never fall back to unpinned delivery.
     */
    public function resolve(string $url): ResolvedDestination
    {
        $parts = $this->parts($url);
        $host = $parts['host'];

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $this->checkIp($host, $url);

            return new ResolvedDestination($url, [$host]);
        }

        $resolved = ($this->resolver ?? new SystemDnsResolver())->resolve(strtolower(rtrim($host, '.')));

        if ($resolved === []) {
            throw new WebhookException(sprintf(
                'Webhook URL [%s] host could not be resolved to any address.',
                $url,
            ));
        }

        $ips = [];

        foreach ($resolved as $ip) {
            if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
                throw new WebhookException(sprintf('Webhook URL [%s] resolution returned a malformed answer.', $url));
            }

            if (!$this->allowPrivateHosts && !PublicIp::isPublic($ip)) {
                throw new WebhookException(sprintf(
                    'Webhook URL [%s] resolves to a non-public destination; the request was not sent.',
                    $url,
                ));
            }

            $ips[] = $ip;
        }

        return new ResolvedDestination($url, array_values(array_unique($ips)));
    }

    /** @return array{scheme:string,host:string} */
    private function parts(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new WebhookException(sprintf('Webhook URL [%s] is malformed.', $url));
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'https' && !($scheme === 'http' && $this->allowHttp)) {
            throw new WebhookException(sprintf(
                'Webhook URL [%s] must use https%s.',
                $url,
                $this->allowHttp ? ' or http' : '',
            ));
        }

        if ($scheme === 'http' && !$this->allowPrivateHosts && $this->isLocalName($parts['host'])) {
            throw new WebhookException(sprintf(
                'Webhook URL [%s] targets a local/private host over http; enable http only for local development.',
                $url,
            ));
        }

        $host = $parts['host'];

        // parse_url may return bracketed IPv6 — normalize to the bare
        // literal so filter_var classification applies.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        return ['scheme' => $scheme, 'host' => $host];
    }

    private function checkIp(string $ip, string $url): void
    {
        if (!$this->allowPrivateHosts && !PublicIp::isPublic($ip)) {
            throw new WebhookException(sprintf(
                'Webhook URL [%s] targets a private, loopback, link-local or reserved destination.',
                $url,
            ));
        }
    }

    private function isLocalName(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));

        return in_array($host, ['localhost', 'ip6-localhost'], true)
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
            || str_ends_with($host, '.localhost');
    }
}
