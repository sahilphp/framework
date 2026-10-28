<?php

declare(strict_types=1);

namespace Sahil\Tests\Examples;

use PHPUnit\Framework\TestCase;
use Sahil\Validation\Validator;

/**
 * Example UNIT test: no application, no container, no I/O.
 *
 * Unit tests in SahilPHP are plain PHPUnit tests. The framework does not
 * require booting an application for logic that does not depend on it, which
 * keeps the feedback loop fast.
 */
final class UnitExampleTest extends TestCase
{
    public function test_signup_rules_accept_a_valid_payload(): void
    {
        $validator = new Validator([
            'email' => 'required|email',
            'age' => 'nullable|integer|min:18',
        ]);

        $result = $validator->validate([
            'email' => 'buffy@example.test',
            'age' => '24',
        ]);

        self::assertTrue($result->passes());
        self::assertSame([
            'email' => 'buffy@example.test',
            'age' => 24,
        ], $result->data());
    }

    public function test_signup_rules_report_every_failing_field(): void
    {
        $validator = new Validator([
            'email' => 'required|email',
            'age' => 'nullable|integer|min:18',
        ]);

        $result = $validator->validate([
            'email' => 'not-an-address',
            'age' => '16',
        ]);

        self::assertTrue($result->fails());
        self::assertSame(['email', 'age'], array_keys($result->errors()));
        self::assertCount(2, $result->errorLines());
    }
}
