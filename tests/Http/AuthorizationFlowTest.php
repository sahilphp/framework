<?php

declare(strict_types=1);

namespace Sahil\Tests\Http;

use Sahil\Auth\AuthManager;
use Sahil\Auth\Bouncer;
use Sahil\Auth\BouncerManager;
use Sahil\Auth\RateLimiter;
use Sahil\Auth\SessionAuthState;
use Sahil\Auth\TokenAuthorizer;
use Sahil\Contracts\Auth\AuthStateContract;
use Sahil\Contracts\Session\SessionContract;
use Sahil\Contracts\View\ViewContract;
use Sahil\Http\Middleware\Authorize;
use Sahil\Http\Request;
use Sahil\Http\Response;
use Sahil\Http\Router;
use Sahil\Routing\Link;
use Sahil\Tests\Auth\AuthUser;
use Sahil\Tests\Auth\RecordingUserProvider;
use Sahil\Tests\FrameworkTestCase;

/**
 * Bouncer end-to-end: the `ability` route-middleware alias, {param}
 * resource arguments, template directives, token ceilings, and
 * request-to-request isolation — all through the real kernel, session
 * auth state and container of the fixture application.
 */
final class AuthorizationFlowTest extends FrameworkTestCase
{
    private function wireAuth(): RecordingUserProvider
    {
        $container = $this->container();
        $provider = new RecordingUserProvider();

        $container->instance(RecordingUserProvider::class, $provider);
        $container->singleton(AuthStateContract::class, static fn ($c): AuthStateContract
            => new SessionAuthState($c->make(SessionContract::class), $provider));
        $container->singleton(AuthManager::class, static fn ($c): AuthManager
            => new AuthManager($provider, $c->make(AuthStateContract::class), new RateLimiter()));
        $container->singleton(BouncerManager::class, static fn ($c): BouncerManager
            => new BouncerManager(static fn () => $c->make(AuthManager::class)->user()));
        $container->instance(Authorize::class, new Authorize(
            $container->make(AuthManager::class),
            $container->make(BouncerManager::class),
        ));

        return $provider;
    }

    private function registerAuthRoutes(): void
    {
        $this->withRoutes(function (Router $router): void {
            $router->register(Link::get('/admin', static fn (): Response => Response::text('admin panel'))
                ->name('admin')
                ->middleware('ability:manage-users'));

            $router->register(Link::get('/posts/{post}/edit', static fn (Request $request, array $params): Response
                => Response::text('editing '.$params['post']))
                ->name('posts.edit')
                ->middleware('ability:update-post,{post}'));

            $router->register(Link::get('/multi', static fn (): Response => Response::text('multi'))
                ->name('multi')
                ->middleware('ability:publish-post,manage-users'));

            $router->register(Link::get('/can-page', static function (): Response {
                try {
                    return Response::html(app()->container()->make(ViewContract::class)->render('pages.can'));
                } catch (\Throwable $e) {
                    return Response::text(get_class($e).': '.$e->getMessage(), 500);
                }
            })->name('can.page'));

            $router->register(Link::get('/token-gated', static function (Request $request): Response {
                // A token-auth path: the token bounds what the owner may
                // attempt; Bouncer still decides.
                $token = TokenAuthorizer::fromAbilities($request->queryParameter('scope') === 'wide' ? ['*'] : ['posts.read']);
                $manager = app()->container()->make(BouncerManager::class);

                $user = app()->container()->make(AuthManager::class)->user();
                if ($user === null) {
                    return Response::json(['error' => 'unauthorized'], 401);
                }

                $checker = $manager->forUser($user)->withToken($token);

                return Response::json([
                    'posts.read' => $checker->allows('posts.read'),
                    'posts.write' => $checker->allows('posts.write'),
                ]);
            })->name('token.gated'));
        });
    }

    /** @return array<string, string> */
    private function loggedInCookies(int $userId): array
    {
        /** @var SessionContract $session */
        $session = $this->container()->make(SessionContract::class);
        $session->put('_auth_user_id', $userId);
        $session->token();
        $session->save();

        return [$session->getName() => $session->id()];
    }

    /* ---------- ability middleware ---------- */

    public function test_guest_gets_401_from_ability_middleware(): void
    {
        $this->wireAuth();
        $this->registerAuthRoutes();

        $response = $this->get('/admin');

        $response->assertStatus(401);
    }

    public function test_allowed_user_reaches_the_handler(): void
    {
        $provider = $this->wireAuth();
        $provider->add(new AuthUser(['id' => 1]));
        $this->registerAuthRoutes();

        Bouncer::swap($this->container()->make(BouncerManager::class));
        try {
            Bouncer::define('manage-users', static fn (AuthUser $u): bool => true);

            $response = $this->get('/admin', cookies: $this->loggedInCookies(1));
            $response->assertStatus(200)->assertSee('admin panel');
        } finally {
            Bouncer::flushState();
        }
    }

    public function test_denied_user_gets_403(): void
    {
        $provider = $this->wireAuth();
        $provider->add(new AuthUser(['id' => 1]));
        $this->registerAuthRoutes();

        Bouncer::swap($this->container()->make(BouncerManager::class));
        try {
            Bouncer::define('manage-users', static fn (AuthUser $u): bool => false);

            $this->get('/admin', cookies: $this->loggedInCookies(1))->assertStatus(403);
        } finally {
            Bouncer::flushState();
        }
    }

    public function test_unknown_ability_on_route_denies(): void
    {
        $provider = $this->wireAuth();
        $provider->add(new AuthUser(['id' => 1]));
        $this->registerAuthRoutes();

        $this->get('/admin', cookies: $this->loggedInCookies(1))->assertStatus(403);
    }

    public function test_route_placeholder_feeds_the_ability(): void
    {
        $provider = $this->wireAuth();
        $provider->add(new AuthUser(['id' => 1]));
        $this->registerAuthRoutes();

        Bouncer::swap($this->container()->make(BouncerManager::class));
        try {
            Bouncer::define('update-post', static fn (AuthUser $u, string $post): bool => $post === '42');

            $this->get('/posts/42/edit', cookies: $this->loggedInCookies(1))->assertStatus(200)->assertSee('editing 42');
            $this->get('/posts/7/edit', cookies: $this->loggedInCookies(1))->assertStatus(403);
        } finally {
            Bouncer::flushState();
        }
    }

    public function test_missing_route_placeholder_is_a_config_error(): void
    {
        $provider = $this->wireAuth();
        $provider->add(new AuthUser(['id' => 1]));

        $this->withRoutes(function (Router $router): void {
            $router->register(Link::get('/broken/{id}', static fn (): Response => Response::text('x'))
                ->middleware('ability:update-post,{post}')); // no {post} param
        });

        $this->get('/broken/1', cookies: $this->loggedInCookies(1))->assertStatus(500);
    }

    public function test_multiple_abilities_mean_any(): void
    {
        $provider = $this->wireAuth();
        $provider->add(new AuthUser(['id' => 1]));
        $this->registerAuthRoutes();

        Bouncer::swap($this->container()->make(BouncerManager::class));
        try {
            Bouncer::define('manage-users', static fn (AuthUser $u): bool => true);

            // publish-post undefined, manage-users allowed → any-of passes.
            $this->get('/multi', cookies: $this->loggedInCookies(1))->assertStatus(200);
        } finally {
            Bouncer::flushState();
        }
    }

    /* ---------- template integration ---------- */

    public function test_can_and_cannot_directives_follow_bouncer(): void
    {
        $provider = $this->wireAuth();
        $provider->add(new AuthUser(['id' => 1]));
        $this->registerAuthRoutes();

        Bouncer::swap($this->container()->make(BouncerManager::class));
        try {
            Bouncer::define('manage-users', static fn (AuthUser $u): bool => true);
            Bouncer::define('delete-post', static fn (AuthUser $u, int $post): bool => $post === 1);

            $response = $this->get('/can-page', cookies: $this->loggedInCookies(1));

            $response->assertStatus(200)
                ->assertSee('you can manage')
                ->assertSee('cannot delete post 2')
                ->assertDontSee('cannot manage')
                ->assertDontSee('can delete post 2')
                ->assertSee('expr=Admin');
        } finally {
            Bouncer::flushState();
        }
    }

    public function test_guest_templates_deny(): void
    {
        $this->wireAuth();
        $this->registerAuthRoutes();

        $response = $this->get('/can-page', cookies: $this->sessionCookiesForGuest());

        $response->assertSee('cannot manage')->assertDontSee('you can manage');
    }

    /** @return array<string, string> */
    private function sessionCookiesForGuest(): array
    {
        /** @var SessionContract $session */
        $session = $this->container()->make(SessionContract::class);
        $session->token();
        $session->save();

        return [$session->getName() => $session->id()];
    }

    /* ---------- token ceiling ---------- */

    public function test_token_ceiling_ands_with_owner_authorization(): void
    {
        $provider = $this->wireAuth();
        $provider->add(new AuthUser(['id' => 1]));
        $this->registerAuthRoutes();

        Bouncer::swap($this->container()->make(BouncerManager::class));
        try {
            Bouncer::define('posts.read', static fn (AuthUser $u): bool => true);
            Bouncer::define('posts.write', static fn (AuthUser $u): bool => true);

            // Token grants only posts.read → write denied despite owner allow.
            $response = $this->get('/token-gated', cookies: $this->loggedInCookies(1));
            $response->assertJson(['posts.read' => true, 'posts.write' => false]);

            // Wildcard token: ceiling opens, owner still decides.
            $wide = $this->get('/token-gated?scope=wide', cookies: $this->loggedInCookies(1));
            $wide->assertJson(['posts.read' => true, 'posts.write' => true]);
        } finally {
            Bouncer::flushState();
        }
    }

    /* ---------- worker isolation ---------- */

    public function test_consecutive_requests_do_not_leak_authorization(): void
    {
        $provider = $this->wireAuth();
        $provider->add(new AuthUser(['id' => 1])); // admin
        $provider->add(new AuthUser(['id' => 2])); // plain
        $this->registerAuthRoutes();

        Bouncer::swap($this->container()->make(BouncerManager::class));
        try {
            Bouncer::define('manage-users', static fn (AuthUser $u): bool => $u->getAuthIdentifier() === 1);

            $this->get('/admin', cookies: $this->loggedInCookies(1))->assertStatus(200);

            // A different session — fresh cookie jar — is denied, not
            // granted by the previous request's decision.
            $plain = $this->container()->make(SessionContract::class);
            $plain->save(); // end session 1
            $other = new \Sahil\Session\Session($this->container()->make(\Sahil\Contracts\Session\SessionDriverContract::class));
            $other->put('_auth_user_id', 2);
            $other->token();
            $other->save();

            $this->get('/admin', cookies: [$other->getName() => $other->id()])->assertStatus(403);
            $this->get('/admin')->assertStatus(401);
        } finally {
            Bouncer::flushState();
        }
    }

    /* ---------- controller-side authorize ---------- */

    public function test_controller_authorize_trait(): void
    {
        $provider = $this->wireAuth();
        $provider->add(new AuthUser(['id' => 1]));

        $controller = new class () {
            use \Sahil\Auth\AuthorizesRequests;

            public function handle(): Response
            {
                $this->authorize('manage-users');

                return Response::text('ok');
            }
        };

        $this->withRoutes(function (Router $router) use ($controller): void {
            $router->register(Link::get('/trait', static fn () => $controller->handle())->name('trait'));
        });

        Bouncer::swap($this->container()->make(BouncerManager::class));
        try {
            Bouncer::define('manage-users', static fn (AuthUser $u): bool => true);

            $this->get('/trait', cookies: $this->loggedInCookies(1))->assertStatus(200);
            $this->get('/trait')->assertStatus(401); // guest → AuthenticationException
        } finally {
            Bouncer::flushState();
        }
    }
}
