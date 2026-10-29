<?php

declare(strict_types=1);

namespace Sahil\Tests\Ui;

use PHPUnit\Framework\TestCase;
use Sahil\Ui\PackageJsonMerger;
use Sahil\Ui\Profile;
use Sahil\Ui\ProfilePlan;

final class PackageJsonMergerTest extends TestCase
{
    public function test_merge_preserves_existing_values_and_adds_missing_ones(): void
    {
        $existing = [
            'name' => 'my-app',
            'version' => '1.2.3',
            'scripts' => ['dev' => 'custom-dev', 'lint' => 'eslint .'],
            'dependencies' => ['lodash' => '^4.17.0'],
            'devDependencies' => ['vite' => '^7.0.0'],
        ];
        $desired = [
            'name' => 'sahilphp-app',
            'private' => true,
            'scripts' => ['dev' => 'vite', 'build' => 'vite build'],
            'devDependencies' => ['vite' => '^8.3.5', 'tailwindcss' => '^4.3.3'],
        ];

        $merged = PackageJsonMerger::merge($existing, $desired);

        self::assertSame('my-app', $merged['name']);
        self::assertSame('1.2.3', $merged['version']);
        self::assertTrue($merged['private']);
        self::assertSame('custom-dev', $merged['scripts']['dev']);
        self::assertSame('vite build', $merged['scripts']['build']);
        self::assertSame('eslint .', $merged['scripts']['lint']);
        self::assertSame('^7.0.0', $merged['devDependencies']['vite']);
        self::assertSame('^4.3.3', $merged['devDependencies']['tailwindcss']);
        self::assertSame('^4.17.0', $merged['dependencies']['lodash']);
    }

    public function test_merge_adds_missing_sections(): void
    {
        $merged = PackageJsonMerger::merge([], ProfilePlan::build(Profile::Both)->package);

        self::assertSame('sahilphp-app', $merged['name']);
        self::assertSame('vite', $merged['scripts']['dev']);
        self::assertArrayHasKey('bootstrap', $merged['devDependencies']);
        self::assertArrayHasKey('tailwindcss', $merged['devDependencies']);
    }

    public function test_merge_does_not_replace_list_values(): void
    {
        $merged = PackageJsonMerger::merge(
            ['keywords' => ['custom']],
            ['keywords' => ['framework']],
        );

        self::assertSame(['custom'], $merged['keywords']);
    }

    public function test_equals_ignores_key_order(): void
    {
        self::assertTrue(PackageJsonMerger::equals(
            ['b' => 2, 'a' => ['d' => 4, 'c' => 3]],
            ['a' => ['c' => 3, 'd' => 4], 'b' => 2],
        ));
        self::assertFalse(PackageJsonMerger::equals(['a' => 1], ['a' => 2]));
    }
}
