<?php

namespace Phlix\Tests\Unit\Common\Database;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Database\ConnectionPool;

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