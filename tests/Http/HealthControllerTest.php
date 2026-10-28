<?php

declare(strict_types=1);

namespace Sahil\Tests\Http;

use App\Http\HealthController;
use PHPUnit\Framework\TestCase;
use Sahil\Foundation\Application;
use Sahil\Foundation\ServiceProvider;

final class HealthControllerTest extends TestCase
{
    /** Path to a fixture installation root (tests/Fixtures/app-root). */
    private const FIXTURE = __DIR__.'/../Fixtures/app-root';

    protected function tearDown(): void
    {
        Application::free();
    }

    public function test_payload_reports_a_booted_application(): void
    {
        $app = Application::configure(self::FIXTURE);
        $app->register(new class ($app) extends ServiceProvider {});
        $app->boot();

        $payload = (new HealthController($app))->payload();

        self::assertSame('ok', $payload['status']);
        self::assertTrue($payload['booted']);
        self::assertSame(PHP_VERSION, $payload['php']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $payload['timestamp']);

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        self::assertSame($payload, json_decode($json, true, 512, JSON_THROW_ON_ERROR), 'payload must be lossless JSON');
    }

    public function test_payload_does_not_leak_secure_configuration(): void
    {
        $app = Application::configure(self::FIXTURE);
        $app->register(new class ($app) extends ServiceProvider {});
        $app->boot();

        $encoded = json_encode((new HealthController($app))->payload(), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsStringIgnoringCase('secret', $encoded);
        self::assertStringNotContainsStringIgnoringCase('password', $encoded);
        self::assertStringNotContainsStringIgnoringCase('basePath', $encoded);
    }
}
