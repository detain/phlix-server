# Step 1.2: Database Layer

**Phase:** 1 - Core Media Server Foundation  
**Plan File:** step-1.2-database-layer.md  
**Objective:** Implement async database connection pool, schema migrations, and query builder

---

## Overview

This step sets up the database layer using workerman/mysql for async MySQL connections. You will create a connection pool, database schema migrations, and basic query functionality.

**Prerequisites:** Step 1.1 must be completed first.

---

## Tasks

### 1.2.1 Create Connection Pool

Create `src/Common/Database/ConnectionPool.php`:
```php
<?php

namespace Phlex\Common\Database;

use Workerman\MySQL\Connection;

class ConnectionPool
{
    private static array $connections = [];
    private static string $configPath = '';
    private static ?ConnectionPool $instance = null;

    public static function init(string $configPath): void
    {
        self::$configPath = $configPath;
        self::$instance = new self();
    }

    public static function getInstance(): ?ConnectionPool
    {
        return self::$instance;
    }

    public static function getConnection(string $name = 'mysql'): Connection
    {
        if (!isset(self::$connections[$name])) {
            $config = include self::$configPath;
            $connConfig = $config['connections'][$name];
            
            self::$connections[$name] = new Connection(
                $connConfig['host'],
                $connConfig['port'],
                $connConfig['username'],
                $connConfig['password'],
                $connConfig['database']
            );
        }
        return self::$connections[$name];
    }

    public static function closeAll(): void
    {
        foreach (self::$connections as $connection) {
            $connection->closeConnection();
        }
        self::$connections = [];
    }
}
```

### 1.2.2 Create Query Builder

Create `src/Common/Database/QueryBuilder.php`:
```php
<?php

namespace Phlex\Common\Database;

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
```

### 1.2.3 Create Database Schema Migration

Create `migrations/001_initial_schema.sql`:
```sql
-- Phlex Media Server Initial Schema

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id CHAR(36) PRIMARY KEY,
    username VARCHAR(255) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_username (username),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User settings table
CREATE TABLE IF NOT EXISTS user_settings (
    user_id CHAR(36) PRIMARY KEY,
    max_streams INT DEFAULT 3,
    max_bitrate INT DEFAULT 100000000,
    preferred_audio_language VARCHAR(10) DEFAULT 'en',
    preferred_subtitle_language VARCHAR(10) DEFAULT 'en',
    subtitle_mode ENUM('always', 'only_foreign', 'none') DEFAULT 'only_foreign',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Libraries table
CREATE TABLE IF NOT EXISTS libraries (
    id CHAR(36) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    type ENUM('movie', 'series', 'music', 'photo', 'video') NOT NULL,
    paths JSON NOT NULL,
    options JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Media items table
CREATE TABLE IF NOT EXISTS media_items (
    id CHAR(36) PRIMARY KEY,
    library_id CHAR(36) NOT NULL,
    parent_id CHAR(36),
    name VARCHAR(255) NOT NULL,
    type ENUM('movie', 'series', 'season', 'episode', 'music', 'album', 'artist', 'video', 'audio', 'book', 'photo') NOT NULL,
    path VARCHAR(1000) NOT NULL,
    metadata_json JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_library (library_id),
    INDEX idx_parent (parent_id),
    INDEX idx_type (type),
    FULLTEXT idx_name (name),
    FOREIGN KEY (library_id) REFERENCES libraries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Media streams table
CREATE TABLE IF NOT EXISTS media_streams (
    id CHAR(36) PRIMARY KEY,
    media_item_id CHAR(36) NOT NULL,
    stream_index INT NOT NULL,
    stream_type ENUM('video', 'audio', 'subtitle') NOT NULL,
    codec VARCHAR(50),
    language VARCHAR(10),
    bitrate INT,
    width INT,
    height INT,
    FOREIGN KEY (media_item_id) REFERENCES media_items(id) ON DELETE CASCADE,
    INDEX idx_media_item (media_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sessions table
CREATE TABLE IF NOT EXISTS sessions (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    device_id VARCHAR(255) NOT NULL,
    device_name VARCHAR(255),
    device_type VARCHAR(50),
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_device (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Playback state table
CREATE TABLE IF NOT EXISTS playback_state (
    id CHAR(36) PRIMARY KEY,
    session_id CHAR(36) NOT NULL,
    media_item_id CHAR(36) NOT NULL,
    position_ticks BIGINT DEFAULT 0,
    duration_ticks BIGINT,
    playback_status ENUM('playing', 'paused', 'stopped') DEFAULT 'stopped',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (media_item_id) REFERENCES media_items(id) ON DELETE CASCADE,
    INDEX idx_session (session_id),
    INDEX idx_media_item (media_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API keys table
CREATE TABLE IF NOT EXISTS api_keys (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    key_hash VARCHAR(255) NOT NULL,
    name VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_key_hash (key_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transcode jobs table
CREATE TABLE IF NOT EXISTS transcode_jobs (
    id CHAR(36) PRIMARY KEY,
    stream_state_id CHAR(36),
    media_item_id CHAR(36) NOT NULL,
    input_path VARCHAR(1000) NOT NULL,
    output_path VARCHAR(1000) NOT NULL,
    status ENUM('queued', 'running', 'completed', 'failed', 'cancelled') DEFAULT 'queued',
    progress DECIMAL(5,2) DEFAULT 0,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    FOREIGN KEY (media_item_id) REFERENCES media_items(id) ON DELETE CASCADE,
    INDEX idx_status (status),
    INDEX idx_media_item (media_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 1.2.4 Create Migration Runner

Create `scripts/run-migrations.php`:
```php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Phlex\Common\Database\ConnectionPool;

$configPath = __DIR__ . '/../config/database.php';
ConnectionPool::init($configPath);

$db = ConnectionPool::getConnection('mysql');

$migrationsDir = __DIR__ . '/../migrations';
$files = glob($migrationsDir . '/*.sql');
sort($files);

foreach ($files as $file) {
    $sql = file_get_contents($file);
    echo "Running migration: " . basename($file) . "\n";
    
    // Split by semicolon and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            try {
                $db->query($statement);
            } catch (\Exception $e) {
                echo "  Warning: " . $e->getMessage() . "\n";
            }
        }
    }
}

echo "Migrations complete.\n";
```

### 1.2.5 Create Unit Tests

Create `tests/unit/Common/Database/QueryBuilderTest.php`:
```php
<?php

namespace Phlex\Tests\Unit\Common\Database;

use PHPUnit\Framework\TestCase;
use Phlex\Common\Database\QueryBuilder;

class QueryBuilderTest extends TestCase
{
    public function testCanCreateSelectQuery(): void
    {
        $builder = QueryBuilder::table($this->getMockConnection(), 'users');
        $builder->select(['id', 'username', 'email']);
        
        // Test that builder returns itself for chaining
        $this->assertInstanceOf(QueryBuilder::class, $builder);
    }

    public function testCanAddWhereClause(): void
    {
        $builder = QueryBuilder::table($this->getMockConnection(), 'users');
        $builder->where('username', '=', 'testuser');
        
        $this->assertInstanceOf(QueryBuilder::class, $builder);
    }

    public function testCanChainMethods(): void
    {
        $builder = QueryBuilder::table($this->getMockConnection(), 'users');
        $result = $builder
            ->select(['id', 'name'])
            ->where('id', '>', 1)
            ->orderBy('name', 'DESC')
            ->limit(10, 20);
        
        $this->assertInstanceOf(QueryBuilder::class, $result);
    }

    private function getMockConnection()
    {
        return new class {
            public function query($sql, $bindings = []) {
                return [];
            }
            public function getLastInsertId() {
                return 'test-id';
            }
            public function closeConnection() {}
        };
    }
}
```

Create `tests/unit/Common/Database/ConnectionPoolTest.php`:
```php
<?php

namespace Phlex\Tests\Unit\Common\Database;

use PHPUnit\Framework\TestCase;
use Phlex\Common\Database\ConnectionPool;

class ConnectionPoolTest extends TestCase
{
    public function testConnectionPoolCanBeInitialized(): void
    {
        $configPath = __DIR__ . '/../../../../config/database.php';
        
        // This should not throw
        ConnectionPool::init($configPath);
        
        $this->assertTrue(true);
    }

    public function testGetInstanceReturnsPoolInstance(): void
    {
        $configPath = __DIR__ . '/../../../../config/database.php';
        ConnectionPool::init($configPath);
        
        $instance = ConnectionPool::getInstance();
        $this->assertInstanceOf(ConnectionPool::class, $instance);
    }
}
```

---

## Verification

After completing all tasks:

1. Verify the connection pool class exists:
```bash
ls -la /home/sites/phlex/src/Common/Database/
```

2. Run the unit tests:
```bash
cd /home/sites/phlex
./vendor/bin/phpunit tests/unit/Common/Database/ --testdox
```

3. Verify migration SQL is valid:
```bash
cat /home/sites/phlex/migrations/001_initial_schema.sql | head -50
```

---

## Git Workflow

After verification, commit your changes:

```bash
cd /home/sites/phlex
git checkout -b step-1.2-database-layer
git add .
git commit -m "Step 1.2: Implement database layer with connection pool and schema"
unset GITHUB_TOKEN
gh pr create --title "Step 1.2: Database Layer" --body "Implements the database layer including ConnectionPool, QueryBuilder, initial schema migration, and unit tests."
gh pr merge --squash --delete-branch
git checkout master && git pull
```

---

## Next Step

After completing and merging this step, proceed to **Step 1.3: Logging Infrastructure** (`plans/phase-1/step-1.3-logging.md`).
