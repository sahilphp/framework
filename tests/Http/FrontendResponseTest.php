<?php

declare(strict_types=1);

namespace Sahil\Tests\Http;

use PHPUnit\Framework\TestCase;
use Sahil\Container\Container;
use Sahil\Foundation\Application;
use Sahil\Frontend\FrontendConfiguration;
use Sahil\Frontend\FrontendManager;
use Sahil\Frontend\FrontendRenderer;
use Sahil\Http\Kernel;
use Sahil\Http\Request;
use Sahil\Http\Response;
use Sahil\Http\Route;
use Sahil\Http\Router;
use Sahil\Tests\Frontend\RecordingFrontendAdapter;
use Sahil\View\RenderedView;

final class FrontendResponseTest extends TestCase
{
    private ?Application $application = null;

    protected function tearDown(): void
    {
        Application::free();
        $this->application = null;
    }

    private function view(): RenderedView
    {
        return new RenderedView('pages.home', ['title' => 'Home'], '<main>Home</main>', ['engine' => 'sahil']);
    }

    private function kernel(FrontendRenderer|null $renderer, Response $response): Kernel
    {
        $application = $this->application ??= Application::configure(sys_get_temp_dir());
        $router = new Router();
        $router->register(Route::get('/', static fn (): Response => $response));

        return new Kernel($router, new Container(), $application, $renderer);
    }

    private function renderer(): FrontendRenderer
    {
        $manager = new FrontendManager();
        $manager->register(new RecordingFrontendAdapter('vue'));
        $manager->register(new RecordingFrontendAdapter('react'));
        $manager->register(new RecordingFrontendAdapter('svelte'));

        return new FrontendRenderer($manager, new FrontendConfiguration());
    }

    private function get(Kernel $kernel): Response
    {
        return $kernel->handle(Request::fromGlobals(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'], []));
    }

    public function test_html_factory_is_unchanged_for_plain_strings(): void
    {
        $response = Response::html('<h1>Native</h1>');

        self::assertSame(200, $response->status());
        self::assertSame('<h1>Native</h1>', $response->body());
        self::assertSame('text/html; charset=utf-8', $response->header('Content-Type'));
        self::assertSame((string) strlen('<h1>Native</h1>'), $response->header('Content-Length'));
        self::assertNull($response->frontend());
        self::assertNull($response->renderedView());
    }

    public function test_html_factory_retains_a_rendered_view(): void
    {
        $view = $this->view();
        $response = Response::html($view);

        self::assertSame('<main>Home</main>', $response->body());
        self::assertSame($view, $response->renderedView());
        self::assertNull($response->frontend());
    }

    /**
     * @dataProvider frontendFactories
     */
    public function test_each_factory_tags_the_response_without_changing_it(string $factory): void
    {
        $view = $this->view();

        /** @var Response $response */
        $response = Response::$factory($view);

        self::assertSame($factory, $response->frontend());
        self::assertSame('<main>Home</main>', $response->body());
        self::assertSame('text/html; charset=utf-8', $response->header('Content-Type'));
        self::assertSame($view, $response->renderedView());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function frontendFactories(): array
    {
        return [
            'vue' => ['vue'],
            'react' => ['react'],
            'svelte' => ['svelte'],
        ];
    }

    public function test_factories_accept_an_existing_response_and_keep_status_headers(): void
    {
        $base = Response::html($this->view(), 202)->withHeader('X-Trace', 'abc');

        $response = Response::vue($base);

        self::assertSame(202, $response->status());
        self::assertSame('abc', $response->header('X-Trace'));
        self::assertSame('vue', $response->frontend());
        self::assertSame($base->renderedView(), $response->renderedView());
        self::assertNull($base->frontend(), 'tagging must be immutable');
    }

    public function test_invalid_frontend_name_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Frontend name [Vue JS]');

        Response::html('<p>x</p>')->withFrontend('Vue JS');
    }

    public function test_kernel_finalizes_vue_react_and_svelte_responses(): void
    {
        foreach (['vue', 'react', 'svelte'] as $frontend) {
            $response = $this->get($this->kernel($this->renderer(), Response::$frontend($this->view())));

            self::assertSame(200, $response->status(), $frontend);
            self::assertStringStartsWith('<!doctype html>', $response->body(), $frontend);
            self::assertStringContainsString('data-component="Pages/Home.vue"', $response->body(), $frontend);
        }
    }

    public function test_kernel_leaves_native_html_responses_untouched(): void
    {
        $native = Response::html($this->view());
        $response = $this->get($this->kernel($this->renderer(), $native));

        self::assertSame($native, $response);
        self::assertSame('<main>Home</main>', $response->body());
    }

    public function test_frontend_response_without_a_kernel_renderer_fails_with_configuration_error(): void
    {
        $response = $this->get($this->kernel(null, Response::vue($this->view()))->useDebugMode(true));

        self::assertSame(500, $response->status());
        self::assertStringContainsString('FrontendRenderer', $response->body());
        self::assertStringContainsString('JavaScript starter-kits', $response->body());
    }

    public function test_production_kernel_hides_the_configuration_error_message(): void
    {
        $response = $this->get($this->kernel(null, Response::vue($this->view())));

        self::assertSame(500, $response->status());
        self::assertSame('{"error":"server_error"}', $response->body());
    }
}
