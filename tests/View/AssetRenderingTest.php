<?php

declare(strict_types=1);

namespace Sahil\Tests\View;

use PHPUnit\Framework\TestCase;
use Sahil\Assets\AssetManager;
use Sahil\View\ViewRenderException;

final class AssetRenderingTest extends TestCase
{
    use TempViews;

    public function test_development_rendering_includes_the_vite_client_and_tailwind_entry(): void
    {
        $workspace = $this->workspace();
        $assets = new AssetManager($workspace->base(), 'tailwind', 'development');

        $workspace->write('page', <<<'HTML'
            @asset_client
            @asset_css('main')
            @asset_js('main')
            <a href="{{ asset('images/logo.png') }}">Logo</a>
            HTML);

        $html = $workspace->engine($assets)->render('page');

        self::assertStringContainsString('http://127.0.0.1:5173/@vite/client', $html);
        self::assertStringContainsString('href="http://127.0.0.1:5173/resources/css/tailwind.css"', $html);
        self::assertStringContainsString('href="/images/logo.png"', $html);
        self::assertStringNotContainsString('bootstrap', $html);
        self::assertStringNotContainsString('<script type="module" src="http://127.0.0.1:5173/resources/js/bootstrap.js"></script>', $html);
    }

    public function test_production_rendering_uses_built_bootstrap_files(): void
    {
        $workspace = $this->workspace();
        $this->write($workspace, 'public/build/assets/bootstrap.css', 'body{}');
        $this->write($workspace, 'public/build/assets/bootstrap.js', 'export{}');

        $assets = new AssetManager($workspace->base(), 'bootstrap', 'production');
        $workspace->write('page', <<<'HTML'
            @asset_client
            @asset_css('main')
            @asset_js('main')
            HTML);

        $html = $workspace->engine($assets)->render('page');

        self::assertStringNotContainsString('@vite/client', $html);
        self::assertStringContainsString('<link rel="stylesheet" href="/build/assets/bootstrap.css">', $html);
        self::assertStringContainsString('<script type="module" src="/build/assets/bootstrap.js"></script>', $html);
        self::assertStringNotContainsString('tailwind', $html);
    }

    public function test_layout_sections_can_add_page_specific_assets(): void
    {
        $workspace = $this->workspace();
        $this->write($workspace, 'public/build/assets/tailwind.css', 'body{}');

        $assets = new AssetManager($workspace->base(), 'tailwind', 'production');

        $workspace->write('layouts.app', <<<'HTML'
            <!doctype html>
            <head>
                @asset_css('main')
                @yield('head')
            </head>
            <body>
                @yield('content')
                @asset_js('main')
                @yield('scripts')
            </body>
            HTML);

        $workspace->write('page', <<<'HTML'
            @extends('layouts.app')
            @section('head')<link rel="stylesheet" href="{{ asset('css/page.css') }}">@endsection
            @section('content')<h1>Page</h1>@endsection
            @section('scripts')<script src="{{ asset('js/page.js') }}" defer></script>@endsection
            HTML);

        $html = $workspace->engine($assets)->render('page');

        self::assertSame(1, substr_count($html, 'href="/build/assets/tailwind.css"'));
        self::assertStringContainsString('href="/css/page.css"', $html);
        self::assertStringContainsString('src="/js/page.js"', $html);
        self::assertStringContainsString('<h1>Page</h1>', $html);
    }

    public function test_asset_attributes_are_escaped(): void
    {
        $workspace = $this->workspace();
        $assets = new AssetManager($workspace->base(), 'tailwind', 'development');

        $workspace->write('page', <<<'HTML'
            @asset_css('main', ['media' => '"><script>alert(1)</script>', 'data-theme' => 'a&b'])
            HTML);

        $html = $workspace->engine($assets)->render('page');

        self::assertStringContainsString('media="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $html);
        self::assertStringContainsString('data-theme="a&amp;b"', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_event_handler_attributes_are_rejected_at_render_time(): void
    {
        $workspace = $this->workspace();
        $assets = new AssetManager($workspace->base(), 'bootstrap', 'development');

        $workspace->write('page', <<<'HTML'
            @asset_js('main', ['onload' => 'alert(1)'])
            HTML);

        $this->expectException(ViewRenderException::class);
        $this->expectExceptionMessage('Event-handler attribute [onload]');

        $workspace->engine($assets)->render('page');
    }

    public function test_a_profile_cannot_load_a_framework_it_did_not_install(): void
    {
        $workspace = $this->workspace();
        $assets = new AssetManager($workspace->base(), 'tailwind', 'development');

        $workspace->write('page', <<<'HTML'
            @asset_css('bootstrap')
            HTML);

        $this->expectException(ViewRenderException::class);
        $this->expectExceptionMessage('does not include the [bootstrap] css entry point');

        $workspace->engine($assets)->render('page');
    }

    public function test_missing_production_build_is_reported_during_rendering(): void
    {
        $workspace = $this->workspace();
        $assets = new AssetManager($workspace->base(), 'none', 'production');

        $workspace->write('page', <<<'HTML'
            @asset_css('main')
            HTML);

        $this->expectException(ViewRenderException::class);
        $this->expectExceptionMessage('bin/sahil ui:build');

        $workspace->engine($assets)->render('page');
    }

    public function test_asset_directives_without_an_asset_manager_fail_clearly(): void
    {
        $workspace = $this->workspace();
        $workspace->write('page', <<<'HTML'
            @asset_css('main')
            HTML);

        $this->expectException(ViewRenderException::class);
        $this->expectExceptionMessage('Register App\Providers\AssetProvider');

        $workspace->engine()->render('page');
    }

    private function write(TempViewWorkspace $workspace, string $relative, string $contents): void
    {
        $path = $workspace->base().'/'.$relative;

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }

        file_put_contents($path, $contents);
    }
}
