<?php

declare(strict_types=1);

namespace Sahil\Webhooks;

/**
 * HMAC-SHA256 signing for outbound webhook payloads.
 *
 * Format: the signature covers `"{timestamp}.{raw_body}"` and is sent
 * hex-encoded in `X-Sahil-Signature` alongside `X-Sahil-Timestamp` and
 * `X-Sahil-Signature-Version: v1`. Receivers recompute
 * `hash_hmac('sha256', timestamp.'.'.body, secret)` and compare with
 * `hash_equals` — never `===` on the attacker-controlled input.
 */
final class WebhookSigner
{
    public const VERSION = 'v1';

    public function sign(string $secret, int $timestamp, string $body): string
    {
        if ($secret === '') {
            throw new WebhookException('A webhook signing secret must not be empty.');
        }

        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /** Constant-time verification for receiver-side helpers. */
    public function verify(string $secret, int $timestamp, string $body, string $signature): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return false;
        }

        return hash_equals($this->sign($secret, $timestamp, $body), $signature);
    }

    /** Freshness check — receivers should also bound `tolerance`. */
    public function isFresh(int $timestamp, int $toleranceSeconds = 300, ?int $now = null): bool
    {
        return abs(($now ?? time()) - $timestamp) <= $toleranceSeconds;
    }
}
