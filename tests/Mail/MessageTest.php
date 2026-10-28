<?php

declare(strict_types=1);

namespace Sahil\Tests\Mail;

use PHPUnit\Framework\TestCase;
use Sahil\Mail\InvalidMessageException;
use Sahil\Mail\Message;

final class MessageTest extends TestCase
{
    private function complete(): Message
    {
        return Message::make()
            ->from('no-reply@example.com', 'SahilPHP')
            ->to('ada@example.com', 'Ada')
            ->subject('Hello')
            ->text('Body');
    }

    public function test_tracks_sender_recipients_and_content(): void
    {
        $message = $this->complete()
            ->cc('cc@example.com')
            ->bcc('bcc@example.com')
            ->replyTo('support@example.com', 'Support')
            ->html('<p>Body</p>')
            ->header('X-Entity-Ref-ID', 'abc-123');

        self::assertSame('no-reply@example.com', $message->sender()?->email);
        self::assertSame(1, count($message->toAddresses()));
        self::assertSame(1, count($message->ccAddresses()));
        self::assertSame(1, count($message->bccAddresses()));
        self::assertSame(3, $message->recipientCount());
        self::assertSame('support@example.com', $message->replyToAddress()?->email);
        self::assertSame('Hello', $message->subjectLine());
        self::assertSame('Body', $message->textBody());
        self::assertSame('<p>Body</p>', $message->htmlBody());
        self::assertSame(['X-Entity-Ref-ID' => 'abc-123'], $message->headers());
        self::assertTrue($message->hasRecipients());
    }

    public function test_accepts_name_address_strings(): void
    {
        $message = Message::make()->to('Ada Lovelace <ada@example.com>');

        self::assertSame('Ada Lovelace', $message->toAddresses()[0]->name);
        self::assertSame('ada@example.com', $message->toAddresses()[0]->email);
    }

    public function test_an_explicit_name_wins_over_an_embedded_one(): void
    {
        $message = Message::make()->to('Ada Lovelace <ada@example.com>', 'Ada');

        self::assertSame('Ada', $message->toAddresses()[0]->name);
    }

    public function test_a_complete_message_is_sendable(): void
    {
        $this->complete()->assertSendable();

        self::assertTrue(true);
    }

    public function test_rejects_a_subject_with_a_line_break(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('subject');

        Message::make()->subject("Hello\r\nBcc: evil@example.com");
    }

    public function test_rejects_an_empty_subject(): void
    {
        $this->expectException(InvalidMessageException::class);

        Message::make()->subject('   ');
    }

    public function test_rejects_reserved_headers(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('managed by the message');

        Message::make()->header('Subject', 'override');
    }

    public function test_rejects_a_header_value_with_a_line_break(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('control characters');

        Message::make()->header('X-Custom', "one\r\nBcc: evil@example.com");
    }

    public function test_rejects_an_invalid_header_name(): void
    {
        $this->expectException(InvalidMessageException::class);

        Message::make()->header('X Custom', 'value');
    }

    public function test_a_message_without_a_sender_is_not_sendable(): void
    {
        $message = Message::make()->to('ada@example.com')->subject('Hello')->text('Body');

        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('no sender');

        $message->assertSendable();
    }

    public function test_a_message_without_recipients_is_not_sendable(): void
    {
        $message = Message::make()->from('no-reply@example.com')->subject('Hello')->text('Body');

        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('no recipients');

        $message->assertSendable();
    }

    public function test_a_message_without_a_subject_is_not_sendable(): void
    {
        $message = Message::make()->from('no-reply@example.com')->to('ada@example.com')->text('Body');

        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('no subject');

        $message->assertSendable();
    }

    public function test_a_message_without_a_body_is_not_sendable(): void
    {
        $message = Message::make()->from('no-reply@example.com')->to('ada@example.com')->subject('Hello');

        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('no text or HTML body');

        $message->assertSendable();
    }

    public function test_a_queued_message_survives_serialization(): void
    {
        $message = $this->complete()->html('<p>Body</p>')->bcc('bcc@example.com');

        $restored = unserialize(serialize($message));

        self::assertInstanceOf(Message::class, $restored);
        self::assertSame('no-reply@example.com', $restored->sender()?->email);
        self::assertSame('Hello', $restored->subjectLine());
        self::assertSame('<p>Body</p>', $restored->htmlBody());
        self::assertSame(2, $restored->recipientCount());
    }
}
