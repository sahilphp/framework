<?php

declare(strict_types=1);

namespace Sahil\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Sahil\Auth\EmailVerification;
use Sahil\Auth\Gate;
use Sahil\Auth\PolicyManager;
use Sahil\Auth\SimpleUser;

/**
 * Authorization API: gates, roles/permissions, object policies, and the
 * optional email-verification integration points.
 */
final class AuthorizationTest extends TestCase
{
    public function test_gate_allows_and_denies(): void
    {
        $gate = new Gate();
        $gate->define('edit-settings', static fn ($user): bool => $user !== null);

        $user = $this->user();

        $this->assertTrue($gate->allows('edit-settings', $user));
        $this->assertFalse($gate->denies('edit-settings', $user));
        $this->assertTrue($gate->denies('edit-settings', null));
    }

    public function test_undefined_gate_denies(): void
    {
        $gate = new Gate();

        $this->assertFalse($gate->allows('nope', $this->user()));
        $this->assertTrue($gate->denies('nope', $this->user()));
    }

    public function test_any_and_all_combinations(): void
    {
        $gate = new Gate();
        $gate->define('read', static fn ($user): bool => true);
        $gate->define('write', static fn ($user): bool => false);

        $user = $this->user();

        $this->assertTrue($gate->any(['read', 'write'], $user));
        $this->assertFalse($gate->all(['read', 'write'], $user));
        $this->assertTrue($gate->all(['read', 'read'], $user));
        $this->assertFalse($gate->any(['write'], $user));
    }

    public function test_can_falls_back_to_user_permissions(): void
    {
        $gate = new Gate();

        $editor = $this->user(permissions: ['publish-post']);
        $reader = $this->user();

        $this->assertTrue($gate->can('publish-post', $editor));
        $this->assertFalse($gate->can('publish-post', $reader));
        $this->assertTrue($gate->cannot('publish-post', $reader));
    }

    public function test_gate_wins_when_defined(): void
    {
        $gate = new Gate();
        $gate->define('publish-post', static fn ($user): bool => false);

        $editor = $this->user(permissions: ['publish-post']);

        // The explicit gate denies, so the permission fallback must not
        // be reached through `allows`, and `can` still consults both.
        $this->assertFalse($gate->allows('publish-post', $editor));
        $this->assertTrue($gate->can('publish-post', $editor));
    }

    public function test_roles_and_permissions_are_queryable(): void
    {
        $user = $this->user(roles: ['author'], permissions: ['edit-own-post']);

        $this->assertTrue($user->hasRole('author'));
        $this->assertFalse($user->hasRole('admin'));
        $this->assertSame(['author'], $user->getRoles());
        $this->assertTrue($user->hasPermission('edit-own-post'));
        $this->assertSame(['edit-own-post'], $user->getPermissions());
    }

    public function test_policy_checks_ownership_and_roles(): void
    {
        $policies = new PolicyManager();
        $policies->register(TestPostModel::class, TestPostPolicy::class);

        $owner = $this->user(roles: ['author']);
        $stranger = $this->user(id: 8);
        $admin = $this->user(roles: ['admin']);

        $post = ['author_id' => 7];

        $this->assertTrue($policies->can('update', TestPostModel::class, $owner, $post));
        $this->assertFalse($policies->can('update', TestPostModel::class, $stranger, $post));
        $this->assertTrue($policies->can('delete', TestPostModel::class, $admin, $post));
        $this->assertFalse($policies->can('delete', TestPostModel::class, $stranger, $post));
        $this->assertTrue($policies->can('create', TestPostModel::class, $owner));
        $this->assertFalse($policies->can('create', TestPostModel::class, $stranger));
    }

    public function test_policy_denies_unknown_actions_and_models(): void
    {
        $policies = new PolicyManager();
        $policies->register(TestPostModel::class, TestPostPolicy::class);

        $user = $this->user(roles: ['admin']);

        $this->assertFalse($policies->can('teleport', TestPostModel::class, $user, []));
        $this->assertFalse($policies->can('update', UnknownModel::class, $user, []));
    }

    public function test_default_policy_denies_when_nothing_is_registered(): void
    {
        $policy = (new PolicyManager())->for(TestPostModel::class);

        $this->assertNull($policy);
    }

    public function test_email_verification_points(): void
    {
        $verification = new EmailVerification();

        $unverified = $this->user(verified: false);
        $verified = $this->user(verified: true);

        $this->assertTrue($verification->needsVerification($unverified));
        $this->assertFalse($verification->verified($unverified));

        $verification->markAsVerified($unverified);
        $this->assertTrue($verification->verified($unverified));
        $this->assertFalse($verification->needsVerification($unverified));

        $this->assertFalse($verification->needsVerification($verified));
    }

    public function test_users_without_the_verification_contract_are_treated_as_verified(): void
    {
        $verification = new EmailVerification();

        $plain = new SimpleUser(['id' => 1, 'email' => 'plain@example.test', 'password' => 'x']);

        $this->assertTrue($verification->verified($plain));
        $this->assertFalse($verification->needsVerification($plain));

        $verification->markAsVerified($plain);
        $verification->sendVerification($plain);
    }

    private function user(array $roles = [], array $permissions = [], bool $verified = true, int $id = 7): AuthUser
    {
        return new AuthUser(
            ['id' => $id, 'email' => 'alice@example.test', 'password' => 'x'],
            $roles,
            $permissions,
            $verified,
        );
    }
}

/** Marker class for policy registration. */
final class TestPostModel
{
}

/** Marker class that deliberately has no policy. */
final class UnknownModel
{
}
