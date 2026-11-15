<?php

declare(strict_types=1);

namespace Sahil\Notifications;

use Sahil\Mail\Attachment;
use Sahil\Mail\Message;

/**
 * Fluent composer for notification emails — builds a `Sahil\Mail\Message`
 * with a greeting/lines/action/closing structure instead of raw body text.
 *
 *     return MailMessage::make()
 *         ->subject('Welcome')
 *         ->greeting('Welcome!')
 *         ->line('Your account is ready.')
 *         ->action('Open dashboard', 'https://app.test/dashboard')
 *         ->closing('The team');
 *
 * Plain-text body is composed from the parts; an optional `->html()`
 * overrides it entirely. `toMessage()` yields the transport-ready Message
 * — the mail channel still owns recipients and the default sender.
 */
final class MailMessage
{
    private string $subject = '';

    private ?string $greeting = null;

    /** @var list<string> */
    private array $lines = [];

    /** @var null|array{0: string, 1: string} */
    private ?array $action = null;

    private ?string $closing = null;

    private ?string $htmlBody = null;

    /** @var list<Attachment> */
    private array $attachments = [];

    private function __construct()
    {
    }

    public static function make(): self
    {
        return new self();
    }

    public function subject(string $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function greeting(string $greeting): self
    {
        $this->greeting = $greeting;

        return $this;
    }

    public function line(string $line): self
    {
        $this->lines[] = $line;

        return $this;
    }

    public function action(string $label, string $url): self
    {
        $this->action = [$label, $url];

        return $this;
    }

    public function closing(string $closing): self
    {
        $this->closing = $closing;

        return $this;
    }

    /** Attach a file to the composed message. */
    public function attach(Attachment|string $attachment, ?string $name = null, ?string $mime = null): self
    {
        $this->attachments[] = is_string($attachment)
            ? Attachment::fromPath($attachment, $name, $mime)
            : $attachment;

        return $this;
    }

    /** Attach in-memory contents under a filename. */
    public function attachData(string $body, string $name, ?string $mime = null): self
    {
        $this->attachments[] = Attachment::fromData($body, $name, $mime);

        return $this;
    }

    /** Override the composed text body with a full HTML body. */
    public function html(string $html): self
    {
        $this->htmlBody = $html;

        return $this;
    }

    /**
     * Compose the parts into the transport {@see Message}.
     */
    public function toMessage(): Message
    {
        $message = Message::make();

        if ($this->subject !== '') {
            $message->subject($this->subject);
        }

        $body = [];

        if ($this->greeting !== null) {
            $body[] = $this->greeting;
        }

        foreach ($this->lines as $line) {
            $body[] = $line;
        }

        if ($this->action !== null) {
            $body[] = $this->action[0].': '.$this->action[1];
        }

        if ($this->closing !== null) {
            $body[] = $this->closing;
        }

        if ($body !== []) {
            $message->text(implode("\n\n", $body));
        }

        if ($this->htmlBody !== null) {
            $message->html($this->htmlBody);
        }

        foreach ($this->attachments as $attachment) {
            $message->attach($attachment);
        }

        return $message;
    }
}
