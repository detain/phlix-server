<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\OAuth2;

use Phlix\Plugins\OAuth2\DbOAuth2StateStore;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Contract of the shared DB-backed OAuth2 state store, including S48 review r1
 * Finding 11: consumption must be ATOMIC — the SELECT takes a `FOR UPDATE` row
 * lock so two concurrent callbacks carrying the same state cannot both read it
 * before either DELETE lands.
 */
final class DbOAuth2StateStoreTest extends TestCase
{
    public function test_consume_locks_the_row_for_update_inside_the_transaction(): void
    {
        $sqls = [];
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())->method('beginTrans');
        $db->expects($this->once())->method('commitTrans');
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$sqls): array|bool {
                $sqls[] = $sql;
                if (str_starts_with($sql, 'SELECT')) {
                    return [['data' => json_encode(['code_verifier' => 'v1'])]];
                }
                return true;
            }
        );

        $store = new DbOAuth2StateStore($db, 'github');
        $entry = $store->consume('state-1');

        $this->assertNotNull($entry);
        $this->assertSame('v1', $entry['code_verifier']);

        $select = $sqls[0] ?? '';
        $this->assertStringStartsWith('SELECT', $select);
        $this->assertStringContainsString(
            'FOR UPDATE',
            $select,
            'the one-shot guarantee needs a row lock, otherwise a state can be consumed twice',
        );
        // The DELETE must follow in the same transaction.
        $this->assertStringContainsString('DELETE FROM oauth_state_store', $sqls[1] ?? '');
    }

    public function test_consume_returns_the_server_side_context(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static fn (string $sql): array|bool => str_starts_with($sql, 'SELECT')
                ? [['data' => json_encode([
                    'code_verifier' => 'v2',
                    'context' => ['intent' => 'link', 'link_user_id' => 'user-1'],
                ])]]
                : true
        );

        $store = new DbOAuth2StateStore($db, 'github');
        $entry = $store->consume('state-2');

        $this->assertNotNull($entry);
        $this->assertSame(['intent' => 'link', 'link_user_id' => 'user-1'], $entry['context'] ?? null);
    }

    public function test_consume_rolls_back_and_returns_null_for_an_unknown_state(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())->method('beginTrans');
        $db->expects($this->once())->method('rollBackTrans');
        $db->expects($this->never())->method('commitTrans');
        $db->method('query')->willReturnCallback(
            static fn (string $sql): array|bool => str_starts_with($sql, 'SELECT') ? [] : true
        );

        $store = new DbOAuth2StateStore($db, 'github');

        $this->assertNull($store->consume('nope'));
    }

    public function test_put_persists_the_provider_scoped_row(): void
    {
        $params = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $bind = []) use (&$params): bool {
                $params[] = ['sql' => $sql, 'bind' => $bind];
                return true;
            }
        );

        $store = new DbOAuth2StateStore($db, 'github');
        $store->put('state-3', 'verifier-3', ['intent' => 'link']);

        $this->assertCount(1, $params);
        $this->assertStringContainsString('INSERT INTO oauth_state_store', $params[0]['sql']);
        $this->assertSame('github', $params[0]['bind'][1] ?? null);
        $this->assertSame('state-3', $params[0]['bind'][2] ?? null);
        $this->assertStringContainsString('"intent":"link"', (string) ($params[0]['bind'][3] ?? ''));
    }

    public function test_put_throws_when_the_insert_fails(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(false);

        $store = new DbOAuth2StateStore($db, 'github');

        $this->expectException(\RuntimeException::class);
        $store->put('state-4', 'verifier-4');
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

        $store = new DbOAuth2StateStore($db, 'github');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to persist OAuth2 state to database');
        $store->put('state-5', 'verifier-5');
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

        $store = new DbOAuth2StateStore($db, 'github');

        $store->put('state-5', 'verifier-5');

        $this->addToAssertionCount(1); // reaching here without a throw IS the assertion
    }
}
