<?php

declare(strict_types=1);

namespace Sahil\Tests\Mail;

use PHPUnit\Framework\TestCase;
use Sahil\Mail\InvalidMessageException;
use Sahil\Mail\MailException;
use Sahil\Mail\MailStatus;
use Sahil\Mail\Message;
use Sahil\Mail\TransportException;
use Sahil\Mail\Transports\SmtpTransport;
use Symfony\Component\Mime\Email;

final class SmtpTransportTest extends TestCase
{
    private function message(): Message
    {
        return Message::make()
            ->from('no-reply@example.com', 'SahilPHP')
            ->to('ada@example.com', 'Ada Lovelace')
            ->cc('cc@example.com')
            ->bcc('audit@example.com')
            ->replyTo('support@example.com', 'Support')
            ->subject('Welcome')
            ->text('Hello Ada, your account is ready.')
            ->header('X-Entity-Ref-ID', 'abc-123');
    }

    public function test_maps_the_message_onto_a_mime_email(): void
    {
        $library = new RecordingSymfonyTransport();
        $transport = new SmtpTransport('smtp', ['host' => 'smtp.example.com'], $library);

        $receipt = $transport->send($this->message());

        self::assertSame(MailStatus::Accepted, $receipt->status);
        self::assertSame('generated@sahilphp.local', $receipt->messageId);
        self::assertCount(1, $library->messages);

        $email = $library->messages[0];
        self::assertInstanceOf(Email::class, $email);

        self::assertCount(1, $email->getFrom());
        self::assertSame('no-reply@example.com', $email->getFrom()[0]->getAddress());
        self::assertSame('SahilPHP', $email->getFrom()[0]->getName());

        self::assertSame('ada@example.com', $email->getTo()[0]->getAddress());
        self::assertSame('Ada Lovelace', $email->getTo()[0]->getName());
        self::assertSame('cc@example.com', $email->getCc()[0]->getAddress());
        self::assertSame('audit@example.com', $email->getBcc()[0]->getAddress());
        self::assertSame('support@example.com', $email->getReplyTo()[0]->getAddress());
        self::assertSame('Welcome', $email->getSubject());
        self::assertSame('Hello Ada, your account is ready.', $email->getTextBody());
        self::assertStringContainsString('X-Entity-Ref-ID: abc-123', $email->toString());
    }

    public function test_bcc_is_removed_from_the_serialized_message_but_kept_for_the_envelope(): void
    {
        $library = new RecordingSymfonyTransport();
        $transport = new SmtpTransport('smtp', ['host' => 'smtp.example.com'], $library);

        $transport->send($this->message());

        $email = $library->messages[0];
        self::assertInstanceOf(Email::class, $email);

        self::assertStringNotContainsString('audit@example.com', $email->toString());
        self::assertSame('audit@example.com', $email->getBcc()[0]->getAddress());
    }

    public function test_a_text_message_gains_an_escaped_html_alternative(): void
    {
        $library = new RecordingSymfonyTransport();
        $transport = new SmtpTransport('smtp', ['host' => 'smtp.example.com'], $library);

        $transport->send(Message::make()
            ->from('no-reply@example.com')
            ->to('ada@example.com')
            ->subject('Hello')
            ->text("Hi <script>alert(1)</script>\n\nBye"));

        $email = $library->messages[0];
        self::assertInstanceOf(Email::class, $email);

        $html = (string) $email->getHtmlBody();

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertSame("Hi <script>alert(1)</script>\n\nBye", $email->getTextBody());
    }

    public function test_an_html_message_gains_a_plain_text_alternative(): void
    {
        $library = new RecordingSymfonyTransport();
        $transport = new SmtpTransport('smtp', ['host' => 'smtp.example.com'], $library);

        $transport->send(Message::make()
            ->from('no-reply@example.com')
            ->to('ada@example.com')
            ->subject('Hello')
            ->html('<p>Hello <strong>Ada</strong></p><p><a href="https://example.com">Docs</a></p>'));

        $email = $library->messages[0];
        self::assertInstanceOf(Email::class, $email);

        self::assertSame("Hello Ada\n\nDocs (https://example.com)", $email->getTextBody());
        self::assertStringContainsString('<strong>Ada</strong>', (string) $email->getHtmlBody());
    }

    public function test_an_invalid_message_never_reaches_the_library(): void
    {
        $library = new RecordingSymfonyTransport();
        $transport = new SmtpTransport('smtp', ['host' => 'smtp.example.com'], $library);

        try {
            $transport->send(Message::make()->from('no-reply@example.com')->subject('Hello')->text('Body'));
            self::fail('A message without recipients must not be transmitted.');
        } catch (InvalidMessageException) {
            self::assertSame([], $library->messages);
        }
    }

    public function test_transport_errors_are_wrapped_and_sanitised(): void
    {
        $transport = new SmtpTransport(
            'smtp',
            ['host' => 'smtp.example.com'],
            RecordingSymfonyTransport::failingWith('AUTH PLAIN AHVzZXIAcGFzc3dvcmQ= for password=hunter2'),
        );

        try {
            $transport->send($this->message());
            self::fail('A failing transport must not report success.');
        } catch (TransportException $error) {
            self::assertStringContainsString('SMTP transport [smtp] failed', $error->getMessage());
            self::assertStringNotContainsString('hunter2', $error->getMessage());
            self::assertStringNotContainsString('AHVzZXIAcGFzc3dvcmQ=', $error->getMessage());
            self::assertNotNull($error->getPrevious());
        }
    }

    public function test_a_rejected_message_is_reported_as_a_failure(): void
    {
        $transport = new SmtpTransport(
            'smtp',
            ['host' => 'smtp.example.com'],
            new RecordingSymfonyTransport(null, null),
        );

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('refused the message');

        $transport->send($this->message());
    }

    public function test_a_missing_host_is_a_configuration_error(): void
    {
        $transport = new SmtpTransport('smtp', ['host' => ''], null);

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('has no host configured');

        $transport->send($this->message());
    }

    public function test_an_unsupported_security_mode_is_rejected(): void
    {
        $transport = new SmtpTransport('smtp', ['host' => 'smtp.example.com', 'security' => 'starttls-maybe'], null);

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('unsupported security mode');

        $transport->send($this->message());
    }

    public function test_authentication_requires_both_username_and_password(): void
    {
        $transport = new SmtpTransport('smtp', [
            'host' => 'smtp.example.com',
            'security' => 'none',
            'auth' => ['username' => 'ada', 'password' => ''],
        ], null);

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('both auth.username and auth.password');

        $transport->send($this->message());
    }
}
