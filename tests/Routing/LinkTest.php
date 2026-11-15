<?php

declare(strict_types=1);

namespace Sahil\Tests\Routing;

use PHPUnit\Framework\TestCase;
use Sahil\Http\Exception\MethodNotAllowedHttpException;
use Sahil\Http\Exception\NotFoundHttpException;
use Sahil\Http\Request;
use Sahil\Http\Response;
use Sahil\Http\Route;
use Sahil\Http\Router;
use Sahil\Routing\Link;

/**
 * The native `Link` route-definition API and the deprecated `Route` alias.
 */
final class LinkTest extends TestCase
{
    private static function request(string $method, string $path): Request
    {
        return Request::fromGlobals(['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path], []);
    }

    public function test_every_http_method_registers(): void
    {
        $handler = static fn (): Response => Response::text('ok');

        self::assertSame(['GET', 'HEAD'], Link::get('/a', $handler)->methods());
        self::assertSame(['POST'], Link::post('/a', $handler)->methods());
        self::assertSame(['PUT'], Link::put('/a', $handler)->methods());
        self::assertSame(['PATCH'], Link::patch('/a', $handler)->methods());
        self::assertSame(['DELETE'], Link::delete('/a', $handler)->methods());
        self::assertSame(['OPTIONS'], Link::options('/a', $handler)->methods());
    }

    public function test_any_registers_every_supported_method(): void
    {
        $link = Link::any('/all', static fn (): Response => Response::text('all'));

        self::assertSame(Request::SUPPORTED_METHODS, $link->methods());
    }

    public function test_match_registers_an_explicit_method_set(): void
    {
        $link = Link::match(['get', 'post'], '/multi', static fn (): Response => Response::text('multi'));

        self::assertSame(['GET', 'POST'], $link->methods());
    }

    public function test_match_rejects_unsupported_methods(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Link::match(['TELEPORT'], '/x', static fn (): Response => Response::text('x'));
    }

    public function test_links_match_and_dispatch_through_the_router(): void
    {
        $router = new Router();
        $router->register(
            Link::get('/users/{id}', static fn (Request $r, array $p): Response => Response::json($p)),
            Link::post('/users', static fn (): Response => Response::text('created', 201)),
            Link::any('/webhook', static fn (): Response => Response::text('hooked')),
        );

        self::assertSame(['id' => '42'], $router->match(self::request('GET', '/users/42'))->params());
        self::assertSame(201, $router->match(self::request('POST', '/users'))->route()->handler()(self::request('POST', '/users'), [])->status());
        self::assertInstanceOf(Response::class, $router->match(self::request('DELETE', '/webhook'))->route()->handler()(self::request('DELETE', '/webhook'), []));

        $this->expectException(MethodNotAllowedHttpException::class);
        $router->match(self::request('DELETE', '/users/42'));
    }

    public function test_named_links_generate_urls(): void
    {
        $router = new Router();
        $router->register(
            Link::get('/users/{id}', static fn (): Response => Response::text('u'))->name('users.show'),
            Link::get('/posts/{slug?}', static fn (): Response => Response::text('p'))->name('posts.show'),
        );

        self::assertSame('/users/7', $router->generate('users.show', ['id' => 7]));
        self::assertSame('/posts', $router->generate('posts.show'));
        self::assertSame('/posts/hello', $router->generate('posts.show', ['slug' => 'hello']));
    }

    public function test_link_group_applies_prefix_and_shared_middleware(): void
    {
        $links = Link::group('/admin', static fn (): array => [
            Link::get('/dashboard', static fn (): Response => Response::text('dash'))->name('admin.dash'),
            Link::get('/users', static fn (): Response => Response::text('users')),
        ], middleware: ['auth']);

        self::assertCount(2, $links);
        self::assertSame('/admin/dashboard', $links[0]->path());
        self::assertSame('/admin/users', $links[1]->path());
        self::assertSame(['auth'], $links[0]->middlewareList());
        self::assertSame('admin.dash', $links[0]->routeName());
    }

    public function test_fluent_group_builder_matches_direct_group(): void
    {
        $links = Link::prefix('/api')
            ->middleware('throttle')
            ->group(static fn (): array => [
                Link::get('/ping', static fn (): Response => Response::text('pong')),
            ]);

        self::assertSame('/api/ping', $links[0]->path());
        self::assertSame(['throttle'], $links[0]->middlewareList());
    }

    public function test_group_links_dispatch_through_one_router(): void
    {
        $links = [
            Link::get('/', static fn (): Response => Response::text('home'))->name('home'),
            ...Link::prefix('/admin')->group(static fn (): array => [
                Link::get('/dashboard', static fn (): Response => Response::text('dash'))->name('admin.dash'),
            ]),
        ];

        $router = new Router();
        $router->register(...$links);

        self::assertSame('dash', $router->match(self::request('GET', '/admin/dashboard'))->route()->handler()(self::request('GET', '/admin/dashboard'), [])->body());
        self::assertSame('/admin/dashboard', $router->generate('admin.dash'));
    }

    public function test_deprecated_route_alias_delegates_to_the_same_system(): void
    {
        $route = Route::get('/legacy', static fn (): Response => Response::text('legacy'));

        self::assertInstanceOf(Route::class, $route);
        self::assertInstanceOf(Link::class, $route);
        self::assertNotInstanceOf(Route::class, Link::get('/new', static fn (): Response => Response::text('n')));

        $router = new Router();
        $router->register(
            $route->name('legacy.show'),
            Link::get('/new', static fn (): Response => Response::text('new'))->name('new.show'),
        );

        self::assertSame('legacy', $router->match(self::request('GET', '/legacy'))->route()->handler()(self::request('GET', '/legacy'), [])->body());
        self::assertSame('new', $router->match(self::request('GET', '/new'))->route()->handler()(self::request('GET', '/new'), [])->body());
        self::assertSame('/legacy', $router->generate('legacy.show'));
    }

    public function test_route_alias_keeps_fluent_configuration(): void
    {
        $route = Route::get('/x', static fn (): Response => Response::text('x'))
            ->name('x.show')
            ->middleware('mw');

        self::assertInstanceOf(Route::class, $route);
        self::assertSame('x.show', $route->routeName());
        self::assertSame(['mw'], $route->middlewareList());
    }

    public function test_groups_still_reject_bare_root_paths(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Link::group('/admin', static fn (): array => [
            Link::get('/', static fn (): Response => Response::text('nope')),
        ]);
    }

    public function test_unmatched_paths_still_throw_not_found(): void
    {
        $router = new Router();
        $router->register(Link::get('/known', static fn (): Response => Response::text('k')));

        $this->expectException(NotFoundHttpException::class);
        $router->match(self::request('GET', '/missing'));
    }
}
