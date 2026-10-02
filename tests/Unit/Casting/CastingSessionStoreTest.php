<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Casting;

use PHPUnit\Framework\TestCase;
use Phlix\Casting\CastingSessionStore;
use Phlix\Casting\CastingSessionStoreInterface;
use RuntimeException;
use Workerman\MySQL\Connection;

/**
 * Device-M1: the casting-session store's SQL posture over a doubled
 * connection — statement shapes, the WriteResult predicate, the touch
 * throttle memo, the replace-race retry, and the bounded lazy sweep.
 *
 * Real-MySQL properties (NOW() defaults, the unique register, DATE_SUB clock
 * math) are the integration sibling's job
 * (`tests/Integration/Casting/CastingSessionStoreRealDbTest.php`); this file
 * pins the LOGIC: which statements run, in what order, and what each result
 * means.
 */
final class CastingSessionStoreTest extends TestCase
{
    /** @var list<array{sql: string, params: list<mixed>}> */
    private array $log = [];

    private int $clockNow = 1000;

    /**
     * Scripted Connection double: dispatch by leading keyword, scripted per
     * call, everything logged.
     *
     * @param list<int|mixed> $deleteResults successive DELETE results (sweep vs targeted)
     * @param list<mixed>     $insertResults successive INSERT results
     * @param list<mixed>     $selectResults successive SELECT results
     * @param list<int|mixed> $updateResults successive UPDATE results
     * @param list<\Throwable|null> $insertThrows throw on Nth insert (null = don't)
     * @param list<\Throwable|null> $selectThrows throw on Nth select
     */
    private function db(
        array $insertResults = [],
        array $selectResults = [],
        array $updateResults = [],
        array $deleteResults = [],
        array $insertThrows = [],
        array $selectThrows = [],
    ): Connection {
        $this->log = [];

        $db = $this->createMock(Connection::class);
        // PSR-12 one-parameter-per-line keeps the double readable: all scripted
        // streams live in one by-ref state array instead of a seven-use header.
        $state = [
            'counters' => ['insert' => 0, 'select' => 0, 'update' => 0, 'delete' => 0],
            'insertResults' => $insertResults,
            'selectResults' => $selectResults,
            'updateResults' => $updateResults,
            'deleteResults' => $deleteResults,
            'insertThrows' => $insertThrows,
            'selectThrows' => $selectThrows,
        ];
        $query = function (string $sql, ?array $params = null) use (&$state): mixed {
            $params ??= [];
            $kind = strtolower(strtok($sql, " \n(") ?: '');
            $this->log[] = ['sql' => $sql, 'params' => $params];

            $index = $state['counters'][$kind] ?? 0;
            $state['counters'][$kind] = $index + 1;

            if ($kind === 'insert' && isset($state['insertThrows'][$index])) {
                throw $state['insertThrows'][$index];
            }
            if ($kind === 'select' && isset($state['selectThrows'][$index])) {
                throw $state['selectThrows'][$index];
            }

            return match ($kind) {
                'insert' => $state['insertResults'][$index] ?? null,
                'select' => $state['selectResults'][$index] ?? [],
                'update' => $state['updateResults'][$index] ?? 0,
                'delete' => $state['deleteResults'][$index] ?? 0,
                default => null,
            };
        };
        $db->method('query')->willReturnCallback($query);

        return $db;
    }

    private function store(Connection $db): CastingSessionStore
    {
        return new CastingSessionStore($db, 60, 86400, function (): int {
            return $this->clockNow;
        });
    }

    /** @param array<string, mixed> $state */
    private static function row(string $sessionId = 'sess-1', array $state = ['k' => 'v']): array
    {
        return [
            'session_id' => $sessionId,
            'type' => 'playto',
            'device_id' => 'dev-1',
            'user_id' => 'user-a',
            'state' => json_encode($state),
            'created_at' => '2026-10-01 00:00:00',
            'last_seen_at' => '2026-10-01 00:00:00',
        ];
    }

    // -----------------------------------------------------------------
    // insert()
    // -----------------------------------------------------------------

    public function testInsertSweepsThenWritesTheFullRowShape(): void
    {
        $db = $this->db(insertResults: ['0']);
        $store = $this->store($db);

        self::assertTrue($store->insert('sess-1', 'playto', 'dev-1', 'user-a', ['a' => 1]));

        self::assertCount(2, $this->log);
        self::assertStringStartsWith('DELETE', $this->log[0]['sql']);
        $horizon = 'last_seen_at < DATE_SUB(NOW(), INTERVAL 86400 SECOND) LIMIT 200';
        self::assertStringContainsString($horizon, $this->log[0]['sql']);

        self::assertStringStartsWith('INSERT', $this->log[1]['sql']);
        self::assertSame(
            ['sess-1', 'playto', 'dev-1', 'user-a', '{"a":1}'],
            $this->log[1]['params'],
            'all five identity/payload columns are bound; both timestamps ride NOW()',
        );
        self::assertStringContainsString('NOW(), NOW()', $this->log[1]['sql']);
    }

    public function testInsertFailsOnUnencodableStateWithoutTouchingTheDatabase(): void
    {
        $db = $this->db();
        $store = $this->store($db);

        // Only the sweep runs; the JSON guard short-circuits before the INSERT.
        self::assertFalse($store->insert('sess-1', 'playto', 'dev-1', 'user-a', ['x' => NAN]));
        self::assertCount(1, $this->log, 'no INSERT was attempted for a mangled payload');
        self::assertStringStartsWith('DELETE', $this->log[0]['sql']);
    }

    public function testInsertTreatsNullResultAsUnwritten(): void
    {
        $store = $this->store($this->db(insertResults: [null]));

        // WriteResult doctrine: a null insert result means nothing was written.
        self::assertFalse($store->insert('s', 'playto', 'd', 'u', []));
    }

    public function testInsertRetriesOnceThroughDeleteByDeviceOnThrow(): void
    {
        $db = $this->db(
            insertResults: ['unused-throws-first', '0'],
            selectResults: [['session_id' => 'old-sess']],
            insertThrows: [new RuntimeException('1062 duplicate')],
        );
        $store = $this->store($db);

        self::assertTrue($store->insert('new-sess', 'playto', 'dev-1', 'user-b', ['a' => 1]));

        $kinds = array_map(fn (array $e): string => strtolower(strtok($e['sql'], ' ') ?: ''), $this->log);
        self::assertSame(['delete', 'insert', 'select', 'delete', 'insert'], $kinds);
        // Retry's deleteByDevice: memo lookup by device key, then the scoped DELETE, then the INSERT again.
        self::assertSame(['playto', 'dev-1'], $this->log[2]['params']);
        self::assertSame(['playto', 'dev-1'], $this->log[3]['params']);
        self::assertSame(['new-sess', 'playto', 'dev-1', 'user-b', '{"a":1}'], $this->log[4]['params']);
    }

    public function testInsertFailsClosedWhenTheRetryAlsoThrows(): void
    {
        $db = $this->db(
            insertThrows: [
                new RuntimeException('1062'),
                new RuntimeException('still gone'),
            ],
            selectThrows: [new RuntimeException('lookup failed')],
        );
        $store = $this->store($db);

        self::assertFalse($store->insert('s', 'playto', 'd', 'u', []));
    }

    // -----------------------------------------------------------------
    // find()
    // -----------------------------------------------------------------

    public function testFindSweepsFirstAndHydratesTheRow(): void
    {
        $db = $this->db(selectResults: [[self::row()]]);
        $store = $this->store($db);

        $record = $store->find('playto', 'dev-1');

        self::assertNotNull($record);
        self::assertSame('sess-1', $record->sessionId);
        self::assertSame(CastingSessionStoreInterface::TYPE_PLAYTO, $record->type);
        self::assertSame(['k' => 'v'], $record->state);
        self::assertSame('user-a', $record->userId);

        $kinds = array_map(fn (array $e): string => strtolower(strtok($e['sql'], ' ') ?: ''), $this->log);
        self::assertSame(['delete', 'select'], $kinds, 'the lazy sweep rides every consult');
    }

    public function testFindReturnsNullOnNoRows(): void
    {
        $store = $this->store($this->db(selectResults: [[]]));
        self::assertNull($store->find('playto', 'dev-1'));
    }

    public function testFindThrowsOnCorruptStatePayload(): void
    {
        $row = self::row();
        $row['state'] = 'this is not json';
        $store = $this->store($this->db(selectResults: [[$row]]));

        $this->expectException(RuntimeException::class);
        $store->find('playto', 'dev-1');
    }

    // -----------------------------------------------------------------
    // touch() throttling
    // -----------------------------------------------------------------

    public function testTouchWritesOncePerWindowAndMemoisesTheRest(): void
    {
        $db = $this->db(updateResults: [1, 1]);
        $store = $this->store($db);

        $this->clockNow = 1000;
        self::assertTrue($store->touch('sess-1'));

        $this->clockNow = 1030;
        self::assertTrue($store->touch('sess-1'), 'inside the 60s window the memo answers without a write');

        $this->clockNow = 1061;
        self::assertTrue($store->touch('sess-1'));

        $writes = array_values(array_filter($this->log, fn (array $e): bool => str_starts_with($e['sql'], 'UPDATE')));
        self::assertCount(2, $writes, '30s later is memoised, 61s later is a real stamp');
        self::assertSame(['sess-1'], $writes[0]['params']);
        self::assertStringContainsString('last_seen_at = NOW()', $writes[0]['sql']);
    }

    public function testTouchZeroRowsFalseAndDropsTheMemo(): void
    {
        $db = $this->db(updateResults: [0, 1]);
        $store = $this->store($db);

        $this->clockNow = 1000;
        self::assertFalse($store->touch('sess-gone'), 'affected=0 is the "row replaced or swept" signal');

        // The memo was dropped on the miss: an immediate retry writes again.
        self::assertTrue($store->touch('sess-gone'));
        $writes = array_values(array_filter($this->log, fn (array $e): bool => str_starts_with($e['sql'], 'UPDATE')));
        self::assertCount(2, $writes);
    }

    public function testTouchZeroAffectedSameSecondDisambiguatesViaExistenceRead(): void
    {
        // The real-DB venue caught this: a same-second re-touch changes zero
        // rows (identical DATETIME) but the row is ALIVE. The store must pay
        // one existence read and keep the session, not signal eviction.
        $db = $this->db(updateResults: [0], selectResults: [[[1 => 1]]]);
        $store = $this->store($db);

        $this->clockNow = 1000;
        self::assertTrue($store->touch('sess-live'));

        $this->clockNow = 1010;
        self::assertTrue($store->touch('sess-live'), 'the memo was stamped by the disambiguated touch');

        $writes = array_values(array_filter($this->log, fn (array $e): bool => str_starts_with($e['sql'], 'UPDATE')));
        self::assertCount(1, $writes, 'window-internal repeats never reach the database again');
        $reads = array_values(array_filter($this->log, fn (array $e): bool => str_starts_with($e['sql'], 'SELECT 1')));
        self::assertCount(1, $reads, 'exactly one disambiguation read, on the rare zero-affected path');
    }

    public function testDeleteDropsTheTouchMemo(): void
    {
        $db = $this->db(updateResults: [1, 1], deleteResults: [1]);
        $store = $this->store($db);

        $this->clockNow = 1000;
        self::assertTrue($store->touch('sess-1'));
        $store->delete('sess-1');

        // Same instant, but the memo is gone — a recycled id cannot inherit optimism.
        self::assertTrue($store->touch('sess-1'));

        $writes = array_values(array_filter($this->log, fn (array $e): bool => str_starts_with($e['sql'], 'UPDATE')));
        self::assertCount(2, $writes);
    }

    // -----------------------------------------------------------------
    // deleteByDevice()
    // -----------------------------------------------------------------

    public function testDeleteByDeviceLooksUpTheIdForMemoHygieneThenDeletesScoped(): void
    {
        $db = $this->db(updateResults: [1, 0], selectResults: [[['session_id' => 'old-1']]], deleteResults: [1, 1]);
        $store = $this->store($db);

        $this->clockNow = 1000;
        self::assertTrue($store->touch('old-1'));

        $store->deleteByDevice('roku', 'dev-9');

        $kinds = array_map(fn (array $e): string => strtolower(strtok($e['sql'], ' ') ?: ''), $this->log);
        self::assertSame(['update', 'select', 'delete'], $kinds);
        self::assertSame(['roku', 'dev-9'], $this->log[1]['params']);
        self::assertSame(['roku', 'dev-9'], $this->log[2]['params']);
        self::assertStringContainsString('WHERE type = ? AND device_id = ?', $this->log[2]['sql']);

        // Memo dropped: the next touch of the OLD id writes again (it is gone,
        // and the UPDATE will honestly answer zero rows).
        $this->clockNow = 1005;
        self::assertFalse($store->touch('old-1'));
    }

    public function testDeleteByDeviceSwallowsMemoLookupFailure(): void
    {
        $db = $this->db(
            selectThrows: [new RuntimeException('blip')],
            deleteResults: [1],
        );
        $store = $this->store($db);

        $store->deleteByDevice('cast', 'dev-9');

        $kinds = array_map(fn (array $e): string => strtolower(strtok($e['sql'], ' ') ?: ''), $this->log);
        self::assertSame(['select', 'delete'], $kinds, 'the scoped DELETE runs even when the memo lookup blips');
    }

    // -----------------------------------------------------------------
    // findByUser() + horizon floors
    // -----------------------------------------------------------------

    public function testFindByUserHydratesEveryRow(): void
    {
        $db = $this->db(selectResults: [[self::row('s1'), self::row('s2')]]);
        $store = $this->store($db);

        $records = $store->findByUser('user-a');
        self::assertCount(2, $records);
        self::assertSame(['s1', 's2'], array_map(fn ($r): string => $r->sessionId, $records));
    }

    public function testHorizonAndWindowAreFlooredIntoTheSweepSql(): void
    {
        $db = $this->db(selectResults: [[]]);
        $store = new CastingSessionStore($db, 0, 5);

        $store->find('playto', 'dev-1');

        self::assertStringContainsString('INTERVAL 60 SECOND', $this->log[0]['sql'], 'stale horizon floors at 60s');
    }
}
