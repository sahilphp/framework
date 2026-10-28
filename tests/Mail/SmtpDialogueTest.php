<?php

declare(strict_types=1);

namespace Sahil\Tests\Mail;

use PHPUnit\Framework\TestCase;
use Sahil\Mail\MailStatus;
use Sahil\Mail\Message;
use Sahil\Mail\TransportException;
use Sahil\Mail\Transports\SmtpTransport;

/**
 * Exercises the real SMTP transport against a local, scripted SMTP server:
 * a genuine socket conversation, including EHLO, authentication, envelope,
 * DATA and the server's response codes.
 */
final class SmtpDialogueTest extends TestCase
{
    public function test_sends_a_multipart_message_and_reports_accepted(): void
    {
        $server = FakeSmtpServer::start('accept');
        $transport = new SmtpTransport('smtp', $server->configuration([
            'local_domain' => 'client.sahilphp.test',
        ]));

        try {
            $receipt = $transport->send(Message::make()
                ->from('no-reply@example.com', 'SahilPHP')
                ->to('ada@example.com', 'Ada Lovelace')
                ->bcc('audit@example.com')
                ->subject('Welcome')
                ->text("Hello Ada\nYour account is ready.")
                ->html('<p>Hello <strong>Ada</strong>, your account is ready.</p>'));
        } finally {
            $server->stop();
        }

        $transcript = $server->transcript();

        self::assertSame(MailStatus::Accepted, $receipt->status);
        self::assertSame('TESTID123', $receipt->messageId);
        self::assertStringContainsString('EHLO client.sahilphp.test', $transcript);
        self::assertStringContainsString('MAIL FROM:<no-reply@example.com>', $transcript);
        self::assertStringContainsString('RCPT TO:<ada@example.com>', $transcript);
        self::assertStringContainsString('Subject: Welcome', $transcript);
        self::assertStringContainsString('Your account is ready.', $transcript);
        self::assertStringContainsString('<strong>Ada</strong>', $transcript);
        self::assertStringContainsString('RCPT TO:<audit@example.com>', $transcript, 'Bcc belongs in the envelope');
        self::assertStringNotContainsString('C: Bcc:', $transcript, 'Bcc must not become a header');
        self::assertStringNotContainsString('C: AUTH PLAIN', $transcript, 'no credentials were configured');
    }

    public function test_authenticates_with_the_configured_credentials(): void
    {
        $server = FakeSmtpServer::start('accept');
        $transport = new SmtpTransport('smtp', $server->configuration([
            'auth' => [
                'username' => 'ada@mail.example.com',
                'password' => 's3cret-token',
            ],
        ]));

        try {
            $receipt = $transport->send(Message::make()
                ->from('no-reply@example.com')
                ->to('ada@example.com')
                ->subject('Authenticated')
                ->text('Body'));
        } finally {
            $server->stop();
        }

        $transcript = $server->transcript();

        self::assertSame(MailStatus::Accepted, $receipt->status);
        self::assertMatchesRegularExpression('/C: AUTH PLAIN (\S+)/', $transcript);

        preg_match('/C: AUTH PLAIN (\S+)/', $transcript, $matches);
        $decoded = base64_decode($matches[1], true);

        self::assertIsString($decoded);
        self::assertSame(
            "ada@mail.example.com\0ada@mail.example.com\0s3cret-token",
            $decoded,
        );
    }

    public function test_a_rejected_authentication_is_reported_without_credentials(): void
    {
        $server = FakeSmtpServer::start('reject_auth');
        $transport = new SmtpTransport('smtp', $server->configuration([
            'auth' => [
                'username' => 'ada@mail.example.com',
                'password' => 's3cret-token',
            ],
        ]));

        try {
            $transport->send(Message::make()
                ->from('no-reply@example.com')
                ->to('ada@example.com')
                ->subject('Rejected')
                ->text('Body'));

            self::fail('A rejected authentication must not report success.');
        } catch (TransportException $error) {
            self::assertStringContainsString('SMTP transport [smtp] failed', $error->getMessage());
            self::assertStringContainsString('535', $error->getMessage());
            self::assertStringNotContainsString('s3cret-token', $error->getMessage());
            self::assertStringNotContainsString('ada@mail.example.com', $error->getMessage());
        } finally {
            $server->stop();
        }
    }

    public function test_a_rejected_recipient_is_reported_as_a_failure(): void
    {
        $server = FakeSmtpServer::start('reject_rcpt');
        $transport = new SmtpTransport('smtp', $server->configuration());

        try {
            $transport->send(Message::make()
                ->from('no-reply@example.com')
                ->to('nobody@example.com')
                ->subject('Nope')
                ->text('Body'));

            self::fail('A rejected recipient must not report success.');
        } catch (TransportException $error) {
            self::assertStringContainsString('550', $error->getMessage());
            self::assertStringContainsString('No such user', $error->getMessage());
        } finally {
            $server->stop();
        }
    }
}
