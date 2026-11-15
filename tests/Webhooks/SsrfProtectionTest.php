<?php

declare(strict_types=1);

namespace Sahil\Tests\Webhooks;

use PHPUnit\Framework\TestCase;
use Sahil\Http\Client\CurlTransport;
use Sahil\Http\Client\HttpClient;
use Sahil\Webhooks\PublicIp;
use Sahil\Webhooks\WebhookException;
use Sahil\Webhooks\WebhookUrlValidator;

/**
 * SSRF regression coverage: IP classification, DNS-answer policy, and —
 * decisively — connection-level pinning through real cURL.
 */
final class SsrfProtectionTest extends TestCase
{
    private FakeDnsForSsrf $dns;
    private WebhookUrlValidator $validator;

    protected function setUp(): void
    {
        $this->dns = new FakeDnsForSsrf();
        $this->validator = new WebhookUrlValidator(resolver: $this->dns);
    }

    /** IP classification — every non-public family fails closed. */
    public function test_ip_classification(): void
    {
        $public = ['8.8.8.8', '93.184.216.34', '1.1.1.1', '2606:4700:4700::1111', '2001:4860:4860::8888'];
        $blocked = [
            '0.0.0.0', '127.0.0.1', '127.99.0.1', '10.0.0.1', '10.255.255.255',
            '172.16.0.1', '172.31.255.255', '192.168.0.1', '192.168.255.255',
            '169.254.169.254',                       // link-local / cloud metadata
            '100.64.0.1', '100.127.255.255',         // CGNAT
            '224.0.0.1', '239.255.255.255', '240.0.0.1', '255.255.255.255',
            '192.0.2.1', '198.51.100.1', '203.0.113.1', // TEST-NETs
            '198.18.0.1', '192.88.99.1', '192.0.0.1',
            '::1', '::', 'fe80::1', 'febf::1', 'fc00::1', 'fd12:3456::1',
            'ff02::1', 'fec0::1',
            '2001:db8::1', '2001::1', '2001:2::1', '2001:10::1', '3fff::1', '5f00::1',
            // IPv4-mapped / compatible / NAT64 wrappers of non-public v4
            '::ffff:127.0.0.1', '::ffff:10.0.0.1', '::ffff:169.254.169.254',
            '::127.0.0.1', '::10.0.0.1', '64:ff9b::7f00:1', '64:ff9b::a00:1',
        ];

        foreach ($public as $ip) {
            self::assertTrue(PublicIp::isPublic($ip), "{$ip} should be public");
        }

        foreach ($blocked as $ip) {
            self::assertFalse(PublicIp::isPublic($ip), "{$ip} should be blocked");
        }

        // Mapped/embedded PUBLIC v4 remains public.
        self::assertTrue(PublicIp::isPublic('::ffff:8.8.8.8'));
        self::assertTrue(PublicIp::isPublic('64:ff9b::808:808'));

        // Malformed input fails closed.
        foreach (['', 'not-an-ip', '999.1.1.1', '12345::1::2', '0x7f.1'] as $bad) {
            self::assertFalse(PublicIp::isPublic($bad), "malformed {$bad} should be blocked");
        }
    }

    public function test_resolve_returns_validated_ips_for_public_hosts(): void
    {
        $this->dns->answers['api.example.com'] = ['93.184.216.34', '2606:4700:4700::1111'];
        $dest = $this->validator->resolve('https://api.example.com/hook');

        self::assertSame(['93.184.216.34', '2606:4700:4700::1111'], $dest->ips);
    }

    public function test_mixed_public_and_private_answers_reject_the_whole_host(): void
    {
        $this->dns->answers['evil.example.com'] = ['93.184.216.34', '169.254.169.254'];
        $this->expectException(WebhookException::class);
        $this->validator->resolve('https://evil.example.com/');
    }

    public function test_private_v4_v6_and_mapped_answers_all_reject(): void
    {
        foreach ([['10.0.0.5'], ['fd00::1'], ['::ffff:127.0.0.1'], ['192.168.1.1']] as $answers) {
            $this->dns->answers['x.example.com'] = $answers;

            try {
                $this->validator->resolve('https://x.example.com/');
                self::fail('Accepted '.implode(',', $answers));
            } catch (WebhookException) {
            }
        }
        self::assertTrue(true);
    }

    public function test_empty_failure_and_malformed_dns_fail_closed(): void
    {
        $this->dns->answers['empty.example.com'] = [];

        try {
            $this->validator->resolve('https://empty.example.com/');
            self::fail('empty answers accepted');
        } catch (WebhookException) {
        }

        $this->dns->answers['bad.example.com'] = ['not-an-ip'];

        try {
            $this->validator->resolve('https://bad.example.com/');
            self::fail('malformed answer accepted');
        } catch (WebhookException) {
        }

        self::assertTrue(true);
    }

    public function test_literal_non_public_ip_in_url_is_rejected(): void
    {
        foreach (['https://127.0.0.1/x', 'https://[::1]/x', 'https://[::ffff:10.0.0.1]/x', 'https://169.254.169.254/'] as $url) {
            try {
                $this->validator->resolve($url);
                self::fail("Accepted {$url}");
            } catch (WebhookException) {
            }
        }
        self::assertTrue(true);
    }

    public function test_dns_rebinding_second_attempt_revalidates(): void
    {
        // First validation: public. Simulate a record flip to private.
        $this->dns->answers['rebind.example.com'] = ['93.184.216.34'];
        $this->validator->resolve('https://rebind.example.com/');

        $this->dns->answers['rebind.example.com'] = ['127.0.0.1'];

        try {
            $this->validator->resolve('https://rebind.example.com/');
            self::fail('rebind accepted');
        } catch (WebhookException) {
        }

        self::assertSame(2, $this->dns->calls); // re-resolved, not cached
    }

    public function test_allow_private_still_resolves_and_pins(): void
    {
        $local = new WebhookUrlValidator(allowHttp: true, allowPrivateHosts: true, resolver: $this->dns);
        $this->dns->answers['dev.local'] = ['127.0.0.1'];

        $dest = $local->resolve('http://dev.local/hook');
        self::assertSame(['127.0.0.1'], $dest->ips); // override permits the IP but pinning still applies
    }

    /**
     * The decisive transport proof: `webhook.test` does NOT exist in
     * DNS — the request can only succeed if CURLOPT_RESOLVE pinned the
     * connection to the validated 127.0.0.1 while the URL, Host header
     * and TLS naming keep the original hostname.
     */
    public function test_curl_pins_connection_to_validated_address(): void
    {
        $server = self::localServer();

        if ($server === null) {
            self::markTestSkipped('local stub server not running');
        }

        $client = new HttpClient(new CurlTransport());
        $response = $client
            ->pending()
            ->timeout(5)
            ->connectTimeout(2)
            ->withResolvedAddresses(['127.0.0.1'])
            ->get("http://webhook.test:{$server}/json");

        self::assertSame(200, $response->status());
        // Host header preserved the original hostname, not the IP.
        self::assertSame("webhook.test:{$server}", $response->json()['host'] ?? null);
    }

    /** Without a pin, a non-existent host fails at DNS — the pin is what connected. */
    public function test_curl_without_pin_fails_dns_for_unknown_host(): void
    {
        $client = new HttpClient(new CurlTransport());
        $this->expectException(\Sahil\Http\Client\HttpClientException::class);
        $client->pending()->timeout(5)->connectTimeout(3)->get('http://webhook-definitely-unresolvable.invalid/x');
    }

    public function test_resolve_pins_survive_into_outgoing_request(): void
    {
        $fake = new \Sahil\Http\Client\FakeTransport(preventStray: false);
        (new HttpClient($fake))->pending()->withResolvedAddresses(['93.184.216.34', '2606:4700:4700::1111'])->get('https://api.example.com/x');

        self::assertSame(['93.184.216.34', '2606:4700:4700::1111'], $fake->recorded()[0]->resolve);
    }

    private static function localServer(): ?int
    {
        static $port = null;

        if ($port === null) {
            $port = false;

            foreach ([18931, 18932] as $candidate) {
                $sock = @fsockopen('127.0.0.1', $candidate, $e, $s, 0.3);

                if ($sock) {
                    fclose($sock);
                    $port = $candidate;
                    break;
                }
            }
        }

        return $port ?: null;
    }
}

final class FakeDnsForSsrf implements \Sahil\Webhooks\DnsResolverContract
{
    /** @var array<string, list<string>> */
    public array $answers = [];
    public int $calls = 0;

    public function resolve(string $host): array
    {
        $this->calls++;

        return $this->answers[$host] ?? ['93.184.216.34'];
    }
}
