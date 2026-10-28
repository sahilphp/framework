<?php

declare(strict_types=1);

namespace Sahil\Tests\Mail;

use Sahil\Contracts\Mail\TransportContract;
use Sahil\Mail\MailReceipt;
use Sahil\Mail\Message;
use Throwable;

/**
 * Transport double: records what reached it and throws on demand.
 */
final class RecordingTransport implements TransportContract
{
    /** @var list<Message> */
    public array $messages = [];

    public function __construct(
        private readonly string $name = 'recording',
        private readonly ?Throwable $failure = null,
        private readonly ?MailReceipt $receipt = null,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function send(Message $message): MailReceipt
    {
        $this->messages[] = clone $message;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->receipt ?? MailReceipt::accepted($this->name, 'recording@example.test');
    }
}
