<?php

declare(strict_types=1);

namespace Sahil\Mail;

use Sahil\Contracts\Mail\MailerContract;
use Sahil\Contracts\Mail\TransportContract;
use Sahil\Mail\Transports\ArrayTransport;

/**
 * Test double for {@see MailerContract}: records what application code
 * tried to do instead of contacting a transport or a queue.
 *
 * `send()` and `queue()` still validate the message (sender, recipients,
 * subject, body) — a fake that accepts everything would hide real bugs —
 * and record a {@see RecordedMail} carrying the message, the mailable class
 * when one was used, the transport name and the requested delay.
 */
final class FakeMailer implements MailerContract
{
    /** @var list<RecordedMail> */
    private array $sent = [];

    /** @var list<RecordedMail> */
    private array $queued = [];

    /** @var array<string, string> default sender applied like MailManager's */
    private array $defaultFrom;

    private ?string $pendingMailable = null;

    public function __construct(array $defaultFrom = ['address' => 'mailer@example.com', 'name' => 'Mailer'])
    {
        $this->defaultFrom = $defaultFrom;
    }

    public function send(Message $message, ?string $transport = null): MailReceipt
    {
        $this->prepare($message);
        $this->sent[] = new RecordedMail($message, $transport, 0, $this->pendingMailable);
        $this->pendingMailable = null;

        return MailReceipt::accepted($transport ?? 'fake', sprintf('<%s@fake.sahilphp>', bin2hex(random_bytes(12))));
    }

    public function queue(Message $message, int $delay = 0, ?string $transport = null): MailReceipt
    {
        $this->prepare($message);
        $this->queued[] = new RecordedMail($message, $transport, $delay, $this->pendingMailable);
        $this->pendingMailable = null;

        return MailReceipt::queued($transport ?? 'fake', sprintf('fake-%s', bin2hex(random_bytes(8))));
    }

    /** Called by {@see PendingMail} so assertions can match mailable classes. */
    public function noteMailable(string $mailableClass): void
    {
        $this->pendingMailable = $mailableClass;
    }

    public function transport(?string $name = null): TransportContract
    {
        return new ArrayTransport($name ?? 'fake');
    }

    /** @return list<RecordedMail> */
    public function sent(): array
    {
        return $this->sent;
    }

    /** @return list<RecordedMail> */
    public function queued(): array
    {
        return $this->queued;
    }

    public function flush(): void
    {
        $this->sent = [];
        $this->queued = [];
        $this->pendingMailable = null;
    }

    private function prepare(Message $message): void
    {
        if ($message->sender() === null) {
            $message->from(
                (string) ($this->defaultFrom['address'] ?? 'mailer@example.com'),
                (string) ($this->defaultFrom['name'] ?? 'Mailer'),
            );
        }

        $message->assertSendable();
    }
}
