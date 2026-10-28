<?php

declare(strict_types=1);

namespace Sahil\Tests\Container;

use PHPUnit\Framework\TestCase;
use Sahil\Container\BindingException;
use Sahil\Container\Container;
use Sahil\Container\NotFoundException;
use Sahil\Contracts\Container\ScopeContract;

final class ScopeTest extends TestCase
{
    public function test_scoped_binding_throws_outside_any_scope(): void
    {
        $container = new Container();
        $container->scoped(RequestService::class);

        try {
            $container->make(RequestService::class);
            self::fail('Expected a BindingException when a scoped binding is resolved outside a scope.');
        } catch (BindingException $exception) {
            self::assertStringContainsString('no scope is active', $exception->getMessage());
            self::assertStringContainsString(RequestService::class, $exception->getMessage());
        }
    }

    public function test_scoped_bindings_are_reused_inside_one_scope(): void
    {
        $container = new Container();
        $container->scoped(RequestService::class);

        $scope = $container->beginScope();

        $first = $container->make(RequestService::class);
        $second = $container->make(RequestService::class);

        self::assertSame($first, $second);
        self::assertSame($first, $second);
        self::assertFalse($scope->isEnded());
        $scope->end();
    }

    public function test_scoped_instances_are_recreated_per_scope_and_never_leak(): void
    {
        $container = new Container();
        $container->scoped(RequestService::class);

        $scopeOne = $container->beginScope();
        $requestOne = $container->make(RequestService::class);
        $scopeOne->end();

        $scopeTwo = $container->beginScope();
        $requestTwo = $container->make(RequestService::class);
        $scopeTwo->end();

        self::assertNotSame($requestOne, $requestTwo);
        self::assertTrue($scopeOne->isEnded());
        self::assertTrue($scopeTwo->isEnded());
        self::assertFalse($container->hasOpenScopes());
    }

    public function test_singletons_persist_across_scopes_unlike_scoped_bindings(): void
    {
        $container = new Container();
        $container->singleton(PersistentService::class);
        $container->scoped(RequestService::class);

        $scopeOne = $container->beginScope();
        $persistentOne = $container->make(PersistentService::class);
        $requestOne = $container->make(RequestService::class);
        $scopeOne->end();

        $scopeTwo = $container->beginScope();
        $persistentTwo = $container->make(PersistentService::class);
        $requestTwo = $container->make(RequestService::class);
        $scopeTwo->end();

        self::assertSame($persistentOne, $persistentTwo, 'singleton must persist');
        self::assertNotSame($requestOne, $requestTwo, 'scoped service must be rebuilt');
    }

    public function test_nested_scopes_are_isolated(): void
    {
        $container = new Container();
        $container->scoped(RequestService::class);

        $outer = $container->beginScope();
        $outerService = $container->make(RequestService::class);

        $inner = $container->beginScope();
        $innerService = $container->make(RequestService::class);

        self::assertNotSame($outerService, $innerService);
        self::assertSame($inner, $container->currentScope());

        $inner->end();

        self::assertSame($outer, $container->currentScope());

        // The outer scope's instance is untouched by the inner cleanup.
        self::assertSame($outerService, $container->make(RequestService::class));

        $outer->end();
        self::assertNull($container->currentScope());
    }

    public function test_instance_bindings_replace_scoped_bindings(): void
    {
        $container = new Container();
        $container->scoped(InputService::class);

        $replacement = new InputService();
        $container->instance(InputService::class, $replacement);

        // No scope is open and the binding no longer requires one.
        self::assertSame($replacement, $container->make(InputService::class));
    }
}

final class RequestService
{
    /** @var list<string> */
    public array $state = [];

    public function start(string $iteration): void
    {
        $this->state[] = $iteration;
    }
}

final class PersistentService
{
    public int $counter = 0;
}

final class InputService
{
}
