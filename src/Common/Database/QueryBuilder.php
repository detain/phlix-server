<?php

namespace Phlex\Common\Database;

use Workerman\MySQL\Connection;

class QueryBuilder
{
    private Connection $connection;
    private string $table = '';
    private array $columns = ['*'];
    private array $where = [];
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

    public function select(array|string $columns = ['*']): self
    {
        $this->columns = is_array($columns) ? $columns : [$columns];
        return $this;
    }

    public function where(string $column, mixed $operator, mixed $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
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

    public function get(): array
    {
        $sql = $this->buildSelect();
        return $this->connection->query($sql, $this->bindings);
    }

    public function first(): ?array
    {
        $this->limit(1);
        $results = $this->get();
        return $results[0] ?? null;
    }

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
        return $this->connection->getLastInsertId();
    }

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

        return $this->connection->query($sql, $this->bindings);
    }

    public function delete(): int
    {
        $sql = sprintf(
            "DELETE FROM %s %s",
            $this->table,
            $this->buildWhere()
        );

        return $this->connection->query($sql, $this->bindings);
    }

    public function count(): int
    {
        $originalColumns = $this->columns;
        $this->columns = ['COUNT(*) as count'];

        $result = $this->first();

        $this->columns = $originalColumns;

        return (int)($result['count'] ?? 0);
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
