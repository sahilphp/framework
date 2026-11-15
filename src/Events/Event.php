<?php

declare(strict_types=1);

namespace Sahil\Events;

use Sahil\Contracts\Events\EventSubscriberContract;
use Sahil\Foundation\Application;

/**
 * Event — the framework's dispatch facade (same convention as
 * `Bouncer`/`RateLimiter`/`Notify`/`Render`).
 *
 *     Event::listen(UserRegistered::class, SendWelcomeNotification::class);
 *     Event::dispatch(new UserRegistered($user));
 *
 * Resolves the container-bound {@see EventDispatcher} (a provider may bind
 * it as a singleton); otherwise creates a default one against the
 * application container.
 */
final class Event
{
    private static ?EventDispatcher $instance = null;

    private function __construct()
    {
    }

    /**
     * @param class-string $event
     * @param class-string|callable $listener
     */
    public static function listen(string $event, string|callable $listener): void
    {
        self::dispatcher()->listen($event, $listener);
    }

    /** @param class-string|EventSubscriberContract $subscriber */
    public static function subscribe(string|EventSubscriberContract $subscriber): void
    {
        self::dispatcher()->subscribe($subscriber);
    }

    /** Dispatch to every matching listener, in registration order. */
    public static function dispatch(object $event): void
    {
        self::dispatcher()->dispatch($event);
    }

    /** @return list<string|callable> */
    public static function listeners(object|string $event): array
    {
        return self::dispatcher()->listeners($event);
    }

    public static function hasListeners(object|string $event): bool
    {
        return self::dispatcher()->hasListeners($event);
    }

    /** Remove all listeners for one event class. */
    public static function forget(string $event): void
    {
        self::dispatcher()->forget($event);
    }

    public static function forgetAll(): void
    {
        self::dispatcher()->forgetAll();
    }

    public static function dispatcher(): EventDispatcher
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $application = Application::peek();

        if ($application !== null) {
            $container = $application->container();

            if ($container->has(EventDispatcher::class)) {
                /** @var EventDispatcher $dispatcher */
                $dispatcher = $container->make(EventDispatcher::class);

                return $dispatcher;
            }

            return new EventDispatcher($container);
        }

        return new EventDispatcher();
    }

    /** Install the dispatcher the facade delegates to — tests and non-application contexts. */
    public static function swap(?EventDispatcher $dispatcher): void
    {
        self::$instance = $dispatcher;
    }

    /** Drop the swapped instance. */
    public static function flushState(): void
    {
        self::$instance = null;
    }
}
