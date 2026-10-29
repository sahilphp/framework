<?php

declare(strict_types=1);

namespace Sahil\Tests\Theme;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sahil\Theme\ThemeGenerator;
use Sahil\Theme\ThemeTokens;
use Sahil\Ui\Profile;

final class ThemeGeneratorTest extends TestCase
{
    /**
     * @return array<string, array{Profile, list<string>}>
     */
    public static function profileFiles(): array
    {
        return [
            'tailwind' => [Profile::Tailwind, [
                'resources/css/theme.tailwind.generated.css',
                'resources/css/theme.tokens.generated.css',
            ]],
            'bootstrap' => [Profile::Bootstrap, [
                'resources/css/theme.bootstrap.generated.css',
                'resources/css/theme.tokens.generated.css',
                'resources/scss/_sahil-theme.generated.scss',
            ]],
            'both' => [Profile::Both, [
                'resources/css/theme.bootstrap.generated.css',
                'resources/css/theme.tailwind.generated.css',
                'resources/css/theme.tokens.generated.css',
                'resources/scss/_sahil-theme.generated.scss',
            ]],
            'none' => [Profile::None, [
                'resources/css/theme.tokens.generated.css',
            ]],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('profileFiles')]
    public function test_each_profile_only_generates_its_framework_files(Profile $profile, array $expected): void
    {
        $plan = (new ThemeGenerator())->plan(ThemeTokens::sample(), $profile);

        self::assertSame($expected, array_keys($plan->generated));
        self::assertArrayHasKey('resources/css/theme.overrides.css', $plan->user);
    }

    public function test_neutral_css_contains_light_and_dark_tokens_and_aliases(): void
    {
        $plan = (new ThemeGenerator())->plan(ThemeTokens::sample(), Profile::Tailwind);
        $css = $plan->generated['resources/css/theme.tokens.generated.css'];

        self::assertStringContainsString(':root {', $css);
        self::assertStringContainsString('--sahil-color-primary: #2563eb;', $css);
        self::assertStringContainsString('--sahil-color-primary: #60a5fa;', $css);
        self::assertStringContainsString('@media (prefers-color-scheme: dark)', $css);
        self::assertStringContainsString('[data-theme="dark"]', $css);
        self::assertStringContainsString('[data-bs-theme="dark"]', $css);
        self::assertStringContainsString('--sahil-primary: var(--sahil-color-primary);', $css);
        self::assertStringContainsString('--sahil-space-md: 1rem;', $css);
        self::assertStringContainsString('--sahil-breakpoint-md: 48rem;', $css);
    }

    public function test_tailwind_mapping_uses_theme_variables_and_literal_breakpoints(): void
    {
        $plan = (new ThemeGenerator())->plan(ThemeTokens::sample(), Profile::Tailwind);
        $css = $plan->generated['resources/css/theme.tailwind.generated.css'];

        self::assertStringContainsString('@theme inline {', $css);
        self::assertStringContainsString('--color-primary: var(--sahil-color-primary);', $css);
        self::assertStringContainsString('--font-sans: var(--sahil-font-sans);', $css);
        self::assertStringContainsString('--text-base: var(--sahil-text-base);', $css);
        self::assertStringContainsString('--leading-normal: var(--sahil-leading-normal);', $css);
        self::assertStringContainsString('--font-weight-semibold: var(--sahil-weight-semibold);', $css);
        self::assertStringContainsString('--spacing: var(--sahil-space-base);', $css);
        self::assertStringContainsString('--radius-md: var(--sahil-radius-md);', $css);
        self::assertStringContainsString('--breakpoint-md: 48rem;', $css);
        self::assertStringNotContainsString('var(--sahil-breakpoint-md)', $css);
    }

    public function test_bootstrap_css_bridge_maps_supported_runtime_variables_only(): void
    {
        $plan = (new ThemeGenerator())->plan(ThemeTokens::sample(), Profile::Bootstrap);
        $css = $plan->generated['resources/css/theme.bootstrap.generated.css'];

        self::assertStringContainsString('--bs-body-bg: var(--sahil-color-surface);', $css);
        self::assertStringContainsString('--bs-body-color: var(--sahil-color-text);', $css);
        self::assertStringContainsString('--bs-link-color: var(--sahil-color-primary);', $css);
        self::assertStringContainsString('--bs-border-radius: var(--sahil-radius-md);', $css);
        self::assertStringNotContainsString('--bs-primary-rgb', $css);
    }

    public function test_bootstrap_sass_mapping_is_optional_but_complete_for_supported_tokens(): void
    {
        $plan = (new ThemeGenerator())->plan(ThemeTokens::sample(), Profile::Bootstrap);
        $scss = $plan->generated['resources/scss/_sahil-theme.generated.scss'];

        self::assertStringContainsString('$primary: #2563eb;', $scss);
        self::assertStringContainsString('$body-bg: #ffffff;', $scss);
        self::assertStringContainsString('$body-color: #0f172a;', $scss);
        self::assertStringContainsString('$border-radius: 0.5rem;', $scss);
        self::assertStringContainsString('$font-size-base: 1rem;', $scss);
        self::assertStringContainsString('$grid-breakpoints: (', $scss);
        self::assertStringContainsString('xxl: 1400px,', $scss);
        self::assertStringNotContainsString('$theme-colors:', $scss);
        self::assertStringContainsString('$enable-dark-mode: true;', $scss);
    }

    public function test_entry_blocks_import_generated_files_before_user_overrides(): void
    {
        $generator = new ThemeGenerator();
        $tailwind = $generator->plan(ThemeTokens::sample(), Profile::Tailwind);
        $bootstrap = $generator->plan(ThemeTokens::sample(), Profile::Bootstrap);
        $both = $generator->plan(ThemeTokens::sample(), Profile::Both);
        $none = $generator->plan(ThemeTokens::sample(), Profile::None);

        $tailwindEntry = $tailwind->entries['resources/css/tailwind.css'];
        $bootstrapEntry = $bootstrap->entries['resources/css/bootstrap.css'];
        $bothTailwindEntry = $both->entries['resources/css/tailwind.css'];
        $bothBootstrapEntry = $both->entries['resources/css/bootstrap.css'];
        $plainEntry = $none->entries['resources/css/app.css'];

        self::assertLessThan(
            strpos($tailwindEntry, '@import "./theme.overrides.css";'),
            strpos($tailwindEntry, '@import "./theme.tailwind.generated.css";'),
        );
        self::assertStringNotContainsString('theme.bootstrap.generated.css', $tailwindEntry);

        self::assertStringContainsString('theme.bootstrap.generated.css', $bootstrapEntry);
        self::assertStringNotContainsString('theme.tailwind.generated.css', $bootstrapEntry);

        self::assertStringContainsString('theme.tailwind.generated.css', $bothTailwindEntry);
        self::assertStringNotContainsString('theme.bootstrap.generated.css', $bothTailwindEntry);
        self::assertStringContainsString('theme.bootstrap.generated.css', $bothBootstrapEntry);
        self::assertStringNotContainsString('theme.tailwind.generated.css', $bothBootstrapEntry);

        self::assertStringNotContainsString('theme.tailwind.generated.css', $plainEntry);
        self::assertStringNotContainsString('theme.bootstrap.generated.css', $plainEntry);
        self::assertStringContainsString('theme.overrides.css', $plainEntry);
    }

    public function test_generation_is_deterministic(): void
    {
        $generator = new ThemeGenerator();
        $first = $generator->plan(ThemeTokens::sample(), Profile::Both);
        $second = $generator->plan(ThemeTokens::sample(), Profile::Both);

        self::assertSame($first->generated, $second->generated);
        self::assertSame($first->entries, $second->entries);
    }
}
