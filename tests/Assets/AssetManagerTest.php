<?php

declare(strict_types=1);

namespace Sahil\Tests\Assets;

use Sahil\Assets\AssetException;
use Sahil\Assets\AssetManager;
use Sahil\Tests\Ui\UiTestCase;

final class AssetManagerTest extends UiTestCase
{
    public function test_tailwind_development_tags_use_the_vite_server_and_only_tailwind(): void
    {
        $assets = new AssetManager(
            basePath: $this->projectPath,
            profile: 'tailwind',
            mode: 'development',
            environment: 'testing',
        );

        self::assertTrue($assets->isDevelopment());
        self::assertSame('tailwind', $assets->profile());
        self::assertSame(
            '<script type="module" src="http://127.0.0.1:5173/@vite/client"></script>',
            $assets->developmentClient(),
        );
        self::assertSame(
            '<link rel="stylesheet" href="http://127.0.0.1:5173/resources/css/tailwind.css">',
            $assets->css('main'),
        );
        self::assertSame('', $assets->js('main'));
        self::assertStringNotContainsString('bootstrap', $assets->css('main'));
    }

    public function test_hot_file_points_development_urls_at_the_running_vite_server(): void
    {
        $this->write('public/hot', "http://127.0.0.1:5199\n");

        $assets = new AssetManager(
            basePath: $this->projectPath,
            profile: 'tailwind',
            mode: 'development',
            devServer: 'http://127.0.0.1:5173',
        );

        self::assertSame(
            '<script type="module" src="http://127.0.0.1:5199/@vite/client"></script>',
            $assets->developmentClient(),
        );
        self::assertSame(
            '<link rel="stylesheet" href="http://127.0.0.1:5199/resources/css/tailwind.css">',
            $assets->css('main'),
        );
    }

    public function test_invalid_hot_file_falls_back_to_the_configured_dev_server(): void
    {
        $this->write('public/hot', "legacy:junk\n");

        $assets = new AssetManager(
            basePath: $this->projectPath,
            profile: 'tailwind',
            mode: 'development',
            devServer: 'http://127.0.0.1:5173',
        );

        self::assertSame(
            '<script type="module" src="http://127.0.0.1:5173/@vite/client"></script>',
            $assets->developmentClient(),
        );
    }

    public function test_bootstrap_development_tags_include_css_and_module_javascript(): void
    {
        $assets = new AssetManager(
            basePath: $this->projectPath,
            profile: 'bootstrap',
            mode: 'development',
        );

        self::assertSame(
            '<link rel="stylesheet" href="http://127.0.0.1:5173/resources/css/bootstrap.css">',
            $assets->css('main'),
        );
        self::assertSame(
            '<script type="module" src="http://127.0.0.1:5173/resources/js/bootstrap.js"></script>',
            $assets->js('main'),
        );
        self::assertStringNotContainsString('tailwind', $assets->css('main'));
    }

    public function test_production_tailwind_references_the_built_file(): void
    {
        $this->write('public/build/assets/tailwind.css', 'body{}');

        $assets = new AssetManager(
            basePath: $this->projectPath,
            profile: 'tailwind',
            mode: 'production',
        );

        self::assertFalse($assets->isDevelopment());
        self::assertSame('', $assets->developmentClient());
        self::assertSame(
            '<link rel="stylesheet" href="/build/assets/tailwind.css">',
            $assets->css('main'),
        );
    }

    public function test_production_both_emits_exactly_the_two_selected_frameworks(): void
    {
        $this->write('public/build/assets/tailwind.css', 'body{}');
        $this->write('public/build/assets/bootstrap.css', 'body{}');
        $this->write('public/build/assets/bootstrap.js', 'export{}');

        $assets = new AssetManager(
            basePath: $this->projectPath,
            profile: 'both',
            mode: 'production',
        );

        $css = $assets->css('main');
        $js = $assets->js('main');

        self::assertStringContainsString('/build/assets/tailwind.css', $css);
        self::assertStringContainsString('/build/assets/bootstrap.css', $css);
        self::assertStringContainsString('/build/assets/bootstrap.js', $js);
        self::assertStringContainsString('type="module"', $js);
    }

    public function test_plain_profile_emits_only_app_css(): void
    {
        $this->write('public/build/assets/app.css', 'body{}');

        $assets = new AssetManager(
            basePath: $this->projectPath,
            profile: 'none',
            mode: 'production',
        );

        self::assertSame('<link rel="stylesheet" href="/build/assets/app.css">', $assets->css('main'));
        self::assertSame('', $assets->js('main'));
    }

    public function test_missing_production_asset_fails_with_build_guidance(): void
    {
        $assets = new AssetManager(
            basePath: $this->projectPath,
            profile: 'tailwind',
            mode: 'production',
        );

        $this->expectException(AssetException::class);
        $this->expectExceptionMessage('bin/sahil ui:build');

        $assets->css('main');
    }

    public function test_inactive_framework_entry_is_refused(): void
    {
        $assets = new AssetManager(
            basePath: $this->projectPath,
            profile: 'tailwind',
            mode: 'development',
        );

        $this->expectException(AssetException::class);
        $this->expectExceptionMessage('does not include the [bootstrap] css entry point');

        $assets->css('bootstrap');
    }

    public function test_unknown_entry_lists_available_entries(): void
    {
        $assets = new AssetManager(
            basePath: $this->projectPath,
            profile: 'tailwind',
            mode: 'development',
        );

        $this->expectException(AssetException::class);
        $this->expectExceptionMessage('Available: tailwind, bootstrap, app');

        $assets->css('missing');
    }

    public function test_attributes_are_escaped_and_event_handlers_are_rejected(): void
    {
        $assets = new AssetManager(
            basePath: $this->projectPath,
            profile: 'tailwind',
            mode: 'development',
        );

        $tag = $assets->css('main', [
            'media' => '"><script>alert(1)</script>',
            'data-theme' => 'a&b',
            'disabled' => true,
            'title' => null,
        ]);

        self::assertStringContainsString('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $tag);
        self::assertStringContainsString('data-theme="a&amp;b"', $tag);
        self::assertStringContainsString(' disabled', $tag);
        self::assertStringNotContainsString('<script>alert(1)</script>', $tag);

        $this->expectException(AssetException::class);
        $this->expectExceptionMessage('Event-handler attribute [onload]');

        $assets->css('main', ['onload' => 'alert(1)']);
    }

    public function test_reserved_source_and_rel_attributes_cannot_be_overridden(): void
    {
        $assets = new AssetManager(
            basePath: $this->projectPath,
            profile: 'tailwind',
            mode: 'development',
        );

        $this->expectException(AssetException::class);
        $this->expectExceptionMessage('Attribute [href] is managed');

        $assets->css('main', ['href' => '/elsewhere.css']);
    }

    public function test_public_url_encodes_segments_and_applies_a_base_url(): void
    {
        $assets = new AssetManager(
            basePath: $this->projectPath,
            profile: 'tailwind',
            mode: 'development',
            baseUrl: '/app',
        );

        self::assertSame('/app/images/logo%20mark.png', $assets->publicUrl('images/logo mark.png'));
        self::assertSame('/app/css/site.css', $assets->publicUrl('/css/site.css'));
    }

    public function test_public_url_rejects_traversal_and_absolute_urls(): void
    {
        $assets = new AssetManager(
            basePath: $this->projectPath,
            profile: 'tailwind',
            mode: 'development',
        );

        foreach (['../secret', '/css/../secret', 'https://example.com/app.css', '//cdn.example.com/app.css', 'css/app.css?v=1'] as $path) {
            try {
                $assets->publicUrl($path);
                self::fail(sprintf('Expected [%s] to be rejected.', $path));
            } catch (AssetException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_auto_mode_is_production_only_for_the_production_environment(): void
    {
        $development = new AssetManager(
            basePath: $this->projectPath,
            profile: 'tailwind',
            mode: 'auto',
            environment: 'development',
        );

        $production = new AssetManager(
            basePath: $this->projectPath,
            profile: 'tailwind',
            mode: 'auto',
            environment: 'production',
        );

        self::assertTrue($development->isDevelopment());
        self::assertFalse($production->isDevelopment());
    }

    public function test_profile_is_inferred_from_the_install_manifest(): void
    {
        $this->write('.sahil-ui.json', "{\"profile\":\"bootstrap\"}\n");

        $assets = new AssetManager(
            basePath: $this->projectPath,
            profile: 'auto',
            mode: 'development',
        );

        self::assertSame('bootstrap', $assets->profile());
    }

    public function test_missing_profile_produces_install_guidance(): void
    {
        $assets = new AssetManager(
            basePath: $this->projectPath,
            profile: 'auto',
            mode: 'development',
        );

        $this->expectException(AssetException::class);
        $this->expectExceptionMessage('bin/sahil ui:install');

        $assets->css('main');
    }
}
