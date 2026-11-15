<?php

declare(strict_types=1);

namespace Sahil\Webhooks;

/**
 * Globally-routable IP classification for outbound destination policy.
 *
 * Explicit prefix tables on packed `inet_pton` bytes — covers
 * unspecified, loopback, private, link-local, CGNAT, multicast,
 * reserved, documentation, benchmarking and translation prefixes for
 * both families. IPv4-mapped IPv6 (`::ffff:a.b.c.d`), IPv4-compatible
 * (`::a.b.c.d`) and NAT64 (`64:ff9b::/96`) are classified by their
 * embedded IPv4 address. Malformed input fails closed.
 */
final class PublicIp
{
    /** IPv4 [network, mask] pairs that are NOT globally routable. */
    private const IPV4_BLOCKED = [
        ['0.0.0.0', '255.0.0.0'],          // "this" network / unspecified
        ['10.0.0.0', '255.0.0.0'],         // RFC 1918
        ['100.64.0.0', '255.192.0.0'],     // CGNAT RFC 6598
        ['127.0.0.0', '255.0.0.0'],        // loopback
        ['169.254.0.0', '255.255.0.0'],    // link-local
        ['172.16.0.0', '255.240.0.0'],     // RFC 1918
        ['192.0.0.0', '255.255.255.0'],    // IETF protocol assignments
        ['192.0.2.0', '255.255.255.0'],    // TEST-NET-1
        ['192.88.99.0', '255.255.255.0'],  // 6to4 relay (deprecated)
        ['192.168.0.0', '255.255.0.0'],    // RFC 1918
        ['198.18.0.0', '255.254.0.0'],     // benchmarking RFC 2544
        ['198.51.100.0', '255.255.255.0'], // TEST-NET-2
        ['203.0.113.0', '255.255.255.0'],  // TEST-NET-3
        ['224.0.0.0', '240.0.0.0'],        // multicast
        ['240.0.0.0', '240.0.0.0'],        // reserved (incl. broadcast)
    ];

    private function __construct()
    {
    }

    public static function isPublic(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return false; // malformed — fail closed
        }

        return strlen($packed) === 4 ? self::isPublicV4($packed) : self::isPublicV6($packed);
    }

    /** @param string $packed 4 packed bytes */
    public static function isPublicV4(string $packed): bool
    {
        $long = unpack('N', $packed)[1];

        foreach (self::IPV4_BLOCKED as [$net, $mask]) {
            if (($long & ip2long($mask)) === ip2long($net)) {
                return false;
            }
        }

        return true;
    }

    /** @param string $packed 16 packed bytes */
    public static function isPublicV6(string $packed): bool
    {
        if (strlen($packed) !== 16) {
            return false;
        }

        if ($packed === str_repeat("\x00", 16) || $packed === str_repeat("\x00", 15)."\x01") {
            return false; // unspecified, loopback
        }

        // IPv4-mapped ::ffff:a.b.c.d and IPv4-compatible ::a.b.c.d — embedded IPv4 decides.
        if (substr($packed, 0, 12) === str_repeat("\x00", 10)."\xff\xff"
            || substr($packed, 0, 12) === str_repeat("\x00", 12)) {
            return self::isPublicV4(substr($packed, 12));
        }

        // NAT64 well-known prefix 64:ff9b::/96 — embedded IPv4 decides.
        if (substr($packed, 0, 12) === hex2bin('0064ff9b0000000000000000')) {
            return self::isPublicV4(substr($packed, 12));
        }

        // Any other address inside ::/8 (reserved block).
        if ($packed[0] === "\x00") {
            return false;
        }

        $first = ord($packed[0]);
        $second = ord($packed[1]);

        if (($first & 0xFE) === 0xFC) {
            return false; // fc00::/7 unique-local
        }

        if ($first === 0xFE && ($second & 0xC0) === 0x80) {
            return false; // fe80::/10 link-local
        }

        if ($first === 0xFE && ($second & 0xC0) === 0xC0) {
            return false; // fec0::/10 site-local (deprecated)
        }

        if ($first === 0xFF) {
            return false; // ff00::/8 multicast
        }

        // 2001:db8::/32 documentation; 2001::/32 Teredo; 2001:2::/48
        // benchmarking; 2001:10::/28 ORCHID — non-global special use.
        if (substr($packed, 0, 4) === "\x20\x01\x0d\xb8"
            || substr($packed, 0, 4) === "\x20\x01\x00\x00"
            || substr($packed, 0, 6) === "\x20\x01\x00\x02\x00\x00"
            || substr($packed, 0, 4) === "\x20\x01\x00\x10") {
            return false;
        }

        // 3fff::/20 documentation (RFC 9637) — first 20 bits.
        if (substr($packed, 0, 2) === "\x3f\xff" && (ord($packed[2]) & 0xF0) === 0) {
            return false;
        }

        // 5f00::/16 segment routing SIDs (RFC 9602).
        if (substr($packed, 0, 2) === "\x5f\x00") {
            return false;
        }

        return true;
    }
}
