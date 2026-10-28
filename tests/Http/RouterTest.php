<?php

declare(strict_types=1);

namespace Sahil\Tests\Http;

use PHPUnit\Framework\TestCase;
use Sahil\Http\Exception\MethodNotAllowedHttpException;
use Sahil\Http\Exception\NotFoundHttpException;
use Sahil\Http\Request;
use Sahil\Http\Response;
use Sahil\Http\Route;
use Sahil\Http\Router;

final class RouterTest extends TestCase
{
    private const SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'];

    private static function request(string $path, string $method = 'GET'): Request
    {
        return Request::fromGlobals(['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path], []);
    }

    public function test_static_route_matches_and_param_extraction(): void
    {
        $router = new Router();
        $router->register(
            Route::get('/health', static fn (): Response => Response::text('health')),
            Route::get('/users/{id}', static fn (): Response => Response::text('user')),
            Route::get('/users/{id}/posts/{postId}', static fn (): Response => Response::text('post')),
            Route::get('/posts/{slug?}', static fn (): Response => Response::text('posts')),
            Route::get('/files/{path:\d+}', static fn (): Response => Response::text('file')),
        );

        // Static
        $match = $router->match(self::request('/health'));
        self::assertSame([], $match->params());

        // Single parameter, decoded
        $match = $router->match(self::request('/users/42'));
        self::assertSame(['id' => '42'], $match->params());

        // Two parameters
        $match = $router->match(self::request('/users/7/posts/9'));
        self::assertSame(['id' => '7', 'postId' => '9'], $match->params());

        // Optional parameter present
        $match = $router->match(self::request('/posts/hello-world'));
        self::assertSame(['slug' => 'hello-world'], $match->params());

        // Optional parameter absent (with root segments intact)
        $match = $router->match(self::request('/posts'));
        self::assertSame(['slug' => ''], $match->params());

        // Pattern-constrained parameter
        $match = $router->match(self::request('/files/123'));
        self::assertSame(['path' => '123'], $match->params());

        // Trailing slash tolerated
        self::assertSame(['id' => '42'], $router->match(self::request('/users/42/'))->params());
    }

    public function test_unmatched_path_throws_404(): void
    {
        $router = new Router();
        $router->register(Route::get('/health', static fn (): Response => Response::text('health')));

        try {
            $router->match(self::request('/definitely-missing'));
            self::fail('expected NotFoundHttpException');
        } catch (NotFoundHttpException $exception) {
            self::assertSame(404, $exception->statusCode());
        }
    }

    public function test_method_mismatch_throws_405_with_allow(): void
    {
        $router = new Router();
        $router->register(
            Route::get('/health', static fn (): Response => Response::text('x')),
            Route::post('/users', static fn (): Response => Response::empty()),
            Route::delete('/users', static fn (): Response => Response::empty()),
        );

        try {
            $router->match(self::request('/users', 'PUT'));
            self::fail('expected MethodNotAllowedHttpException');
        } catch (MethodNotAllowedHttpException $exception) {
            self::assertSame(405, $exception->statusCode());
            self::assertSame('DELETE, POST', $exception->getHeaders()['Allow']);
        }
    }

    public function test_head_matches_get_templates(): void
    {
        $router = new Router();
        $router->register(Route::get('/health', static fn (): Response => Response::text('x')));

        self::assertSame([], $router->match(self::request('/health', 'HEAD'))->params());
    }

    public function test_route_names_and_reverse_generation(): void
    {
        $router = new Router();
        $router->register(
            Route::get('/health', static fn (): Response => Response::text('x'))->name('health'),
            Route::get('/users/{id}', static fn (): Response => Response::text('x'))->name('users.show'),
            Route::get('/posts/{slug?}', static fn (): Response => Response::text('x'))->name('posts.index'),
        );

        self::assertSame('/health', $router->generate('health'));
        self::assertSame('/users/9', $router->generate('users.show', ['id' => '9']));
        self::assertSame('/posts', $router->generate('posts.index'));
        self::assertSame('/posts/abc', $router->generate('posts.index', ['slug' => 'abc']));

        self::assertNotNull($router->routeByName('health'));
        self::assertNull($router->routeByName('nope'));
    }

    public function test_reverse_generation_rejects_missing_and_extra_params(): void
    {
        $router = new Router();
        $router->register(Route::get('/users/{id}', static fn (): Response => Response::text('x'))->name('users.show'));

        self::expectException(\InvalidArgumentException::class);
        $router->generate('users.show');
    }

    public function test_reverse_generation_rejects_extra_params(): void
    {
        $router = new Router();
        $router->register(Route::get('/health', static fn (): Response => Response::text('x'))->name('health'));

        self::expectException(\InvalidArgumentException::class);

        $router->generate('health', ['unexpected' => 'x']);
    }

    public function test_reverse_generation_rejects_unsafe_values(): void
    {
        $router = new Router();
        $router->register(Route::get('/users/{id}', static fn (): Response => Response::text('x'))->name('users.show'));

        self::expectException(\InvalidArgumentException::class);

        $router->generate('users.show', ['id' => 'a/b']);
    }

    public function test_unregistered_name_rejected(): void
    {
        $router = new Router();

        self::expectException(\InvalidArgumentException::class);

        $router->generate('nowhere');
    }

    public function test_duplicate_route_names_rejected(): void
    {
        $router = new Router();
        $router->register(Route::get('/one', static fn (): Response => Response::text('x'))->name('dup'));

        self::expectException(\InvalidArgumentException::class);

        $router->register(Route::get('/two', static fn (): Response => Response::text('x'))->name('dup'));
    }

    public function test_path_validation_rejects_bad_templates(): void
    {
        $rejected = 0;

        foreach (['users', ' /users', '/users/{a-b}', '/users/{x?}/{y}'] as $template) {
            try {
                Route::get($template, static fn (): Response => Response::text('x'));

                self::fail(sprintf('Expected template [%s] to be rejected.', $template));
            } catch (\InvalidArgumentException) {
                $rejected++;
            }
        }

        self::assertSame(4, $rejected, 'every listed template must be rejected');
    }

    public function test_first_registered_wins(): void
    {
        $router = new Router();

        $routeOne = Route::get('/users/{first}', static fn (): Response => Response::text('1'));
        $routeTwo = Route::get('/users/{second:\d+}', static fn (): Response => Response::text('2'));

        $router->register($routeOne, $routeTwo);

        self::assertSame(['first' => '1'], $router->match(self::request('/users/1'))->params());
    }
}
