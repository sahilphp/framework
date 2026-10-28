<?php

declare(strict_types=1);

namespace Sahil\Tests\Container;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Sahil\Container\BindingException;
use Sahil\Container\Container;
use Sahil\Container\NotFoundException;

final class ContainerTest extends TestCase
{
    public function test_closure_binding_resolves_each_time(): void
    {
        $container = new Container();
        $container->bind('counter.counter', static fn (): \stdClass => new \stdClass());

        self::assertNotSame($container->get('counter.counter'), $container->get('counter.counter'));
    }

    public function test_singleton_returns_same_instance(): void
    {
        $container = new Container();
        $container->singleton('service.service', static fn (): \stdClass => new \stdClass());

        self::assertSame($container->get('service.service'), $container->get('service.service'));
    }

    public function test_instance_binding_is_shared_and_shared_for_alias(): void
    {
        $container = new Container();
        $object = new \stdClass();
        $container->instance(\stdClass::class, $object);
        $container->alias('the.object', \stdClass::class);

        self::assertSame($object, $container->get('the.object'));
        self::assertTrue($container->bound('the.object'));
    }

    public function test_class_to_class_binding_resolves_concrete(): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, ArrayRepository::class);

        $resolved = $container->make(RepositoryInterface::class);

        self::assertInstanceOf(ArrayRepository::class, $resolved);
    }

    public function test_auto_wiring_injects_typed_constructor_dependencies(): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, ArrayRepository::class);

        $service = $container->make(ServiceWithDependencies::class);

        self::assertInstanceOf(ArrayRepository::class, $service->repository);
    }

    public function test_auto_wiring_falls_back_to_default_values(): void
    {
        $container = new Container();

        $service = $container->make(ServiceWithDefaults::class);

        self::assertSame(42, $service->number);
    }

    public function test_circular_dependency_is_detected(): void
    {
        $container = new Container();

        try {
            $container->make(CyclicA::class);
            self::fail('Expected a BindingException for a circular dependency.');
        } catch (BindingException $exception) {
            self::assertStringContainsString('Circular dependency detected', $exception->getMessage());
            self::assertStringContainsString(CyclicA::class, $exception->getMessage());
            self::assertStringContainsString(CyclicB::class, $exception->getMessage());
        }
    }

    public function test_unknown_identifier_throws_not_found(): void
    {
        $container = new Container();

        $this->expectException(NotFoundException::class);
        $this->expectException(NotFoundExceptionInterface::class);

        $container->make('definitely.missing.service');
    }

    public function test_non_object_factory_result_through_make_is_rejected(): void
    {
        $container = new Container();
        $container->bind('value.value', static fn (): string => 'hello');

        $this->expectException(BindingException::class);

        $container->make('value.value');
    }

    public function test_aliased_chains_resolve_to_base_binding(): void
    {
        $container = new Container();
        $container->singleton('base.service', static fn (): \stdClass => new \stdClass());
        $container->alias('alias.one', 'base.service');
        $container->alias('alias.two', 'alias.one');

        self::assertSame($container->get('base.service'), $container->get('alias.two'));
    }

    public function test_self_alias_is_rejected(): void
    {
        $container = new Container();

        $this->expectException(ContainerExceptionInterface::class);

        $container->alias('same.same', 'same.same');
    }

    public function test_flush_clears_bindings_and_instances(): void
    {
        $container = new Container();
        $container->singleton('service.service', static fn (): \stdClass => new \stdClass());
        $container->get('service.service');

        $container->flush();

        self::assertFalse($container->bound('service.service'));

        $this->expectException(NotFoundExceptionInterface::class);
        $container->get('service.service');
    }

    public function test_has_reports_clearly_for_bound_and_unbound_ids(): void
    {
        $container = new Container();
        $container->bind(RepositoryInterface::class, ArrayRepository::class);

        self::assertTrue($container->has(RepositoryInterface::class));
        self::assertFalse($container->has('definitely.missing.service'));
    }

    public function test_unresolvable_parameter_is_reported_with_context(): void
    {
        $container = new Container();

        try {
            $container->make(ServiceWithUntypedRequirement::class);
            self::fail('Expected a BindingException for an unresolvable parameter.');
        } catch (BindingException $exception) {
            self::assertStringContainsString('requiredValue', $exception->getMessage());
            self::assertStringContainsString(ServiceWithUntypedRequirement::class, $exception->getMessage());
        }
    }
}

interface RepositoryInterface
{
}

final class ArrayRepository implements RepositoryInterface
{
}

final class ServiceWithDependencies
{
    public function __construct(public readonly RepositoryInterface $repository)
    {
    }
}

final class ServiceWithDefaults
{
    public function __construct(public readonly int $number = 42)
    {
    }
}

final class CyclicA
{
    public function __construct(public readonly CyclicB $b)
    {
    }
}

final class CyclicB
{
    public function __construct(public readonly CyclicA $a)
    {
    }
}

final class ServiceWithUntypedRequirement
{
    /**
     * The parameter is deliberately untyped/undefaulted to exercise the
     * "unresolvable parameter" error path.
     */
    public function __construct(public $requiredValue)
    {
    }
}
