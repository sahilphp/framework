<?php

declare(strict_types=1);

namespace Sahil\Mail;

use Sahil\Contracts\View\ViewContract;
use Sahil\Foundation\Application;

/**
 * A reusable email: compose it once, send it anywhere.
 *
 *     final class WelcomeMail extends Mailable
 *     {
 *         public function __construct(private readonly User $user) {}
 *
 *         public function build(): void
 *         {
 *             $this->subject('Welcome, '.$this->user->name)
 *                  ->html($this->view('emails.welcome', ['user' => $this->user]));
 *         }
 *     }
 *
 *     Mail::to($user->email)->send(new WelcomeMail($user));
 *
 * `build()` mutates an internal {@see Message} through the protected
 * helpers below; {@see toMessage()} runs it lazily, so a mailable is cheap
 * to construct and serializes safely for the queue (it holds plain message
 * data, never transports or services).
 */
abstract class Mailable
{
    private ?Message $message = null;

    /** Compose the message: subject, bodies, attachments, extra headers. */
    abstract public function build(): void;

    /**
     * Build (once) and return the transport-ready message.
     */
    public function toMessage(): Message
    {
        if ($this->message === null) {
            $this->message = Message::make();
            $this->build();
        }

        return $this->message;
    }

    /* ---------- composition helpers ---------- */

    protected function from(Address|string $address, ?string $name = null): static
    {
        $this->draft()->from($address, $name);

        return $this;
    }

    protected function subject(string $subject): static
    {
        $this->draft()->subject($subject);

        return $this;
    }

    protected function text(string $text): static
    {
        $this->draft()->text($text);

        return $this;
    }

    protected function html(string $html): static
    {
        $this->draft()->html($html);

        return $this;
    }

    /**
     * Render a view template as the HTML body. Needs a bound
     * {@see ViewContract}; without one the exception is descriptive.
     */
    protected function view(string $view, array $data = []): static
    {
        $application = Application::peek();

        if ($application === null || !$application->container()->has(ViewContract::class)) {
            throw new MailException(sprintf(
                'Mailable [%s] called view() but no view engine is bound in the container.',
                static::class,
            ));
        }

        /** @var ViewContract $views */
        $views = $application->container()->make(ViewContract::class);
        $this->draft()->html($views->render($view, $data));

        return $this;
    }

    protected function replyTo(Address|string $address, ?string $name = null): static
    {
        $this->draft()->replyTo($address, $name);

        return $this;
    }

    protected function cc(Address|string $address, ?string $name = null): static
    {
        $this->draft()->cc($address, $name);

        return $this;
    }

    protected function bcc(Address|string $address, ?string $name = null): static
    {
        $this->draft()->bcc($address, $name);

        return $this;
    }

    protected function attach(Attachment|string $attachment, ?string $name = null, ?string $mime = null): static
    {
        $this->draft()->attach($attachment, $name, $mime);

        return $this;
    }

    protected function attachData(string $body, string $name, ?string $mime = null): static
    {
        $this->draft()->attachData($body, $name, $mime);

        return $this;
    }

    /** Embed a resource for the HTML body; returns its content-id. */
    protected function embed(Attachment|string $attachment, ?string $name = null, ?string $mime = null): string
    {
        return $this->draft()->embed($attachment, $name, $mime);
    }

    protected function header(string $name, string $value): static
    {
        $this->draft()->header($name, $value);

        return $this;
    }

    /** The message being built — created on first use. */
    private function draft(): Message
    {
        return $this->message ??= Message::make();
    }
}
