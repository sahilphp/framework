<?php

declare(strict_types=1);

namespace Sahil\Http;

use Closure;
use InvalidArgumentException;
use Sahil\Database\Expression;
use Sahil\Orm\ModelQuery;
use Sahil\Orm\PageResult;
use Sahil\Validation\ValidationException;

/**
 * API listing queries: allowlisted filter/sort/search + bounded
 * pagination over the existing ModelQuery and PageResult.
 *
 *     $page = ApiQuery::from($request)
 *         ->allowedFilters(['status', 'role'])
 *         ->filter('min_age', fn ($q, $v) => $q->where('age', '>=', (int) $v))
 *         ->searchable(['name', 'email'])
 *         ->allowedSorts(['name', 'created_at'])
 *         ->defaultSort('-created_at')
 *         ->uniqueBy('id')
 *         ->paginate(User::query()->where('tenant_id', $tenant));
 *
 * Security model: filter names, operators, sort fields, searchable
 * columns and field lists are developer allowlists — never client names.
 * Every value is parameter-bound; grouped search SQL quotes identifiers
 * through the driver. Unknown filters/sorts → 422 ValidationException.
 */
final class ApiQuery
{
    /** Nested-filter operator suffixes → builder translation. */
    private const OPERATORS = ['eq', 'ne', 'in', 'not_in', 'gt', 'lt', 'gte', 'lte', 'null', 'not_null', 'like'];

    private const MAX_FILTER_LIST = 50;
    private const MAX_IN_VALUES = 25;
    private const MAX_SEARCH_LENGTH = 200;

    private array $filters = [];   // name => ['ops'=>list<string>] | callable
    private array $sorts = [];
    private array $defaultSorts = [];
    private array $search = [];
    private array $fields = [];
    private ?string $uniqueBy = null;
    private int $perPage = 15;
    private int $maxPerPage = 100;
    private string $pageParam = 'page';
    private string $perPageParam = 'perPage';

    private function __construct(private readonly Request $request)
    {
    }

    public static function from(Request $request): self
    {
        return new self($request);
    }

    /* ---------- configuration ---------- */

    /**
     * Allowlist equality filters — `['status', 'role']` — or scoped ops:
     * `['created_at' => ['gte','lte'], 'status' => ['eq','ne']]`.
     * `filter[name]=v` equals; `filter[name][op]=v` uses the op.
     */
    public function allowedFilters(array $filters): self
    {
        $clone = clone $this;

        foreach ($filters as $name => $ops) {
            if (is_int($name)) {
                $name = $ops;
                $ops = ['eq'];
            }

            $clone->filters[(string) $name] = ['ops' => (array) $ops];
        }

        return $clone;
    }

    /**
     * Custom filter — the callback receives the query and the raw
     * (scalar) client value; it must apply wheres through the builder.
     *
     * @param callable $callback `fn (ModelQuery $query, mixed $value): ?ModelQuery`
     */
    public function filter(string $name, callable $callback): self
    {
        $clone = clone $this;
        $clone->filters[$name] = $callback;

        return $clone;
    }

    /** @param list<string> $columns */
    public function allowedSorts(array $columns): self
    {
        $clone = clone $this;
        $clone->sorts = array_values($columns);

        return $clone;
    }

    /** `-col` for DESC; applied only when no `sort` param arrives. */
    public function defaultSort(string ...$columns): self
    {
        $clone = clone $this;
        $clone->defaultSorts = array_values($columns);

        return $clone;
    }

    /**
     * Deterministic pagination tie-breaker appended when the effective
     * sort does not already include this column. REQUIRED for stable
     * offset pagination on non-unique sorts.
     */
    public function uniqueBy(string $column): self
    {
        $clone = clone $this;
        $clone->uniqueBy = $column;

        return $clone;
    }

    /** @param list<string> $columns literal-substring LIKE search fields */
    public function searchable(array $columns): self
    {
        $clone = clone $this;
        $clone->search = array_values($columns);

        return $clone;
    }

    /** Resource-level field allowlist for `?fields=id,name` — opt-in. */
    public function allowedFields(array $fields): self
    {
        $clone = clone $this;
        $clone->fields = array_values($fields);

        return $clone;
    }

    public function perPage(int $default, ?int $max = null): self
    {
        $clone = clone $this;
        $clone->perPage = $default;
        $clone->maxPerPage = $max ?? $clone->maxPerPage;

        return $clone;
    }

    public function pageParameters(string $page, string $perPage): self
    {
        $clone = clone $this;
        $clone->pageParam = $page;
        $clone->perPageParam = $perPage;

        return $clone;
    }

    /* ---------- the query params that were allowed through ---------- */

    /** Client-selected `?fields=` intersected with the allowlist. */
    public function selectedFields(): ?array
    {
        if ($this->fields === []) {
            return null;
        }

        $raw = $this->request->queryParameter('fields');

        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $requested = array_filter(array_map('trim', explode(',', $raw)));

        return array_values(array_intersect($requested, $this->fields));
    }

    /* ---------- execution ---------- */

    /** Apply filters + search + sorts (no pagination) to a query. */
    public function apply(ModelQuery $query): ModelQuery
    {
        $query = $this->applyFilters($query);
        $query = $this->applySearch($query);

        return $this->applySorts($query);
    }

    /** Filter + sort + search + paginate → existing PageResult. */
    public function paginate(ModelQuery $query): PageResult
    {
        [$page, $perPage] = $this->paginationInput();
        $query = $this->apply($query);

        $total = $query->count();
        $items = $query->limit($perPage)->offset(($page - 1) * $perPage)->get();

        return new PageResult($items, $page, $perPage, $total);
    }

    /** Validated `[page, perPage]` for non-ORM listing paths. */
    public function page(): array
    {
        return $this->paginationInput();
    }

    /* ---------- internals ---------- */

    private function paginationInput(): array
    {
        $errors = [];
        $page = $this->positiveInt($this->request->queryParameter($this->pageParam), 1, $this->pageParam, $errors);
        $perPage = $this->positiveInt($this->request->queryParameter($this->perPageParam), $this->perPage, $this->perPageParam, $errors);
        $perPage = min($perPage, $this->maxPerPage);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$page, $perPage];
    }

    private function positiveInt(?string $raw, int $default, string $param, array &$errors): int
    {
        if ($raw === null || $raw === '') {
            return $default;
        }

        if (!preg_match('/^\d+$/', $raw) || (int) $raw < 1) {
            $errors[$param][] = sprintf('The %s parameter must be a positive integer.', $param);

            return $default;
        }

        return (int) $raw;
    }

    private function applyFilters(ModelQuery $query): ModelQuery
    {
        $raw = $this->request->query()['filter'] ?? null;

        if ($raw === null) {
            return $query;
        }

        if (!is_array($raw)) {
            throw ValidationException::withMessages(['filter' => ['Malformed filter parameter.']]);
        }

        if (count($raw) > self::MAX_FILTER_LIST) {
            throw ValidationException::withMessages(['filter' => ['Too many filters.']]);
        }

        foreach ($raw as $name => $value) {
            $name = (string) $name;

            if (!array_key_exists($name, $this->filters)) {
                throw ValidationException::withMessages([
                    'filter' => [sprintf('Unknown filter [%s].', $name)],
                ]);
            }

            $definition = $this->filters[$name];

            if ($definition instanceof Closure) {
                $result = $definition($query, is_scalar($value) ? $value : null);
                $query = $result instanceof ModelQuery ? $result : $query;

                continue;
            }

            $query = $this->applyFilterValue($query, $name, $value, $definition['ops']);
        }

        return $query;
    }

    private function applyFilterValue(ModelQuery $query, string $name, mixed $value, array $allowedOps): ModelQuery
    {
        // filter[name]=v → equality (or the single allowed op)
        if (!is_array($value)) {
            return $this->applyOperator($query, $name, 'eq', $value, $allowedOps);
        }

        foreach ($value as $op => $operand) {
            $query = $this->applyOperator($query, $name, (string) $op, $operand, $allowedOps);
        }

        return $query;
    }

    private function applyOperator(ModelQuery $query, string $name, string $op, mixed $value, array $allowedOps): ModelQuery
    {
        if (!in_array($op, self::OPERATORS, true) || !in_array($op, $allowedOps, true)) {
            throw ValidationException::withMessages([
                'filter' => [sprintf('Unsupported operator [%s] for filter [%s].', $op, $name)],
            ]);
        }

        $column = $this->safeColumn($name);
        $comparisons = ['eq' => '=', 'ne' => '!=', 'gt' => '>', 'lt' => '<', 'gte' => '>=', 'lte' => '<='];

        if (isset($comparisons[$op])) {
            return $query->where($column, $comparisons[$op], $this->scalar($value, $name));
        }

        return match ($op) {
            'like' => $query->where($column, 'like', '%'.$this->escapeLike((string) $this->scalar($value, $name)).'%'),
            'in' => $query->whereIn($column, $this->valueList($value, $name)),
            'not_in' => $query->whereNotIn($column, $this->valueList($value, $name)),
            'null' => $this->truthy($value) ? $query->whereNull($column) : $query->whereNotNull($column),
            default => $query->whereNotNull($column), // 'not_null'
        };
    }

    /** Column names come from the developer allowlist — quoted defensively anyway. */
    private function safeColumn(string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Filter column [%s] is not a safe identifier.', $name));
        }

        return $name;
    }

    private function scalar(mixed $value, string $name): mixed
    {
        if (!is_scalar($value) && $value !== null) {
            throw ValidationException::withMessages([
                'filter' => [sprintf('Filter [%s] expects a scalar value.', $name)],
            ]);
        }

        return $value;
    }

    /** @return list<mixed> */
    private function valueList(mixed $value, string $name): array
    {
        $list = is_array($value) ? array_values($value) : explode(',', (string) $value);

        if ($list === [] || count($list) > self::MAX_IN_VALUES) {
            throw ValidationException::withMessages([
                'filter' => [sprintf('Filter [%s] list must have 1–%d values.', $name, self::MAX_IN_VALUES)],
            ]);
        }

        foreach ($list as $item) {
            $this->scalar($item, $name);
        }

        return $list;
    }

    private function truthy(mixed $value): bool
    {
        return $value === null || $value === '' || filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function applySearch(ModelQuery $query): ModelQuery
    {
        $term = $this->request->queryParameter('search');

        if ($term === null || trim($term) === '' || $this->search === []) {
            return $query;
        }

        if (mb_strlen($term) > self::MAX_SEARCH_LENGTH) {
            throw ValidationException::withMessages(['search' => ['Search term is too long.']]);
        }

        // (col LIKE ? OR col LIKE ?) grouped — literal substring, so
        // LIKE wildcards in user input are escaped.
        $pattern = '%'.$this->escapeLike($term).'%';
        $clauses = [];
        $bindings = [];

        foreach ($this->search as $column) {
            $clauses[] = $query->quoteIdentifier($this->safeColumn($column)).' LIKE ?';
            $bindings[] = $pattern;
        }

        return $query->whereRaw(new Expression('('.implode(' OR ', $clauses).')'), $bindings);
    }

    private function escapeLike(string $value): string
    {
        return strtr($value, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }

    private function applySorts(ModelQuery $query): ModelQuery
    {
        $requested = $this->request->queryParameter('sort');
        $sorts = [];

        if ($requested !== null && trim($requested) !== '') {
            foreach (explode(',', $requested) as $field) {
                $field = trim($field);

                if ($field === '') {
                    continue;
                }

                $desc = str_starts_with($field, '-');
                $column = ltrim($field, '-+');

                if (!in_array($column, $this->sorts, true)) {
                    throw ValidationException::withMessages([
                        'sort' => [sprintf('Unsupported sort field [%s].', $column)],
                    ]);
                }

                $sorts[] = [$column, $desc ? 'desc' : 'asc'];
            }
        } else {
            foreach ($this->defaultSorts as $field) {
                $desc = str_starts_with($field, '-');
                $sorts[] = [ltrim($field, '-+'), $desc ? 'desc' : 'asc'];
            }
        }

        $sorted = [];
        foreach ($sorts as [$column, $dir]) {
            $sorted[$column] = true;
            $query = $query->orderBy($this->safeColumn($column), $dir);
        }

        // Deterministic tie-breaker for stable offset pagination.
        if ($this->uniqueBy !== null && !isset($sorted[$this->uniqueBy])) {
            $query = $query->orderBy($this->safeColumn($this->uniqueBy), 'asc');
        }

        return $query;
    }


}
