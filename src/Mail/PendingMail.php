<?php

declare(strict_types=1);

namespace Sahil\Mail;

use Sahil\Contracts\Mail\MailerContract;

/**
 * The fluent half of {@see Mail::to()}: holds the recipients and any fields
 * set while chaining, then hands a {@see Message} — built inline or by a
 * {@see Mailable} — to the mailer.
 *
 *     Mail::to('a@b')->subject('Hi')->html('<b>x</b>')->send();
 *     Mail::to($user->email)->send(new WelcomeMail($user));
 *     Mail::mailer('smtp')->to('a@b')->queue(new InvoiceMail($invoice));
 *
 * When a mailable is sent through a pending message, recipients named on
 * the chain are merged into the mailable's message — the chain addresses
 * the message, the mailable composes it.
 */
final class PendingMail
{
    private readonly Message $message;

    public function __construct(
        private readonly MailerContract $mailer,
        private readonly ?string $transport = null,
    ) {
        $this->message = Message::make();
    }

    /* ---------- recipients & fields ---------- */

    public function to(Address|string $address, ?string $name = null): self
    {
        $this->message->to($address, $name);

        return $this;
    }

    public function cc(Address|string $address, ?string $name = null): self
    {
        $this->message->cc($address, $name);

        return $this;
    }

    public function bcc(Address|string $address, ?string $name = null): self
    {
        $this->message->bcc($address, $name);

        return $this;
    }

    public function from(Address|string $address, ?string $name = null): self
    {
        $this->message->from($address, $name);

        return $this;
    }

    public function replyTo(Address|string $address, ?string $name = null): self
    {
        $this->message->replyTo($address, $name);

        return $this;
    }

    public function subject(string $subject): self
    {
        $this->message->subject($subject);

        return $this;
    }

    public function text(string $text): self
    {
        $this->message->text($text);

        return $this;
    }

    public function html(string $html): self
    {
        $this->message->html($html);

        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->message->header($name, $value);

        return $this;
    }

    public function attach(Attachment|string $attachment, ?string $name = null, ?string $mime = null): self
    {
        $this->message->attach($attachment, $name, $mime);

        return $this;
    }

    public function attachData(string $body, string $name, ?string $mime = null): self
    {
        $this->message->attachData($body, $name, $mime);

        return $this;
    }

    /** Embed a resource for the HTML body; returns its content-id. */
    public function embed(Attachment|string $attachment, ?string $name = null, ?string $mime = null): string
    {
        return $this->message->embed($attachment, $name, $mime);
    }

    /* ---------- delivery ---------- */

    /**
     * Send now: the chained message, or the given mailable with the chained
     * recipients merged in.
     */
    public function send(?Mailable $mailable = null): MailReceipt
    {
        if ($mailable !== null && $this->mailer instanceof FakeMailer) {
            $this->mailer->noteMailable($mailable::class);
        }

        return $this->mailer->send($this->final($mailable), $this->transport);
    }

    /** Queue for later delivery (see {@see MailerContract::queue()}). */
    public function queue(?Mailable $mailable = null, int $delay = 0): MailReceipt
    {
        if ($mailable !== null && $this->mailer instanceof FakeMailer) {
            $this->mailer->noteMailable($mailable::class);
        }

        return $this->mailer->queue($this->final($mailable), $delay, $this->transport);
    }

    private function final(?Mailable $mailable): Message
    {
        if ($mailable === null) {
            return $this->message;
        }

        $message = $mailable->toMessage();

        // The chain addresses the message; the mailable composes it.
        foreach ($this->message->toAddresses() as $a) {
            $message->to($a);
        }
        foreach ($this->message->ccAddresses() as $a) {
            $message->cc($a);
        }
        foreach ($this->message->bccAddresses() as $a) {
            $message->bcc($a);
        }

        if ($message->sender() === null && $this->message->sender() !== null) {
            $message->from($this->message->sender());
        }

        return $message;
    }
}
