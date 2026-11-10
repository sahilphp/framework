<?php

declare(strict_types=1);

namespace Sahil\Tests\View;

use PHPUnit\Framework\TestCase;
use Sahil\Contracts\View\ComponentRendererContract;
use Sahil\View\ViewRenderException;

/**
 * Both component syntaxes must reach the installed component renderer and
 * contribute finished markup — never a marker or a literal call in the HTML.
 */
final class ComponentRenderingTest extends TestCase
{
    use TempViews;

    public function test_self_closing_tag_renders_through_the_component_renderer(): void
    {
        $workspace = $this->workspace();
        $workspace->write('page', '<main><fiber:component name="Counter" /></main>');

        $html = $workspace->engine(null, new RecordingComponentRenderer())
            ->render('page');

        self::assertSame('<main>[Counter]</main>', $html);
    }

    public function test_directive_renders_through_the_same_component_renderer(): void
    {
        $workspace = $this->workspace();
        $workspace->write('page', "<main>@component('Counter')</main>");

        $html = $workspace->engine(null, new RecordingComponentRenderer())
            ->render('page');

        self::assertSame('<main>[Counter]</main>', $html);
    }

    public function test_both_syntaxes_produce_identical_output(): void
    {
        $workspace = $this->workspace();

        $workspace->write('tag', '<div><fiber:component name="Counter" /></div>');
        $workspace->write('directive', "<div>@component('Counter')</div>");

        $engine = $workspace->engine(null, new RecordingComponentRenderer());

        self::assertSame($engine->render('tag'), $engine->render('directive'));
    }

    public function test_fully_qualified_names_and_open_close_tags_are_supported(): void
    {
        $workspace = $this->workspace();
        $workspace->write(
            'page',
            '<fiber:component name="App\Firefiber\Components\UserProfile"></fiber:component>',
        );

        $html = $workspace->engine(null, new RecordingComponentRenderer())->render('page');

        self::assertSame('[App\Firefiber\Components\UserProfile]', $html);
    }

    public function test_attributes_become_component_properties_in_camel_case(): void
    {
        $workspace = $this->workspace();
        $workspace->write(
            'page',
            '<fiber:component name="Profile" user-id="42" title="My Profile" />',
        );

        $renderer = new RecordingComponentRenderer();
        $workspace->engine(null, $renderer)->render('page');

        self::assertSame(
            [['name' => 'Profile', 'attributes' => ['userId' => '42', 'title' => 'My Profile']]],
            $renderer->calls,
        );
    }

    public function test_bound_attributes_receive_template_values(): void
    {
        $workspace = $this->workspace();
        $workspace->write('page', '<fiber:component name="Profile" :user-id="user.id" />');

        $renderer = new RecordingComponentRenderer();
        $workspace->engine(null, $renderer)->render('page', ['user' => ['id' => 7]]);

        self::assertSame(
            [['name' => 'Profile', 'attributes' => ['userId' => 7]]],
            $renderer->calls,
        );
    }

    public function test_no_component_markers_survive_rendering(): void
    {
        $workspace = $this->workspace();
        $workspace->write(
            'page',
            "<p>before</p>\n<fiber:component name=\"Counter\" />\n@component('Counter')",
        );

        $html = $workspace->engine(null, new RecordingComponentRenderer())->render('page');

        self::assertSame("<p>before</p>\n[Counter]\n[Counter]", $html);
        self::assertStringNotContainsString('@component', $html);
        self::assertStringNotContainsString('fiber:component', $html);
        self::assertStringNotContainsString('<!--', $html);
    }

    public function test_requesting_a_component_without_a_renderer_fails_clearly(): void
    {
        $workspace = $this->workspace();
        $workspace->write('page', '<fiber:component name="Counter" />');

        $this->expectException(ViewRenderException::class);
        $this->expectExceptionMessage('no component renderer is installed');

        $workspace->engine()->render('page');
    }

    public function test_invalid_component_names_are_rejected(): void
    {
        $workspace = $this->workspace();
        $workspace->write('page', '<fiber:component name="../../etc/passwd" />');

        $this->expectException(ViewRenderException::class);
        $this->expectExceptionMessage('Invalid component name');

        $workspace->engine(null, new RecordingComponentRenderer())->render('page');
    }

    public function test_a_component_tag_without_a_name_is_a_syntax_error(): void
    {
        $workspace = $this->workspace();
        $workspace->write('page', '<fiber:component title="x" />');

        $this->expectException(\Sahil\View\ViewSyntaxException::class);

        $workspace->engine(null, new RecordingComponentRenderer())->render('page');
    }
}

final class RecordingComponentRenderer implements ComponentRendererContract
{
    /** @var list<array{name: string, attributes: array<string, mixed>}> */
    public array $calls = [];

    public function renderComponent(string $name, array $attributes = []): string
    {
        $this->calls[] = ['name' => $name, 'attributes' => $attributes];

        return '['.$name.']';
    }
}
