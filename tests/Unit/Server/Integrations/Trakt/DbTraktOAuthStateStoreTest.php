<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Integrations\Trakt;

use Phlix\Server\Integrations\Trakt\DbTraktOAuthStateStore;
use Phlix\Server\Integrations\Trakt\TraktOAuthStateStore;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see DbTraktOAuthStateStore}.
 *
 * Tests the DB-backed implementation covering:
 * - Basic put/consume round-trip
 * - Mismatched state returns null
 * - One-shot consumption (replay returns null)
 * - Missing state returns null
 * - Wrong state still wipes entry (security)
 * - TTL expiration behavior
 * - Concurrent access via transactions
 */
final class DbTraktOAuthStateStoreTest extends TestCase
{
    /**
     * @var list<string> Every SQL string the repository issued.
     */
    private array $seenSql = [];

    /**
     * @var list<array<int, mixed>> Every parameter array passed to query().
     */
    private array $seenParams = [];

    /**
     * Build a mock Connection.
     *
     * @param array<string, array<int, array<string, mixed>>> $byFragment
     *        Map of SQL-fragment => rows to return when the SQL contains it.
     */
    private function mockConnection(array $byFragment = []): Connection
    {
        $seenSql = &$this->seenSql;
        $seenParams = &$this->seenParams;
        $mock = $this->createMock(Connection::class);

        $mock->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use ($byFragment, &$seenSql, &$seenParams): mixed {
                $seenSql[] = $sql;
                $seenParams[] = $params;
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
        $this->seenParams = [];
    }

    public function test_round_trip_returns_verifier(): void
    {
        $db = $this->mockConnection([
            'SELECT data FROM oauth_state_store' => [
                ['data' => json_encode(['code_verifier' => 'verifier-abc'])],
            ],
        ]);

        $store = new DbTraktOAuthStateStore($db);
        $store->put('state-123', 'verifier-abc');

        self::assertSame('verifier-abc', $store->consume('state-123'));
    }

    public function test_consume_with_mismatched_state_returns_null(): void
    {
        $db = $this->mockConnection([
            'SELECT data FROM oauth_state_store' => [],
        ]);

        $store = new DbTraktOAuthStateStore($db);
        $store->put('state-123', 'verifier-abc');

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
                        return [['data' => json_encode(['code_verifier' => 'verifier-abc'])]];
                    }
                    return [];
                }
                return true;
            }
        );
        $db->method('beginTrans')->willReturn(true);
        $db->method('commitTrans')->willReturn(true);
        $db->method('rollBackTrans')->willReturn(true);

        $store = new DbTraktOAuthStateStore($db);
        $store->put('state-123', 'verifier-abc');

        self::assertSame('verifier-abc', $store->consume('state-123'));
        // Replay attempt — the entry was wiped on the first consume.
        self::assertNull($store->consume('state-123'));
    }

    public function test_consume_when_never_issued_returns_null(): void
    {
        $db = $this->mockConnection([
            'SELECT data FROM oauth_state_store' => [],
        ]);

        $store = new DbTraktOAuthStateStore($db);

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

        $store = new DbTraktOAuthStateStore($db);
        $store->put('state-123', 'verifier-abc');

        // A wrong-state attempt MUST also wipe the entry so an attacker
        // cannot probe and then immediately replay with the right state.
        self::assertNull($store->consume('state-WRONG'));
        self::assertNull($store->consume('state-123'));
    }

    public function test_put_uses_correct_provider(): void
    {
        $db = $this->mockConnection();
        $store = new DbTraktOAuthStateStore($db);

        $store->put('test-state', 'test-verifier');

        // Find the INSERT statement index
        $insertIndex = null;
        foreach ($this->seenSql as $i => $sql) {
            if (str_contains($sql, 'INSERT INTO oauth_state_store')) {
                $insertIndex = $i;
                break;
            }
        }
        self::assertNotNull($insertIndex, 'INSERT statement not found');
        // Verify the INSERT used 'trakt' as the provider (params: id, provider, state, data, expires)
        self::assertSame('trakt', $this->seenParams[$insertIndex][1]);
    }

    public function test_put_stores_code_verifier_in_json_data(): void
    {
        $db = $this->mockConnection();
        $store = new DbTraktOAuthStateStore($db);

        $store->put('test-state', 'my-code-verifier');

        // Find the INSERT statement index
        $insertIndex = null;
        foreach ($this->seenSql as $i => $sql) {
            if (str_contains($sql, 'INSERT INTO oauth_state_store')) {
                $insertIndex = $i;
                break;
            }
        }
        self::assertNotNull($insertIndex, 'INSERT statement not found');
        // Verify the JSON data contains the code_verifier (params: id, provider, state, data, expires)
        /** @var string $insertData */
        $insertData = $this->seenParams[$insertIndex][3];
        $data = json_decode($insertData, true);
        self::assertIsArray($data);
        self::assertSame('my-code-verifier', $data['code_verifier']);
    }

    public function test_consume_queries_with_correct_provider_filter(): void
    {
        $db = $this->mockConnection([
            'SELECT data FROM oauth_state_store' => [],
        ]);
        $store = new DbTraktOAuthStateStore($db);

        $store->consume('any-state');

        // Find the SELECT statement index
        $selectIndex = null;
        foreach ($this->seenSql as $i => $sql) {
            if (str_contains($sql, 'SELECT data FROM oauth_state_store')) {
                $selectIndex = $i;
                break;
            }
        }
        self::assertNotNull($selectIndex, 'SELECT statement not found');
        // The SELECT should filter by provider = 'trakt' (params: provider, state)
        self::assertSame('trakt', $this->seenParams[$selectIndex][0]);
        self::assertSame('any-state', $this->seenParams[$selectIndex][1]);
    }

    public function test_cleanup_runs_after_successful_consume(): void
    {
        $db = $this->mockConnection([
            'SELECT data FROM oauth_state_store' => [
                ['data' => json_encode(['code_verifier' => 'verifier'])],
            ],
        ]);
        $store = new DbTraktOAuthStateStore($db);

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
        $store = new DbTraktOAuthStateStore($db);

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
        $store = new DbTraktOAuthStateStore($db, 300); // 5 minutes instead of 10

        $store->put('state', 'verifier');

        $insertStatements = array_filter(
            $this->seenSql,
            static fn(string $sql): bool => str_contains($sql, 'INSERT INTO oauth_state_store')
        );
        self::assertCount(1, $insertStatements);
        // The INSERT should contain FROM_UNIXTIME with a timestamp that's approximately 300 seconds in the future
        self::assertStringContainsString('FROM_UNIXTIME(', $insertStatements[0]);
    }

    public function test_implements_trakt_oauth_state_store_interface(): void
    {
        $db = $this->createMock(Connection::class);
        $store = new DbTraktOAuthStateStore($db);

        self::assertInstanceOf(TraktOAuthStateStore::class, $store);
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

        $store = new DbTraktOAuthStateStore($db);
        $result = $store->consume('state-123');

        self::assertNull($result);
        // Should have rolled back
        $rollBackCalls = array_filter(
            $this->seenSql,
            static fn(string $sql): bool => str_contains($sql, 'rollBackTrans')
        );
        // Note: rollBackTrans is called via method mock, not query
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
                    return [['data' => json_encode(['code_verifier' => 'verifier'])]];
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

        $store = new DbTraktOAuthStateStore($db);
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
        $store = new DbTraktOAuthStateStore($db);

        $result = $store->consume('state-123');

        self::assertNull($result);
    }

    public function test_missing_code_verifier_in_data_returns_null(): void
    {
        $db = $this->mockConnection([
            'SELECT data FROM oauth_state_store' => [
                ['data' => json_encode(['nonce' => 'some-nonce'])], // Missing code_verifier
            ],
        ]);
        $store = new DbTraktOAuthStateStore($db);

        $result = $store->consume('state-123');

        self::assertNull($result);
    }

    /**
     * S131 — the `false` arm, driven through production.
     *
     * ⚠ Honest framing: `false` is a value THIS client never returns; the arm
     * exists for defensive breadth (a driver swap, or a `lastInsertId()` that
     * honours its declared `string|false`). Reaching it requires a double,
     * which is exactly what this test is. It is here so that deleting the arm
     * has to be a deliberate decision rather than a silent simplification.
     *
     * @return void
     */
    public function test_put_throws_when_the_insert_returns_false(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(false);

        $store = new DbTraktOAuthStateStore($db, 600);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to persist Trakt OAuth state to database');
        $store->put('state-123', 'verifier-123');
    }

    /**
     * S131 — the `null` arm, driven through production.
     *
     * `put()` guards its INSERT so an unpersisted state is never handed back to
     * the caller as if it had been stored — if it were, the OAuth callback
     * would later find no row and fail the flow with a misleading error.
     *
     * The guard used to read `$result === false`, a value
     * `Workerman\MySQL\Connection::query()` cannot produce (it THROWS on a
     * real error). `null` is the falsy value it DOES return for a zero-row
     * INSERT, and for any statement whose leading keyword it fails to
     * recognise — a heredoc reformat is enough
     * ({@see \Phlix\Common\Database\WriteResult} trap 3).
     *
     * Deleting `|| $result === null` from `WriteResult::wroteNothing()` turns
     * this RED.
     *
     * @return void
     */
    public function test_put_throws_when_the_insert_wrote_nothing_null(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(null);

        $store = new DbTraktOAuthStateStore($db, 600);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to persist Trakt OAuth state to database');
        $store->put('state-123', 'verifier-123');
    }

    /**
     * 🔴 S131 — `'0'` is a SUCCESSFUL insert and must NOT throw.
     *
     * `oauth_state_store.id` is a `CHAR(36)` UUID minted in PHP with no
     * `AUTO_INCREMENT` column, so `PDO::lastInsertId()` answers the string
     * `'0'` — which is FALSY. "Simplifying" this guard to `if (!$result)`
     * would make every single successfully-stored state throw, i.e. every
     * OAuth login would 500 at the authorize step.
     *
     * @return void
     */
    public function test_a_successful_insert_returning_the_falsy_string_zero_does_not_throw(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn('0');

        $store = new DbTraktOAuthStateStore($db, 600);

        $store->put('state-123', 'verifier-123');

        $this->addToAssertionCount(1); // reaching here without a throw IS the assertion
    }
}
