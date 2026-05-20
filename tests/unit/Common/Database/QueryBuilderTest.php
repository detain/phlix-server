<?php

namespace Phlix\Tests\Unit\Common\Database;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Database\QueryBuilder;
use Workerman\MySQL\Connection;

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

    private function getMockConnection(): Connection
    {
        return new class('127.0.0.1', 3306, 'root', '', 'test') extends Connection {
            public function __construct(
                string $host = '127.0.0.1',
                int $port = 3306,
                string $user = 'root',
                string $password = '',
                string $dbname = '',
                string $charset = 'utf8mb4'
            ) {
                // Skip parent constructor to avoid actual connection
            }

            public function query($query = '', $params = null, $fetchmode = \PDO::FETCH_ASSOC) {
                return [];
            }

            public function getLastInsertId() {
                return 'test-id';
            }

            public function closeConnection(): void {
            }
        };
    }
}