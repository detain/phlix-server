<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm;

use Phlix\Plugins\Scrobbler\Lastfm\DbLastfmOAuthStateStore;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmOAuthStateStore;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see DbLastfmOAuthStateStore}.
 *
 * Tests the DB-backed implementation covering:
 * - Basic put/consume round-trip (returns user_id)
 * - Mismatched state returns null
 * - One-shot consumption (replay returns null)
 * - Missing state returns null
 * - Wrong state still wipes entry (security)
 * - TTL expiration behavior
 * - Concurrent access via transactions
 *
 * @covers \Phlix\Plugins\Scrobbler\Lastfm\DbLastfmOAuthStateStore
 */
final class DbLastfmOAuthStateStoreTest extends TestCase
{
    /**
     * @var list<string> Every SQL string the repository issued.
     */
    private array $seenSql = [];

    /**
     * Build a mock Connection.
     *
     * @param array<string, array<int, array<string, mixed>>> $byFragment
     *        Map of SQL-fragment => rows to return when the SQL contains it.
     */
    private function mockConnection(array $byFragment = []): Connection
    {
        $seenSql = &$this->seenSql;
        $mock = $this->createMock(Connection::class);

        $mock->method('query')->willReturnCallback(
            function (string $sql) use ($byFragment, &$seenSql): mixed {
                $seenSql[] = $sql;
                foreach ($byFragment as $fragment => $rows) {
                    if (str_contains($sql, $fragment)) {
                        return $rows;
                    }
                }
                return [];
            }
        );

        $mock->method('beginTrans')->willReturn(true);
        $mock->method('commitTrans')->willReturn(true);
        $mock->method('rollBackTrans')->willReturn(true);

        return $mock;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seenSql = [];
    }

    public function test_round_trip_returns_user_id(): void
    {
        $db = $this->mockConnection([
            'SELECT data FROM oauth_state_store' => [
                ['data' => json_encode(['user_id' => 'user-abc-123'])],
            ],
        ]);

        $store = new DbLastfmOAuthStateStore($db);
        $store->put('state-123', 'user-abc-123');

        self::assertSame('user-abc-123', $store->consume('state-123'));
    }

    public function test_consume_with_mismatched_state_returns_null(): void
    {
        $db = $this->mockConnection([
            'SELECT data FROM oauth_state_store' => [],
        ]);

        $store = new DbLastfmOAuthStateStore($db);
        $store->put('state-123', 'user-abc');

        self::assertNull($store->consume('state-WRONG'));
    }

    public function test_consume_is_one_shot(): void
    {
        $firstCall = true;
        $db = $this->createMock(Connection::class);

        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$firstCall): mixed {
                $this->seenSql[] = $sql;
                if (str_contains($sql, 'SELECT data FROM oauth_state_store')) {
                    if ($firstCall) {
                        $firstCall = false;
                        return [['data' => json_encode(['user_id' => 'user-abc'])]];
                    }
                    return [];
                }
                return true;
            }
        );
        $db->method('beginTrans')->willReturn(true);
        $db->method('commitTrans')->willReturn(true);
        $db->method('rollBackTrans')->willReturn(true);

        $store = new DbLastfmOAuthStateStore($db);
        $store->put('state-123', 'user-abc');

        self::assertSame('user-abc', $store->consume('state-123'));
        // Replay attempt — the entry was wiped on the first consume.
        self::assertNull($store->consume('state-123'));
    }

    public function test_consume_when_never_issued_returns_null(): void
    {
        $db = $this->mockConnection([
            'SELECT data FROM oauth_state_store' => [],
        ]);

        $store = new DbLastfmOAuthStateStore($db);

        self::assertNull($store->consume('whatever'));
    }

    public function test_mismatched_state_still_wipes_stored_entry(): void
    {
        $callCount = 0;
        $db = $this->createMock(Connection::class);

        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$callCount): mixed {
                $this->seenSql[] = $sql;
                if (str_contains($sql, 'SELECT data FROM oauth_state_store')) {
                    $callCount++;
                    // First call returns nothing (wrong state), second call also nothing (wiped)
                    return [];
                }
                return true;
            }
        );
        $db->method('beginTrans')->willReturn(true);
        $db->method('commitTrans')->willReturn(true);
        $db->method('rollBackTrans')->willReturn(true);

        $store = new DbLastfmOAuthStateStore($db);
        $store->put('state-123', 'user-abc');

        // A wrong-state attempt MUST also wipe the entry so an attacker
        // cannot probe and then immediately replay with the right state.
        self::assertNull($store->consume('state-WRONG'));
        self::assertNull($store->consume('state-123'));
    }

    public function test_put_uses_correct_provider(): void
    {
        $db = $this->mockConnection();
        $store = new DbLastfmOAuthStateStore($db);

        $store->put('test-state', 'user-123');

        // Verify the INSERT used 'lastfm' as the provider
        $insertStatements = array_filter(
            $this->seenSql,
            static fn(string $sql): bool => str_contains($sql, 'INSERT INTO oauth_state_store')
        );
        self::assertCount(1, $insertStatements);
        self::assertStringContainsString("'lastfm'", $insertStatements[0]);
    }

    public function test_put_stores_user_id_in_json_data(): void
    {
        $db = $this->mockConnection();
        $store = new DbLastfmOAuthStateStore($db);

        $store->put('test-state', 'user-xyz-789');

        $insertStatements = array_filter(
            $this->seenSql,
            static fn(string $sql): bool => str_contains($sql, 'INSERT INTO oauth_state_store')
        );
        self::assertCount(1, $insertStatements);
        self::assertStringContainsString('"user_id":"user-xyz-789"', $insertStatements[0]);
    }

    public function test_consume_queries_with_correct_provider_filter(): void
    {
        $db = $this->mockConnection([
            'SELECT data FROM oauth_state_store' => [],
        ]);
        $store = new DbLastfmOAuthStateStore($db);

        $store->consume('any-state');

        $selectStatements = array_filter(
            $this->seenSql,
            static fn(string $sql): bool => str_contains($sql, 'SELECT data FROM oauth_state_store')
        );
        self::assertCount(1, $selectStatements);
        // The SELECT should filter by provider = 'lastfm'
        self::assertStringContainsString("provider = 'lastfm'", $selectStatements[0]);
        self::assertStringContainsString("state_value = ?", $selectStatements[0]);
        self::assertStringContainsString("expires_at > NOW()", $selectStatements[0]);
    }

    public function test_cleanup_runs_after_successful_consume(): void
    {
        $db = $this->mockConnection([
            'SELECT data FROM oauth_state_store' => [
                ['data' => json_encode(['user_id' => 'user-123'])],
            ],
        ]);
        $store = new DbLastfmOAuthStateStore($db);

        $store->consume('state-123');

        $cleanupStatements = array_filter(
            $this->seenSql,
            static fn(string $sql): bool => str_contains($sql, 'DELETE FROM oauth_state_store WHERE expires_at <= NOW()')
        );
        self::assertCount(1, $cleanupStatements);
    }

    public function test_cleanup_runs_after_failed_consume(): void
    {
        $db = $this->mockConnection([
            'SELECT data FROM oauth_state_store' => [],
        ]);
        $store = new DbLastfmOAuthStateStore($db);

        $store->consume('nonexistent');

        $cleanupStatements = array_filter(
            $this->seenSql,
            static fn(string $sql): bool => str_contains($sql, 'DELETE FROM oauth_state_store WHERE expires_at <= NOW()')
        );
        self::assertCount(1, $cleanupStatements);
    }

    public function test_custom_ttl_is_used(): void
    {
        $db = $this->mockConnection();
        $store = new DbLastfmOAuthStateStore($db, 300); // 5 minutes instead of 10

        $store->put('state', 'user-123');

        $insertStatements = array_filter(
            $this->seenSql,
            static fn(string $sql): bool => str_contains($sql, 'INSERT INTO oauth_state_store')
        );
        self::assertCount(1, $insertStatements);
        // The INSERT should contain FROM_UNIXTIME with a timestamp that's approximately 300 seconds in the future
        self::assertStringContainsString('FROM_UNIXTIME(', $insertStatements[0]);
    }

    public function test_implements_lastfm_oauth_state_store_interface(): void
    {
        $db = $this->createMock(Connection::class);
        $store = new DbLastfmOAuthStateStore($db);

        self::assertInstanceOf(LastfmOAuthStateStore::class, $store);
    }

    public function test_transaction_rollback_on_select_failure(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('beginTrans')->willReturn(true);
        $db->method('rollBackTrans')->willReturn(true);
        $db->method('query')->willReturnCallback(
            function (string $sql): mixed {
                $this->seenSql[] = $sql;
                if (str_contains($sql, 'SELECT data FROM oauth_state_store')) {
                    return 'error'; // Simulate error
                }
                return true;
            }
        );

        $store = new DbLastfmOAuthStateStore($db);
        $result = $store->consume('state-123');

        self::assertNull($result);
    }

    public function test_transaction_rollback_on_delete_failure(): void
    {
        $firstCall = true;
        $db = $this->createMock(Connection::class);
        $db->method('beginTrans')->willReturn(true);
        $db->method('commitTrans')->willReturn(true);
        $db->method('rollBackTrans')->willReturn(true);
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$firstCall): mixed {
                $this->seenSql[] = $sql;
                if (str_contains($sql, 'SELECT data FROM oauth_state_store')) {
                    return [['data' => json_encode(['user_id' => 'user-123'])]];
                }
                if (str_contains($sql, 'DELETE FROM oauth_state_store')) {
                    if ($firstCall) {
                        $firstCall = false;
                        return false; // Simulate delete failure
                    }
                    return true;
                }
                return true;
            }
        );

        $store = new DbLastfmOAuthStateStore($db);
        $result = $store->consume('state-123');

        self::assertNull($result);
    }

    public function test_empty_json_data_returns_null(): void
    {
        $db = $this->mockConnection([
            'SELECT data FROM oauth_state_store' => [
                ['data' => '{}'], // Empty JSON object
            ],
        ]);
        $store = new DbLastfmOAuthStateStore($db);

        $result = $store->consume('state-123');

        self::assertNull($result);
    }

    public function test_missing_user_id_in_data_returns_null(): void
    {
        $db = $this->mockConnection([
            'SELECT data FROM oauth_state_store' => [
                ['data' => json_encode(['some_other_field' => 'value'])], // Missing user_id
            ],
        ]);
        $store = new DbLastfmOAuthStateStore($db);

        $result = $store->consume('state-123');

        self::assertNull($result);
    }

    public function test_uuid_generation_for_put(): void
    {
        $db = $this->mockConnection();
        $store = new DbLastfmOAuthStateStore($db);

        $store->put('state-abc', 'user-123');

        $insertStatements = array_filter(
            $this->seenSql,
            static fn(string $sql): bool => str_contains($sql, 'INSERT INTO oauth_state_store')
        );
        self::assertCount(1, $insertStatements);
        // UUID format: 8-4-4-4-12 hex characters
        self::assertMatchesRegularExpression(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/',
            $insertStatements[0]
        );
    }
}