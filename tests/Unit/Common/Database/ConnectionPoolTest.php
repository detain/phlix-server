<?php

namespace Phlix\Tests\Unit\Common\Database;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Database\ConnectionPool;

class ConnectionPoolTest extends TestCase
{
    public function testConnectionPoolCanBeInitialized(): void
    {
        $configPath = __DIR__ . '/../../../../config/database.php';

        $this->expectNotToPerformAssertions();

        // This should not throw
        ConnectionPool::init($configPath);
    }

    public function testGetInstanceReturnsPoolInstance(): void
    {
        $configPath = __DIR__ . '/../../../../config/database.php';
        ConnectionPool::init($configPath);

        $instance = ConnectionPool::getInstance();
        $this->assertInstanceOf(ConnectionPool::class, $instance);
    }

    /**
     * Regression: PhlixMySQLConnection must default its charset to utf8mb4,
     * NOT the parent's legacy 'utf8' (utf8mb3). With native prepared
     * statements a utf8mb3 connection makes MySQL 8 reject INSERTs into the
     * utf8mb4_unicode_ci schema with error 3988 ("Conversion from collation
     * utf8mb3_general_ci into utf8mb4_unicode_ci impossible for parameter").
     * Reflection only — does not open a connection.
     */
    public function testPhlixConnectionDefaultsCharsetToUtf8mb4(): void
    {
        $ctor = new \ReflectionMethod(\Phlix\Common\Database\PhlixMySQLConnection::class, '__construct');
        $params = $ctor->getParameters();
        $charset = $params[5] ?? null;

        $this->assertNotNull($charset, 'constructor must declare a $charset parameter');
        $this->assertSame('charset', $charset->getName());
        $this->assertTrue($charset->isDefaultValueAvailable());
        $this->assertSame('utf8mb4', $charset->getDefaultValue());
    }

    public function testConfiguredCharsetIsUtf8mb4(): void
    {
        /** @var array{connections: array<string, array<string, mixed>>} $config */
        $config = require __DIR__ . '/../../../../config/database.php';
        $mysql = $config['connections']['mysql'] ?? [];
        $this->assertSame('utf8mb4', $mysql['charset'] ?? null);
    }
}
