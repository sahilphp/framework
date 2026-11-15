<?php

declare(strict_types=1);

namespace Sahil\Events;

use ReflectionClass;
use ReflectionNamedType;
use Sahil\Container\Container;
use Sahil\Contracts\Events\EventSubscriberContract;
use Sahil\Contracts\Events\QueueableContract;
use Sahil\Events\Jobs\InvokeListenerJob;
use Sahil\Foundation\Application;

/**
 * The single authoritative event dispatcher.
 *
 * Listeners are `class-string`s (resolved through the container per
 * execution — constructor DI included) or plain callables. Registration
 * order is execution order; duplicate registrations are rejected so a
 * repeated provider boot can't double-fire a listener.
 *
 * A listener implementing {@see QueueableContract} is submitted to the
 * existing queue as an {@see InvokeListenerJob} instead of running
 * inline. The dispatcher holds no listener instances and no event data,
 * so nothing request-scoped can leak across worker cycles.
 */
final class EventDispatcher
{
    /** @var array<string, list<string|callable>> event class => listeners */
    private array $listeners = [];

    public function __construct(
        private readonly ?Container $container = null,
    ) {
    }

    /**
     * @param class-string $event
     * @param class-string|callable $listener
     */
    public function listen(string $event, string|callable $listener): void
    {
        if (!class_exists($event) && !interface_exists($event)) {
            throw new EventException(sprintf('Cannot listen for unknown event class [%s].', $event));
        }

        if (is_string($listener)) {
            $this->assertListenerClass($listener);
        }

        if (in_array($listener, $this->listeners[$event] ?? [], true)) {
            throw new EventException(sprintf(
                'Listener is already registered for event [%s].',
                $event,
            ));
        }

        $this->listeners[$event][] = $listener;
    }

    /**
     * Register every mapping a subscriber declares. Class-string subscribers
     * are resolved through the container when one is bound.
     *
     * @param class-string|EventSubscriberContract $subscriber
     */
    public function subscribe(string|EventSubscriberContract $subscriber): void
    {
        if (is_string($subscriber)) {
            $subscriber = $this->resolve($subscriber);
        }

        if (!$subscriber instanceof EventSubscriberContract) {
            throw new EventException(sprintf(
                'Subscriber [%s] must implement %s.',
                $subscriber::class,
                EventSubscriberContract::class,
            ));
        }

        foreach ($subscriber->subscribe() as $event => $listeners) {
            foreach ($listeners as $listener) {
                $this->listen($event, $listener);
            }
        }
    }

    /** @return list<string|callable> matching listeners, in registration order */
    public function listeners(object|string $event): array
    {
        $matched = [];

        foreach ($this->listeners as $registered => $listeners) {
            $applies = is_string($event)
                ? $event === $registered || is_subclass_of($event, $registered)
                : $event instanceof $registered;

            if ($applies) {
                array_push($matched, ...$listeners);
            }
        }

        return $matched;
    }

    public function hasListeners(object|string $event): bool
    {
        return $this->listeners($event) !== [];
    }

    /** Remove all listeners for one event class (exact key). */
    public function forget(string $event): void
    {
        unset($this->listeners[$event]);
    }

    public function forgetAll(): void
    {
        $this->listeners = [];
    }

    /**
     * Run every matching listener in registration order. Synchronous
     * listeners execute inline — the first failure propagates and stops
     * dispatch; queued listeners are submitted to the queue.
     */
    public function dispatch(object $event): void
    {
        foreach ($this->listeners($event) as $listener) {
            if (is_string($listener) && is_subclass_of($listener, QueueableContract::class)) {
                $this->queue($listener, $event);

                continue;
            }

            $this->invoke($listener, $event);
        }
    }

    /**
     * @param class-string|callable $listener
     */
    private function invoke(string|callable $listener, object $event): void
    {
        if (is_string($listener)) {
            $instance = $this->resolve($listener);
            $instance->handle($event);

            return;
        }

        $listener($event);
    }

    /**
     * @param class-string $listener
     */
    private function queue(string $listener, object $event): void
    {
        $job = new InvokeListenerJob($listener, $event);

        /** @var QueueableContract $probe */
        $probe = $this->resolve($listener);

        if (($name = $probe->queueName()) !== null) {
            $job->onQueue($name);
        }

        $job->dispatch($probe->queueDelay());
    }

    /**
     * Resolve a listener/subscriber through the application container when
     * available, falling back to a direct instantiation.
     *
     * @param class-string $class
     */
    private function resolve(string $class): object
    {
        $container = $this->container ?? Application::peek()?->container();

        if ($container !== null && $container->has($class)) {
            return $container->make($class);
        }

        if ($container !== null) {
            try {
                return $container->make($class);
            } catch (\Throwable) {
                // Fall through to plain instantiation.
            }
        }

        if (!class_exists($class)) {
            throw new EventException(sprintf('Listener class [%s] does not exist.', $class));
        }

        return new $class();
    }

    /**
     * @param class-string $listener
     */
    private function assertListenerClass(string $listener): void
    {
        if (!class_exists($listener)) {
            throw new EventException(sprintf('Listener class [%s] does not exist.', $listener));
        }

        if (!method_exists($listener, 'handle')) {
            throw new EventException(sprintf(
                'Listener [%s] must define a handle() method.',
                $listener,
            ));
        }

        $parameters = (new ReflectionClass($listener))->getMethod('handle')->getParameters();

        if ($parameters === []) {
            throw new EventException(sprintf(
                'Listener [%s]::handle() must accept the event as its first parameter.',
                $listener,
            ));
        }

        $type = $parameters[0]->getType();

        if ($type instanceof ReflectionNamedType && $type->isBuiltin() && $type->getName() !== 'object') {
            throw new EventException(sprintf(
                'Listener [%s]::handle() first parameter must be an event object, not [%s].',
                $listener,
                $type->getName(),
            ));
        }
    }
}
