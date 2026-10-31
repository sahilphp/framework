<?php

declare(strict_types=1);

namespace Sahil\Tests\Frontend;

use PHPUnit\Framework\TestCase;
use Sahil\Frontend\FrontendConfiguration;
use Sahil\Frontend\FrontendManager;
use Sahil\Frontend\FrontendRenderer;
use Sahil\Frontend\InvalidFrontendConfigurationException;
use Sahil\Frontend\MissingFrontendAdapterException;
use Sahil\Http\Response;
use Sahil\View\RenderedView;

final class FrontendRendererTest extends TestCase
{
    private FrontendManager $manager;

    protected function setUp(): void
    {
        $this->manager = new FrontendManager();
    }

    private function renderer(bool $development = true): FrontendRenderer
    {
        return new FrontendRenderer($this->manager, new FrontendConfiguration(development: $development));
    }

    private function view(array $data = ['title' => 'Home']): RenderedView
    {
        return new RenderedView('pages.home', $data, '<main>server-rendered</main>', ['engine' => 'sahil']);
    }

    public function test_native_html_responses_pass_through_unchanged(): void
    {
        $response = Response::html($this->view());

        self::assertSame($response, $this->renderer()->render($response));
        self::assertSame('<main>server-rendered</main>', $response->body());
        self::assertNull($response->frontend());
    }

    public function test_vue_response_becomes_a_complete_document(): void
    {
        $this->manager->register(new RecordingFrontendAdapter('vue'));

        $response = $this->renderer()->render(Response::vue($this->view()));
        $html = $response->body();

        self::assertSame(200, $response->status());
        self::assertSame('text/html; charset=utf-8', $response->header('Content-Type'));
        self::assertStringStartsWith('<!doctype html>', $html);
        self::assertStringContainsString('<html lang="en">', $html);
        self::assertStringContainsString('<title>Home</title>', $html);
        self::assertStringContainsString('<div id="app" data-component="Pages/Home.vue" data-framework="vue"></div>', $html);
        self::assertStringContainsString('<script type="application/json" id="app-props">', $html);
        self::assertStringContainsString('"title":"Home"', $html);
        self::assertStringContainsString('src="http://127.0.0.1:5173/resources/js/app.js"', $html);
        self::assertStringContainsString('href="/build/assets/app.css"', $html);
        // The server-rendered fallback markup is NOT embedded: the adapter owns the client render.
        self::assertStringNotContainsString('server-rendered', $html);
    }

    public function test_react_and_svelte_targets_dispatch_to_their_adapter(): void
    {
        $this->manager->register(new RecordingFrontendAdapter('react', 'Pages/Home.jsx'));
        $this->manager->register(new RecordingFrontendAdapter('svelte', 'Pages/Home.svelte'));

        $react = $this->renderer()->render(Response::react($this->view()))->body();
        $svelte = $this->renderer()->render(Response::svelte($this->view()))->body();

        self::assertStringContainsString('data-component="Pages/Home.jsx"', $react);
        self::assertStringContainsString('data-component="Pages/Home.svelte"', $svelte);
        self::assertStringContainsString('data-framework="vue"', $react, 'recording double keeps its default attribute');
    }

    public function test_payload_cannot_break_out_of_the_script_element(): void
    {
        $this->manager->register(new RecordingFrontendAdapter('vue'));

        $html = $this->renderer()->render(Response::vue($this->view([
            'title' => 'x',
            'attack' => '</script><script>alert(1)</script>',
        ])))->body();

        self::assertStringNotContainsString('</script><script>alert(1)</script>', $html);
        self::assertStringContainsString('\u003C\/script\u003E', $html);
        self::assertSame(1, substr_count($html, '<script type="application/json"'));
    }

    public function test_attributes_and_title_are_escaped(): void
    {
        $this->manager->register(new RecordingFrontendAdapter('vue', attributes: ['data-x' => '"><script>alert(1)</script>']));

        $html = $this->renderer()->render(Response::vue($this->view(['title' => '<b>Title</b>'])))->body();

        self::assertStringContainsString('data-x="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $html);
        self::assertStringContainsString('<title>&lt;b&gt;Title&lt;/b&gt;</title>', $html);
        self::assertStringNotContainsString('<b>Title</b>', $html);
    }

    public function test_event_handler_attributes_on_the_mount_are_refused(): void
    {
        $this->manager->register(new RecordingFrontendAdapter('vue', attributes: ['onclick' => 'alert(1)']));

        $this->expectException(InvalidFrontendConfigurationException::class);
        $this->expectExceptionMessage('Event-handler attribute [onclick]');

        $this->renderer()->render(Response::vue($this->view()));
    }

    public function test_status_and_headers_are_preserved(): void
    {
        $this->manager->register(new RecordingFrontendAdapter('vue'));

        $response = Response::vue($this->view())
            ->withStatus(201)
            ->withHeader('X-Trace', 'abc')
            ->withHeader('Content-Type', 'text/plain');

        $rendered = $this->renderer()->render($response);

        self::assertSame(201, $rendered->status());
        self::assertSame('abc', $rendered->header('X-Trace'));
        self::assertSame('text/html; charset=utf-8', $rendered->header('Content-Type'));
        self::assertSame((string) strlen($rendered->body()), $rendered->header('Content-Length'));
    }

    public function test_rendering_is_immutable(): void
    {
        $this->manager->register(new RecordingFrontendAdapter('vue'));

        $original = Response::vue($this->view());

        $rendered = $this->renderer()->render($original);

        self::assertNotSame($original, $rendered);
        self::assertSame('<main>server-rendered</main>', $original->body());
        self::assertSame('vue', $original->frontend());
        self::assertStringStartsWith('<!doctype html>', $rendered->body());
    }

    public function test_development_and_production_asset_modes_are_requested_separately(): void
    {
        $adapter = new RecordingFrontendAdapter('vue');
        $this->manager->register($adapter);

        $this->renderer(development: true)->render(Response::vue($this->view()));
        $this->renderer(development: false)->render(Response::vue($this->view()));

        self::assertSame([true, false], $adapter->assetModes);
    }

    public function test_missing_adapter_reports_actionable_error(): void
    {
        $this->expectException(MissingFrontendAdapterException::class);
        $this->expectExceptionMessage('No frontend adapter is registered for [react]');

        $this->renderer()->render(Response::react($this->view()));
    }

    public function test_frontend_response_without_rendered_view_fails_clearly(): void
    {
        $this->manager->register(new RecordingFrontendAdapter('vue'));

        $response = Response::html('<h1>plain string</h1>')->withFrontend('vue');

        $this->expectException(InvalidFrontendConfigurationException::class);
        $this->expectExceptionMessage('no rendered view metadata');

        $this->renderer()->render($response);
    }

    public function test_invalid_component_name_is_refused(): void
    {
        $this->manager->register(new RecordingFrontendAdapter('vue', 'Pages/Home.vue"><script>'));

        $this->expectException(InvalidFrontendConfigurationException::class);
        $this->expectExceptionMessage('Invalid frontend component');

        $this->renderer()->render(Response::vue($this->view()));
    }

    public function test_javascript_asset_urls_are_refused(): void
    {
        $this->manager->register(new RecordingFrontendAdapter('vue', script: 'javascript:alert(1)'));

        $this->expectException(InvalidFrontendConfigurationException::class);
        $this->expectExceptionMessage('must be http(s), root-relative or relative');

        $this->renderer()->render(Response::vue($this->view()));
    }

    public function test_invalid_frontend_configuration_values_are_rejected(): void
    {
        $this->expectException(InvalidFrontendConfigurationException::class);
        $this->expectExceptionMessage('Invalid frontend mount id');

        new FrontendConfiguration(mountId: 'bad id"');
    }

    public function test_configuration_from_array_validates_types(): void
    {
        $this->expectException(InvalidFrontendConfigurationException::class);
        $this->expectExceptionMessage('frontend.development must be a boolean');

        FrontendConfiguration::fromArray(['development' => 'yes']);
    }

    public function test_configuration_from_array_applies_defaults(): void
    {
        $configuration = FrontendConfiguration::fromArray([]);

        self::assertSame('app', $configuration->mountId());
        self::assertSame('title', $configuration->titleKey());
        self::assertSame('en', $configuration->lang());
        self::assertTrue($configuration->development());
    }
}
