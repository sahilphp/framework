<?php

declare(strict_types=1);

namespace Sahil\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Sahil\Auth\AuthManager;
use Sahil\Auth\BouncerManager;
use Sahil\Auth\RateLimiter;
use Sahil\Auth\Tokens\PersonalAccessToken;
use Sahil\Contracts\Auth\AuthenticatableContract;
use Sahil\Contracts\Auth\AuthStateContract;
use Sahil\Contracts\Auth\UserProviderContract;
use Sahil\Database\ConnectionFactory;
use Sahil\Database\Schema\Schema;
use Sahil\Database\Schema\Table;
use Sahil\Http\Kernel;
use Sahil\Http\Middleware\Authenticate;
use Sahil\Http\Middleware\RequireTokenAbility;
use Sahil\Http\Request;
use Sahil\Http\Response;
use Sahil\Http\Router;
use Sahil\Routing\Link;

final class ApiTokenHttpTest extends TestCase
{
    private string $path;

    private AuthManager $auth;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite unavailable.');
        }

        \Sahil\Foundation\Application::free();
        $this->path = sys_get_temp_dir().'/sahil-tokhttp-'.bin2hex(random_bytes(6)).'.sqlite';
        $db = (new ConnectionFactory())->make(['driver' => 'sqlite', 'database' => $this->path]);
        TokenUser::useConnection($db);
        PersonalAccessToken::useConnection($db);

        (new Schema($db))->create('users', static function (Table $t): void {
            $t->id();
            $t->string('email');
            $t->string('password');
        });
        (new Schema($db))->create('personal_access_tokens', static function (Table $t): void {
            $t->id();
            $t->string('tokenable_type');
            $t->integer('tokenable_id');
            $t->string('name');
            $t->string('token_id', 64)->unique();
            $t->string('token_hash', 64);
            $t->text('abilities');
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });

        $db->table('users')->insert(['email' => 'a@x', 'password' => 'h']);
        $db->table('users')->insert(['email' => 'b@x', 'password' => 'h']);

        $provider = new class () implements UserProviderContract {
            public function retrieveByIdentifier(int|string $identifier): ?AuthenticatableContract
            {
                return TokenUser::find($identifier);
            }

            public function retrieveByCredentials(array $credentials): ?AuthenticatableContract
            {
                return null;
            }

            public function validateCredentials(AuthenticatableContract $user, array $credentials): bool
            {
                return false;
            }

            public function rehashPasswordIfRequired(AuthenticatableContract $user, string $password, array $options): void
            {
            }
        };

        $state = new class () implements AuthStateContract {
            private ?AuthenticatableContract $user = null;
            public function user(): ?AuthenticatableContract
            {
                return $this->user;
            }
            public function id(): int|string|null
            {
                return $this->user?->getAuthIdentifier();
            }
            public function check(): bool
            {
                return $this->user !== null;
            }
            public function guest(): bool
            {
                return $this->user === null;
            }
            public function setUser(AuthenticatableContract $user): void
            {
                $this->user = $user;
            }
            public function clear(): void
            {
                $this->user = null;
            }
            public function remember(AuthenticatableContract $user, string $token): void
            {
            }
            public function retrieveRememberedUser(string $token): ?AuthenticatableContract
            {
                return null;
            }
            public function forgetRememberToken(AuthenticatableContract $user): void
            {
            }
        };

        $this->auth = new AuthManager($provider, $state, new RateLimiter());
    }

    protected function tearDown(): void
    {
        TokenUser::forgetConnection();
        PersonalAccessToken::forgetConnection();
        \Sahil\Auth\Bouncer::flushState();
        \Sahil\Foundation\Application::free();
        @unlink($this->path);
    }

    private function kernel(array $routes): Kernel
    {
        $app = \Sahil\Foundation\Application::configure(__DIR__.'/../Fixtures/app-root');
        $app->container()->instance(AuthManager::class, $this->auth);
        $app->container()->instance(Authenticate::class, new Authenticate($this->auth));
        $app->container()->instance(RequireTokenAbility::class, new RequireTokenAbility($this->auth));

        $router = new Router();
        $router->register(...$routes);

        return new Kernel($router, $app->container(), $app);
    }

    private function req(string $path, ?string $bearer = null): Request
    {
        return Request::fromGlobals(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $path]
            + ($bearer === null ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$bearer]),
            [],
        );
    }

    public function test_auth_api_route_accepts_valid_token_and_rejects_bad(): void
    {
        $new = TokenUser::find(1)->createToken('app', ['posts:read']);
        $kernel = $this->kernel([
            Link::get('/me', static fn (Request $r) => Response::json(['id' => 1]))
                ->middleware('auth:api'),
        ]);

        $ok = $kernel->handle($this->req('/me', $new->plainTextToken));
        self::assertSame(200, $ok->status());
        // principal attached to this request via viaToken()
        self::assertSame(1, $this->auth->user()?->getAuthIdentifier());

        $denied = $kernel->handle($this->req('/me', 'bogus.token'));
        self::assertSame(401, $denied->status());
        self::assertSame('unauthenticated', json_decode($denied->body(), true)['error']);
        // no redirect for API clients — JSON only
        self::assertSame('application/json', $denied->header('Content-Type'));
    }

    public function test_token_middleware_enforces_abilities(): void
    {
        $new = TokenUser::find(1)->createToken('app', ['posts:read']);
        $kernel = $this->kernel([
            Link::get('/posts', static fn () => Response::json(['ok' => true]))
                ->middleware(['auth:api', 'token:posts:read']),
        ]);

        self::assertSame(200, $kernel->handle($this->req('/posts', $new->plainTextToken))->status());

        // a token WITHOUT the ability → 403, not a bypass
        $limited = TokenUser::find(1)->createToken('limited', ['other:scope']);
        $res = $kernel->handle($this->req('/posts', $limited->plainTextToken));
        self::assertSame(403, $res->status());
    }

    public function test_worker_isolation_sequential_requests(): void
    {
        $a = TokenUser::find(1)->createToken('a');
        $b = TokenUser::find(2)->createToken('b');
        $kernel = $this->kernel([
            Link::get('/who', function () {
                return Response::json(['id' => $this->auth->user()?->getAuthIdentifier()]);
            })->middleware('auth:api'),
        ]);

        self::assertSame(1, json_decode($kernel->handle($this->req('/who', $a->plainTextToken))->body(), true)['id']);
        self::assertSame(2, json_decode($kernel->handle($this->req('/who', $b->plainTextToken))->body(), true)['id']);
        // request 3: no token after an authenticated one → 401, NOT user 2
        $res = $kernel->handle($this->req('/who'));
        self::assertSame(401, $res->status());
        self::assertNull($this->auth->user());
        // revoked after another request → fails immediately
        TokenUser::find(2)->revokeToken($b->accessToken->publicId());
        self::assertSame(401, $kernel->handle($this->req('/who', $b->plainTextToken))->status());
    }

    public function test_bouncer_ceiling_token_never_widens(): void
    {
        $manager = new BouncerManager(static fn () => null);
        $manager->define('posts:read', static fn () => true);
        \Sahil\Auth\Bouncer::swap($manager);

        $user = TokenUser::find(1);
        // owner has the Bouncer permission…
        self::assertTrue($manager->allows('posts:read', [], $user));

        // …but a token without the ability still denies
        $withLimited = $user->createToken('x', ['other']);
        $user->withAccessToken($withLimited->accessToken);
        self::assertFalse($manager->allows('posts:read', [], $user, $user->currentTokenAuthorizer()));
        // Authorizable::can() picks the ceiling up automatically
        self::assertFalse($user->can('posts:read'));

        // a token WITH the ability → Bouncer decides → allowed
        $withRight = $user->createToken('y', ['posts:read']);
        $user->withAccessToken($withRight->accessToken);
        self::assertTrue($user->can('posts:read'));
    }
}
