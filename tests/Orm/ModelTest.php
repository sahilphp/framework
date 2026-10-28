<?php

declare(strict_types=1);

namespace Sahil\Tests\Orm;

use PDO;
use PHPUnit\Framework\TestCase;
use Sahil\Database\Connection;
use Sahil\Database\Drivers\MySqlDriver;
use Sahil\Orm\MassAssignmentException;
use Sahil\Orm\Relation;
use Sahil\Tests\Database\RecordingPdo;

/**
 * Attribute/mass-assignment/serialization behavior — no live DB needed.
 */
final class ModelTest extends TestCase
{
    protected function setUp(): void
    {
        $recorder = new RecordingPdo();

        OrmUser::useConnection(Connection::connect(
            new MySqlDriver(),
            'mysql:host=nodesx;',
            null,
            null,
            [],
            static fn (array $_): PDO => $recorder
        ));
    }

    protected function tearDown(): void
    {
        OrmUser::forgetConnection();
    }

    /** Custom connection recorder for tests that inspect SQL. */
    private function connectRecording(): RecordingPdo
    {
        $recorder = new RecordingPdo();
        OrmUser::useConnection(Connection::connect(
            new MySqlDriver(),
            'mysql:host=x',
            null,
            null,
            [],
            static fn (array $_): PDO => $recorder
        ));

        return $recorder;
    }

    public function test_fill_only_accepts_fillable_keys(): void
    {
        $model = new OrmUser();
        $model->fill(['name' => 'Ada', 'email' => 'a@b.co']);

        self::assertSame('Ada', $model->name);
        self::assertNull($model->getAttribute('password'));
    }

    public function test_mass_assignment_is_loud_and_aggregated(): void
    {
        $model = new OrmUser();

        try {
            $model->fill(['name' => 'started', 'is_admin' => true, 'role' => 'root']);
            self::fail('Expected mass-assignment rejection.');
        } catch (MassAssignmentException $e) {
            self::assertStringContainsString('is_admin, role', $e->getMessage());
            self::assertStringContainsString('OrmUser', $e->getMessage());
        }

        // Fill is atomic: a rejected call leaves attributes untouched.
        self::assertNull($model->name);
    }

    public function test_mass_assignment_is_atomic(): void
    {
        $model = new OrmUser();

        try {
            $model->fill(['name' => 'started', 'is_admin' => true]);
            self::fail('Expected mass-assignment rejection.');
        } catch (MassAssignmentException) {
        }

        self::assertNull($model->name, 'no key may survive a rejected fill');
    }

    public function test_guarded_keys_refused_even_if_set(): void
    {
        $model = new OrmUser();
        $model->fill(['password' => 'can-be-assigned']);

        self::assertSame('can-be-assigned', $model->getAttribute('password'));

        $stricter = new StrictUser();

        try {
            $stricter->fill(['password' => 'x']);
            self::fail('guarded must refuse');
        } catch (MassAssignmentException $e) {
            self::assertStringContainsString('password', $e->getMessage());
        }
    }

    public function test_hidden_attributes_never_serialize(): void
    {
        $model = new OrmUser(['name' => 'Ada', 'password' => 'super-secret-9'], true);

        $data = $model->toArray();

        self::assertSame('Ada', $data['name']);
        self::assertArrayNotHasKey('password', $data);

        $json = json_decode(json_encode($model) ?: '', true);

        self::assertArrayNotHasKey('password', $json ?? []);
    }

    public function test_casts_apply_explicitly(): void
    {
        $model = new OrmUser(['name' => 'x', 'active' => 1], true);

        self::assertTrue($model->active, 'cast bool on db int');
        self::assertSame(1, $model->rawAttribute('active'));
    }

    public function test_dirty_tracking_and_rollback(): void
    {
        $model = new OrmUser(['name' => 'A', 'email' => 'a@b.co'], true);

        self::assertSame([], $model->dirty());

        $model->name = 'B';
        $model->email = 'changed@b.co';

        self::assertSame(['name', 'email'], $model->dirty());
        self::assertTrue($model->isDirty('name'));
        self::assertFalse($model->isDirty('active'));

        $model->rollbackEdits();

        self::assertSame('A', $model->name);
        self::assertSame([], $model->dirty());
    }

    public function test_save_updates_only_dirty_columns_with_timestamp(): void
    {
        $recorder = $this->connectRecording();

        $model = new OrmUser(['name' => 'Ada', 'email' => 'a@b.co'], true);
        $model->setRawAttribute('id', 42, false);

        $model->name = 'Changed';
        $model->save();

        $sql = $recorder->executions[0]['sql'];

        self::assertStringStartsWith('UPDATE `orm_users` SET', $sql);
        self::assertStringContainsString('`name` = ?', $sql);
        self::assertStringContainsString('`updated_at` = ?', $sql);
        self::assertStringNotContainsString('`email`', $sql, 'untouched columns must not update');
    }

    public function test_insert_writes_timestamp_columns_and_backfills_pk(): void
    {
        $recorder = $this->connectRecording();

        $created = OrmUser::create(['name' => 'N', 'email' => 'n@b.co']);

        [$sql, $params] = [$recorder->executions[0]['sql'], $recorder->executions[0]['params']];

        self::assertStringStartsWith('INSERT INTO `orm_users`', $sql);
        self::assertStringContainsString('`created_at`', $sql);
        self::assertStringContainsString('`updated_at`', $sql);
        self::assertSame('N', $params[0]);
        self::assertCount(4, $params, 'name + email + created_at + updated_at');

        // RecordingPdo::lastInsertId returns 77 — the PK must be backfilled.
        self::assertSame(77, $created->id);
    }

    public function test_eager_loading_is_one_query_per_level_not_per_row(): void
    {
        $recorder = $this->connectRecording();
        $recorder->rowsFor = [
            'SELECT * FROM `orm_users`' => [
                ['id' => 1, 'name' => 'A', 'email' => 'a@x.test', 'active' => 1],
                ['id' => 2, 'name' => 'B', 'email' => 'b@x.test', 'active' => 1],
                ['id' => 3, 'name' => 'C', 'email' => 'c@x.test', 'active' => 1],
            ],
            'SELECT * FROM `orm_posts` WHERE `user_id` IN (?, ?, ?)' => [
                ['id' => 11, 'user_id' => 1, 'title' => 'p1'],
                ['id' => 12, 'user_id' => 1, 'title' => 'p2'],
                ['id' => 13, 'user_id' => 3, 'title' => 'p3'],
            ],
        ];

        $users = OrmUser::query()->with('posts')->get();

        // base query + exactly ONE batched posts query (no per-row fan-out).
        self::assertCount(2, $recorder->executions);

        $postsQuery = $recorder->executions[1];
        self::assertSame('SELECT * FROM `orm_posts` WHERE `user_id` IN (?, ?, ?)', $postsQuery['sql']);
        self::assertSame([1, 2, 3], $postsQuery['params']);

        $first = $users->first();
        self::assertCount(2, $first->posts->all());
        self::assertSame('p1', $first->posts->first()->title);

        $third = $users->all()[2];
        self::assertCount(1, $third->posts->all());
        $second = $users->all()[1];
        self::assertCount(0, $second->posts->all()); // loaded, but empty
    }

    public function test_dotted_eager_path_loads_each_level_with_one_query(): void
    {
        $recorder = $this->connectRecording();
        $recorder->rowsFor = [
            'SELECT * FROM `orm_users`' => [
                ['id' => 1, 'name' => 'A', 'email' => 'a@x.test', 'active' => 1],
                ['id' => 2, 'name' => 'B', 'email' => 'b@x.test', 'active' => 1],
            ],
            'SELECT * FROM `orm_posts` WHERE `user_id` IN (?, ?)' => [
                ['id' => 11, 'user_id' => 1, 'title' => 'p1'],
                ['id' => 12, 'user_id' => 2, 'title' => 'p2'],
            ],
            'SELECT * FROM `orm_users` WHERE `id` IN (?, ?)' => [
                ['id' => 1, 'name' => 'A', 'email' => 'a@x.test', 'active' => 1],
                ['id' => 2, 'name' => 'B', 'email' => 'b@x.test', 'active' => 1],
            ],
        ];

        $users = OrmUser::query()->with('posts.author')->get();

        // base + posts + authors: exactly ONE query per dotted level.
        self::assertCount(3, $recorder->executions);
        self::assertSame('SELECT * FROM `orm_posts` WHERE `user_id` IN (?, ?)', $recorder->executions[1]['sql']);
        self::assertSame([1, 2], $recorder->executions[1]['params']);
        self::assertSame('SELECT * FROM `orm_users` WHERE `id` IN (?, ?)', $recorder->executions[2]['sql']);
        self::assertSame([1, 2], $recorder->executions[2]['params']);

        // the nested level is attached on every child row, not per parent row.
        self::assertSame('A', $users->first()->posts->first()->author->name);
        self::assertSame('B', $users->all()[1]->posts->first()->author->name);
    }

    public function test_lazy_relation_magic_access_maps_to_load(): void
    {
        $recorder = $this->connectRecording();
        $recorder->rowsFor = [
            'SELECT * FROM `orm_users` WHERE `id` = ? LIMIT 1' => [
                ['id' => 1, 'name' => 'A', 'email' => 'a@x.test', 'active' => 1],
            ],
            'SELECT * FROM `orm_profiles` WHERE `user_id` IN (?)' => [
                ['id' => 9, 'user_id' => 1, 'bio' => 'bio-1'],
            ],
        ];

        $user = OrmUser::query()->find(1);

        // lazy property magic triggers ONE extra batched query on access
        self::assertSame('bio-1', $user->profile->bio, 'lazy property access loads the relation once');
        self::assertSame('SELECT * FROM `orm_users` WHERE `id` = ? LIMIT 1', $recorder->executions[0]['sql']);
        self::assertSame('SELECT * FROM `orm_profiles` WHERE `user_id` IN (?)', $recorder->executions[1]['sql']);
        self::assertCount(2, $recorder->executions);
    }

    public function test_relation_names_are_snake_cased_from_class_names(): void
    {
        self::assertSame('orm_user', Relation::snake(Relation::basename('Sahil\\Tests\\Orm\\OrmUser')));
        self::assertSame('relation', Relation::snake(Relation::basename('Sahil\\Orm\\Relation')));
    }

    public function test_relation_factories_produce_correct_descriptors(): void
    {
        $relation = new OrmUser(['name' => 'x']);

        self::assertSame('hasOne', $relation->profile()->type);
        self::assertSame('user_id', $relation->profile()->foreignKey);
        self::assertSame('hasMany', $relation->posts()->type);
        self::assertSame('user_id', $relation->posts()->foreignKey);
        self::assertSame('belongsToMany', $relation->roles()->type);
        self::assertSame('orm_user_roles', $relation->roles()->pivotTable);
        self::assertSame('user_id', $relation->roles()->pivotSelfKey);
        self::assertSame('role_id', $relation->roles()->pivotRelatedKey);

        $profile = new OrmProfile(['bio' => 'x']);

        self::assertSame('belongsTo', $profile->user()->type);
        self::assertSame('user_id', $profile->user()->foreignKey);
        self::assertSame('id', $profile->user()->ownerKey);
    }

    public function test_undefined_relations_fail_loudly(): void
    {
        $model = new OrmUser(['name' => 'x']);

        $this->expectException(\Sahil\Orm\OrmException::class);
        $this->expectExceptionMessage('[missing]');

        $model->load('missing');
    }

    public function test_table_defaults_from_class_name(): void
    {
        self::assertSame('orm_users', new OrmUser()->table());
    }
}
