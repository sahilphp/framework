<?php

declare(strict_types=1);

namespace Sahil\Notifications;

use RuntimeException;
use Sahil\Contracts\Notifications\NotifiableContract;
use Sahil\Contracts\Notifications\NotificationContract;
use Sahil\Foundation\Application;
use Sahil\Notifications\Jobs\SendNotificationJob;

/**
 * Notify — the public entry point for sending notifications.
 *
 *     $user->notify(new WelcomeNotification());            // one recipient
 *     Notify::send($users, new WelcomeNotification());     // many
 *     Notify::queue($user, new WelcomeNotification());     // via the queue
 *
 * The static API delegates to the container-bound {@see Notifier} and
 * {@see NotificationStore} — same convention as `Bouncer` and
 * `RateLimiter`. Nothing is request-scoped: the facade resolves the
 * services per call, so long-running workers share definitions but
 * never state.
 */
final class Notify
{
    private static ?Notifier $notifier = null;

    private static ?NotificationStore $store = null;

    private function __construct()
    {
    }

    /**
     * Send to one notifiable or an iterable of them. Returns the report
     * for a single recipient, or one report per recipient.
     *
     * @return NotificationReport|list<NotificationReport>
     */
    public static function send(
        NotifiableContract|iterable $notifiables,
        NotificationContract $notification,
    ): NotificationReport|array {
        if ($notifiables instanceof NotifiableContract) {
            return self::notifier()->send($notifiables, $notification);
        }

        $reports = [];

        foreach ($notifiables as $notifiable) {
            $reports[] = self::notifier()->send($notifiable, $notification);
        }

        return $reports;
    }

    /**
     * Dispatch a notification through the queue. Returns the job id.
     */
    public static function queue(NotifiableContract $notifiable, NotificationContract $notification, int $delay = 0): string
    {
        return (new SendNotificationJob($notifiable, $notification))->dispatch($delay);
    }

    /**
     * The channel router — for providers registering channels and for
     * code that needs per-channel reports directly.
     */
    public static function notifier(): Notifier
    {
        if (self::$notifier !== null) {
            return self::$notifier;
        }

        /** @var Notifier $notifier */
        $notifier = self::container()->make(Notifier::class);

        return $notifier;
    }

    /**
     * The persistence backend for `database` notifications.
     */
    public static function store(): NotificationStore
    {
        if (self::$store !== null) {
            return self::$store;
        }

        /** @var NotificationStore $store */
        $store = self::container()->make(NotificationStore::class);

        return $store;
    }

    /** Install service instances — tests and non-application contexts. */
    public static function swap(?Notifier $notifier, ?NotificationStore $store = null): void
    {
        self::$notifier = $notifier;
        self::$store = $store;
    }

    /** Drop swapped instances. */
    public static function flushState(): void
    {
        self::$notifier = null;
        self::$store = null;
    }

    private static function container(): \Sahil\Container\Container
    {
        $application = Application::peek();

        if ($application === null) {
            throw new RuntimeException(
                'Notify needs a configured application or services installed via Notify::swap().',
            );
        }

        return $application->container();
    }
}
