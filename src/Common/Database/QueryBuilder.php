<?php

declare(strict_types=1);

namespace Phlex\Common\Database;

use Phlex\Common\Util\RowMap;
use Workerman\MySQL\Connection;

class QueryBuilder
{
    private Connection $connection;
    private string $table = '';
    /** @var list<string> */
    private array $columns = ['*'];
    /** @var list<string> */
    private array $where = [];
    /** @var list<mixed> */
    private array $bindings = [];
    private ?int $limit = null;
    private ?int $offset = null;
    private string $orderBy = '';
    private string $orderDirection = 'ASC';

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public static function table(Connection $connection, string $table): self
    {
        $builder = new self($connection);
        $builder->table = $table;
        return $builder;
    }

    /**
     * @param list<string>|string $columns
     */
    public function select(array|string $columns = ['*']): self
    {
        $this->columns = is_array($columns) ? array_values($columns) : [$columns];
        return $this;
    }

    public function where(string $column, mixed $operator, mixed $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        if (!is_string($operator)) {
            throw new \InvalidArgumentException('Operator must be a string when a value is provided');
        }

        $this->where[] = "$column $operator ?";
        $this->bindings[] = $value;
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->orderBy = $column;
        $this->orderDirection = strtoupper($direction);
        return $this;
    }

    public function limit(int $limit, ?int $offset = null): self
    {
        $this->limit = $limit;
        $this->offset = $offset;
        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function get(): array
    {
        $sql = $this->buildSelect();
        return RowMap::listFromMixed($this->connection->query($sql, $this->bindings));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        $this->limit(1);
        $results = $this->get();
        return $results[0] ?? null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): mixed
    {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            $this->table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $this->connection->query($sql, array_values($data));
        return $this->connection->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(array $data): int
    {
        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = "$column = ?";
            $this->bindings[] = $data[$column];
        }

        $sql = sprintf(
            "UPDATE %s SET %s %s",
            $this->table,
            implode(', ', $sets),
            $this->buildWhere()
        );

        $rows = $this->connection->query($sql, $this->bindings);
        return is_int($rows) ? $rows : 0;
    }

    public function delete(): int
    {
        $sql = sprintf(
            "DELETE FROM %s %s",
            $this->table,
            $this->buildWhere()
        );

        $rows = $this->connection->query($sql, $this->bindings);
        return is_int($rows) ? $rows : 0;
    }

    public function count(): int
    {
        $originalColumns = $this->columns;
        $this->columns = ['COUNT(*) as count'];

        $result = $this->first();

        $this->columns = $originalColumns;

        $count = $result['count'] ?? 0;
        return is_numeric($count) ? (int)$count : 0;
    }

    private function buildSelect(): string
    {
        $sql = sprintf(
            "SELECT %s FROM %s",
            implode(', ', $this->columns),
            $this->table
        );

        $sql .= $this->buildWhere();

        if ($this->orderBy) {
            $sql .= " ORDER BY {$this->orderBy} {$this->orderDirection}";
        }

        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
            if ($this->offset !== null) {
                $sql .= " OFFSET {$this->offset}";
            }
        }

        return $sql;
    }

    private function buildWhere(): string
    {
        if (empty($this->where)) {
            return '';
        }
        return ' WHERE ' . implode(' AND ', $this->where);
    }
}
