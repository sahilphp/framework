<?php

declare(strict_types=1);

namespace Sahil\Tests\Rendering;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sahil\Contracts\Rendering\RenderAdapterContract;
use Sahil\Contracts\View\ViewContract;
use Sahil\Http\Concerns\RendersPages;
use Sahil\Http\Response;
use Sahil\Rendering\Adapters\HtmlAdapter;
use Sahil\Rendering\Render;
use Sahil\Rendering\RenderManager;
use Sahil\View\RenderedView;

final class UnifiedRenderTest extends TestCase
{
    protected function tearDown(): void
    {
        Render::flushState();
    }

    private function manager(): RenderManager
    {
        $views = new class () implements ViewContract {
            public string $template = '';

            /** @var array<string, mixed> */
            public array $data = [];

            public function render(string $view, array $data = []): string
            {
                return $this->renderView($view, $data)->markup();
            }

            public function renderView(string $view, array $data = []): RenderedView
            {
                $this->template = $view;
                $this->data = $data;

                return new RenderedView($view, $data, '<h1>'.$data['message'].'</h1>');
            }

            public function exists(string $view): bool
            {
                return true;
            }

            /** @return list<string> */
            public function paths(): array
            {
                return [];
            }

            public function flush(): int
            {
                return 0;
            }
        };

        return new RenderManager([
            new HtmlAdapter($views),
            new SpyAdapter('vue'),
            new SpyAdapter('react'),
            new SpyAdapter('svelte'),
            new SpyAdapter('fire'),
        ]);
    }

    public function test_html_adapter_renders_through_view_engine(): void
    {
        $manager = $this->manager();
        $views = $manager->render('html', 'pages.school.index', ['message' => 'Hi']);

        self::assertSame('<h1>Hi</h1>', $views->body());
        self::assertSame(200, $views->status());
        self::assertStringContainsString('text/html', (string) $views->header('Content-Type'));
        self::assertSame('pages.school.index', $views->renderedView()?->template());
    }

    public function test_register_rejects_duplicates_and_bad_names(): void
    {
        $manager = $this->manager();

        $this->expectException(InvalidArgumentException::class);
        $manager->register(new SpyAdapter('vue'));
    }

    public function test_missing_environment_fails_loudly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/No render adapter for environment \[inertia\]/');
        $this->manager()->render('inertia', 'pages.x');
    }

    public function test_invalid_status_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->manager()->render('html', 'pages.x', [], 999);
    }

    public function test_named_environments_reach_their_adapter(): void
    {
        $manager = $this->manager();
        $response = $manager->render('vue', 'pages.school.index', ['message' => 'x'], 201);

        self::assertSame('vue:pages.school.index', $response->body());
        self::assertSame(201, $response->status());
    }

    public function test_controller_helpers_delegate_per_environment(): void
    {
        Render::swap($this->manager());

        $controller = new class () {
            use RendersPages;

            public function go(): array
            {
                return [
                    'html' => $this->html('pages.a', ['message' => 'm'], 201),
                    'vue' => $this->vue('pages.v'),
                    'react' => $this->react('pages.r'),
                    'svelte' => $this->svelte('pages.s'),
                    'fire' => $this->fire('pages.f'),
                    'custom' => $this->renderTo('vue', 'pages.custom'),
                ];
            }
        };

        $r = $controller->go();

        self::assertSame('<h1>m</h1>', $r['html']->body());
        self::assertSame(201, $r['html']->status());
        self::assertSame('vue:pages.v', $r['vue']->body());
        self::assertSame('react:pages.r', $r['react']->body());
        self::assertSame('svelte:pages.s', $r['svelte']->body());
        self::assertSame('fire:pages.f', $r['fire']->body());
        self::assertSame('vue:pages.custom', $r['custom']->body());
    }

    public function test_response_html_with_data_renders_template(): void
    {
        Render::swap($this->manager());

        $response = Response::html('pages.school.index', ['message' => 'Yo']);

        self::assertSame('<h1>Yo</h1>', $response->body());
        self::assertSame('pages.school.index', $response->renderedView()?->template());
    }

    public function test_response_html_markup_unchanged(): void
    {
        // No data argument → plain markup path, no renderer involved.
        $response = Response::html('<p>raw</p>', 202);

        self::assertSame('<p>raw</p>', $response->body());
        self::assertSame(202, $response->status());
        self::assertNull($response->renderedView());
    }

    public function test_frontend_factories_with_data_delegate_to_render(): void
    {
        Render::swap($this->manager());

        $response = Response::vue('pages.school.index', ['message' => 'x']);

        self::assertSame('vue:pages.school.index', $response->body());
        self::assertSame('vue', $response->frontend());
        self::assertSame('pages.school.index', $response->renderedView()?->template());
        self::assertSame(['message' => 'x'], $response->renderedView()?->data());
    }

    public function test_response_fire_factory(): void
    {
        Render::swap($this->manager());

        $response = Response::fire('pages.dashboard', [], 202);

        self::assertSame('fire:pages.dashboard', $response->body());
        self::assertSame('fire', $response->frontend());
        self::assertSame(202, $response->status());
    }

    public function test_rendered_view_factory_path_preserved(): void
    {
        $rendered = new RenderedView('pages.x', ['a' => 1], '<b>x</b>');
        $response = Response::svelte($rendered, 404);

        self::assertSame('<b>x</b>', $response->body());
        self::assertSame('svelte', $response->frontend());
        self::assertSame(404, $response->status());
        self::assertSame($rendered, $response->renderedView());
    }

    public function test_worker_isolation_swap_changes_adapter(): void
    {
        Render::swap($this->manager());
        $first = Render::to('vue', 'pages.a');

        Render::swap(new RenderManager([new SpyAdapter('vue', 'other')]));
        $second = Render::to('vue', 'pages.a');

        self::assertSame('vue:pages.a', $first->body());
        self::assertSame('other:pages.a', $second->body());
    }
}

final class SpyAdapter implements RenderAdapterContract
{
    public function __construct(
        private readonly string $name,
        private readonly string $prefix = '',
    ) {
    }

    public function environment(): string
    {
        return $this->name;
    }

    public function render(string $page, array $data, int $status): Response
    {
        return new Response($status, ['Content-Type' => 'text/html'], ($this->prefix !== '' ? $this->prefix : $this->name).':'.$page);
    }
}
