<?php

declare(strict_types=1);

namespace Sahil\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Sahil\Auth\AuthManager;
use Sahil\Auth\Password;
use Sahil\Auth\RateLimiter;
use Sahil\Auth\SessionAuthState;
use Sahil\Session\FileSessionDriver;
use Sahil\Session\Session;

/**
 * Security-focused tests for login: invalid credentials, brute-force
 * lockout, enumeration-resistant behaviour, password rehashing and
 * session-ID rotation on login/logout.
 */
final class AuthManagerTest extends TestCase
{
    private string $sessionPath;

    protected function setUp(): void
    {
        $this->sessionPath = sys_get_temp_dir().'/sahil_auth_session_'.bin2hex(random_bytes(6));

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

    public function test_password_hashing_round_trips_and_is_salted(): void
    {
        $first = Password::hash('correct horse battery staple');
        $second = Password::hash('correct horse battery staple');

        $this->assertNotSame($first, $second, 'each hash must carry its own salt');
        $this->assertTrue(Password::verify('correct horse battery staple', $first));
        $this->assertFalse(Password::verify('wrong password', $first));
        $this->assertContains(Password::getInfo($first)['algoName'], ['2y', 'bcrypt']);
        $this->assertFalse(Password::needsRehash($first));
        $this->assertTrue(Password::needsRehash($first, ['cost' => 4]), 'a weaker cost must demand a rehash');
    }

    public function test_generated_tokens_are_unique_and_long(): void
    {
        $token = Password::generateToken();
        $other = Password::generateToken();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertNotSame($token, $other);
    }

    public function test_valid_credentials_authenticate_the_user(): void
    {
        [$auth] = $this->makeAuth();

        $this->assertTrue($auth->attempt(['email' => 'alice@example.test', 'password' => 'secret']));
        $this->assertTrue($auth->check());
        $this->assertSame(7, $auth->id());
        $this->assertSame('alice@example.test', $auth->user()?->get('email'));
    }

    public function test_invalid_credentials_are_rejected_and_do_not_authenticate(): void
    {
        [$auth, $provider] = $this->makeAuth();

        $this->assertFalse($auth->attempt(['email' => 'alice@example.test', 'password' => 'wrong']));
        $this->assertFalse($auth->check());
        $this->assertNull($auth->user());
        $this->assertSame(0, $provider->writesFor(7));
    }

    public function test_unknown_identifier_is_rejected_without_distinct_failure(): void
    {
        [$auth] = $this->makeAuth();

        $this->assertFalse($auth->attempt(['email' => 'ghost@example.test', 'password' => 'secret']));
        $this->assertFalse($auth->check());
    }

    public function test_repeated_failures_lock_out_even_the_correct_password(): void
    {
        [$auth] = $this->makeAuth();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->assertFalse(
                $auth->attempt(['email' => 'alice@example.test', 'password' => 'wrong']),
                "failure #{$attempt} must be rejected"
            );
        }

        // The rate limiter now blocks the key: the CORRECT password is
        // refused too, which is what stops credential stuffing.
        $this->assertFalse($auth->attempt(['email' => 'alice@example.test', 'password' => 'secret']));
        $this->assertFalse($auth->check());
    }

    public function test_successful_login_clears_the_failure_counter(): void
    {
        [$auth] = $this->makeAuth();

        $auth->attempt(['email' => 'alice@example.test', 'password' => 'wrong']);
        $auth->attempt(['email' => 'alice@example.test', 'password' => 'wrong']);

        $this->assertTrue($auth->attempt(['email' => 'alice@example.test', 'password' => 'secret']));

        // Counter cleared: another pair of failures must not lock us out.
        $auth->attempt(['email' => 'alice@example.test', 'password' => 'wrong']);
        $auth->attempt(['email' => 'alice@example.test', 'password' => 'wrong']);

        $this->assertTrue($auth->attempt(['email' => 'alice@example.test', 'password' => 'secret']));
    }

    public function test_password_is_rehashed_on_login_when_options_change(): void
    {
        [$auth, $provider] = $this->makeAuth(hashOptions: ['cost' => 4]);

        $auth->setPasswordOptions(['cost' => 12]);
        $this->assertTrue($auth->attempt(['email' => 'alice@example.test', 'password' => 'secret']));

        $this->assertSame(1, $provider->writesFor(7), 'the stale-cost hash is rewritten once');

        $stored = $provider->retrieveByIdentifier(7)->getAuthPassword();
        $this->assertSame(12, password_get_info($stored)['options']['cost']);
    }

    public function test_login_rotates_the_session_id_against_fixation(): void
    {
        [$auth, , $session] = $this->makeAuth();

        $session->put('pre_login', 'value');
        $preLoginId = $session->id();

        $this->assertTrue($auth->attempt(['email' => 'alice@example.test', 'password' => 'secret']));

        $this->assertNotSame($preLoginId, $session->id(), 'the session ID must change at login');
        $this->assertTrue($auth->check());
    }

    public function test_logout_rotates_the_session_id_and_clears_state(): void
    {
        [$auth, , $session] = $this->makeAuth();

        $auth->attempt(['email' => 'alice@example.test', 'password' => 'secret']);
        $loggedInId = $session->id();

        $auth->logout();

        $this->assertNotSame($loggedInId, $session->id());
        $this->assertFalse($auth->check());
        $this->assertTrue($auth->guest());
        $this->assertNull($auth->user());
        $this->assertFalse($session->has('_auth_user_id'));
    }

    public function test_login_without_remembering_leaves_no_remember_token(): void
    {
        [$auth, , $session] = $this->makeAuth();

        $auth->attempt(['email' => 'alice@example.test', 'password' => 'secret']);

        $this->assertFalse($session->has('_auth_remember_token'));
    }

    public function test_remembered_user_is_retrieved_only_with_the_exact_token(): void
    {
        [$auth, $provider, , $state] = $this->makeAuth();
        $user = $provider->retrieveByIdentifier(7);

        $this->assertNotNull($user);

        $state->remember($user, 'token-a');

        $this->assertNotNull($state->retrieveRememberedUser('token-a'));
        $this->assertNull($state->retrieveRememberedUser('token-b'), 'a wrong token must not authenticate');
    }

    /**
     * @param array<string, mixed> $hashOptions
     * @return array{0: AuthManager, 1: RecordingUserProvider, 2: Session, 3: SessionAuthState}
     */
    private function makeAuth(array $hashOptions = []): array
    {
        $provider = new RecordingUserProvider();
        $provider->add(new AuthUser([
            'id' => 7,
            'email' => 'alice@example.test',
            'password' => Password::hash('secret', $hashOptions),
        ]));

        $session = new Session(new FileSessionDriver($this->sessionPath));
        $state = new SessionAuthState($session, $provider);
        $auth = new AuthManager($provider, $state, new RateLimiter());

        return [$auth, $provider, $session, $state];
    }
}
