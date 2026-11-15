<?php

declare(strict_types=1);

namespace Sahil\Events\Jobs;

use Sahil\Events\EventException;
use Sahil\Foundation\Application;
use Sahil\Queue\Jobs\Job;
use Throwable;

/**
 * Executes one queued listener for one event.
 *
 * The payload is exactly `(listener class, event object)` — serializable
 * event data and a listener identity, never the container, connections or
 * request state. The event is serialized once at construction so an
 * unserializable payload fails at dispatch time with a descriptive error,
 * not later inside a worker. Listener dependencies resolve fresh when the
 * job runs — long-running-worker safe.
 */
final class InvokeListenerJob extends Job
{
    private readonly string $serializedEvent;

    public function __construct(
        private readonly string $listener,
        object $event,
    ) {
        try {
            $this->serializedEvent = serialize($event);
        } catch (Throwable $e) {
            throw new EventException(sprintf(
                'Event [%s] cannot be queued for listener [%s]: it is not serializable (%s).',
                $event::class,
                $listener,
                $e->getMessage(),
            ), 0, $e);
        }
    }

    public function handle(): mixed
    {
        if (!class_exists($this->listener)) {
            throw new EventException(sprintf(
                'Queued listener class [%s] no longer exists.',
                $this->listener,
            ));
        }

        /** @var object $event */
        $event = unserialize($this->serializedEvent, ['allowed_classes' => true]);

        $container = Application::peek()?->container();
        $listener = $container !== null && $container->has($this->listener)
            ? $container->make($this->listener)
            : new $this->listener();

        $listener->handle($event);

        return null;
    }
}
