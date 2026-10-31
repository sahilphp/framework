<?php

declare(strict_types=1);

namespace Sahil\Tests\View;

use PHPUnit\Framework\TestCase;
use Sahil\View\RenderedView;

final class RenderedViewTest extends TestCase
{
    use TempViews;

    public function test_render_view_keeps_template_data_context_and_markup(): void
    {
        $workspace = $this->workspace();
        $workspace->write('pages.home', '<h1>{{ heading }}</h1>');

        $engine = $workspace->engine();
        $view = $engine->renderView('pages.home', ['heading' => 'Hello']);

        self::assertInstanceOf(RenderedView::class, $view);
        self::assertSame('pages.home', $view->template());
        self::assertTrue($view->hasTemplate());
        self::assertSame(['heading' => 'Hello'], $view->data());
        self::assertSame('<h1>Hello</h1>', $view->markup());
        self::assertSame('sahil', $view->contextValue('engine'));
        self::assertStringContainsString('pages/home.sahil.php', (string) $view->contextValue('path'));
    }

    public function test_render_returns_the_same_markup_as_render_view(): void
    {
        $workspace = $this->workspace();
        $workspace->write('greeting', '<p>{{ name }}</p>');

        $engine = $workspace->engine();

        self::assertSame(
            $engine->renderView('greeting', ['name' => 'Ada'])->markup(),
            $engine->render('greeting', ['name' => 'Ada']),
        );
    }

    public function test_rendered_view_is_stringable_for_existing_code(): void
    {
        $workspace = $this->workspace();
        $workspace->write('page', '<p>markup</p>');

        $view = $workspace->engine()->renderView('page');

        self::assertSame('<p>markup</p>', (string) $view);
        self::assertSame('<p>markup</p>', strtoupper((string) $view) === '<P>MARKUP</P>' ? '<p>markup</p>' : '');
    }

    public function test_markup_without_a_template_has_no_template_metadata(): void
    {
        $view = RenderedView::fromMarkup('<p>cached</p>', ['source' => 'cache']);

        self::assertFalse($view->hasTemplate());
        self::assertSame('', $view->template());
        self::assertSame([], $view->data());
        self::assertSame('<p>cached</p>', $view->markup());
        self::assertSame('cache', $view->contextValue('source'));
    }

    public function test_data_defaults_are_returned_for_missing_context_keys(): void
    {
        $view = new RenderedView('page', ['a' => 1], 'x');

        self::assertNull($view->contextValue('missing'));
        self::assertSame('fallback', $view->contextValue('missing', 'fallback'));
    }
}
