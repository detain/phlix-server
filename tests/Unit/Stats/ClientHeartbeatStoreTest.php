<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Stats;

use Phlix\Stats\ClientHeartbeatStore;
use Phlix\Stats\ClientHeartbeatStoreInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Workerman\MySQL\Connection;

/**
 * S518 (AD-27): the telemetry landing's PHP-side posture — bounded inputs at
 * the door, upsert-on-PK SQL shape (the live UPSERT semantics belong to the
 * real-MySQL sibling), and `record()`'s absolute no-throw guarantee.
 */
final class ClientHeartbeatStoreTest extends TestCase
{
    public function testInterfaceCarriesTheColumnWidthContract(): void
    {
        // The migration file is the schema authority; if a column width ever
        // moves, these interface constants must move with it in the same commit
        // — this pin is what forces that coupling to be noticed.
        self::assertSame(64, ClientHeartbeatStoreInterface::MAX_INSTANCE_ID_LENGTH);
        self::assertSame(32, ClientHeartbeatStoreInterface::MAX_VERSION_LENGTH);
        self::assertSame(32, ClientHeartbeatStoreInterface::MAX_CLIENT_TYPE_LENGTH);
        self::assertSame(64, ClientHeartbeatStoreInterface::MAX_BUILD_TOKEN_LENGTH);
    }

    public function testRecordBuildsTheBoundedUpsert(): void
    {
        $captured = [];
        $db = $this->db(captured: $captured, insertResult: 1);
        $store = new ClientHeartbeatStore($db);

        self::assertTrue($store->record('inst-1', '1.2.3', 'tizen', 'bt'));
        self::assertStringContainsString('INSERT INTO client_heartbeats', $captured['sql']);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $captured['sql']);
        // first_seen_at is set only on the INSERT arm — never refreshed on update.
        self::assertStringNotContainsString('first_seen_at = VALUES(', $captured['sql']);
        self::assertStringContainsString('last_seen_at = NOW()', $captured['sql']);
        self::assertSame(['inst-1', '1.2.3', 'tizen', 'bt'], $captured['params']);
    }

    public function testRecordRejectsOutOfRangeInputsBeforeSql(): void
    {
        $db = $this->db(neverReached: true);
        $store = new ClientHeartbeatStore($db);

        self::assertFalse($store->record('', 'v', 't', ''));
        self::assertFalse($store->record(str_repeat('x', 65), 'v', 't', ''));
        self::assertFalse($store->record('inst', str_repeat('v', 33), 't', ''));
        self::assertFalse($store->record('inst', 'v', str_repeat('t', 33), ''));
        self::assertFalse($store->record('inst', 'v', 't', str_repeat('b', 65)));
    }

    public function testRecordMapsWriteNothingToFalseAndSwallowsThrows(): void
    {
        $nullDb = $this->db(insertResult: null);
        self::assertFalse((new ClientHeartbeatStore($nullDb))->record('i', 'v', 't', ''));

        $throwing = $this->db(throwOnQuery: true);
        // The swallow contract: a MySQL error NEVER escapes to the caller — the
        // client's consented tick gets its honest recorded:false instead.
        self::assertFalse((new ClientHeartbeatStore($throwing))->record('i', 'v', 't', ''));
    }

    public function testRecentClampsThePage(): void
    {
        $captured = [];
        $db = $this->db(selectRows: [['instance_id' => 'a']], captured: $captured);
        $store = new ClientHeartbeatStore($db);

        self::assertSame([['instance_id' => 'a']], $store->recent(5000));
        self::assertSame(1000, $captured['params'][0], 'clamped to the hard page ceiling');
        self::assertSame([['instance_id' => 'a']], $store->recent(0));
        self::assertSame(1, $captured['params'][0], 'clamped to at least one');
    }

    public function testReadHelpersFailSoftToEmptyCensus(): void
    {
        $throwing = $this->db(throwOnQuery: true);
        $store = new ClientHeartbeatStore($throwing);

        self::assertSame([], $store->recent());
        self::assertSame(0, $store->countInstances());
    }

    public function testCountInstancesReadsTheTotalColumn(): void
    {
        $db = $this->db(selectRows: [['total' => 7]]);
        self::assertSame(7, (new ClientHeartbeatStore($db))->countInstances());
    }

    /**
     * Optional keys: callers initialise with `[]` (psalm's array<never,never> is
     * not a subtype of a required-key shape), and the double fills the full
     * {sql, params} pair on the statement it captures.
     *
     * @param array{sql?: string, params?: list<mixed>}|null $captured
     */
    private function db(
        mixed $insertResult = 1,
        array $selectRows = [],
        bool $throwOnQuery = false,
        bool $neverReached = false,
        ?array &$captured = null,
    ): Connection {
        $db = $this->createMock(Connection::class);
        if ($neverReached) {
            $db->expects(self::never())->method('query');
            return $db;
        }

        $db->method('query')->willReturnCallback(
            /** @param list<mixed>|null $params */
            function (
                string $sql,
                ?array $params = null
            ) use (
                $insertResult,
                $selectRows,
                $throwOnQuery,
                &$captured
            ): mixed {
                if ($throwOnQuery) {
                    throw new RuntimeException('mysql down');
                }
                if ($captured !== null) {
                    $captured = ['sql' => $sql, 'params' => $params ?? []];
                }
                $kind = strtolower(strtok($sql, " \n(") ?: '');

                return $kind === 'select' ? $selectRows : $insertResult;
            }
        );

        return $db;
    }
}
