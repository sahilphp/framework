<?php

declare(strict_types=1);

namespace Sahil\Tests\Frontend;

use PHPUnit\Framework\TestCase;
use Sahil\Frontend\FrontendManager;
use Sahil\Frontend\InvalidFrontendConfigurationException;
use Sahil\Frontend\MissingFrontendAdapterException;

final class FrontendManagerTest extends TestCase
{
    public function test_registers_and_resolves_adapters_by_target(): void
    {
        $manager = new FrontendManager();
        $vue = new RecordingFrontendAdapter('vue');

        $manager->register($vue);

        self::assertTrue($manager->has('vue'));
        self::assertFalse($manager->has('react'));
        self::assertSame($vue, $manager->adapter('vue'));
        self::assertSame(['vue'], $manager->targets());
    }

    public function test_registering_the_same_target_twice_fails(): void
    {
        $manager = new FrontendManager();
        $manager->register(new RecordingFrontendAdapter('vue'));

        $this->expectException(InvalidFrontendConfigurationException::class);
        $this->expectExceptionMessage('A frontend adapter for [vue] is already registered.');

        $manager->register(new RecordingFrontendAdapter('vue'));
    }

    public function test_invalid_adapter_name_is_rejected(): void
    {
        $manager = new FrontendManager();

        $this->expectException(InvalidFrontendConfigurationException::class);
        $this->expectExceptionMessage('must be lower-case letters, digits and dashes');

        $manager->register(new RecordingFrontendAdapter('Vue JS'));
    }

    public function test_missing_adapter_lists_available_adapters(): void
    {
        $manager = new FrontendManager();
        $manager->register(new RecordingFrontendAdapter('vue'));

        try {
            $manager->adapter('svelte');
            self::fail('Expected a missing adapter exception.');
        } catch (MissingFrontendAdapterException $exception) {
            self::assertStringContainsString('No frontend adapter is registered for [svelte]', $exception->getMessage());
            self::assertStringContainsString('Registered adapters: vue', $exception->getMessage());
            self::assertStringContainsString('starter-kit', $exception->getMessage());
        }
    }

    public function test_missing_adapter_with_no_registrations_says_none(): void
    {
        try {
            (new FrontendManager())->adapter('react');
            self::fail('Expected a missing adapter exception.');
        } catch (MissingFrontendAdapterException $exception) {
            self::assertStringContainsString('Registered adapters: none', $exception->getMessage());
        }
    }
}
