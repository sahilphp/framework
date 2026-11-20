<?php

declare(strict_types=1);

namespace Sahil\Tests\Http;

use PHPUnit\Framework\TestCase;
use Sahil\Foundation\Application;
use Sahil\Foundation\ServiceProvider;
use Sahil\Http\Kernel;
use Sahil\Http\Request;
use Sahil\Http\Response;
use Sahil\Http\Router;
use Sahil\Routing\Link;

/**
 * The kernel applies the `identification` configuration namespace to every
 * response it emits: the `X-Powered-By` header on all responses and the
 * `generator` meta tag inside framework-produced HTML documents.
 */
final class IdentificationHttpTest extends TestCase
{
    private const DOCUMENT = '<!doctype html><html lang="en"><head>'
        .'<meta charset="utf-8"><title>Page</title></head><body><p>Hi</p></body></html>';

    protected function tearDown(): void
    {
        Application::free();
    }

    /**
     * @param array<string, mixed> $identificationConfig
     */
    private static function kernelWith(array $routes, array $identificationConfig = ['version' => '1.2.3']): Kernel
    {
        $app = Application::configure(__DIR__.'/../Fixtures/app-root');
        $app->config()->set('identification', $identificationConfig);
        $app->register(new class ($app) extends ServiceProvider {});
        $app->boot();

        $router = new Router();
        $router->register(...$routes);

        return new Kernel($router, $app->container(), $app);
    }

    private static function request(string $path, string $method = 'GET'): Request
    {
        return Request::fromGlobals(
            ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path],
            [],
        );
    }

    public function test_html_documents_get_the_signature_meta_and_header(): void
    {
        $kernel = self::kernelWith([
            Link::get('/page', static fn (): Response => Response::html(self::DOCUMENT)),
        ]);

        $response = $kernel->handle(self::request('/page'));

        self::assertSame('SahilPHP', $response->header('X-Powered-By'));
        self::assertStringContainsString(
            '<meta name="generator" content="SahilPHP 1.2.3">',
            $response->body(),
        );
        self::assertSame(1, substr_count($response->body(), 'name="generator"'));
        self::assertSame((string) strlen($response->body()), $response->header('Content-Length'));
    }

    public function test_json_responses_get_the_header_but_never_a_meta_tag(): void
    {
        $kernel = self::kernelWith([
            Link::get('/api', static fn (): Response => Response::json(['ok' => true])),
        ]);

        $response = $kernel->handle(self::request('/api'));

        self::assertSame('SahilPHP', $response->header('X-Powered-By'));
        self::assertSame('{"ok":true}', $response->body());
    }

    public function test_identification_can_be_disabled_entirely(): void
    {
        $kernel = self::kernelWith([
            Link::get('/page', static fn (): Response => Response::html(self::DOCUMENT)),
        ], ['enabled' => false]);

        $response = $kernel->handle(self::request('/page'));

        self::assertNull($response->header('X-Powered-By'));
        self::assertSame(self::DOCUMENT, $response->body());
    }

    public function test_powered_by_is_independently_configurable(): void
    {
        $kernel = self::kernelWith([
            Link::get('/page', static fn (): Response => Response::html(self::DOCUMENT)),
        ], ['powered_by' => false, 'version' => '1.2.3']);

        $response = $kernel->handle(self::request('/page'));

        self::assertNull($response->header('X-Powered-By'));
        self::assertStringContainsString('name="generator"', $response->body());
    }

    public function test_an_application_powered_by_header_wins(): void
    {
        $kernel = self::kernelWith([
            Link::get('/page', static fn (): Response => Response::text('x')->withHeader('X-Powered-By', 'Coffee')),
        ]);

        $response = $kernel->handle(self::request('/page'));

        self::assertSame('Coffee', $response->header('X-Powered-By'));
    }

    public function test_application_generator_metadata_is_preserved(): void
    {
        $document = str_replace(
            '<title>',
            '<meta name="generator" content="My CMS"><title>',
            self::DOCUMENT,
        );

        $kernel = self::kernelWith([
            Link::get('/page', static fn (): Response => Response::html($document)),
        ]);

        $response = $kernel->handle(self::request('/page'));

        self::assertSame(1, substr_count($response->body(), 'name="generator"'));
        self::assertStringContainsString('content="My CMS"', $response->body());
    }

    public function test_error_responses_carry_the_fingerprint_too(): void
    {
        $kernel = self::kernelWith([]);

        $response = $kernel->handle(self::request('/nope'));

        self::assertSame(404, $response->status());
        self::assertSame('SahilPHP', $response->header('X-Powered-By'));
        self::assertStringContainsString('name="generator"', $response->body());
    }

    public function test_unsupported_media_type_responses_are_identified(): void
    {
        $kernel = self::kernelWith([]);

        $request = Request::fromGlobals(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api', 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'text/csv'],
            [],
        );

        $response = $kernel->handle($request);

        self::assertSame(415, $response->status());
        self::assertSame('SahilPHP', $response->header('X-Powered-By'));
        self::assertSame('{"error":"unsupported_media_type"}', $response->body());
    }

    public function test_large_document_bodies_survive_identification(): void
    {
        // Responses carry their body as one string; a large document is
        // decorated without truncation and its length stays consistent.
        $body = str_replace('<p>Hi</p>', str_repeat('<p>chunk</p>', 20000), self::DOCUMENT);

        $kernel = self::kernelWith([
            Link::get('/page', static fn (): Response => Response::html($body)),
        ]);

        $response = $kernel->handle(self::request('/page'));

        self::assertSame('SahilPHP', $response->header('X-Powered-By'));
        self::assertStringContainsString('name="generator"', $response->body());
        self::assertStringEndsWith('</html>', $response->body());
        self::assertSame((string) strlen($response->body()), $response->header('Content-Length'));
    }

    public function test_redirects_and_bodyless_responses_get_only_the_header(): void
    {
        $kernel = self::kernelWith([
            Link::get('/go', static fn (): Response => Response::redirect('/page')),
            Link::get('/gone', static fn (): Response => Response::empty()),
        ]);

        $redirect = $kernel->handle(self::request('/go'));
        $empty = $kernel->handle(self::request('/gone'));

        self::assertSame('SahilPHP', $redirect->header('X-Powered-By'));
        self::assertSame('/page', $redirect->header('Location'));
        self::assertSame('SahilPHP', $empty->header('X-Powered-By'));
        self::assertSame('', $empty->body());
    }
}
