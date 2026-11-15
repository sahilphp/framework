<?php

declare(strict_types=1);

namespace Sahil\Tests\Auth;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Sahil\Auth\Tokens\PersonalAccessToken;
use Sahil\Auth\Tokens\TokenGuard;
use Sahil\Database\Connection;
use Sahil\Database\ConnectionFactory;
use Sahil\Database\Schema\Schema;
use Sahil\Database\Schema\Table;
use Sahil\Http\Request;
use Sahil\RateLimit\Identity;

final class ApiTokenTest extends TestCase
{
    private string $path;

    private ?Connection $db = null;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite unavailable.');
        }

        $this->path = sys_get_temp_dir().'/sahil-tok-'.bin2hex(random_bytes(6)).'.sqlite';
        $this->db = (new ConnectionFactory())->make(['driver' => 'sqlite', 'database' => $this->path]);
        TokenUser::useConnection($this->db);
        PersonalAccessToken::useConnection($this->db);

        (new Schema($this->db))->create('users', static function (Table $t): void {
            $t->id();
            $t->string('email');
            $t->string('password');
        });
        (new Schema($this->db))->create('personal_access_tokens', static function (Table $t): void {
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
            $t->index(['tokenable_type', 'tokenable_id']);
            $t->index('expires_at');
        });

        $this->db->table('users')->insert(['email' => 'a@x', 'password' => 'h']);
    }

    protected function tearDown(): void
    {
        TokenUser::forgetConnection();
        PersonalAccessToken::forgetConnection();
        $this->db = null;
        @unlink($this->path);
    }

    private function user(): TokenUser
    {
        return TokenUser::find(1);
    }

    private function requestWith(?string $bearer): Request
    {
        return Request::fromGlobals(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/me']
            + ($bearer === null ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$bearer]),
            [],
        );
    }

    /* ---------- generation & storage ---------- */

    public function test_create_token_returns_plaintext_once_and_stores_hash(): void
    {
        $new = $this->user()->createToken('mobile', ['posts:read']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{24}\.[0-9a-f]{64}$/', $new->plainTextToken);
        self::assertSame('mobile', $new->accessToken->name());
        // only the sha256 digest is persisted — never the secret
        self::assertSame(64, strlen((string) $new->accessToken->getAttribute('token_hash')));
        self::assertStringNotContainsString(substr($new->plainTextToken, 25), (string) $new->accessToken->getAttribute('token_hash'));
        // serialization never leaks the hash or the plaintext
        self::assertStringNotContainsString('token_hash', json_encode($new));
        self::assertStringNotContainsString($new->plainTextToken, json_encode($new));
        self::assertStringNotContainsString('token_hash', json_encode($new->accessToken));
    }

    public function test_create_token_validates_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->user()->createToken('   ');
    }

    public function test_create_token_rejects_past_expiration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->user()->createToken('x', ['*'], new DateTimeImmutable('-1 hour'));
    }

    /* ---------- authentication ---------- */

    public function test_valid_bearer_authenticates_and_attaches_token(): void
    {
        $new = $this->user()->createToken('api', ['posts:read']);
        $user = (new TokenGuard())->authenticate($this->requestWith($new->plainTextToken));
        self::assertNotNull($user);
        self::assertSame(1, $user->getAuthIdentifier());
        self::assertTrue($user->tokenCan('posts:read'));
        self::assertFalse($user->tokenCan('posts:delete'));
    }

    public function test_missing_malformed_and_wrong_secret_fail_closed(): void
    {
        $new = $this->user()->createToken('api');
        $guard = new TokenGuard();
        self::assertNull($guard->authenticate($this->requestWith(null)));
        self::assertNull($guard->authenticate($this->requestWith('not-a-token')));
        self::assertNull($guard->authenticate($this->requestWith($new->accessToken->publicId())));
        // right id, wrong secret
        self::assertNull($guard->authenticate($this->requestWith($new->accessToken->publicId().'.'.bin2hex(random_bytes(32)))));
        // unknown id
        self::assertNull($guard->authenticate($this->requestWith('ffffffffffffffffffffffff.'.bin2hex(random_bytes(32)))));
    }

    public function test_revoked_and_expired_tokens_are_rejected(): void
    {
        $u = $this->user();
        $new = $u->createToken('api');
        $u->revokeToken($new->accessToken->publicId());
        self::assertNull((new TokenGuard())->authenticate($this->requestWith($new->plainTextToken)));

        // manually expire
        $new2 = $u->createToken('api2');
        PersonalAccessToken::query()->where('id', '=', $new2->accessToken->getAttribute('id'))
            ->update(['expires_at' => '2000-01-01 00:00:00']);
        self::assertNull((new TokenGuard())->authenticate($this->requestWith($new2->plainTextToken)));
    }

    /* ---------- lifecycle ---------- */

    public function test_owner_scoped_revocation_and_revoke_all(): void
    {
        $u = $this->user();
        $this->db->table('users')->insert(['email' => 'b@x', 'password' => 'h']);
        $other = TokenUser::find(2);

        $mine = $u->createToken('mine');
        $theirs = $other->createToken('theirs');

        // cannot find or revoke another user's token
        self::assertNull($u->findToken($theirs->accessToken->publicId()));
        self::assertFalse($u->revokeToken($theirs->accessToken->publicId()));

        self::assertTrue($u->revokeToken($mine->accessToken->publicId()));
        self::assertCount(1, $other->tokens()->all());

        $other->createToken('more');
        self::assertSame(2, $other->revokeAllTokens());
        self::assertCount(0, $other->tokens()->all());
    }

    public function test_prune_expired(): void
    {
        $u = $this->user();
        $u->createToken('live');
        $dead = $u->createToken('dead');
        PersonalAccessToken::query()->where('id', '=', $dead->accessToken->getAttribute('id'))
            ->update(['expires_at' => '2000-01-01 00:00:00']);
        self::assertSame(1, PersonalAccessToken::pruneExpired());
        self::assertCount(1, $u->tokens()->all());
    }

    public function test_last_used_throttled(): void
    {
        $new = $this->user()->createToken('api');
        $guard = new TokenGuard();
        $guard->authenticate($this->requestWith($new->plainTextToken));
        $first = PersonalAccessToken::findByTokenId($new->accessToken->publicId())->lastUsedAt();
        self::assertNotNull($first);
        // immediate second request → throttled, same timestamp
        $guard->authenticate($this->requestWith($new->plainTextToken));
        $second = PersonalAccessToken::findByTokenId($new->accessToken->publicId())->lastUsedAt();
        self::assertSame($first->getTimestamp(), $second->getTimestamp());
    }

    /* ---------- identity ---------- */

    public function test_token_identity_uses_public_id_not_secret(): void
    {
        $new = $this->user()->createToken('api');
        $user = (new TokenGuard())->authenticate($this->requestWith($new->plainTextToken));
        $id = Identity::tokenOrIp()($this->requestWith(null), $user);
        self::assertSame('tok:'.$new->accessToken->publicId(), $id);
        self::assertStringNotContainsString(substr($new->plainTextToken, 25), $id);
        // a different token → different identity
        $new2 = $this->user()->createToken('api2');
        self::assertNotSame($id, 'tok:'.$new2->accessToken->publicId());
    }
}
