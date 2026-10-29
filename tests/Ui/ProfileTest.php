<?php

declare(strict_types=1);

namespace Sahil\Tests\Ui;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sahil\Ui\Profile;
use Sahil\Ui\ProfilePlan;

final class ProfileTest extends TestCase
{
    /**
     * @return array<string, array{Profile, list<string>}>
     */
    public static function profileFiles(): array
    {
        return [
            'tailwind' => [Profile::Tailwind, [
                'package.json',
                'resources/css/tailwind.css',
                'vite.config.js',
            ]],
            'bootstrap' => [Profile::Bootstrap, [
                'package.json',
                'resources/css/bootstrap.css',
                'resources/js/bootstrap.js',
                'vite.config.js',
            ]],
            'both' => [Profile::Both, [
                'package.json',
                'resources/css/bootstrap.css',
                'resources/css/tailwind.css',
                'resources/js/bootstrap.js',
                'vite.config.js',
            ]],
            'none' => [Profile::None, [
                'package.json',
                'resources/css/app.css',
                'vite.config.js',
            ]],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('profileFiles')]
    public function test_a_profile_only_generates_its_own_files(Profile $profile, array $expected): void
    {
        $plan = ProfilePlan::build($profile);

        self::assertSame($expected, array_keys($plan->files));
    }

    public function test_tailwind_dependencies_include_vite_and_tailwind(): void
    {
        $dependencies = Profile::Tailwind->dependencies();

        self::assertArrayHasKey('vite', $dependencies);
        self::assertArrayHasKey('tailwindcss', $dependencies);
        self::assertArrayHasKey('@tailwindcss/vite', $dependencies);
        self::assertArrayNotHasKey('bootstrap', $dependencies);
    }

    public function test_bootstrap_dependencies_include_bootstrap(): void
    {
        $dependencies = Profile::Bootstrap->dependencies();

        self::assertArrayHasKey('vite', $dependencies);
        self::assertArrayHasKey('bootstrap', $dependencies);
        self::assertArrayNotHasKey('tailwindcss', $dependencies);
    }

    public function test_both_profile_combines_the_dependency_sets(): void
    {
        $dependencies = Profile::Both->dependencies();

        self::assertArrayHasKey('bootstrap', $dependencies);
        self::assertArrayHasKey('tailwindcss', $dependencies);
        self::assertSame($dependencies, Profile::Both->dependencies());
    }

    public function test_plain_profile_has_no_framework_packages(): void
    {
        self::assertSame(['vite' => '^8.3.5'], Profile::None->dependencies());
    }

    public function test_profile_aliases_resolve(): void
    {
        self::assertSame(Profile::Tailwind, Profile::resolve('tailwindcss'));
        self::assertSame(Profile::Bootstrap, Profile::resolve('bootstrap5'));
        self::assertSame(Profile::Both, Profile::resolve('all'));
        self::assertSame(Profile::None, Profile::resolve('plain'));
        self::assertNull(Profile::resolve('nothing-here'));
    }

    public function test_expected_assets_match_profile_outputs(): void
    {
        self::assertSame(['public/build/assets/tailwind.css'], Profile::Tailwind->expectedAssets());
        self::assertSame(
            ['public/build/assets/bootstrap.css', 'public/build/assets/bootstrap.js'],
            Profile::Bootstrap->expectedAssets(),
        );
        self::assertSame(
            [
                'public/build/assets/tailwind.css',
                'public/build/assets/bootstrap.css',
                'public/build/assets/bootstrap.js',
            ],
            Profile::Both->expectedAssets(),
        );
        self::assertSame(['public/build/assets/app.css'], Profile::None->expectedAssets());
    }

    public function test_required_packages_do_not_contain_duplicates(): void
    {
        foreach (Profile::cases() as $profile) {
            self::assertSame(
                array_values(array_unique($profile->requiredPackages())),
                $profile->requiredPackages(),
            );
        }
    }
}
