<?php

declare(strict_types=1);

namespace Sahil\Mail;

/**
 * One mail operation captured by {@see FakeMailer}.
 */
final class RecordedMail
{
    /**
     * @param class-string|null $mailable the mailable class when one was sent
     */
    public function __construct(
        public readonly Message $message,
        public readonly ?string $transport,
        public readonly int $delay,
        public readonly ?string $mailable,
    ) {
    }
}
