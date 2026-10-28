<?php

declare(strict_types=1);

namespace Sahil\Tests\Http;

use Sahil\Testing\ApplicationTestCase;

/**
 * The example route proves that profile selection reaches the view layer
 * through the asset service and the controller, without manual URL building.
 */
final class UiKitRouteTest extends ApplicationTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function configurationOverrides(): array
    {
        return [
            'assets.profile' => 'bootstrap',
            'assets.mode' => 'development',
        ];
    }

    public function test_the_bootstrap_ui_kit_page_uses_the_bootstrap_layout_and_assets(): void
    {
        $this->get('/ui-kit')
            ->assertOk()
            ->assertSee('Bootstrap 5 profile')
            ->assertSee('http://127.0.0.1:5173/resources/css/bootstrap.css')
            ->assertSee('http://127.0.0.1:5173/resources/js/bootstrap.js')
            ->assertSee('form-control')
            ->assertDontSee('tailwind.css');
    }
}
