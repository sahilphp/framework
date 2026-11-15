<?php

declare(strict_types=1);

namespace Sahil\RateLimit;

use InvalidArgumentException;

/**
 * Parses human-readable rate-limit windows into seconds.
 *
 *     Duration::seconds('1 minute');  // 60
 *     Duration::seconds('5 minutes'); // 300
 *     Duration::seconds('1 hour');    // 3600
 *     Duration::seconds('1 day');     // 86400
 *     Duration::seconds('30 seconds');// 30
 *     Duration::seconds(90);          // 90 — ints pass through
 */
final class Duration
{
    private const UNITS = [
        'second' => 1, 'seconds' => 1, 'sec' => 1, 'secs' => 1, 's' => 1,
        'minute' => 60, 'minutes' => 60, 'min' => 60, 'mins' => 60, 'm' => 60,
        'hour' => 3600, 'hours' => 3600, 'hr' => 3600, 'hrs' => 3600, 'h' => 3600,
        'day' => 86400, 'days' => 86400, 'd' => 86400,
    ];

    private function __construct()
    {
    }

    /**
     * @throws InvalidArgumentException When the value cannot be interpreted
     */
    public static function seconds(string|int $duration): int
    {
        if (is_int($duration)) {
            if ($duration < 1) {
                throw new InvalidArgumentException(sprintf(
                    'Rate-limit duration must be a positive number of seconds; got %d.',
                    $duration,
                ));
            }

            return $duration;
        }

        $duration = strtolower(trim($duration));

        if (preg_match('/^(\d+)\s*([a-z]+)$/', $duration, $m) === 1
            && isset(self::UNITS[$m[2]])) {
            $seconds = (int) $m[1] * self::UNITS[$m[2]];

            if ($seconds > 0) {
                return $seconds;
            }
        }

        throw new InvalidArgumentException(sprintf(
            'Invalid rate-limit duration "%s" — use e.g. "30 seconds", "1 minute", "5 minutes", "1 hour" or "1 day".',
            $duration,
        ));
    }
}
