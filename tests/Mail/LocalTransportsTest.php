<?php

declare(strict_types=1);

namespace Sahil\Tests\Mail;

use PHPUnit\Framework\TestCase;
use Sahil\Mail\MailStatus;
use Sahil\Mail\Message;
use Sahil\Mail\Transports\ArrayTransport;
use Sahil\Mail\Transports\LogTransport;

final class LocalTransportsTest extends TestCase
{
    private function message(): Message
    {
        return Message::make()
            ->from('no-reply@example.com')
            ->to('ada@example.com')
            ->subject('Hello')
            ->text('Body')
            ->html('<p>Body</p>');
    }

    public function test_the_log_transport_suppresses_rather_than_sends(): void
    {
        $receipt = (new LogTransport())->send($this->message());

        self::assertSame(MailStatus::Suppressed, $receipt->status);
        self::assertSame('log', $receipt->transport);
        self::assertNull($receipt->messageId);
        self::assertStringContainsString('Not transmitted', $receipt->detail);
        self::assertTrue($receipt->successful(), 'Suppression is intentional, not a failure.');
        self::assertFalse($receipt->status->isPending());
    }

    public function test_the_array_transport_records_a_snapshot_of_each_message(): void
    {
        $transport = new ArrayTransport();
        $message = $this->message();

        $first = $transport->send($message);

        $message->to('second@example.com');

        self::assertSame(MailStatus::Accepted, $first->status);
        self::assertNotSame('', (string) $first->messageId);
        self::assertCount(1, $transport->messages());
        self::assertSame(1, $transport->messages()[0]->recipientCount(), 'stored message is a snapshot');
        self::assertSame(2, $message->recipientCount());

        $transport->send($message);

        self::assertCount(2, $transport->messages());
        self::assertSame(2, $transport->last()?->recipientCount());

        $transport->flush();

        self::assertSame([], $transport->messages());
        self::assertNull($transport->last());
    }
}
