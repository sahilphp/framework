<?php

declare(strict_types=1);

namespace Sahil\Tests\Mail;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sahil\Mail\InvalidMessageException;
use Sahil\Mail\Jobs\SendMailJob;
use Sahil\Mail\MailException;
use Sahil\Mail\MailManager;
use Sahil\Mail\MailReceipt;
use Sahil\Mail\MailStatus;
use Sahil\Mail\Message;
use Sahil\Mail\TransportException;
use Sahil\Mail\Transports\ArrayTransport;

final class MailManagerTest extends TestCase
{
    /**
     * @param array<string, mixed> $transports
     * @param array<string, mixed> $from
     */
    private function manager(
        array $transports = ['array' => ['driver' => 'array']],
        string $default = 'array',
        array $from = ['address' => 'no-reply@example.com', 'name' => 'SahilPHP'],
        bool $queueEnabled = false,
        ?CollectingLogger $logger = null,
        ?RecordingDispatcher $dispatcher = null,
        int $queueDelay = 0,
    ): MailManager {
        return new MailManager(
            $transports,
            $default,
            $from,
            $queueEnabled,
            'emails',
            $queueDelay,
            $logger,
            $dispatcher === null ? null : static fn (): RecordingDispatcher => $dispatcher,
        );
    }

    private function message(): Message
    {
        return Message::make()
            ->to('ada@example.com', 'Ada')
            ->subject('Your account is ready')
            ->text('Welcome aboard.');
    }

    public function test_sends_through_the_default_transport_and_applies_the_default_sender(): void
    {
        $manager = $this->manager();

        $receipt = $manager->send($this->message());

        self::assertSame(MailStatus::Accepted, $receipt->status);
        self::assertSame('array', $receipt->transport);
        self::assertNotNull($receipt->messageId);
        self::assertStringContainsString('accepted by the next hop', $receipt->describe());
        self::assertStringContainsString('not confirmed', $receipt->detail);

        $transport = $manager->transport('array');

        self::assertInstanceOf(ArrayTransport::class, $transport);
        self::assertCount(1, $transport->messages());
        self::assertSame('no-reply@example.com', $transport->messages()[0]->sender()?->email);
        self::assertSame('SahilPHP', $transport->messages()[0]->sender()?->name);
    }

    public function test_an_explicit_sender_is_not_overridden(): void
    {
        $manager = $this->manager();

        $manager->send($this->message()->from('support@example.com'));

        $transport = $manager->transport('array');

        self::assertInstanceOf(ArrayTransport::class, $transport);
        self::assertSame('support@example.com', $transport->messages()[0]->sender()?->email);
    }

    public function test_an_explicit_transport_is_used(): void
    {
        $manager = $this->manager(
            ['array' => ['driver' => 'array'], 'log' => ['driver' => 'log']],
            'log',
        );

        $receipt = $manager->send($this->message(), 'array');

        self::assertSame('array', $receipt->transport);

        $transport = $manager->transport('array');

        self::assertInstanceOf(ArrayTransport::class, $transport);
        self::assertCount(1, $transport->messages());
    }

    public function test_an_unknown_transport_lists_what_is_available(): void
    {
        $manager = $this->manager(['array' => ['driver' => 'array'], 'log' => ['driver' => 'log']]);

        try {
            $manager->send($this->message(), 'postmark');
            self::fail('An unknown transport must not be accepted.');
        } catch (MailException $error) {
            self::assertStringContainsString('[postmark]', $error->getMessage());
            self::assertStringContainsString('array, log', $error->getMessage());
        }
    }

    public function test_an_unsupported_driver_is_rejected(): void
    {
        $manager = $this->manager(['custom' => ['driver' => 'carrier-pigeon']], 'custom');

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Unsupported mail driver [carrier-pigeon]');

        $manager->transport('custom');
    }

    public function test_a_missing_sender_fails_before_a_transport_is_contacted(): void
    {
        $manager = $this->manager(['array' => ['driver' => 'array']], 'array', ['address' => '']);

        try {
            $manager->send($this->message());
            self::fail('A message with no sender must not be sent.');
        } catch (InvalidMessageException $error) {
            self::assertStringContainsString('No sender is configured', $error->getMessage());
        }

        $transport = $manager->transport('array');

        self::assertInstanceOf(ArrayTransport::class, $transport);
        self::assertSame([], $transport->messages());
    }

    public function test_a_message_without_recipients_is_rejected_before_sending(): void
    {
        $manager = $this->manager();
        $message = Message::make()->subject('Hello')->text('Body');

        $this->expectException(InvalidMessageException::class);

        try {
            $manager->send($message);
        } finally {
            $transport = $manager->transport('array');

            self::assertInstanceOf(ArrayTransport::class, $transport);
            self::assertSame([], $transport->messages());
        }
    }

    public function test_a_transport_failure_is_wrapped_and_sanitised(): void
    {
        $manager = $this->manager();
        $manager->extend('recording', new RecordingTransport('recording', new RuntimeException(
            'AUTH PLAIN AHVzZXIAcGFzc3dvcmQ= rejected for password=hunter2 at smtp://ada:s3cret@mail.example.com',
        )));

        try {
            $manager->send($this->message(), 'recording');
            self::fail('A failing transport must not report success.');
        } catch (TransportException $error) {
            self::assertStringNotContainsString('AHVzZXIAcGFzc3dvcmQ=', $error->getMessage());
            self::assertStringNotContainsString('hunter2', $error->getMessage());
            self::assertStringNotContainsString('s3cret', $error->getMessage());
            self::assertStringContainsString('smtp://[redacted]@mail.example.com', $error->getMessage());
            self::assertInstanceOf(RuntimeException::class, $error->getPrevious());
        }
    }

    public function test_a_mail_exception_from_a_transport_is_not_wrapped_twice(): void
    {
        $failure = new TransportException('SMTP transport [smtp] failed: connection refused.');
        $manager = $this->manager();
        $manager->extend('recording', new RecordingTransport('recording', $failure));

        try {
            $manager->send($this->message(), 'recording');
            self::fail('A failing transport must not report success.');
        } catch (TransportException $error) {
            self::assertSame($failure, $error);
        }
    }

    public function test_a_successful_send_is_logged_without_message_content(): void
    {
        $logger = new CollectingLogger();
        $manager = $this->manager(logger: $logger);

        $manager->send(Message::make()
            ->to('ada@example.com')
            ->subject('Sensitive subject')
            ->text('Sensitive body')
            ->html('<p>Sensitive body</p>'));

        $entry = $logger->last();

        self::assertNotNull($entry);
        self::assertSame('info', $entry['level']);
        self::assertSame('mail.accepted', $entry['message']);
        self::assertSame('accepted', $entry['context']['status']);
        self::assertSame('array', $entry['context']['transport']);
        self::assertSame(1, $entry['context']['recipients']);

        $logged = json_encode($logger->entries, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('Sensitive subject', $logged);
        self::assertStringNotContainsString('Sensitive body', $logged);
        self::assertStringNotContainsString('ada@example.com', $logged);
    }

    public function test_a_transport_failure_is_logged_without_credentials(): void
    {
        $logger = new CollectingLogger();
        $manager = $this->manager(logger: $logger);
        $manager->extend('recording', new RecordingTransport('recording', new RuntimeException(
            'password=hunter2 rejected',
        )));

        try {
            $manager->send($this->message(), 'recording');
        } catch (TransportException) {
            // Expected; the log entry is what this test asserts.
        }

        $entry = $logger->last();

        self::assertNotNull($entry);
        self::assertSame('error', $entry['level']);
        self::assertSame('mail.failed', $entry['message']);
        self::assertSame('recording', $entry['context']['transport']);
        self::assertSame('failed', $entry['context']['status']);

        $logged = json_encode($logger->entries, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('hunter2', $logged);
        self::assertStringNotContainsString('Your account is ready', $logged);
    }

    public function test_an_extended_transport_wins_over_configuration(): void
    {
        $manager = $this->manager();
        $recording = new RecordingTransport('recording', null, MailReceipt::accepted('recording', 'fixed-id'));
        $manager->extend('array', $recording);

        $receipt = $manager->send($this->message());

        self::assertSame('recording', $receipt->transport);
        self::assertSame('fixed-id', $receipt->messageId);
        self::assertCount(1, $recording->messages);
    }

    public function test_queue_is_disabled_by_default_and_never_sends(): void
    {
        $dispatcher = new RecordingDispatcher();
        $manager = $this->manager(dispatcher: $dispatcher);

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Queued mail delivery is disabled');

        try {
            $manager->queue($this->message());
        } finally {
            self::assertSame([], $dispatcher->pushed);
        }
    }

    public function test_queue_stores_a_job_and_reports_queued_without_sending(): void
    {
        $logger = new CollectingLogger();
        $dispatcher = new RecordingDispatcher();
        $manager = $this->manager(queueEnabled: true, logger: $logger, dispatcher: $dispatcher);

        $receipt = $manager->queue($this->message(), 30);

        self::assertSame(MailStatus::Queued, $receipt->status);
        self::assertTrue($receipt->status->isPending());
        self::assertSame('queued-1', $receipt->queueId);
        self::assertStringContainsString('no transport has attempted it yet', $receipt->detail);

        self::assertCount(1, $dispatcher->pushed);
        self::assertSame('emails', $dispatcher->pushed[0]['queue']);
        self::assertSame(30, $dispatcher->pushed[0]['delay']);
        self::assertInstanceOf(SendMailJob::class, $dispatcher->pushed[0]['job']);

        $transport = $manager->transport('array');

        self::assertInstanceOf(ArrayTransport::class, $transport);
        self::assertSame([], $transport->messages(), 'queueing must not send');

        $entry = $logger->last();

        self::assertNotNull($entry);
        self::assertSame('mail.queued', $entry['message']);
        self::assertSame('queued', $entry['context']['status']);
        self::assertSame('queued-1', $entry['context']['queue_id']);
    }

    public function test_queue_uses_the_configured_default_delay(): void
    {
        $dispatcher = new RecordingDispatcher();
        $manager = $this->manager(queueEnabled: true, dispatcher: $dispatcher, queueDelay: 5);

        $manager->queue($this->message());

        self::assertSame(5, $dispatcher->pushed[0]['delay']);
    }

    public function test_a_queue_failure_is_wrapped_and_sanitised(): void
    {
        $manager = $this->manager(
            queueEnabled: true,
            dispatcher: new RecordingDispatcher(new RuntimeException('connection lost password=hunter2')),
        );

        try {
            $manager->queue($this->message());
            self::fail('A failed enqueue must not report success.');
        } catch (MailException $error) {
            self::assertStringContainsString('could not be queued', $error->getMessage());
            self::assertStringNotContainsString('hunter2', $error->getMessage());
            self::assertStringContainsString('password=[redacted]', $error->getMessage());
        }
    }

    public function test_queued_delivery_needs_a_dispatcher(): void
    {
        $manager = $this->manager(queueEnabled: true, dispatcher: null);

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('needs a queue dispatcher');

        $manager->queue($this->message());
    }
}
