<?php

declare(strict_types=1);

namespace Sahil\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Sahil\Auth\AuthenticationException;
use Sahil\Auth\Authorizable;
use Sahil\Auth\AuthorizationException;
use Sahil\Auth\Bouncer;
use Sahil\Auth\BouncerManager;
use Sahil\Auth\Policy;
use Sahil\Auth\TokenAuthorizer;
use Sahil\Contracts\Auth\AuthenticatableContract;

final class PostModel
{
    public function __construct(
        public readonly int $id,
        public readonly int $user_id,
        public readonly string $status = 'draft',
    ) {
    }
}

final class PostModelPolicy extends Policy
{
    public function update(AuthenticatableContract $user, mixed $post): bool
    {
        return $post instanceof PostModel && $user->getAuthIdentifier() === $post->user_id;
    }

    public function publishPost(AuthenticatableContract $user, mixed $post): bool
    {
        return $post instanceof PostModel && $post->status === 'draft';
    }
}

final class TraitUser implements AuthenticatableContract
{
    use Authorizable;

    public function __construct(private readonly int $id)
    {
    }

    public function getAuthIdentifier(): int|string
    {
        return $this->id;
    }

    public function getAuthPassword(): string
    {
        return 'x';
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }
}

/**
 * Bouncer — the framework's authorization engine: registration semantics,
 * exact case-sensitive evaluation, policies, tokens, and the traits the
 * public API is built on.
 */
final class BouncerTest extends TestCase
{
    private function user(int $id = 7, array $roles = [], array $permissions = []): AuthUser
    {
        return new AuthUser(['id' => $id, 'email' => 'u@example.com'], $roles, $permissions);
    }

    private function bouncer(?AuthUser $user = null): BouncerManager
    {
        return new BouncerManager(static fn (): ?AuthenticatableContract => $user);
    }

    /* ---------- registration ---------- */

    public function test_define_has_forget(): void
    {
        $bouncer = $this->bouncer($this->user());
        $bouncer->define('manage-users', static fn ($u): bool => true);

        $this->assertTrue($bouncer->has('manage-users'));
        $this->assertFalse($bouncer->has('other'));
        $this->assertSame(['manage-users'], $bouncer->abilities());

        $bouncer->forget('manage-users');
        $this->assertFalse($bouncer->has('manage-users'));
    }

    public function test_duplicate_define_throws(): void
    {
        $bouncer = $this->bouncer();
        $bouncer->define('manage-users', static fn ($u): bool => true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already defined');
        $bouncer->define('manage-users', static fn ($u): bool => false);
    }

    public function test_forget_allows_deliberate_redefinition(): void
    {
        $bouncer = $this->bouncer($this->user());
        $bouncer->define('x', static fn ($u): bool => false);
        $bouncer->forget('x');
        $bouncer->define('x', static fn ($u): bool => true);

        $this->assertTrue($bouncer->allows('x'));
    }

    public function test_empty_ability_name_rejected_at_define(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bouncer()->define('  ', static fn ($u): bool => true);
    }

    /* ---------- evaluation ---------- */

    public function test_unknown_ability_denies(): void
    {
        $bouncer = $this->bouncer($this->user());
        $this->assertFalse($bouncer->allows('nope'));
        $this->assertTrue($bouncer->denies('nope'));
    }

    public function test_empty_ability_denies(): void
    {
        $this->assertFalse($this->bouncer($this->user())->allows(''));
    }

    public function test_ability_names_are_case_sensitive(): void
    {
        $bouncer = $this->bouncer($this->user());
        $bouncer->define('Manage-Users', static fn ($u): bool => true);

        $this->assertTrue($bouncer->allows('Manage-Users'));
        $this->assertFalse($bouncer->allows('manage-users'));
        $this->assertFalse($bouncer->allows('MANAGE-USERS'));
    }

    public function test_resource_arguments_reach_the_callback(): void
    {
        $post = new PostModel(1, 7);
        $bouncer = $this->bouncer($this->user(7));
        $bouncer->define('update-post', static fn ($u, PostModel $p): bool => $u->getAuthIdentifier() === $p->user_id);

        $this->assertTrue($bouncer->allows('update-post', $post));
        $this->assertFalse($this->bouncer($this->user(8))->allows('update-post', $post));
    }

    public function test_multiple_arguments_reach_the_callback(): void
    {
        $bouncer = $this->bouncer($this->user(1));
        $bouncer->define('publish-post', static fn ($u, PostModel $p, string $channel): bool
            => $p->status === 'draft' && $channel === 'web');

        $this->assertTrue($bouncer->allows('publish-post', [new PostModel(1, 1), 'web']));
        $this->assertFalse($bouncer->allows('publish-post', [new PostModel(1, 1), 'print']));
    }

    public function test_non_true_return_denies(): void
    {
        $bouncer = $this->bouncer($this->user());
        $bouncer->define('truthy', static fn ($u): int => 1);
        $bouncer->define('nullish', static fn ($u) => null);

        $this->assertFalse($bouncer->allows('truthy'));
        $this->assertFalse($bouncer->allows('nullish'));
    }

    public function test_throwing_callback_propagates_with_context(): void
    {
        $bouncer = $this->bouncer($this->user());
        $bouncer->define('explode', static function ($u): bool {
            throw new \LogicException('kaboom');
        });

        try {
            $bouncer->allows('explode');
            $this->fail('expected the wrapped throwable');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('explode', $e->getMessage());
            $this->assertInstanceOf(\LogicException::class, $e->getPrevious());
        }
    }

    public function test_guest_evaluates_defined_ability_with_null_user(): void
    {
        $bouncer = $this->bouncer(null);
        $bouncer->define('public-page', static fn (?AuthenticatableContract $u): bool => $u === null);
        $bouncer->define('members-only', static fn (AuthenticatableContract $u): bool => true);

        $this->assertTrue($bouncer->allows('public-page'));
        $this->assertFalse($bouncer->allows('members-only')); // never runs: non-nullable user param
    }

    public function test_untyped_user_parameter_is_members_only(): void
    {
        $bouncer = $this->bouncer(null);
        $bouncer->define('members', static fn ($u): bool => true); // no type → no guests

        $this->assertFalse($bouncer->allows('members'));
    }

    public function test_stored_permission_is_an_independent_grant(): void
    {
        $bouncer = $this->bouncer($this->user(permissions: ['edit-own-post']));
        $bouncer->define('other-ability', static fn ($u): bool => false);

        $this->assertTrue($bouncer->allows('edit-own-post'));
        $this->assertFalse($bouncer->allows('other-ability'));
    }

    /* ---------- authorize ---------- */

    public function test_authorize_guest_raises_401(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->bouncer(null)->authorize('anything');
    }

    public function test_authorize_denied_raises_403(): void
    {
        $bouncer = $this->bouncer($this->user());

        try {
            $bouncer->authorize('manage-users');
            $this->fail('expected denial');
        } catch (AuthorizationException $e) {
            $this->assertSame(403, $e->statusCode());
            $this->assertStringContainsString('manage-users', $e->getMessage());
        }
    }

    public function test_authorize_accepts_custom_message(): void
    {
        $bouncer = $this->bouncer($this->user());

        try {
            $bouncer->authorize('manage-users', message: 'Admins only.');
            $this->fail('expected denial');
        } catch (AuthorizationException $e) {
            $this->assertSame('Admins only.', $e->getMessage());
        }
    }

    public function test_authorize_passes_when_allowed(): void
    {
        $bouncer = $this->bouncer($this->user());
        $bouncer->define('ok', static fn ($u): bool => true);
        $bouncer->authorize('ok'); // no exception

        $this->addToAssertionCount(1);
    }

    /* ---------- policies ---------- */

    public function test_policy_resolves_basename_suffix_abilities(): void
    {
        $bouncer = $this->bouncer($this->user(7));
        $bouncer->policy(PostModel::class, PostModelPolicy::class);

        $this->assertTrue($bouncer->allows('update-post-model', new PostModel(1, 7)));
        $this->assertFalse($this->bouncer($this->user(8))->allows('update-post-model', new PostModel(1, 7)));
    }

    public function test_policy_camelized_ability_method(): void
    {
        $bouncer = $this->bouncer($this->user(7));
        $bouncer->policy(PostModel::class, PostModelPolicy::class);

        // `publish-post` → `publishPost` on PostModelPolicy.
        $this->assertTrue($bouncer->allows('publish-post', new PostModel(1, 7)));
    }

    public function test_missing_policy_method_denies(): void
    {
        $bouncer = $this->bouncer($this->user(7));
        $bouncer->policy(PostModel::class, PostModelPolicy::class);

        $this->assertFalse($bouncer->allows('teleport-post-model', new PostModel(1, 7)));
    }

    public function test_unregistered_model_denies(): void
    {
        $this->assertFalse($this->bouncer($this->user())->allows('update-post-model', new PostModel(1, 7)));
    }

    public function test_defined_ability_wins_over_policy(): void
    {
        $bouncer = $this->bouncer($this->user(7));
        $bouncer->policy(PostModel::class, PostModelPolicy::class);
        $bouncer->define('update-post-model', static fn ($u, $p): bool => false);

        // Defined denies; the policy's allow must not override it — only
        // a stored permission still can.
        $this->assertFalse($bouncer->allows('update-post-model', new PostModel(1, 7)));
    }

    public function test_duplicate_policy_registration_throws(): void
    {
        $bouncer = $this->bouncer();
        $bouncer->policy(PostModel::class, PostModelPolicy::class);

        $this->expectException(\RuntimeException::class);
        $bouncer->policy(PostModel::class, PostModelPolicy::class);
    }

    /* ---------- tokens ---------- */

    public function test_token_ceiling_anded_with_owner(): void
    {
        $bouncer = $this->bouncer($this->user());
        $bouncer->define('posts.read', static fn ($u): bool => true);
        $bouncer->define('posts.write', static fn ($u): bool => true);

        $checker = $bouncer->forUser($this->user())->withToken(new TokenAuthorizer(['posts.read']));

        $this->assertTrue($checker->allows('posts.read'));
        $this->assertFalse($checker->allows('posts.write')); // owner can, token cannot
    }

    public function test_token_cannot_grant_what_owner_lacks(): void
    {
        $bouncer = $this->bouncer($this->user());
        $checker = $bouncer->forUser($this->user())->withToken(TokenAuthorizer::wildcard());

        $this->assertFalse($checker->allows('anything'));
    }

    public function test_empty_token_denies_everything(): void
    {
        $bouncer = $this->bouncer($this->user());
        $bouncer->define('posts.read', static fn ($u): bool => true);

        $checker = $bouncer->forUser($this->user())->withToken(new TokenAuthorizer([]));

        $this->assertFalse($checker->allows('posts.read'));
    }

    public function test_token_matching_is_exact_and_case_sensitive(): void
    {
        $token = new TokenAuthorizer(['Posts.Read']);

        $this->assertTrue($token->allows('Posts.Read'));
        $this->assertFalse($token->allows('posts.read'));
        $this->assertFalse($token->allows('posts.*'));
    }

    public function test_wildcard_is_opt_in_only(): void
    {
        $this->assertFalse((new TokenAuthorizer(['posts.*']))->allows('users.read'));
        $this->assertTrue((new TokenAuthorizer(['*']))->allows('users.read'));
    }

    /* ---------- fluent + trait APIs ---------- */

    public function test_for_user_checker(): void
    {
        $bouncer = $this->bouncer();
        $bouncer->define('update-post', static fn ($u, PostModel $p): bool => $u->getAuthIdentifier() === $p->user_id);

        $this->assertTrue($bouncer->forUser($this->user(7))->allows('update-post', new PostModel(1, 7)));
        $this->assertTrue($bouncer->forUser($this->user(8))->denies('update-post', new PostModel(1, 7)));
    }

    public function test_authorizable_trait_can(): void
    {
        $user = new TraitUser(7);
        $bouncer = $this->bouncer();
        $bouncer->define('update-post', static fn ($u, PostModel $p): bool => $u->getAuthIdentifier() === $p->user_id);

        Bouncer::swap($bouncer);
        try {
            $this->assertTrue($user->can('update-post', new PostModel(1, 7)));
            $this->assertTrue($user->cant('update-post', new PostModel(1, 9)));
            $this->assertTrue($user->cannot('update-post', new PostModel(1, 9)));
            $user->authorize('update-post', new PostModel(1, 7));
        } finally {
            Bouncer::flushState();
        }
    }

    public function test_static_facade_delegates_to_swapped_manager(): void
    {
        $bouncer = $this->bouncer($this->user());
        $bouncer->define('a', static fn ($u): bool => true);

        Bouncer::swap($bouncer);
        try {
            Bouncer::define('b', static fn ($u): bool => false);
            $this->assertTrue(Bouncer::has('a'));
            $this->assertTrue(Bouncer::allows('a'));
            $this->assertTrue(Bouncer::denies('b'));
            $this->assertSame(['a', 'b'], Bouncer::abilities());
            Bouncer::authorize('a');
        } finally {
            Bouncer::flushState();
        }
    }

    public function test_no_application_and_no_swap_explains_itself(): void
    {
        Bouncer::swap(null);
        // Without a configured application this throws a clear error.
        if (\Sahil\Foundation\Application::peek() === null) {
            $this->expectException(\RuntimeException::class);
            Bouncer::allows('x');
        } else {
            $this->assertTrue(true); // app context exists in-suite; covered by flow tests
        }
    }

    /* ---------- worker isolation ---------- */

    public function test_decisions_are_never_cached(): void
    {
        $current = $this->user(7);
        $bouncer = new BouncerManager(static function () use (&$current): ?AuthenticatableContract {
            return $current;
        });
        $bouncer->define('update-post', static fn ($u, PostModel $p): bool => $u->getAuthIdentifier() === $p->user_id);

        $post = new PostModel(1, 7);
        $this->assertTrue($bouncer->allows('update-post', $post));

        // Next request, different user — same manager, fresh decision.
        $current = $this->user(8);
        $this->assertFalse($bouncer->allows('update-post', $post));

        // Next request, guest.
        $current = null;
        $this->assertFalse($bouncer->allows('update-post', $post));
    }
}
