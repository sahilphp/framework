<?php

declare(strict_types=1);

namespace Sahil\Tests\View;

use Sahil\Contracts\View\ViewContract;
use Sahil\Testing\ApplicationTestCase;

final class AssetProviderTest extends ApplicationTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function configurationOverrides(): array
    {
        return [
            'assets.profile' => 'tailwind',
            'assets.mode' => 'development',
            'assets.dev_server' => 'http://127.0.0.1:5173',
        ];
    }

    public function test_the_view_engine_receives_the_asset_manager_from_the_container(): void
    {
        $html = $this->container()->make(ViewContract::class)->render('pages.home', [
            'title' => 'Home',
            'heading' => 'Home',
            'posts' => [],
            'attribution' => '',
            'rendered_at' => 'now',
        ]);

        self::assertStringContainsString('http://127.0.0.1:5173/@vite/client', $html);
        self::assertStringContainsString('http://127.0.0.1:5173/resources/css/tailwind.css', $html);
        self::assertStringNotContainsString('bootstrap.css', $html);
        self::assertStringNotContainsString('bootstrap.js', $html);
    }
}
