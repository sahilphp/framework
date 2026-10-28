<?php

declare(strict_types=1);

namespace Sahil\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Sahil\Auth\AuthManager;
use Sahil\Auth\Password;
use Sahil\Auth\RateLimiter;
use Sahil\Auth\SessionAuthState;
use Sahil\Http\Middleware\Authenticate;
use Sahil\Http\Request;
use Sahil\Http\Response;
use Sahil\Session\FileSessionDriver;
use Sahil\Session\Session;

/**
 * Unauthenticated access: protected handlers must not run and the client
 * gets a generic 401 without any detail about accounts.
 */
final class AuthenticateMiddlewareTest extends TestCase
{
    private string $sessionPath;

    protected function setUp(): void
    {
        $this->sessionPath = sys_get_temp_dir().'/sahil_auth_mw_'.bin2hex(random_bytes(6));

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

    public function test_guest_gets_401_and_the_handler_never_runs(): void
    {
        $auth = $this->makeAuth();
        $middleware = new Authenticate($auth);

        $request = Request::fromGlobals(
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account']
        );

        $handlerRan = false;
        $response = $middleware->handle($request, static function (Request $r) use (&$handlerRan): Response {
            $handlerRan = true;

            return Response::text('account details');
        });

        $this->assertFalse($handlerRan, 'a guest must never reach the handler');
        $this->assertSame(401, $response->status());
        $this->assertStringNotContainsString('account details', $response->body());
        $this->assertStringContainsString('unauthenticated', (string) $response->body());
    }

    public function test_authenticated_user_reaches_the_handler(): void
    {
        $auth = $this->makeAuth();
        $auth->attempt(['email' => 'alice@example.test', 'password' => 'secret']);

        $middleware = new Authenticate($auth);
        $request = Request::fromGlobals(
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account']
        );

        $response = $middleware->handle($request, static fn (Request $r): Response => Response::text('account details'));

        $this->assertSame(200, $response->status());
        $this->assertSame('account details', $response->body());
    }

    public function test_401_body_does_not_leak_account_information(): void
    {
        $auth = $this->makeAuth();
        $middleware = new Authenticate($auth);

        $request = Request::fromGlobals(
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account']
        );

        $response = $middleware->handle($request, static fn (Request $r): Response => Response::text('secret page'));

        $body = (string) $response->body();
        $this->assertStringNotContainsString('alice@example.test', $body);
        $this->assertStringNotContainsString('secret', $body);
    }

    private function makeAuth(): AuthManager
    {
        $provider = new RecordingUserProvider();
        $provider->add(new AuthUser([
            'id' => 7,
            'email' => 'alice@example.test',
            'password' => Password::hash('secret'),
        ]));

        $session = new Session(new FileSessionDriver($this->sessionPath));
        $state = new SessionAuthState($session, $provider);

        return new AuthManager($provider, $state, new RateLimiter());
    }
}
