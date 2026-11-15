<?php

declare(strict_types=1);

namespace Sahil\Validation;

use InvalidArgumentException;
use Sahil\Database\Connection;

/**
 * Database uniqueness rule — the framework's extension point for
 * "this value must not already exist" checks.
 *
 *     use Sahil\Validation\Unique;
 *
 *     'email' => ['required', 'email', new Unique($connection, 'users', 'email')],
 *
 *     // ignoring an existing row when updating:
 *     new Unique($connection, 'users', 'email')->ignore($user->id),
 *
 * Identifiers (table and column names) are validated against a strict
 * whitelist before they ever reach the query builder, so a rule built
 * from trusted configuration cannot be turned into SQL injection — and
 * values always go through bound parameters.
 */
final class Unique implements RuleContract
{
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private string|int|null $ignoreValue = null;

    private ?string $ignoreColumn = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
        private readonly string $column,
    ) {
        foreach (['table' => $table, 'column' => $column] as $kind => $identifier) {
            if (preg_match(self::IDENTIFIER, $identifier) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'Unique rule: %s name [%s] must be a simple SQL identifier.',
                    $kind,
                    $identifier,
                ));
            }
        }
    }

    /**
     * Exclude one row — typically the record being updated.
     */
    public function ignore(string|int $value, string $column = 'id'): self
    {
        if (preg_match(self::IDENTIFIER, $column) !== 1) {
            throw new InvalidArgumentException(
                sprintf('Unique rule: ignore column [%s] must be a simple SQL identifier.', $column),
            );
        }

        $rule = clone $this;
        $rule->ignoreValue = $value;
        $rule->ignoreColumn = $column;

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

        if ($this->ignoreValue !== null) {
            // where() returns a NEW builder — the query must be reassigned.
            $query = $query->where($this->ignoreColumn ?? 'id', '<>', $this->ignoreValue);
        }

        return $query->first() === null
            ? null
            : 'This field has already been taken.';
    }
}
