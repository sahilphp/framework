<?php

declare(strict_types=1);

namespace Sahil\Tests\Http;

use PHPUnit\Framework\TestCase;
use Sahil\Auth\AuthManager;
use Sahil\Auth\Gate;
use Sahil\Auth\Password;
use Sahil\Auth\RateLimiter;
use Sahil\Auth\SessionAuthState;
use Sahil\Container\Container;
use Sahil\Foundation\Application;
use Sahil\Foundation\ServiceProvider;
use Sahil\Http\Kernel;
use Sahil\Http\Middleware\Authorize;
use Sahil\Http\Middleware\MiddlewareContract;
use Sahil\Http\Middleware\MiddlewareSpec;
use Sahil\Http\Middleware\ParameterizedMiddlewareContract;
use Sahil\Http\Request;
use Sahil\Http\Response;
use Sahil\Http\Route;
use Sahil\Http\Router;
use Sahil\Session\FileSessionDriver;
use Sahil\Session\Session;

/**
 * Route middleware parameters: MiddlewareSpec resolution, the adapter contract,
 * and Authorize's parameterized form.
 */
final class MiddlewareSpecTest extends TestCase
{
    private ?string $sessionPath = null;

    protected function tearDown(): void
    {
        Application::free();

        if ($this->sessionPath !== null) {
            foreach ((glob($this->sessionPath.'/*') ?: []) as $file) {
                @unlink($file);
            }

            @rmdir($this->sessionPath);
            $this->sessionPath = null;
        }
    }

    private static function kernelWith(Router $router, ?Container $container = null): Kernel
    {
        Application::free();
        $app = Application::configure(__DIR__.'/../Fixtures/app-root');
        $app->register(new class ($app) extends ServiceProvider {});
        $app->boot();

        return new Kernel($router, $container ?? $app->container(), $app);
    }

    private static function request(string $path = '/x', string $method = 'GET'): Request
    {
        return Request::fromGlobals(['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path], []);
    }

    public function test_parameters_reach_the_middleware_through_the_kernel(): void
    {
        $router = new Router();
        $router->register(
            Route::get('/limited', static fn (): Response => Response::text('reached'))
                ->middleware([new MiddlewareSpec(ParameterEchoMiddleware::class, ['ten', 'twenty'])]),
        );

        $kernel = self::kernelWith($router);
        $response = $kernel->handle(self::request('/limited'));

        self::assertSame('reached', $response->body());
        self::assertSame('ten,twenty', $response->header('X-Params'));
    }

    public function test_spec_without_parameters_uses_the_plain_middleware_path(): void
    {
        $router = new Router();
        $router->register(
            Route::get('/plain', static fn (): Response => Response::text('ok'))
                ->middleware([new MiddlewareSpec(ParameterEchoMiddleware::class)]),
        );

        $response = self::kernelWith($router)->handle(self::request('/plain'));

        self::assertSame('ok', $response->body());
        self::assertSame('handle', $response->header('X-Params'), 'no parameters → MiddlewareContract::handle()');
    }

    public function test_parameters_for_a_non_parameterized_middleware_fail_loudly(): void
    {
        $container = self::container();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(ParameterizedMiddlewareContract::class);

        (new MiddlewareSpec(PlainTagMiddleware::class, ['unused']))->resolve($container);
    }

    public function test_spec_rejects_a_class_that_is_not_middleware(): void
    {
        $container = self::container();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(MiddlewareContract::class);

        (new MiddlewareSpec(NotMiddleware::class))->resolve($container);
    }

    /**
     * A container with the test middleware bound, for direct spec resolution.
     */
    private static function container(): Container
    {
        Application::free();
        $app = Application::configure(__DIR__.'/../Fixtures/app-root');
        $app->register(new class ($app) extends ServiceProvider {});
        $app->boot();

        $container = $app->container();
        $container->bind(ParameterEchoMiddleware::class);
        $container->bind(PlainTagMiddleware::class);
        $container->bind(NotMiddleware::class);

        return $container;
    }

    public function test_spec_rejects_an_empty_class_name(): void
    {
        $this->expectException(\RuntimeException::class);

        new MiddlewareSpec('   ');
    }

    public function test_authorize_implements_both_middleware_contracts(): void
    {
        [$auth] = $this->makeAuth([]);

        $authorize = new Authorize($auth, new Gate());

        self::assertInstanceOf(MiddlewareContract::class, $authorize);
        self::assertInstanceOf(ParameterizedMiddlewareContract::class, $authorize);
    }

    public function test_authorize_denies_when_the_session_user_lacks_the_ability(): void
    {
        [$auth] = $this->makeAuth([]);
        $gate = new Gate();
        $gate->define('publish-post', static fn ($user): bool => false);

        $authorize = new Authorize($auth, $gate);

        $response = $authorize->handleWithParameters(
            self::request('/posts', 'POST'),
            static fn (Request $r): Response => Response::text('published'),
            ['publish-post'],
        );

        self::assertSame(403, $response->status());
        self::assertStringNotContainsString('published', $response->body());
    }

    public function test_authorize_allows_when_a_gate_grants_the_ability(): void
    {
        [$auth] = $this->makeAuth([]);
        $gate = new Gate();
        $gate->define('publish-post', static fn ($user): bool => $user !== null);

        $authorize = new Authorize($auth, $gate);

        $response = $authorize->handleWithParameters(
            self::request('/posts', 'POST'),
            static fn (Request $r): Response => Response::text('published'),
            ['publish-post'],
        );

        self::assertSame(200, $response->status());
        self::assertSame('published', $response->body());
    }

    public function test_authorize_rejects_non_string_parameters(): void
    {
        [$auth] = $this->makeAuth([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('non-empty ability name');

        (new Authorize($auth, new Gate()))->handleWithParameters(
            self::request('/posts', 'POST'),
            static fn (Request $r): Response => Response::text('published'),
            [123],
        );
    }

    /**
     * @return array{0: AuthManager}
     */
    private function makeAuth(array $permissions): array
    {
        $this->sessionPath ??= $this->makeSessionPath();

        $provider = new SpecUserProvider();
        $provider->add(new SpecUser(
            ['id' => 7, 'email' => 'alice@example.test', 'password' => Password::hash('secret')],
            $permissions,
        ));

        $session = new Session(new FileSessionDriver($this->sessionPath));
        $state = new SessionAuthState($session, $provider);
        $auth = new AuthManager($provider, $state, new RateLimiter());
        $auth->attempt(['email' => 'alice@example.test', 'password' => 'secret']);

        return [$auth];
    }

    private function makeSessionPath(): string
    {
        $path = sys_get_temp_dir().'/sahil_mw_spec_'.bin2hex(random_bytes(6));

        if (!mkdir($path, 0o700, true) && !is_dir($path)) {
            self::fail('Unable to create session directory.');
        }

        return $path;
    }
}

/**
 * Middleware that reports the parameters it was given.
 */
final class ParameterEchoMiddleware implements MiddlewareContract, ParameterizedMiddlewareContract
{
    public function handle(Request $request, \Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        return $response->withHeader('X-Params', 'handle');
    }

    public function handleWithParameters(Request $request, \Closure $next, array $parameters): Response
    {
        /** @var Response $response */
        $response = $next($request);

        return $response->withHeader('X-Params', implode(',', array_map(strval(...), $parameters)));
    }
}

/**
 * A class that is not middleware at all.
 */
final class NotMiddleware
{
}

/**
 * Middleware that does not accept parameters.
 */
final class PlainTagMiddleware implements MiddlewareContract
{
    public function handle(Request $request, \Closure $next): Response
    {
        return $next($request);
    }
}

final class SpecUser implements \Sahil\Contracts\Auth\AuthenticatableContract, \Sahil\Contracts\Auth\AuthorizableContract
{
    /** @param array<string, mixed> $attributes @param list<string> $permissions */
    public function __construct(
        private readonly array $attributes,
        private readonly array $permissions = [],
    ) {
    }

    public function getAuthIdentifier(): int|string
    {
        return (int) $this->attributes['id'];
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthPassword(): string
    {
        return (string) $this->attributes['password'];
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken(string $token): void
    {
    }

    public function hasRole(string $role): bool
    {
        return false;
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function getRoles(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return $this->permissions;
    }
}

final class SpecUserProvider implements \Sahil\Contracts\Auth\UserProviderContract
{
    /** @var array<int, SpecUser> */
    private array $users = [];

    public function add(SpecUser $user): void
    {
        $this->users[$user->getAuthIdentifier()] = $user;
    }

    public function retrieveByIdentifier(int|string $identifier): ?\Sahil\Contracts\Auth\AuthenticatableContract
    {
        return $this->users[(int) $identifier] ?? null;
    }

    public function retrieveByCredentials(array $credentials): ?\Sahil\Contracts\Auth\AuthenticatableContract
    {
        foreach ($this->users as $user) {
            if ($user->getAuthIdentifier() === 7) {
                return $user;
            }
        }

        return null;
    }

    public function validateCredentials(\Sahil\Contracts\Auth\AuthenticatableContract $user, array $credentials): bool
    {
        return Password::verify((string) ($credentials['password'] ?? ''), $user->getAuthPassword());
    }

    public function rehashPasswordIfRequired(\Sahil\Contracts\Auth\AuthenticatableContract $user, string $password, array $options): void
    {
    }
}
