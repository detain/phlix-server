<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Phlix\Common\Database\ConnectionPool;

$configPath = __DIR__ . '/../config/database.php';
ConnectionPool::init($configPath);

$db = ConnectionPool::getConnection('mysql');

$migrationsDir = __DIR__ . '/../migrations';
$files = glob($migrationsDir . '/*.sql');
sort($files);

/**
 * Strip SQL comments and split a file into individual executable
 * statements. Handles:
 *   - line `--` comments (the whole line, including any `;` inside it)
 *   - block `/* ... *\/` comments
 *   - blank lines after comment removal
 * The previous naive `explode(';', $sql)` shredded files whose comments
 * happened to contain a semicolon (e.g. "is re-attached in memory;
 * otherwise it is marked failed"), so the post-comment ALTER never ran.
 *
 * @return list<string>
 */
function split_statements(string $sql): array
{
    // 1. Drop /* ... */ block comments (non-greedy, multi-line aware).
    $stripped = preg_replace('#/\*.*?\*/#s', '', $sql) ?? $sql;

    // 2. Drop line-level `--` comments. Anything from `--` on a line
    //    until end-of-line is comment text. Tolerate both `-- comment`
    //    and `--comment` and `code -- comment` forms.
    $stripped = preg_replace('/--[^\n]*/', '', $stripped) ?? $stripped;

    // 3. Split on `;`. Trim each fragment and drop empty ones.
    $statements = [];
    foreach (explode(';', $stripped) as $part) {
        $part = trim($part);
        if ($part !== '') {
            $statements[] = $part;
        }
    }

    return $statements;
}

/**
 * Some `ALTER TABLE ... ADD COLUMN` / `ADD INDEX` statements legitimately
 * fail on re-runs because the column / index already exists. MySQL 8
 * doesn't accept `IF NOT EXISTS` on those clauses (only MariaDB does),
 * so we recognise the matching error codes/text and downgrade them to
 * `note:` rather than a full warning.
 */
function is_expected_idempotent_error(\Throwable $e): bool
{
    $msg = $e->getMessage();
    return str_contains($msg, 'Duplicate column name')
        || str_contains($msg, 'Duplicate key name')
        || str_contains($msg, 'check that column/key exists')
        || str_contains($msg, 'already exists');
}

foreach ($files as $file) {
    $sql = (string) file_get_contents($file);
    echo "Running migration: " . basename($file) . "\n";

    foreach (split_statements($sql) as $statement) {
        try {
            $db->query($statement);
        } catch (\Throwable $e) {
            if (is_expected_idempotent_error($e)) {
                echo "  note: " . $e->getMessage() . "\n";
            } else {
                echo "  Warning: " . $e->getMessage() . "\n";
            }
        }
    }
}

echo "Migrations complete.\n";
