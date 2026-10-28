<?php

declare(strict_types=1);

namespace Sahil\Tests\Testing;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Sahil\Http\Response;
use Sahil\Testing\TestConsoleResult;
use Sahil\Testing\TestLogger;
use Sahil\Testing\TestResponse;

/**
 * The testing utilities are framework code too: their assertions must fail
 * with actionable diagnostics and pass without being counted as risky.
 */
final class TestingUtilitiesTest extends TestCase
{
    public function test_response_assertions_pass_and_count_as_assertions(): void
    {
        $response = TestResponse::fromResponse('GET', '/example', Response::json(['ok' => true], 201)
            ->withHeader('X-Trace', 'abc'));

        $response
            ->assertStatus(201)
            ->assertHeader('x-trace', 'abc')
            ->assertHeaderMissing('X-Missing')
            ->assertJsonPath('ok', true)
            ->assertJsonCount(1);

        self::assertTrue(true);
    }

    public function test_failed_status_assertion_reports_request_status_and_body(): void
    {
        $response = TestResponse::fromResponse('GET', '/broken', Response::json(['error' => 'nope'], 500));

        try {
            $response->assertOk();
            self::fail('Expected the assertion to fail.');
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('GET /broken', $failure->getMessage());
            self::assertStringContainsString('Expected status 200, got 500', $failure->getMessage());
            self::assertStringContainsString('"error":"nope"', $failure->getMessage());
        }
    }

    public function test_missing_json_path_reports_the_actual_payload(): void
    {
        $response = TestResponse::fromResponse('GET', '/example', Response::json(['data' => ['id' => 7]]));

        try {
            $response->assertJsonPath('data.name', 'Buffy');
            self::fail('Expected the assertion to fail.');
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('data.name', $failure->getMessage());
            self::assertStringContainsString('"id": 7', $failure->getMessage());
        }
    }

    public function test_console_failure_reports_both_streams_and_exit_code(): void
    {
        $result = new TestConsoleResult("sahil 'migrate'", 1, 'partial output', 'database unavailable');

        try {
            $result->assertSuccessful();
            self::fail('Expected the assertion to fail.');
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('Exit code: 1', $failure->getMessage());
            self::assertStringContainsString('partial output', $failure->getMessage());
            self::assertStringContainsString('database unavailable', $failure->getMessage());
        }

        $result->assertFailed()->assertExitCode(1)->assertOutputContains('partial output');
    }

    public function test_test_logger_assertions_use_the_recorded_context(): void
    {
        $logger = new TestLogger();
        $logger->info('User registered', ['user_id' => 7]);

        $logger->assertLogged('info', 'User registered');
        self::assertSame([['user_id' => 7]], array_column($logger->records(), 'context'));

        $this->expectException(AssertionFailedError::class);
        $logger->assertLogged('error', 'User registered');
    }
}
