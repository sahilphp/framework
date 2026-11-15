<?php

declare(strict_types=1);

namespace Sahil\Validation;

use InvalidArgumentException;
use Sahil\Database\Connection;

/**
 * Database existence rule — the counterpart of {@see Unique}:
 * "this value must reference a row that exists".
 *
 *     'user_id' => ['required', 'integer', new Exists($connection, 'users', 'id')],
 *
 * Identifiers (table and column names) are validated against a strict
 * whitelist before they ever reach the query builder; values always go
 * through bound parameters. An unreachable database throws — the
 * validator must fail loudly, not treat the lookup failure as a missing
 * row (that would wrongly reject valid input) or a found one (that would
 * wrongly accept forged references).
 */
final class Exists implements RuleContract
{
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** @var array<string, scalar> additional WHERE constraints */
    private array $constraints = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
        private readonly string $column,
    ) {
        foreach (['table' => $table, 'column' => $column] as $kind => $identifier) {
            if (preg_match(self::IDENTIFIER, $identifier) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'Exists rule: %s name [%s] must be a simple SQL identifier.',
                    $kind,
                    $identifier,
                ));
            }
        }
    }

    /** Narrow the lookup — e.g. `->where('active', 1)`. */
    public function where(string $column, string|int|float|bool $value): self
    {
        if (preg_match(self::IDENTIFIER, $column) !== 1) {
            throw new InvalidArgumentException(
                sprintf('Exists rule: where column [%s] must be a simple SQL identifier.', $column),
            );
        }

        $rule = clone $this;
        $rule->constraints[$column] = $value;

        return $rule;
    }

    public function validate(string $field, mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null; // type rules own this failure
        }

        $query = $this->connection
            ->table($this->table)
            ->where($this->column, '=', $value)
            ->limit(1);

        foreach ($this->constraints as $column => $expected) {
            // where() returns a NEW builder — the query must be reassigned.
            $query = $query->where($column, '=', $expected);
        }

        return $query->first() === null
            ? 'The selected value is invalid.'
            : null;
    }
}
