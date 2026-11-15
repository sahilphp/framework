<?php

declare(strict_types=1);

namespace Sahil\Mail;

use Sahil\Contracts\Mail\MailerContract;
use Sahil\Foundation\Application;

/**
 * Mail — the official email facade.
 *
 *     Mail::to('customer@example.com')
 *         ->subject('Welcome')
 *         ->html('<h1>Welcome!</h1>')
 *         ->text('Welcome!')
 *         ->send();
 *
 *     Mail::to($user->email)->send(new WelcomeMail($user));
 *     Mail::to($user->email)->queue(new InvoiceMail($invoice));
 *     Mail::mailer('smtp')->to('ops@example.com')->send($alert);
 *
 *     Mail::fake();
 *     Mail::assertSent(WelcomeMail::class);
 *     Mail::assertQueued();
 *     Mail::assertNothingSent();
 *
 * Thin facade over the container-bound {@see MailerContract} — same
 * convention as `Log`/`Kapu`/`Http`. `Mail::fake()` swaps the binding, so
 * code that injects the contract sees the fake too.
 */
final class Mail
{
    private static ?MailerContract $instance = null;

    private static ?MailerContract $previous = null;

    private function __construct()
    {
    }

    // ---- Builder entry points --------------------------------------------

    /** Start a pending message addressed to the recipient. */
    public static function to(Address|string $address, ?string $name = null): PendingMail
    {
        return self::pending()->to($address, $name);
    }

    /** Select a named transport, then chain as usual. */
    public static function mailer(string $name): PendingMail
    {
        return new PendingMail(self::mailerInstance(), $name);
    }

    /** An unaddressed pending message (add recipients on the chain). */
    public static function pending(): PendingMail
    {
        return new PendingMail(self::mailerInstance());
    }

    // ---- Direct send/queue ----------------------------------------------

    public static function send(Message|Mailable $message): MailReceipt
    {
        if ($message instanceof Mailable) {
            return self::pending()->send($message);
        }

        return self::mailerInstance()->send($message);
    }

    public static function queue(Message|Mailable $message, int $delay = 0): MailReceipt
    {
        if ($message instanceof Mailable) {
            return self::pending()->queue($message, $delay);
        }

        return self::mailerInstance()->queue($message, $delay);
    }

    // ---- Fakes & assertions ----------------------------------------------

    /**
     * Install a {@see FakeMailer} that records sends/queues instead of
     * touching transports. The container binding is swapped too, so code
     * that injects {@see MailerContract} is intercepted as well. Call
     * {@see flushState()} (e.g. in tearDown) to restore the real mailer.
     */
    public static function fake(): FakeMailer
    {
        $fake = new FakeMailer();
        self::$previous = null;

        $application = Application::peek();

        if ($application !== null && $application->container()->has(MailerContract::class)) {
            /** @var MailerContract $previous */
            $previous = $application->container()->make(MailerContract::class);
            self::$previous = $previous;

            // Aliases resolve to the base name before instances are
            // checked, so the fake must be bound where lookups land.
            $application->container()->instance(MailerContract::class, $fake);
            $application->container()->instance($previous::class, $fake);
        }

        self::$instance = $fake;

        return $fake;
    }

    /** The installed fake, or null when the real mailer is in use. */
    public static function fakeMailer(): ?FakeMailer
    {
        $mailer = self::mailerInstance();

        return $mailer instanceof FakeMailer ? $mailer : null;
    }

    /** @return list<RecordedMail> every send() recorded by the fake */
    public static function sent(): array
    {
        return self::fakeMailer()?->sent() ?? [];
    }

    /** @return list<RecordedMail> every queue() recorded by the fake */
    public static function queued(): array
    {
        return self::fakeMailer()?->queued() ?? [];
    }

    /**
     * Assert at least one send() matched the filter: a mailable class name,
     * a predicate on {@see RecordedMail} (or on {@see Message}), or null
     * for "anything was sent".
     */
    public static function assertSent(string|callable|null $filter = null, ?int $times = null): void
    {
        self::assertMatching(self::sent(), $filter, $times, 'sent');
    }

    /** Same as {@see assertSent()} for messages passed to queue(). */
    public static function assertQueued(string|callable|null $filter = null, ?int $times = null): void
    {
        self::assertMatching(self::queued(), $filter, $times, 'queued');
    }

    public static function assertNothingSent(): void
    {
        self::assertThat(
            self::sent() === [] && self::queued() === [],
            sprintf(
                'Expected no mail to be sent or queued, but %d were sent and %d queued.',
                count(self::sent()),
                count(self::queued()),
            ),
        );
    }

    /** Assert through PHPUnit when it is on the classpath; throw otherwise. */
    private static function assertThat(bool $ok, string $message): void
    {
        if (class_exists(\PHPUnit\Framework\Assert::class)) {
            \PHPUnit\Framework\Assert::assertTrue($ok, $message);

            return;
        }

        if (!$ok) {
            throw new MailException($message);
        }
    }

    /**
     * @param list<RecordedMail> $records
     * @param string|callable(RecordedMail|Message): bool|null $filter
     */
    private static function assertMatching(array $records, string|callable|null $filter, ?int $times, string $mode): void
    {
        $matches = array_values(array_filter($records, static function (RecordedMail $record) use ($filter): bool {
            if ($filter === null) {
                return true;
            }

            if (is_string($filter)) {
                return $record->mailable === $filter;
            }

            return (bool) $filter($record);
        }));

        $count = count($matches);
        $ok = $times === null ? $count > 0 : $count === $times;

        $expected = is_string($filter) ? $filter : (is_callable($filter) ? 'matching predicate' : 'any message');

        self::assertThat($ok, sprintf(
            'Expected %s to be %s%s, but %d record(s) matched.',
            $expected,
            $mode,
            $times === null ? '' : sprintf(' exactly %d time(s)', $times),
            $count,
        ));
    }

    // ---- Mailer resolution ------------------------------------------------

    private static function mailerInstance(): MailerContract
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $application = Application::peek();

        if ($application !== null && $application->container()->has(MailerContract::class)) {
            /** @var MailerContract $mailer */
            $mailer = $application->container()->make(MailerContract::class);

            return $mailer;
        }

        throw new MailException(
            'Mail needs a configured application with a bound MailerContract — or Mail::swap() for tests.',
        );
    }

    /** Install the mailer the facade delegates to — tests and non-application contexts. */
    public static function swap(?MailerContract $mailer): void
    {
        self::$instance = $mailer;
    }

    /** Drop the swapped instance — restores a fake's container binding too. */
    public static function flushState(): void
    {
        self::$instance = null;

        if (self::$previous !== null) {
            $application = Application::peek();

            if ($application !== null) {
                $application->container()->instance(MailerContract::class, self::$previous);
                $application->container()->instance(self::$previous::class, self::$previous);
            }

            self::$previous = null;
        }
    }
}
