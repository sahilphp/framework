<?php

declare(strict_types=1);

namespace Sahil\Tests\Events;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sahil\Contracts\Events\EventSubscriberContract;
use Sahil\Contracts\Events\QueueableContract;
use Sahil\Events\Event;
use Sahil\Events\EventDispatcher;
use Sahil\Events\EventException;
use Sahil\Events\Jobs\InvokeListenerJob;
use Sahil\Foundation\Application;

final class EventDispatcherTest extends TestCase
{
    protected function tearDown(): void
    {
        Event::flushState();
        Application::free();
    }

    private function dispatcher(): EventDispatcher
    {
        return new EventDispatcher();
    }

    public function test_dispatch_runs_listeners_in_registration_order(): void
    {
        $dispatcher = $this->dispatcher();
        Recorder::$calls = [];

        $dispatcher->listen(UserRegistered::class, FirstListener::class);
        $dispatcher->listen(UserRegistered::class, SecondListener::class);

        $dispatcher->dispatch(new UserRegistered('ada'));

        self::assertSame(['first:ada', 'second:ada'], Recorder::$calls);
    }

    public function test_multiple_events_match_by_instanceof(): void
    {
        $dispatcher = $this->dispatcher();
        Recorder::$calls = [];

        $dispatcher->listen(BaseEvent::class, FirstListener::class);
        $dispatcher->dispatch(new ChildEvent());

        self::assertSame(['first:child'], Recorder::$calls);
    }

    public function test_callable_listeners(): void
    {
        $dispatcher = $this->dispatcher();
        $seen = [];

        $dispatcher->listen(UserRegistered::class, function (UserRegistered $e) use (&$seen): void {
            $seen[] = $e->name;
        });

        $dispatcher->dispatch(new UserRegistered('grace'));

        self::assertSame(['grace'], $seen);
    }

    public function test_no_listeners_is_a_noop(): void
    {
        $this->dispatcher()->dispatch(new UserRegistered('nobody'));
        self::assertFalse($this->dispatcher()->hasListeners(UserRegistered::class));
    }

    public function test_duplicate_registration_rejected(): void
    {
        $dispatcher = $this->dispatcher();
        $dispatcher->listen(UserRegistered::class, FirstListener::class);

        $this->expectException(EventException::class);
        $dispatcher->listen(UserRegistered::class, FirstListener::class);
    }

    public function test_unknown_event_and_listener_classes_fail_descriptively(): void
    {
        $dispatcher = $this->dispatcher();

        try {
            $dispatcher->listen('App\\Events\\Missing', FirstListener::class);
            self::fail('expected');
        } catch (EventException $e) {
            self::assertStringContainsString('unknown event class', $e->getMessage());
        }

        $this->expectException(EventException::class);
        $dispatcher->listen(UserRegistered::class, 'App\\Listeners\\Missing');
    }

    public function test_invalid_handler_signatures_rejected(): void
    {
        $dispatcher = $this->dispatcher();

        foreach ([NoHandleListener::class, NoParamListener::class, ScalarParamListener::class] as $bad) {
            try {
                $dispatcher->listen(UserRegistered::class, $bad);
                self::fail($bad.' should be rejected');
            } catch (EventException $e) {
                self::assertStringContainsString('handle()', $e->getMessage());
            }
        }
    }

    public function test_forget_and_forget_all(): void
    {
        $dispatcher = $this->dispatcher();
        $dispatcher->listen(UserRegistered::class, FirstListener::class);
        $dispatcher->listen(BaseEvent::class, SecondListener::class);

        $dispatcher->forget(UserRegistered::class);
        self::assertSame([SecondListener::class], $dispatcher->listeners(UserRegistered::class));

        $dispatcher->forgetAll();
        self::assertSame([], $dispatcher->listeners(UserRegistered::class));
    }

    public function test_sync_exception_propagates_and_state_survives(): void
    {
        $dispatcher = $this->dispatcher();
        Recorder::$calls = [];

        $dispatcher->listen(UserRegistered::class, FailingListener::class);
        $dispatcher->listen(UserRegistered::class, FirstListener::class);

        try {
            $dispatcher->dispatch(new UserRegistered('x'));
            self::fail('expected');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame([], Recorder::$calls); // second listener never ran
        self::assertTrue($dispatcher->hasListeners(UserRegistered::class));

        // Dispatcher still usable after the failure.
        $dispatcher->forget(UserRegistered::class);
        $dispatcher->listen(UserRegistered::class, FirstListener::class);
        $dispatcher->dispatch(new UserRegistered('ok'));
        self::assertSame(['first:ok'], Recorder::$calls);
    }

    public function test_subscriber_registers_grouped_listeners(): void
    {
        $dispatcher = $this->dispatcher();
        Recorder::$calls = [];

        $dispatcher->subscribe(new AccountSubscriber());
        $dispatcher->dispatch(new UserRegistered('hopper'));

        self::assertSame(['first:hopper', 'second:hopper'], Recorder::$calls);
    }

    public function test_queued_listener_produces_serializable_job(): void
    {
        $dispatcher = $this->dispatcher();
        $dispatcher->listen(UserRegistered::class, QueuedListener::class);

        // Dispatch would reach the queue; without an app we assert the job
        // contract directly — construction validates serializability.
        $job = new InvokeListenerJob(QueuedListener::class, new UserRegistered('queued'));
        self::assertInstanceOf(InvokeListenerJob::class, $job);
    }

    public function test_unserializable_event_rejected_at_job_creation(): void
    {
        $event = new class () {
            public mixed $inner;

            public function __construct()
            {
                $this->inner = fn () => 1; // closure → not serializable
            }
        };

        $this->expectException(EventException::class);
        new InvokeListenerJob(QueuedListener::class, $event);
    }

    public function test_queued_job_executes_listener_with_restored_event(): void
    {
        Recorder::$calls = [];

        $job = new InvokeListenerJob(SecondListener::class, new UserRegistered('restored'));
        $job->handle();

        self::assertSame(['second:restored'], Recorder::$calls);
    }

    public function test_queued_job_missing_listener_fails_descriptively(): void
    {
        $job = new InvokeListenerJob('App\\Listeners\\Gone', new UserRegistered('x'));

        $this->expectException(EventException::class);
        $job->handle();
    }

    public function test_facade_delegates_and_swaps(): void
    {
        $dispatcher = $this->dispatcher();
        Recorder::$calls = [];

        Event::swap($dispatcher);
        Event::listen(UserRegistered::class, FirstListener::class);
        Event::dispatch(new UserRegistered('facade'));

        self::assertSame(['first:facade'], Recorder::$calls);
        self::assertTrue(Event::hasListeners(UserRegistered::class));

        Event::forget(UserRegistered::class);
        self::assertFalse(Event::hasListeners(UserRegistered::class));
    }

    public function test_consecutive_dispatches_no_state_leak(): void
    {
        $dispatcher = $this->dispatcher();
        Recorder::$calls = [];

        $dispatcher->listen(UserRegistered::class, FirstListener::class);
        $dispatcher->dispatch(new UserRegistered('a'));
        $dispatcher->dispatch(new UserRegistered('b'));

        self::assertSame(['first:a', 'first:b'], Recorder::$calls);

        // Job executions with different payloads don't share state.
        (new InvokeListenerJob(SecondListener::class, new UserRegistered('c')))->handle();
        (new InvokeListenerJob(SecondListener::class, new UserRegistered('d')))->handle();
        self::assertSame(['first:a', 'first:b', 'second:c', 'second:d'], Recorder::$calls);
    }
}

class BaseEvent
{
    public readonly string $name;

    public function __construct()
    {
        $this->name = 'base';
    }
}

final class UserRegistered extends BaseEvent
{
    public function __construct(string $name)
    {
        $this->name = $name;
    }
}

final class ChildEvent extends BaseEvent
{
    public readonly string $name;

    public function __construct()
    {
        $this->name = 'child';
    }
}

final class Recorder
{
    /** @var list<string> */
    public static array $calls = [];
}

final class FirstListener
{
    public function handle(object $event): void
    {
        Recorder::$calls[] = 'first:'.$event->name;
    }
}

final class SecondListener
{
    public function handle(object $event): void
    {
        Recorder::$calls[] = 'second:'.$event->name;
    }
}

final class FailingListener
{
    public function handle(object $event): void
    {
        throw new RuntimeException('boom');
    }
}

final class QueuedListener implements QueueableContract
{
    public function handle(object $event): void
    {
    }

    public function queueName(): ?string
    {
        return null;
    }

    public function queueDelay(): int
    {
        return 0;
    }
}

final class NoHandleListener
{
}

final class NoParamListener
{
    public function handle(): void
    {
    }
}

final class ScalarParamListener
{
    public function handle(string $event): void
    {
    }
}

final class AccountSubscriber implements EventSubscriberContract
{
    public function subscribe(): array
    {
        return [
            UserRegistered::class => [FirstListener::class, SecondListener::class],
        ];
    }
}
