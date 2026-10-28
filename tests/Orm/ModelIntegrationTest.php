<?php

declare(strict_types=1);

namespace Sahil\Tests\Orm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sahil\Database\Connection;
use Sahil\Database\ConnectionException;
use Sahil\Database\ConnectionFactory;
use Sahil\Database\QueryException;
use Sahil\Orm\Model;
use Sahil\Orm\OrmResult;

/**
 * Phase-8 integration: model lifecycle + relations against REAL test
 * databases. Requires running test servers (scripts/test-databases.sh)
 * and the same env URLs as Phase 7:
 *
 *   SAHIL_DB_TEST_MYSQL='mysql://root@127.0.0.1:33306/sahil_tests'
 *   SAHIL_DB_TEST_PGSQL='pgsql://jinx@127.0.0.1:55432/sahil_tests'
 *
 * Missing/unreachable env → explicit SKIP (never a silent "pass").
 *
 * @group integration
 */
final class ModelIntegrationTest extends TestCase
{
    private Connection $db;

    private string $dialect;

    /**
     * @return array<string, array{0: string, 1: ?array<string, mixed>}>
     */
    public static function databaseProvider(): array
    {
        $cases = [];

        foreach (['mysql' => 'SAHIL_DB_TEST_MYSQL', 'pgsql' => 'SAHIL_DB_TEST_PGSQL'] as $label => $envName) {
            $url = getenv($envName);
            $cases[$label] = [$label, is_string($url) && trim($url) !== '' ? self::configuration($label, $url) : null];
        }

        return $cases;
    }

    /**
     * @return array<string, mixed>
     */
    private static function configuration(string $driver, string $url): array
    {
        $parts = parse_url($url) ?: [];

        return [
            'driver' => $driver,
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => $parts['port'] ?? ($driver === 'mysql' ? 3306 : 5432),
            'database' => ltrim($parts['path'] ?? '/sahil_tests', '/'),
            'username' => $parts['user'] ?? '',
            'password' => $parts['pass'] ?? '',
        ];
    }

    /**
     * @param string $label database label
     * @param array<string, mixed>|null $configuration null → skip
     */
    #[DataProvider('databaseProvider')]
    public function test_model_lifecycle_and_relations(string $label, ?array $configuration): void
    {
        if ($configuration === null) {
            $envName = $label === 'mysql' ? 'SAHIL_DB_TEST_MYSQL' : 'SAHIL_DB_TEST_PGSQL';
            self::markTestSkipped("Set {$envName} to run the {$label} ORM integration suite.");
        }

        $this->dialect = $label;

        try {
            $this->db = (new ConnectionFactory())->make($configuration);
        } catch (ConnectionException $failure) {
            self::markTestSkipped("[{$label} unreachable] ".$failure->getMessage());
        }

        Model::useConnection($this->db);

        try {
            $this->createSchema();
            $this->runLifecycleAssertions();
            $this->runRelationAssertions();
            $this->runEagerLoadingAssertions();
            $this->runPaginationAndScopeAssertions();
        } finally {
            $this->dropSchema();
            Model::forgetConnection();
        }
    }

    private function createSchema(): void
    {
        $this->dropSchema();

        $id = $this->dialect === 'mysql' ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'BIGSERIAL PRIMARY KEY';
        $active = $this->dialect === 'mysql' ? 'TINYINT NOT NULL DEFAULT 1' : 'SMALLINT NOT NULL DEFAULT 1';
        $stamp = $this->dialect === 'mysql' ? 'DATETIME NULL' : 'TIMESTAMP NULL';

        $this->db->execute(<<<SQL
            CREATE TABLE orm_users (
                id $id,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(150) NOT NULL,
                password VARCHAR(64) NULL,
                active $active,
                created_at $stamp,
                updated_at $stamp
            )
            SQL);

        $this->db->execute(<<<'SQL'
            CREATE TABLE orm_profiles (
                id SERIAL PRIMARY KEY,
                user_id BIGINT NOT NULL,
                bio TEXT NOT NULL
            )
            SQL);

        $this->db->execute(<<<'SQL'
            CREATE TABLE orm_posts (
                id SERIAL PRIMARY KEY,
                user_id BIGINT NOT NULL,
                title VARCHAR(200) NOT NULL
            )
            SQL);

        $this->db->execute(<<<'SQL'
            CREATE TABLE orm_roles (
                id SERIAL PRIMARY KEY,
                label VARCHAR(50) NOT NULL
            )
            SQL);

        $this->db->execute(<<<'SQL'
            CREATE TABLE orm_user_roles (
                user_id BIGINT NOT NULL,
                role_id BIGINT NOT NULL,
                PRIMARY KEY (user_id, role_id)
            )
            SQL);
    }

    private function dropSchema(): void
    {
        foreach (['orm_user_roles', 'orm_roles', 'orm_posts', 'orm_profiles', 'orm_users'] as $table) {
            try {
                $this->db->execute("DROP TABLE IF EXISTS {$table}");
            } catch (QueryException) {
                // first run: the table does not exist yet on engines that
                // reject DROP IF EXISTS — the next suite run recreates it.
            }
        }
    }

    private function runLifecycleAssertions(): void
    {
        // ---- create: fillable mass assignment + timestamp maintenance ----
        $user = OrmUser::create([
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'password' => 'hash-me-please',
            'active' => 1,
        ]);

        self::assertNotNull($user->id, 'auto-increment pk backfilled after insert');
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $user->created_at);
        self::assertNotNull($user->updated_at);

        // find returns a hydrated model with hidden applied on serialization
        $found = OrmUser::query()->find($user->id);
        self::assertNotNull($found);
        self::assertSame('Ada', $found->name);
        $serialized = $found->toArray();
        self::assertArrayNotHasKey('password', $serialized, 'hidden attribute never serializes');
        self::assertArrayHasKey('email', $serialized);

        // update: dirty-subset only
        $found->name = 'Grace';
        self::assertTrue($found->isDirty('name'));
        self::assertFalse($found->isDirty('email'));
        self::assertTrue($found->save());

        $reloaded = OrmUser::query()->find($found->id);
        self::assertSame('Grace', $reloaded?->name);
        self::assertSame('ada@example.test', $reloaded?->email);

        // refresh: re-reads the DB row, discarding unsaved edits
        $found->name = 'Unsaved';
        $found->refresh();
        self::assertSame('Grace', $found->name);

        // keep the row for later assertions
        $this->lifecycleUser = $user;
    }

    private OrmUser $lifecycleUser;

    private function runRelationAssertions(): void
    {
        $user = $this->lifecycleUser;

        OrmProfile::create(['user_id' => $user->id, 'bio' => 'first programmer']);
        OrmPost::create(['user_id' => $user->id, 'title' => 'One']);
        OrmPost::create(['user_id' => $user->id, 'title' => 'Two']);
        OrmPost::create(['user_id' => ((int) $user->id) + 5, 'title' => 'Other author']);

        $roleAdmin = OrmRole::create(['label' => 'admin']);
        OrmRole::create(['label' => 'not-linked']);

        // pivot write (explicit, parameterized)
        $this->db->execute('INSERT INTO orm_user_roles (user_id, role_id) VALUES (?, ?)', [$user->id, $roleAdmin->id]);

        // ---- lazy loading ----
        $profile = $user->load('profile');
        self::assertInstanceOf(OrmProfile::class, $profile);
        self::assertSame('first programmer', $profile->bio);

        // inverse belongsTo from the profile back to the user
        self::assertSame($user->id, $profile->user->id);

        $posts = $user->load('posts');
        self::assertInstanceOf(OrmResult::class, $posts);
        self::assertCount(2, $posts->all());

        $roles = $user->load('roles');
        self::assertInstanceOf(OrmResult::class, $roles);
        self::assertCount(1, $roles->all());
        self::assertSame('admin', $roles->first()?->label);

        // magic access hits the loaded cache (no duplicate query)
        self::assertSame($roles, $user->roles);
    }

    private function runEagerLoadingAssertions(): void
    {
        // ---- eager 1:1 + 1:n on one batched parents query ----
        $eager = OrmUser::query()->with('profile', 'posts')->get();
        self::assertCount(1, $eager->all());

        $eagerUser = $eager->first();
        self::assertInstanceOf(OrmProfile::class, $eagerUser->profile);
        self::assertCount(2, $eagerUser->posts->all());

        // ---- nested eager: posts → author (belongsTo level 2) ----
        $nested = OrmPost::query()->with('author')->get();
        self::assertCount(3, $nested->all());

        $adaPosts = array_values(array_filter(
            $nested->all(),
            static fn (OrmPost $post): bool => $post->title !== 'Other author'
        ));
        self::assertCount(2, $adaPosts);

        foreach ($adaPosts as $post) {
            self::assertInstanceOf(OrmUser::class, $post->author);
            self::assertSame('Grace', $post->author->name);
        }

        // ---- belongsToMany eager through the pivot ----
        $withRoles = OrmUser::query()->with('roles')->first();
        self::assertCount(1, $withRoles->roles->all());
        self::assertSame('admin', $withRoles->roles->first()?->label);

        // eager cache replaces lazy results — same data, no requery
        self::assertSame($withRoles->roles, $withRoles->loadedRelation('roles'));

        // ---- dotted eager path: posts.author resolves the second level ----
        $dotted = OrmUser::query()->with('posts.author')->first();
        self::assertNotNull($dotted);
        self::assertCount(2, $dotted->posts->all());

        foreach ($dotted->posts->all() as $post) {
            self::assertInstanceOf(OrmUser::class, $post->loadedRelation('author'));
            self::assertSame('Grace', $post->author->name);
        }
    }

    private function runPaginationAndScopeAssertions(): void
    {
        // ---- pagination: total, window, last page ----
        $pageOne = OrmUser::query()->orderBy('id')->paginate(1);
        self::assertSame(1, $pageOne->currentPage);
        self::assertSame(1, $pageOne->perPage);
        self::assertGreaterThanOrEqual(1, $pageOne->total);
        self::assertSame(1, $pageOne->lastPage());
        self::assertCount(1, $pageOne->result->all());

        $arrays = $pageOne->toArray();
        self::assertArrayHasKey('meta', $arrays);
        self::assertSame($pageOne->total, $arrays['meta']['total']);

        // ---- scopes: named, reusable constraints ----
        $user = $this->lifecycleUser;
        $user->active = 0;
        $user->save();

        self::assertCount(0, OrmUser::query()->active()->get()->all(), 'scopeActive excludes deactivated rows');
        self::assertCount(1, OrmUser::query()->inactive()->get()->all(), 'scopeInactive finds it');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('[noSuchScope]');

        OrmUser::query()->noSuchScope();
    }

    public function tearDown(): void
    {
        Model::forgetConnection();
    }
}
