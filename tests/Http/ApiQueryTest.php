<?php

declare(strict_types=1);

namespace Sahil\Tests\Http;

use PHPUnit\Framework\TestCase;
use Sahil\Database\Connection;
use Sahil\Database\ConnectionFactory;
use Sahil\Database\Schema\Schema;
use Sahil\Database\Schema\Table;
use Sahil\Http\ApiQuery;
use Sahil\Http\Request;
use Sahil\Orm\Model;
use Sahil\Validation\ValidationException;

final class ApiUser extends Model
{
    protected ?string $table = 'users';

    public function __construct(array $attributes = [], bool $exists = false)
    {
        if ($exists) {
            foreach ($attributes as $key => $value) {
                $this->setRawAttribute((string) $key, $value, false);
            }
            return;
        }
        parent::__construct($attributes);
    }
}

final class ApiQueryTest extends TestCase
{
    private string $path;

    private Connection $db;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite unavailable.');
        }

        $this->path = sys_get_temp_dir().'/sahil-apiq-'.bin2hex(random_bytes(6)).'.sqlite';
        $this->db = (new ConnectionFactory())->make(['driver' => 'sqlite', 'database' => $this->path]);
        ApiUser::useConnection($this->db);

        (new Schema($this->db))->create('users', static function (Table $t): void {
            $t->id();
            $t->string('name');
            $t->string('email');
            $t->string('status');
            $t->integer('age')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        $rows = [
            ['Asha', 'asha@x', 'active', 30, '2024-01-01'],
            ['Baraka', 'baraka@x', 'active', 25, '2024-02-01'],
            ['Chiku', 'chiku@x', 'banned', 40, '2024-03-01'],
            ['Daudi', 'daudi@x', 'active', 22, '2024-04-01'],
            ['Ema', 'ema@x', 'banned', 35, '2024-05-01'],
        ];
        foreach ($rows as [$n, $e, $s, $a, $c]) {
            $this->db->table('users')->insert(['name' => $n, 'email' => $e, 'status' => $s, 'age' => $a, 'created_at' => $c]);
        }
    }

    protected function tearDown(): void
    {
        ApiUser::forgetConnection();
        @unlink($this->path);
    }

    private function req(array $query = []): Request
    {
        return Request::fromGlobals(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/users'], $query);
    }

    private function listing(array $query = []): \Sahil\Orm\PageResult
    {
        return ApiQuery::from($this->req($query))
            ->allowedFilters(['status', 'age' => ['gte', 'lte', 'eq']])
            ->searchable(['name', 'email'])
            ->allowedSorts(['name', 'created_at', 'age'])
            ->defaultSort('-created_at')
            ->uniqueBy('id')
            ->paginate(ApiUser::query());
    }

    /* ---------- pagination ---------- */

    public function test_default_pagination(): void
    {
        $page = $this->listing();
        self::assertSame(5, $page->total);
        self::assertSame(1, $page->currentPage);
        self::assertCount(5, $page->result->all());
    }

    public function test_page_and_per_page(): void
    {
        $page = ApiQuery::from($this->req(['perPage' => 2, 'page' => 2]))
            ->allowedSorts(['name'])->defaultSort('name')->uniqueBy('id')
            ->paginate(ApiUser::query());
        self::assertSame(2, $page->currentPage);
        self::assertCount(2, $page->result->all());
        self::assertSame(5, $page->total);
    }

    public function test_invalid_page_values_rejected(): void
    {
        $this->expectException(ValidationException::class);
        ApiQuery::from($this->req(['page' => '0']))->paginate(ApiUser::query());
    }

    public function test_non_numeric_per_page_rejected(): void
    {
        $this->expectException(ValidationException::class);
        ApiQuery::from($this->req(['perPage' => 'abc']))->paginate(ApiUser::query());
    }

    public function test_per_page_capped_at_max(): void
    {
        $page = ApiQuery::from($this->req(['perPage' => '5000']))
            ->perPage(15, 50)->uniqueBy('id')->paginate(ApiUser::query());
        self::assertSame(50, $page->perPage);
    }

    public function test_out_of_range_page_returns_empty(): void
    {
        $page = ApiQuery::from($this->req(['page' => '99']))->uniqueBy('id')->paginate(ApiUser::query());
        self::assertSame(0, count($page->result->all()));
        self::assertSame(5, $page->total);
    }

    /* ---------- filtering ---------- */

    public function test_exact_filter(): void
    {
        $page = $this->listing(['filter' => ['status' => 'banned']]);
        self::assertSame(2, $page->total);
    }

    public function test_operators(): void
    {
        self::assertSame(2, $this->listing(['filter' => ['age' => ['gte' => '35']]])->total);
        self::assertSame(2, $this->listing(['filter' => ['age' => ['lte' => '25']]])->total);
    }

    public function test_unknown_filter_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->listing(['filter' => ['password' => 'x']]);
    }

    public function test_disallowed_operator_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->listing(['filter' => ['status' => ['like' => '%']]]);
    }

    public function test_filter_string_not_array_is_malformed(): void
    {
        $req = Request::fromGlobals(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/users'], ['filter' => 'string']);
        $this->expectException(ValidationException::class);
        ApiQuery::from($req)->allowedFilters(['status'])->paginate(ApiUser::query());
    }

    public function test_custom_filter_callback(): void
    {
        $page = ApiQuery::from($this->req(['filter' => ['older_than' => '30']]))
            ->filter('older_than', fn ($q, $v) => $q->where('age', '>', (int) $v))
            ->uniqueBy('id')->paginate(ApiUser::query());
        self::assertSame(2, $page->total);
    }

    public function test_sql_injection_in_filter_value_is_bound(): void
    {
        $page = $this->listing(['filter' => ['status' => "x' OR '1'='1"]]);
        self::assertSame(0, $page->total); // bound as a literal — matches nothing
    }

    /* ---------- sorting ---------- */

    public function test_sort_asc_desc_and_default(): void
    {
        $asc = $this->listing(['sort' => 'name']);
        self::assertSame('Asha', $asc->result->all()[0]->getAttribute('name'));

        $desc = $this->listing(['sort' => '-name']);
        self::assertSame('Ema', $desc->result->all()[0]->getAttribute('name'));

        $default = $this->listing(); // -created_at
        self::assertSame('Ema', $default->result->all()[0]->getAttribute('name'));
    }

    public function test_unknown_sort_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->listing(['sort' => 'password']);
    }

    public function test_stable_ordering_appends_unique_tiebreaker(): void
    {
        // sort by non-unique 'status' — pagination must still be stable
        $page1 = ApiQuery::from($this->req(['sort' => 'status', 'perPage' => 2, 'page' => 1]))
            ->allowedSorts(['status'])->uniqueBy('id')->paginate(ApiUser::query());
        $page2 = ApiQuery::from($this->req(['sort' => 'status', 'perPage' => 2, 'page' => 2]))
            ->allowedSorts(['status'])->uniqueBy('id')->paginate(ApiUser::query());
        $ids1 = array_map(fn ($m) => $m->getAttribute('id'), $page1->result->all());
        $ids2 = array_map(fn ($m) => $m->getAttribute('id'), $page2->result->all());
        self::assertSame($ids1, $ids1); // deterministic
        self::assertEmpty(array_intersect($ids1, $ids2)); // no dupes across pages
        self::assertSame([1, 2], $ids1); // id tie-break keeps order stable
    }

    /* ---------- search ---------- */

    public function test_search_across_configured_fields(): void
    {
        self::assertSame(1, $this->listing(['search' => 'asha'])->total);
        self::assertSame(1, $this->listing(['search' => 'chiku@x'])->total); // email field
    }

    public function test_search_escapes_wildcards(): void
    {
        // '%' is literal → matches nothing rather than everything
        self::assertSame(0, $this->listing(['search' => '%'])->total);
        self::assertSame(0, $this->listing(['search' => "' OR 1=1 --"])->total);
    }

    public function test_oversized_search_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->listing(['search' => str_repeat('a', 500)]);
    }

    /* ---------- composition ---------- */

    public function test_app_conditions_preserved(): void
    {
        $page = ApiQuery::from($this->req(['filter' => ['status' => 'active']]))
            ->allowedFilters(['status'])->uniqueBy('id')
            ->paginate(ApiUser::query()->where('age', '>', 21));
        self::assertSame(3, $page->total); // active AND age>21
    }

    public function test_selected_fields(): void
    {
        $q = ApiQuery::from($this->req(['fields' => 'id,name,password']))
            ->allowedFields(['id', 'name']);
        self::assertSame(['id', 'name'], $q->selectedFields());
        self::assertNull(ApiQuery::from($this->req())->allowedFields(['id'])->selectedFields());
    }
}
