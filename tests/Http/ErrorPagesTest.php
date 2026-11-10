<?php

declare(strict_types=1);

namespace Sahil\Tests\Http;

use PHPUnit\Framework\TestCase;
use Sahil\Container\Container;
use Sahil\Foundation\Application;
use Sahil\Foundation\ServiceProvider;
use Sahil\Http\ErrorRenderer;
use Sahil\Http\Exception\HttpException;
use Sahil\Http\Exception\NotFoundHttpException;
use Sahil\Http\Kernel;
use Sahil\Http\Request;
use Sahil\Http\Route;
use Sahil\Http\Router;
use Sahil\View\TemplateCompiler;
use Sahil\View\TemplateResolver;
use Sahil\View\ViewEngine;

final class ErrorPagesTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        Application::free();

        foreach ($this->temporaryDirectories as $directory) {
            self::removeDirectory($directory);
        }

        $this->temporaryDirectories = [];
    }

    public function test_a_browser_gets_a_designed_html_page_for_a_404(): void
    {
        $response = $this->kernel()->handle(self::request('/nope'));

        self::assertSame(404, $response->status());
        self::assertSame('text/html; charset=utf-8', $response->header('Content-Type'));
        self::assertSame('no-store', $response->header('Cache-Control'), 'an error page is never cached');

        $body = $response->body();

        self::assertStringContainsString('<!doctype html>', $body);
        self::assertStringContainsString('404', $body);
        self::assertStringContainsString('This page could not be found', $body);
        self::assertStringContainsString('Back to home', $body);
        self::assertStringNotContainsString('http_error', $body);
    }

    public function test_json_clients_keep_the_stable_error_contract(): void
    {
        $response = $this->kernel()->handle(
            self::request('/nope', headers: ['Accept' => 'application/json']),
        );

        self::assertSame(404, $response->status());
        self::assertSame('application/json', $response->header('Content-Type'));
        self::assertSame('{"error":"http_error","status":404}', $response->body());
    }

    public function test_ajax_requests_and_json_bodies_are_answered_with_json(): void
    {
        $kernel = $this->kernel();

        $xhr = $kernel->handle(self::request('/nope', method: 'POST', headers: ['X-Requested-With' => 'XMLHttpRequest']));
        self::assertSame('application/json', $xhr->header('Content-Type'));

        $api = $kernel->handle(self::request('/nope', method: 'POST', headers: ['Content-Type' => 'application/json']));
        self::assertSame('application/json', $api->header('Content-Type'));
    }

    public function test_debug_mode_adds_the_exception_to_the_page_and_production_never_does(): void
    {
        $production = $this->kernel()->handle(self::request('/boom'));

        self::assertSame(500, $production->status());
        self::assertStringContainsString('Something went wrong on our end', $production->body());
        self::assertStringNotContainsString('database fallen over', $production->body());
        self::assertStringNotContainsString('RuntimeException', $production->body());

        $debug = $this->kernel(debug: true)->handle(self::request('/boom'));

        self::assertSame(500, $debug->status());
        self::assertStringContainsString('database fallen over', $debug->body());
        self::assertStringContainsString('RuntimeException', $debug->body());
    }

    public function test_json_debug_payload_stays_unchanged(): void
    {
        $response = $this->kernel(debug: true)->handle(
            self::request('/boom', headers: ['Accept' => 'application/json']),
        );

        self::assertSame(500, $response->status());
        self::assertSame(
            '{"error":"server_error","message":"database fallen over","exception":"RuntimeException"}',
            $response->body(),
        );
    }

    public function test_http_exception_headers_are_preserved_on_the_html_page(): void
    {
        $router = new Router();
        $router->register(Route::get('/down', static fn (): never => throw new class ('down') extends HttpException {
            public function __construct(string $message)
            {
                parent::__construct(503, $message, ['Retry-After' => '30']);
            }
        }));

        $response = $this->kernel($router)->handle(self::request('/down'));

        self::assertSame(503, $response->status());
        self::assertSame('30', $response->header('Retry-After'));
        self::assertSame('text/html; charset=utf-8', $response->header('Content-Type'));
        self::assertStringContainsString('The service is temporarily unavailable', $response->body());
    }

    public function test_every_common_status_has_its_own_page(): void
    {
        $packageViews = dirname(__DIR__, 2).'/resources/views';
        $renderer = new ErrorRenderer(new ViewEngine(
            new TemplateResolver([$packageViews]),
            new TemplateCompiler($this->temporaryDirectory('error-cache')),
        ));

        $pages = [
            400 => 'The request could not be understood',
            401 => 'Please sign in to continue',
            403 => 'You do not have access to this page',
            404 => 'This page could not be found',
            405 => 'That method is not allowed here',
            419 => 'Your page has expired',
            429 => 'Too many requests',
            500 => 'Something went wrong on our end',
            503 => 'The service is temporarily unavailable',
        ];

        foreach ($pages as $status => $heading) {
            $response = $renderer->respond(self::statusException($status), self::request('/x'));

            self::assertSame($status, $response->status(), 'status '.$status);
            self::assertStringContainsString($heading, $response->body(), 'status '.$status.' page content');
            self::assertStringContainsString('Back to home', $response->body(), 'status '.$status.' action');
            self::assertStringContainsString('<!doctype html>', $response->body(), 'status '.$status.' is a document');
        }
    }

    public function test_an_unknown_status_uses_the_group_page(): void
    {
        $packageViews = dirname(__DIR__, 2).'/resources/views';
        $renderer = new ErrorRenderer(new ViewEngine(
            new TemplateResolver([$packageViews]),
            new TemplateCompiler($this->temporaryDirectory('error-cache')),
        ));

        $response = $renderer->respond(self::statusException(418), self::request('/x'));

        self::assertSame(418, $response->status());
        self::assertStringContainsString('This request could not be completed', $response->body());
        self::assertStringContainsString('Teapot', $response->body(), 'the reason phrase is shown');
    }

    public function test_an_application_view_overrides_the_framework_page(): void
    {
        $views = $this->temporaryDirectory('error-views');

        self::assertTrue(mkdir($views.'/errors', 0o700, true));
        self::assertNotFalse(file_put_contents(
            $views.'/errors/404.sahil.php',
            '<main class="own-design">{{ status }} Own design</main>',
        ));

        $response = (new ErrorRenderer($this->engine($views)))
            ->respond(new NotFoundHttpException(), self::request('/x'));

        self::assertSame(404, $response->status());
        self::assertStringContainsString('Own design', $response->body());
        self::assertStringNotContainsString('This page could not be found', $response->body());
    }

    public function test_a_broken_error_page_falls_back_to_the_built_in_page(): void
    {
        $views = $this->temporaryDirectory('broken-error-views');

        self::assertTrue(mkdir($views.'/errors', 0o700, true));
        self::assertNotFalse(file_put_contents($views.'/errors/404.sahil.php', "@extends('errors.missing-layout')\n"));
        self::assertNotFalse(file_put_contents($views.'/errors/4xx.sahil.php', "@extends('errors.missing-layout')\n"));

        $response = (new ErrorRenderer($this->engine($views)))
            ->respond(new NotFoundHttpException(), self::request('/x'));

        self::assertSame(404, $response->status());
        self::assertSame('text/html; charset=utf-8', $response->header('Content-Type'));
        self::assertStringContainsString('404', $response->body());
        self::assertStringContainsString('Back to home', $response->body());
    }

    public function test_the_renderer_still_answers_html_without_any_view_engine(): void
    {
        $response = (new ErrorRenderer())->respond(new \RuntimeException('database down'), self::request('/x'), true);

        self::assertSame(500, $response->status());
        self::assertSame('text/html; charset=utf-8', $response->header('Content-Type'));
        self::assertStringContainsString('500', $response->body());
        self::assertStringContainsString('database down', $response->body());
    }

    public function test_a_container_without_application_configuration_degrades_to_the_built_in_page(): void
    {
        $response = ErrorRenderer::fromContainer(new Container())
            ->respond(new NotFoundHttpException(), self::request('/x'));

        self::assertSame(404, $response->status());
        self::assertSame('text/html; charset=utf-8', $response->header('Content-Type'));
    }

    private function engine(string $root): ViewEngine
    {
        return new ViewEngine(
            new TemplateResolver([$root]),
            new TemplateCompiler($this->temporaryDirectory('error-cache')),
        );
    }

    private function kernel(?Router $router = null, bool $debug = false): Kernel
    {
        Application::free();
        $application = Application::configure(__DIR__.'/../Fixtures/app-root');
        $application->register(new class ($application) extends ServiceProvider {});
        $application->boot();

        if ($router === null) {
            $router = new Router();
            $router->register(Route::get('/boom', static function (): never {
                throw new \RuntimeException('database fallen over');
            }));
        }

        return (new Kernel($router, $application->container(), $application))->useDebugMode($debug);
    }

    private static function statusException(int $status): HttpException
    {
        return new class ($status) extends HttpException {
            public function __construct(int $status)
            {
                parent::__construct($status, 'failure');
            }
        };
    }

    /**
     * @param array<string, string> $headers
     */
    private static function request(string $path, string $method = 'GET', array $headers = []): Request
    {
        $server = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.str_replace('-', '_', strtoupper($name))] = $value;
        }

        return Request::fromGlobals($server, []);
    }

    private function temporaryDirectory(string $prefix): string
    {
        $directory = sys_get_temp_dir().'/sahil-error-'.$prefix.'-'.bin2hex(random_bytes(6));

        self::assertTrue(mkdir($directory, 0o700, true), 'unable to create the temporary directory');

        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($directory);
    }
}
