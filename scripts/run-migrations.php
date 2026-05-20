<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Phlix\Common\Database\ConnectionPool;

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