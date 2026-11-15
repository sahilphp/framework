<?php

declare(strict_types=1);

namespace Sahil\Support\Logging;

/**
 * Human-readable `line` format for local development:
 *
 *   [2026-11-15T02:40:00+00:00] ERROR file: Something broke {"user_id":7}
 */
final class LogLineFormatter
{
    /** @param array<string,mixed> $event */
    public static function render(array $event): string
    {
        $context = isset($event['context']) && is_array($event['context']) && $event['context'] !== []
            ? ' '.json_encode($event['context'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : '';

        return sprintf(
            "[%s] %s %s: %s%s\n",
            (string) ($event['timestamp'] ?? gmdate('c')),
            strtoupper((string) ($event['level'] ?? 'info')),
            (string) ($event['channel'] ?? '-'),
            (string) ($event['message'] ?? ''),
            $context,
        );
    }
}
