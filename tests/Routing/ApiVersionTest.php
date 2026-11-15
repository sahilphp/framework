<?php

declare(strict_types=1);

namespace Sahil\Tests\Routing;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Sahil\Http\Exception\NotFoundHttpException;
use Sahil\Http\Request;
use Sahil\Http\Response;
use Sahil\Http\Router;
use Sahil\OpenApi\Generator;
use Sahil\Routing\Link;
use Sahil\Routing\Version;

/**
 * Native URL-prefix API versioning: `Link::version()` composes with the
 * existing group machinery, tags routes with normalized version metadata,
 * and dispatch/OpenAPI stay honest about what is registered.
 */
final class ApiVersionTest extends TestCase
{
    private static function request(string $method, string $path): Request
    {
        return Request::fromGlobals(['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path], []);
    }

    private static function handler(string $body): \Closure
    {
        return static fn (): Response => Response::text($body);
    }

    /* ---------- Version value ---------- */

    public function test_version_normalization(): void
    {
        self::assertSame('v1', Version::normalize('v1'));
        self::assertSame('v1', Version::normalize('V1'));
        self::assertSame('v1', Version::normalize('1'));
        self::assertSame('v2', Version::normalize('/v2/'));
        self::assertSame('v2.5', Version::normalize('v2.5'));
        self::assertSame('v1', (string) Version::of('1'));
        self::assertTrue(Version::of('v2')->equals('v2'));
        self::assertTrue(Version::isValid('v10'));
    }

    public function test_version_rejects_malformed_identifiers(): void
    {
        foreach (['', '/', 'v', 'foo', 'v1-beta', 'v-1', '1.0.x', 'latest'] as $bad) {
            try {
                Version::normalize($bad);
                self::fail("Version [$bad] should have been rejected.");
            } catch (InvalidArgumentException) {
            }
            self::assertFalse(Version::isValid($bad));
        }
    }

    /* ---------- Link::version ---------- */

    public function test_version_prefixes_and_tags_routes(): void
    {
        $links = Link::version('v1', fn () => [
            Link::get('/users', self::handler('u'))->name('api.users'),
        ]);

        self::assertCount(1, $links);
        self::assertSame('/v1/users', $links[0]->path());
        self::assertSame('v1', $links[0]->apiVersion());
        self::assertSame('api.users', $links[0]->routeName());
    }

    public function test_version_normalizes_before_prefixing(): void
    {
        $links = Link::version('2', fn () => [Link::get('/x', self::handler('x'))]);
        self::assertSame('/v2/x', $links[0]->path());
        self::assertSame('v2', $links[0]->apiVersion());
    }

    public function test_invalid_version_fails_at_registration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Link::version('latest', fn () => [Link::get('/x', self::handler('x'))]);
    }

    public function test_nested_version_groups_are_rejected(): void
    {
        // /v1/v2 or /v1/v1 would be accidental double prefixes — never allowed.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('nested version');
        Link::version('v1', fn () => Link::version('v2', fn () => Link::get('/x', self::handler('x'))));
    }

    public function test_nested_same_version_is_rejected_too(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Link::version('v1', fn () => Link::version('v1', fn () => Link::get('/x', self::handler('x'))));
    }

    /* ---------- Composition ---------- */

    public function test_version_composes_with_prefix_and_middleware(): void
    {
        $links = Link::prefix('/api')
            ->middleware('group.mw')
            ->group(fn () => [
                ...Link::version('v1', fn () => [
                    Link::get('/users', self::handler('u'))->middleware('route.mw'),
                ], 'version.mw'),
            ]);

        self::assertSame('/api/v1/users', $links[0]->path());
        self::assertSame('v1', $links[0]->apiVersion());
        self::assertSame(['group.mw', 'version.mw', 'route.mw'], $links[0]->middlewareList());
    }

    public function test_route_params_and_constraints_survive(): void
    {
        $router = new Router();
        $router->register(...Link::version('v1', fn () => [
            Link::get('/users/{id:\d+}', self::handler('show')),
        ]));

        $match = $router->match(self::request('GET', '/v1/users/42'));
        self::assertSame('42', $match->params()['id']);

        $this->expectException(NotFoundHttpException::class);
        $router->match(self::request('GET', '/v1/users/abc'));
    }

    public function test_unversioned_routes_stay_unversioned(): void
    {
        $router = new Router();
        $router->register(...[
            Link::get('/api/users', self::handler('plain')),
            ...Link::version('v1', fn () => [Link::get('/api/users', self::handler('v1'))]),
        ]);

        self::assertNull($router->routes()[0]->apiVersion());
        self::assertSame('v1', $router->routes()[1]->apiVersion());
        self::assertSame(['v1'], $router->versions());
    }

    public function test_duplicate_route_names_still_fail(): void
    {
        $router = new Router();
        $router->register(...Link::version('v1', fn () => [
            Link::get('/a', self::handler('a'))->name('dup'),
        ]));

        $this->expectException(InvalidArgumentException::class);
        $router->register(...Link::version('v2', fn () => [
            Link::get('/a', self::handler('a2'))->name('dup'),
        ]));
    }

    public function test_router_lists_registered_versions_sorted(): void
    {
        $router = new Router();
        $router->register(...[
            ...Link::version('v10', fn () => [Link::get('/a', self::handler('a'))]),
            ...Link::version('v2', fn () => [Link::get('/b', self::handler('b'))]),
            Link::get('/c', self::handler('c')),
        ]);

        self::assertSame(['v2', 'v10'], $router->versions());
    }

    /* ---------- Dispatch ---------- */

    public function test_dispatch_selects_the_requested_version(): void
    {
        $router = new Router();
        $router->register(...Link::prefix('/api')->group(fn () => [
            ...Link::version('v1', fn () => [Link::get('/users', self::handler('v1'))]),
            ...Link::version('v2', fn () => [Link::get('/users', self::handler('v2'))]),
        ]));

        $m1 = $router->match(self::request('GET', '/api/v1/users'));
        $m2 = $router->match(self::request('GET', '/api/v2/users'));
        self::assertSame('v1', $m1->route()->apiVersion());
        self::assertSame('v2', $m2->route()->apiVersion());
        self::assertSame('v1', $m1->route()->handler()(self::request('GET', '/api/v1/users'), [])->body());
    }

    public function test_unregistered_version_is_a_clean_404(): void
    {
        $router = new Router();
        $router->register(...[
            Link::get('/api/users', self::handler('unversioned')),
            ...Link::version('v1', fn () => [Link::get('/api/users', self::handler('v1'))]),
        ]);

        // /api/v9/users must NOT fall back to v1 or to the unversioned route.
        $this->expectException(NotFoundHttpException::class);
        $router->match(self::request('GET', '/api/v9/users'));
    }

    public function test_unversioned_catch_all_does_not_hide_versioned_routes(): void
    {
        $router = new Router();
        // Literal versioned routes registered before param catch-alls win.
        $router->register(...[
            ...Link::prefix('/api')->group(fn () => [
                ...Link::version('v1', fn () => [Link::get('/users', self::handler('v1'))]),
            ]),
            Link::get('/api/{tenant}/users', self::handler('tenant')),
        ]);

        $match = $router->match(self::request('GET', '/api/v1/users'));
        self::assertSame('v1', $match->route()->apiVersion());
    }

    /* ---------- Version-aware OpenAPI ---------- */

    private function versionedRouter(): Router
    {
        $router = new Router();
        $router->register(...[
            Link::get('/health', self::handler('ok'))->name('health'),
            ...Link::prefix('/api')->group(fn () => [
                ...Link::version('v1', fn () => [
                    Link::get('/users', self::handler('v1'))->name('api.v1.users'),
                ]),
                ...Link::version('v2', fn () => [
                    Link::get('/users', self::handler('v2'))->name('api.v2.users'),
                ]),
            ]),
        ]);

        return $router;
    }

    public function test_openapi_filters_to_one_version(): void
    {
        $doc = (new Generator())->generate($this->versionedRouter()->routes(), [], ['api_version' => 'v2']);

        self::assertSame(['/api/v2/users'], array_keys($doc['paths']));
        self::assertSame('v2', $doc['x-api-version']);
        self::assertSame('v2', $doc['info']['version']);
        self::assertSame('api_v2_users', $doc['paths']['/api/v2/users']['get']['operationId']);
    }

    public function test_openapi_version_doc_can_include_unversioned_routes(): void
    {
        $doc = (new Generator())->generate($this->versionedRouter()->routes(), [], [
            'api_version' => 'v1', 'include_unversioned' => true,
        ]);

        self::assertSame(['/api/v1/users', '/health'], array_keys($doc['paths']));
    }

    public function test_openapi_unversioned_doc_unaffected(): void
    {
        $doc = (new Generator())->generate($this->versionedRouter()->routes(), [], []);

        self::assertSame(['/api/v1/users', '/api/v2/users', '/health'], array_keys($doc['paths']));
        self::assertArrayNotHasKey('x-api-version', $doc);
        self::assertSame('1.0.0', $doc['info']['version']);
    }

    public function test_openapi_unknown_version_is_a_diagnostic_error(): void
    {
        $generator = new Generator();
        $generator->generate($this->versionedRouter()->routes(), [], ['api_version' => 'v9']);

        $errors = array_filter($generator->diagnostics(), fn ($d) => $d['level'] === 'error');
        self::assertNotEmpty($errors);
        self::assertStringContainsString('v9', array_values($errors)[0]['message']);
    }

    public function test_openapi_describe_for_other_version_is_not_orphan(): void
    {
        // Metadata declared for v2 routes must not error when generating v1.
        $generator = new Generator();
        $generator->generate($this->versionedRouter()->routes(), [
            'api.v2.users' => ['summary' => 'v2 list'],
            'api.v1.users' => ['summary' => 'v1 list'],
        ], ['api_version' => 'v1']);

        $errors = array_filter($generator->diagnostics(), fn ($d) => $d['level'] === 'error');
        self::assertSame([], array_values($errors));
    }
}
