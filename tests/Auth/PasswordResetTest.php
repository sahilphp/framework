<?php

declare(strict_types=1);

namespace Sahil\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Sahil\Auth\Password;
use Sahil\Auth\PasswordResetManager;

/**
 * Password reset security: expiring, single-use tokens that never leak
 * whether the e-mail address exists.
 */
final class PasswordResetTest extends TestCase
{
    private RecordingUserProvider $provider;

    private InMemoryResetTokenRepository $tokens;

    private PasswordResetManager $manager;

    protected function setUp(): void
    {
        $this->provider = new RecordingUserProvider();
        $this->provider->add(new AuthUser([
            'id' => 7,
            'email' => 'alice@example.test',
            'password' => Password::hash('secret'),
        ]));

        $this->tokens = new InMemoryResetTokenRepository();
        $this->manager = new PasswordResetManager($this->provider, $this->tokens);
    }

    public function test_token_is_cryptographically_random(): void
    {
        $token = $this->manager->createToken('alice@example.test');

        $this->assertIsString($token);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertNotSame($token, $this->manager->createToken('alice@example.test'));
    }

    public function test_token_creation_for_unknown_account_returns_no_token(): void
    {
        $this->assertNull($this->manager->createToken('ghost@example.test'));
    }

    public function test_valid_token_resets_the_password_once(): void
    {
        $token = $this->manager->createToken('alice@example.test');

        $this->assertIsString($token);
        $this->assertTrue($this->manager->resetPassword($token, 'alice@example.test', 'a brand new secret'));

        $user = $this->provider->retrieveByIdentifier(7);
        $this->assertTrue(Password::verify('a brand new secret', $user->getAuthPassword()));
        $this->assertSame(1, $this->provider->writesFor(7));

        // Single use: the same token must be dead now.
        $this->assertFalse($this->manager->resetPassword($token, 'alice@example.test', 'third secret'));
        $this->assertSame(1, $this->provider->writesFor(7), 'a reused token must not change anything');
    }

    public function test_expired_token_is_refused(): void
    {
        $token = $this->manager->createToken('alice@example.test');
        $this->assertIsString($token);

        // Inside the window: still valid.
        $this->tokens->setNow($this->tokens->now() + 3599);
        $this->assertNotNull($this->manager->validateToken($token, 'alice@example.test'));

        // Past the window: expired, and no password write happens.
        $this->tokens->setNow($this->tokens->now() + 2);
        $this->assertNull($this->manager->validateToken($token, 'alice@example.test'));
        $this->assertFalse($this->manager->resetPassword($token, 'alice@example.test', 'nope'));
        $this->assertSame(0, $this->provider->writesFor(7));
    }

    public function test_token_bound_to_wrong_account_is_refused(): void
    {
        $this->provider->add(new AuthUser([
            'id' => 8,
            'email' => 'mallory@example.test',
            'password' => Password::hash('other'),
        ]));

        $token = $this->manager->createToken('alice@example.test');
        $this->assertIsString($token);

        $this->assertNull($this->manager->validateToken($token, 'mallory@example.test'));
        $this->assertFalse($this->manager->resetPassword($token, 'mallory@example.test', 'stolen'));
        $this->assertSame(0, $this->provider->writesFor(8));
    }

    public function test_reset_for_unknown_account_never_touches_passwords(): void
    {
        $this->assertFalse($this->manager->resetPassword('deadbeef', 'ghost@example.test', 'anything'));
        $this->assertSame(0, $this->provider->writesFor(7));
    }

    public function test_delete_tokens_invalidates_outstanding_tokens(): void
    {
        $token = $this->manager->createToken('alice@example.test');
        $this->assertIsString($token);

        $user = $this->provider->retrieveByIdentifier(7);
        $this->assertNotNull($user);
        $this->manager->deleteTokens($user);

        $this->assertFalse($this->manager->resetPassword($token, 'alice@example.test', 'anything'));
    }
}
