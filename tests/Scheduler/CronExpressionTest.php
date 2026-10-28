<?php

declare(strict_types=1);

namespace Sahil\Tests\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Sahil\Scheduler\CronExpression;
use Sahil\Scheduler\SchedulerException;

final class CronExpressionTest extends TestCase
{
    private static function utc(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    public function test_every_minute_matches_any_instant(): void
    {
        $cron = CronExpression::parse('* * * * *');

        self::assertTrue($cron->isDue(self::utc('2026-01-01 00:00:00')));
        self::assertTrue($cron->isDue(self::utc('2026-10-28 12:34:00')));
        self::assertSame('* * * * *', $cron->expression());
    }

    public function test_step_values(): void
    {
        $cron = CronExpression::parse('*/15 * * * *');

        foreach (['00', '15', '30', '45'] as $minute) {
            self::assertTrue($cron->isDue(self::utc('2026-10-28 10:'.$minute.':00')), $minute);
        }

        self::assertFalse($cron->isDue(self::utc('2026-10-28 10:07:00')));
        self::assertFalse($cron->isDue(self::utc('2026-10-28 10:16:00')));
    }

    public function test_weekday_range(): void
    {
        $cron = CronExpression::parse('0 9 * * 1-5');

        self::assertTrue($cron->isDue(self::utc('2026-10-26 09:00:00')), 'Monday');
        self::assertFalse($cron->isDue(self::utc('2026-10-31 09:00:00')), 'Saturday');
        self::assertFalse($cron->isDue(self::utc('2026-10-26 09:01:00')), 'not the scheduled minute');
        self::assertFalse($cron->isDue(self::utc('2026-10-26 10:00:00')), 'not the scheduled hour');
    }

    public function test_month_and_weekday_names(): void
    {
        $cron = CronExpression::parse('0 9 * JAN MON');

        self::assertTrue($cron->isDue(self::utc('2026-01-05 09:00:00')));
        self::assertFalse($cron->isDue(self::utc('2026-01-06 09:00:00')), 'Tuesday');
        self::assertFalse($cron->isDue(self::utc('2026-02-02 09:00:00')), 'February');
    }

    public function test_sunday_is_also_seven(): void
    {
        $sunday = self::utc('2026-11-01 00:00:00');

        self::assertTrue(CronExpression::parse('0 0 * * 0')->isDue($sunday));
        self::assertTrue(CronExpression::parse('0 0 * * 7')->isDue($sunday));
    }

    public function test_both_day_fields_restricted_match_either(): void
    {
        // "the 1st of the month OR any Monday"
        $cron = CronExpression::parse('0 0 1 * 1');

        self::assertTrue($cron->isDue(self::utc('2026-06-01 00:00:00')), 'the 1st');
        self::assertTrue($cron->isDue(self::utc('2026-06-08 00:00:00')), 'a Monday');
        self::assertTrue($cron->isDue(self::utc('2026-07-01 00:00:00')), 'the 1st, not a Monday');
        self::assertFalse($cron->isDue(self::utc('2026-06-03 00:00:00')), 'neither');
        self::assertFalse($cron->isDue(self::utc('2026-06-09 00:00:00')), 'a Tuesday that is not the 1st');
    }

    public function test_a_star_day_field_requires_the_other_to_match(): void
    {
        $cron = CronExpression::parse('0 0 * * 1');

        self::assertTrue($cron->isDue(self::utc('2026-06-08 00:00:00')));
        self::assertFalse($cron->isDue(self::utc('2026-06-09 00:00:00')));
        self::assertFalse($cron->isDue(self::utc('2026-07-01 00:00:00')), 'the 1st is not automatically included');
    }

    public function test_ranges_with_steps_and_lists(): void
    {
        $range = CronExpression::parse('0-10/5 * * * *');

        self::assertTrue($range->isDue(self::utc('2026-10-28 10:00:00')));
        self::assertTrue($range->isDue(self::utc('2026-10-28 10:05:00')));
        self::assertTrue($range->isDue(self::utc('2026-10-28 10:10:00')));
        self::assertFalse($range->isDue(self::utc('2026-10-28 10:06:00')));

        $list = CronExpression::parse('5,10,20 * * * *');

        self::assertTrue($list->isDue(self::utc('2026-10-28 10:20:00')));
        self::assertFalse($list->isDue(self::utc('2026-10-28 10:21:00')));
    }

    public function test_seconds_do_not_affect_matching(): void
    {
        $cron = CronExpression::parse('0 9 * * *');

        self::assertTrue($cron->isDue(self::utc('2026-10-28 09:00:59')));
    }

    public function test_invalid_expressions_are_rejected(): void
    {
        $invalid = [
            '* * * *',
            '* * * * * *',
            '60 * * * *',
            '* 24 * * *',
            '* * 0 * *',
            '* * 32 * *',
            '* * * 13 *',
            '* * * * 8',
            '*/0 * * * *',
            '5-1 * * * *',
            'a * * * *',
            '* * * FOO *',
            '1,,2 * * * *',
        ];

        foreach ($invalid as $expression) {
            try {
                CronExpression::parse($expression);
                self::fail(sprintf('Expression [%s] should have been rejected.', $expression));
            } catch (SchedulerException) {
                self::assertTrue(true);
            }
        }
    }
}
