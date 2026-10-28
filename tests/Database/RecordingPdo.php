<?php

declare(strict_types=1);

namespace Sahil\Tests\Database;

use PDO;
use PDOStatement;

/**
 * Test double: a PDO subclass whose constructor is never invoked, and a
 * statement whose fetches return canned data. Every execute() is logged
 * (SQL + bound params) for SQL/parameter assertions.
 */
final class RecordingPdo extends PDO
{
    /**
     * @var list<array{sql: string, params: list<mixed>}>
     */
    public array $executions = [];

    /**
     * @var array<string, list<array<string, mixed>>> canned rows per SQL text
     */
    public array $rowsFor = [];

    public bool $failPrepare = false;

    /** Exact SQL text that should fail at prepare time; null disables it. */
    public ?string $failOnSql = null;

    /** Simulated auto-increment value for lastInsertId(). */
    public string $simulatedInsertId = '77';

    public function __construct()
    {
        // parent constructor deliberately NOT called: testing double
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->simulatedInsertId;
    }

    public function prepare(string $query, ?array $options = null): PDOStatement|false
    {
        if ($this->failPrepare) {
            throw new \PDOException('SQLSTATE[42S02] [1024] simulated: table missing');
        }

        if ($this->failOnSql !== null && $this->failOnSql === $query) {
            throw new \PDOException('SQLSTATE[HY000] [2013] simulated failure for: '.$query);
        }

        return new RecordingStatement($this, $query, $this->rowsFor[$query] ?? []);
    }

    public function beginTransaction(): bool
    {
        $this->executions[] = ['sql' => 'BEGIN', 'params' => []];

        return true;
    }

    public function commit(): bool
    {
        $this->executions[] = ['sql' => 'COMMIT', 'params' => []];

        return true;
    }

    public function rollBack(): bool
    {
        $this->executions[] = ['sql' => 'ROLLBACK', 'params' => []];

        return true;
    }
}

/**
 * Statement double: logs execute params, echoes canned rows.
 */
final class RecordingStatement extends PDOStatement
{
    /**
     * @var list<mixed>|null
     */
    private ?array $bound = null;

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(
        private readonly RecordingPdo $pdo,
        string $sql,
        private readonly array $rows,
    ) {
        // parent constructor deliberately NOT called: testing double
        $this->queryString = $sql;
    }

    public function execute(?array $params = null): bool
    {
        $params ??= [];

        $this->bound = $params;
        $this->pdo->executions[] = [
            'sql' => $this->queryString,
            'params' => $params,
        ];

        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->rows;
    }

    public function rowCount(): int
    {
        return $this->bound === null ? 0 : count($this->rows);
    }
}
