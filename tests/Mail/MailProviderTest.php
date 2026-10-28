<?php

declare(strict_types=1);

namespace Sahil\Tests\Mail;

use App\Providers\MailProvider;
use PHPUnit\Framework\TestCase;
use Sahil\Contracts\Mail\MailerContract;
use Sahil\Foundation\Application;
use Sahil\Mail\MailManager;
use Sahil\Notifications\Notifier;
use Sahil\Support\Inspection\Inspector;

final class MailProviderTest extends TestCase
{
    private ?Application $application = null;

    protected function setUp(): void
    {
        Application::free();
    }

    protected function tearDown(): void
    {
        Application::free();
        $this->application = null;
    }

    private function boot(): Application
    {
        $application = Application::configure(dirname(__DIR__, 2));
        $application->loadConfiguration();
        $application->register(new MailProvider($application));
        $application->boot();

        return $this->application = $application;
    }

    public function test_the_provider_binds_the_mailer_contract_and_notifier(): void
    {
        $application = $this->boot();
        $container = $application->container();

        self::assertInstanceOf(MailManager::class, $container->make(MailerContract::class));
        self::assertSame($container->make(MailerContract::class), $container->make('mail'));
        self::assertSame($container->make(MailerContract::class), $container->make('mailer'));

        $notifier = $container->make('notifier');

        self::assertInstanceOf(Notifier::class, $notifier);
        self::assertSame(['mail'], array_keys($notifier->channels()));
    }

    public function test_configuration_exposes_mail_settings_with_credentials_redacted(): void
    {
        $application = $this->boot();
        $flat = Inspector::configSafe($application, 'mail');

        self::assertArrayHasKey('mail.default', $flat);
        self::assertSame('smtp', $flat['mail.transports.smtp.driver']);
        self::assertArrayHasKey('mail.transports.smtp.host', $flat);
        self::assertArrayHasKey('mail.transports.smtp.port', $flat);
        self::assertArrayHasKey('mail.transports.smtp.security', $flat);
        self::assertArrayHasKey('mail.transports.smtp.auth.username', $flat);
        self::assertSame('[redacted]', $flat['mail.transports.smtp.auth.password']);
        self::assertArrayHasKey('mail.queue.enabled', $flat);
        self::assertArrayHasKey('mail.queue.name', $flat);
    }

    public function test_environment_check_knows_the_documented_mail_variables(): void
    {
        $application = $this->boot();
        $report = implode("\n", Inspector::environmentCheck($application)['report']);

        self::assertStringContainsString('MAIL_TRANSPORT', $report);
        self::assertStringContainsString('MAIL_FROM_ADDRESS', $report);
        self::assertStringContainsString('MAIL_PASSWORD', $report);
    }
}
