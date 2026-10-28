<?php

declare(strict_types=1);

namespace Sahil\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Sahil\Auth\AuthManager;
use Sahil\Auth\Gate;
use Sahil\Auth\Password;
use Sahil\Auth\RateLimiter;
use Sahil\Auth\SessionAuthState;
use Sahil\Http\Middleware\Authorize;
use Sahil\Http\Request;
use Sahil\Http\Response;
use Sahil\Session\FileSessionDriver;
use Sahil\Session\Session;

/**
 * Permission denial: guests are 401, authenticated users without the
 * required ability are 403, and the denied handler never runs.
 */
final class AuthorizeMiddlewareTest extends TestCase
{
    private string $sessionPath;

    protected function setUp(): void
    {
        $this->sessionPath = sys_get_temp_dir().'/sahil_authz_mw_'.bin2hex(random_bytes(6));

        if (!mkdir($this->sessionPath, 0o700, true) && !is_dir($this->sessionPath)) {
            $this->fail('Unable to create session directory.');
        }
    }

    protected function tearDown(): void
    {
        foreach ((glob($this->sessionPath.'/*') ?: []) as $file) {
            @unlink($file);
        }

        @rmdir($this->sessionPath);
    }

    public function test_guest_is_rejected_with_401_even_when_the_gate_would_allow(): void
    {
        $gate = new Gate();
        $gate->define('publish-post', static fn ($user): bool => true);

        [$auth] = $this->makeAuth([], login: false);
        $middleware = new Authorize($auth, $gate);

        $response = $middleware->handle(
            Request::fromGlobals(server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/posts']),
            static fn (Request $r): Response => Response::text('published'),
            'publish-post'
        );

        $this->assertSame(401, $response->status());
        $this->assertStringNotContainsString('published', $response->body());
    }

    public function test_authenticated_user_without_permission_gets_403(): void
    {
        $gate = new Gate();
        $gate->define('publish-post', static fn ($user): bool => false);

        [$auth] = $this->makeAuth([]);
        $middleware = new Authorize($auth, $gate);

        $handlerRan = false;
        $response = $middleware->handle(
            Request::fromGlobals(server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/posts']),
            static function (Request $r) use (&$handlerRan): Response {
                $handlerRan = true;

                return Response::text('published');
            },
            'publish-post'
        );

        $this->assertFalse($handlerRan, 'a denied request must never reach the handler');
        $this->assertSame(403, $response->status());
        $this->assertStringNotContainsString('published', $response->body());
    }

    public function test_undefined_gate_denies_access(): void
    {
        [$auth] = $this->makeAuth([]);
        $middleware = new Authorize($auth, new Gate());

        $response = $middleware->handle(
            Request::fromGlobals(server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/posts']),
            static fn (Request $r): Response => Response::text('published'),
            'never-defined'
        );

        $this->assertSame(403, $response->status());
    }

    public function test_user_permission_grants_access_when_the_gate_denies(): void
    {
        $gate = new Gate();
        $gate->define('publish-post', static fn ($user): bool => false);

        [$auth] = $this->makeAuth(['publish-post']);
        $middleware = new Authorize($auth, $gate);

        $response = $middleware->handle(
            Request::fromGlobals(server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/posts']),
            static fn (Request $r): Response => Response::text('published'),
            'publish-post'
        );

        $this->assertSame(200, $response->status());
        $this->assertSame('published', $response->body());
    }

    public function test_gate_ability_grants_access(): void
    {
        $gate = new Gate();
        $gate->define('publish-post', static fn ($user): bool => $user !== null);

        [$auth] = $this->makeAuth([]);
        $middleware = new Authorize($auth, $gate);

        $response = $middleware->handle(
            Request::fromGlobals(server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/posts']),
            static fn (Request $r): Response => Response::text('published'),
            'publish-post'
        );

        $this->assertSame(200, $response->status());
    }

    /**
     * @param list<string> $permissions
     * @return array{0: AuthManager, 1: RecordingUserProvider}
     */
    private function makeAuth(array $permissions, bool $login = true): array
    {
        $provider = new RecordingUserProvider();
        $provider->add(new AuthUser(
            ['id' => 7, 'email' => 'alice@example.test', 'password' => Password::hash('secret')],
            permissions: $permissions
        ));

        $session = new Session(new FileSessionDriver($this->sessionPath));
        $state = new SessionAuthState($session, $provider);
        $auth = new AuthManager($provider, $state, new RateLimiter());

        if ($login) {
            $auth->attempt(['email' => 'alice@example.test', 'password' => 'secret']);
        }

        return [$auth, $provider];
    }
}
