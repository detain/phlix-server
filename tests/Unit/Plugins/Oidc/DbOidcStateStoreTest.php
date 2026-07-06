<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Oidc;

use Phlix\Plugins\Oidc\DbOidcStateStore;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * @covers \Phlix\Plugins\Oidc\DbOidcStateStore
 */
final class DbOidcStateStoreTest extends TestCase
{
    public function testPutStoresStateInDatabase(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('INSERT INTO oauth_state_store'),
                $this->callback(function ($params): bool {
                    return is_array($params)
                        && count($params) === 5
                        && is_string($params[0])
                        && $params[1] === 'oidc'
                        && $params[2] === 'test-state'
                        && is_string($params[3])
                        && is_int($params[4]);
                })
            )
            ->willReturn(true);

        $store = new DbOidcStateStore($db, 600);
        $store->put('test-state', 'test-verifier', 'test-nonce');
    }

    public function testPutWithCustomTtl(): void
    {
        $capturedParams = null;
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(function ($sql, $params) use (&$capturedParams): bool {
                $capturedParams = $params;
                return true;
            });

        $customTtl = 300;
        $store = new DbOidcStateStore($db, $customTtl);
        $store->put('state1', 'verifier1', 'nonce1');

        $this->assertIsArray($capturedParams);
        $expectedExpiry = time() + $customTtl;
        $this->assertGreaterThanOrEqual($expectedExpiry - 2, $capturedParams[4]);
        $this->assertLessThanOrEqual($expectedExpiry + 2, $capturedParams[4]);
    }

    public function testPutThrowsRuntimeExceptionOnInsertFailure(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->willReturn(false);

        $store = new DbOidcStateStore($db, 600);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to persist OIDC state to database');
        $store->put('state', 'verifier', 'nonce');
    }

    public function testConsumeReturnsStoredEntry(): void
    {
        $callCount = 0;
        $db = $this->createMock(Connection::class);

        $db->expects($this->once())->method('beginTrans');
        $db->expects($this->once())->method('commitTrans');

        $db->method('query')
            ->willReturnCallback(function ($sql) use (&$callCount): array|bool {
                $callCount++;
                if ($callCount === 1) {
                    // SELECT query
                    return [[
                        'data' => json_encode([
                            'code_verifier' => 'stored-verifier',
                            'nonce' => 'stored-nonce',
                        ]),
                    ]];
                }
                // DELETE for consume + cleanup (2 calls with DELETE)
                return true;
            });

        $store = new DbOidcStateStore($db, 600);
        $result = $store->consume('test-state');

        $this->assertSame([
            'code_verifier' => 'stored-verifier',
            'nonce' => 'stored-nonce',
        ], $result);
    }

    public function testConsumeReturnsNullForUnknownState(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects($this->once())->method('beginTrans');
        $db->expects($this->once())->method('rollBackTrans');

        // SELECT returns empty, then cleanup
        $db->method('query')->willReturnOnConsecutiveCalls([], true);

        $store = new DbOidcStateStore($db, 600);
        $result = $store->consume('unknown-state');

        $this->assertNull($result);
    }

    public function testConsumeReturnsNullForExpiredEntry(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects($this->once())->method('beginTrans');
        $db->expects($this->once())->method('rollBackTrans');

        // SELECT with expires_at check returns empty, then cleanup
        $db->method('query')->willReturnOnConsecutiveCalls([], true);

        $store = new DbOidcStateStore($db, 600);
        $result = $store->consume('expired-state');

        $this->assertNull($result);
    }

    public function testConsumeIsOneShotSecondConsumeReturnsNull(): void
    {
        $callCount = 0;
        $db = $this->createMock(Connection::class);

        $db->method('beginTrans');
        $db->method('rollBackTrans');
        $db->method('commitTrans');

        $db->method('query')
            ->willReturnCallback(function ($sql) use (&$callCount): array|bool {
                $callCount++;
                if ($callCount === 1) {
                    // First SELECT - returns entry
                    return [[
                        'data' => json_encode([
                            'code_verifier' => 'verifier',
                            'nonce' => 'nonce',
                        ]),
                    ]];
                }
                if ($callCount === 2) {
                    // DELETE for consume
                    return true;
                }
                if ($callCount === 3) {
                    // Second SELECT - entry already consumed
                    return [];
                }
                // Third DELETE for cleanup
                return true;
            });

        $store = new DbOidcStateStore($db, 600);

        $result1 = $store->consume('state');
        $this->assertNotNull($result1);

        $result2 = $store->consume('state');
        $this->assertNull($result2);
    }

    public function testConsumeCleansUpExpiredEntries(): void
    {
        $callCount = 0;
        $db = $this->createMock(Connection::class);

        $db->expects($this->once())->method('beginTrans');
        $db->expects($this->once())->method('commitTrans');

        $db->method('query')
            ->willReturnCallback(function ($sql) use (&$callCount): array|bool {
                $callCount++;
                if ($callCount === 1) {
                    return [[
                        'data' => json_encode([
                            'code_verifier' => 'verifier',
                            'nonce' => 'nonce',
                        ]),
                    ]];
                }
                return true;
            });

        $store = new DbOidcStateStore($db, 600);
        $store->consume('state');

        // Verify cleanup was called (callCount should be >= 3: SELECT, DELETE, cleanup)
        $this->assertGreaterThanOrEqual(3, $callCount);
    }

    public function testConsumeRollsBackOnException(): void
    {
        $callCount = 0;
        $db = $this->createMock(Connection::class);

        $db->expects($this->once())->method('beginTrans');
        $db->expects($this->once())->method('rollBackTrans');

        // First query (SELECT) throws, subsequent queries (cleanup) succeed
        $db->method('query')
            ->willReturnCallback(function ($sql) use (&$callCount): bool {
                $callCount++;
                if ($callCount === 1) {
                    throw new \PDOException('Database error');
                }
                return true;
            });

        $store = new DbOidcStateStore($db, 600);
        $result = $store->consume('state');

        $this->assertNull($result);
    }

    public function testDefaultTtlIs600Seconds(): void
    {
        $capturedParams = null;
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(function ($sql, $params) use (&$capturedParams): bool {
                $capturedParams = $params;
                return true;
            });

        $store = new DbOidcStateStore($db);
        $store->put('state', 'verifier', 'nonce');

        $this->assertIsArray($capturedParams);
        $expectedExpiry = time() + 600;
        $this->assertGreaterThanOrEqual($expectedExpiry - 2, $capturedParams[4]);
        $this->assertLessThanOrEqual($expectedExpiry + 2, $capturedParams[4]);
    }

    public function testNegativeTtlIsIgnored(): void
    {
        $capturedParams = null;
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(function ($sql, $params) use (&$capturedParams): bool {
                $capturedParams = $params;
                return true;
            });

        $store = new DbOidcStateStore($db, -100);
        $store->put('state', 'verifier', 'nonce');

        $this->assertIsArray($capturedParams);
        $expectedExpiry = time() + 600;
        $this->assertGreaterThanOrEqual($expectedExpiry - 2, $capturedParams[4]);
        $this->assertLessThanOrEqual($expectedExpiry + 2, $capturedParams[4]);
    }

    public function testConsumeWithEmptyCodeVerifierReturnsNull(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects($this->once())->method('beginTrans');
        $db->expects($this->once())->method('rollBackTrans');

        // SELECT returns entry with empty code_verifier, then cleanup
        $db->method('query')->willReturnOnConsecutiveCalls(
            [['data' => json_encode(['code_verifier' => '', 'nonce' => 'nonce'])]],
            true,
            true  // cleanup
        );

        $store = new DbOidcStateStore($db, 600);
        $result = $store->consume('state');

        $this->assertNull($result);
    }

    public function testConsumeWithMissingCodeVerifierReturnsNull(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects($this->once())->method('beginTrans');
        $db->expects($this->once())->method('rollBackTrans');

        // SELECT returns entry without code_verifier, then cleanup
        $db->method('query')->willReturnOnConsecutiveCalls(
            [['data' => json_encode(['nonce' => 'nonce'])]],
            true,
            true  // cleanup
        );

        $store = new DbOidcStateStore($db, 600);
        $result = $store->consume('state');

        $this->assertNull($result);
    }

    public function testImplementsOidcStateStoreInterface(): void
    {
        $db = $this->createMock(Connection::class);
        $store = new DbOidcStateStore($db, 600);

        $this->assertInstanceOf(\Phlix\Plugins\Oidc\OidcStateStore::class, $store);
    }
}
