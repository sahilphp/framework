<?php

declare(strict_types=1);

namespace Sahil\Tests\Mail;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Throwable;

/**
 * Symfony transport double: captures the MIME message our SMTP transport
 * built and can fail or reject on demand.
 */
final class RecordingSymfonyTransport implements TransportInterface
{
    /** @var list<RawMessage> */
    public array $messages = [];

    public function __construct(
        private readonly ?Throwable $failure = null,
        private readonly ?string $messageId = 'generated@sahilphp.local',
    ) {
    }

    public static function failingWith(string $message): self
    {
        return new self(new TransportException($message));
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $this->messages[] = $message;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($this->messageId === null) {
            return null;
        }

        $sent = new SentMessage($message, $envelope ?? Envelope::create($message));
        $sent->setMessageId($this->messageId);

        return $sent;
    }

    public function __toString(): string
    {
        return 'smtp://recording.test';
    }
}
