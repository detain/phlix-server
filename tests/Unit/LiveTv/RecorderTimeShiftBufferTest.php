<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\LiveTv;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\LiveTv\LiveTvManager;
use Phlix\LiveTv\Recorder;
use Phlix\LiveTv\TimeShift\DbTimeShiftSessionStore;
use Phlix\LiveTv\TimeShift\TimeShiftSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * SV-3.1 f-b: the Recorder maintains a REAL on-disk rolling time-shift buffer,
 * persisted via the DB-backed store so a session is resolvable across workers.
 *
 * The detached ffmpeg spawn is stubbed (only {@see Recorder::spawnTimeShiftBuffer()}
 * is doubled via the mock builder, mirroring RecordingSchedulerTest's approach)
 * so NO real ffmpeg runs in CI; the tuner URL is provided by a mock
 * {@see LiveTvManager}. Every OTHER Recorder method runs its real implementation.
 *
 * @covers \Phlix\LiveTv\Recorder::startTimeShift
 * @covers \Phlix\LiveTv\Recorder::stopTimeShift
 * @covers \Phlix\LiveTv\Recorder::getTimeShift
 *
 * @since SV-3.1 f-b
 */
final class RecorderTimeShiftBufferTest extends TestCase
{
    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storagePath = sys_get_temp_dir() . '/phlix-ts-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        // Defensive cleanup of any buffer dirs a test left behind.
        $root = $this->storagePath . '/timeshift';
        if (is_dir($root)) {
            foreach ((array) glob($root . '/*') as $dir) {
                if (is_string($dir) && is_dir($dir)) {
                    foreach ((array) glob($dir . '/*') as $f) {
                        if (is_string($f)) {
                            @unlink($f);
                        }
                    }
                    @rmdir($dir);
                }
            }
            @rmdir($root);
            @rmdir($this->storagePath);
        }
        parent::tearDown();
    }

    /**
     * Build a Recorder whose detached-ffmpeg spawn is stubbed (no real process),
     * returning $spawnPid and recording each call into $spawnCalls by reference.
     * All other methods run their real implementation.
     *
     * @param Connection $db
     * @param DbTimeShiftSessionStore $store
     * @param StructuredLogger $logger
     * @param int $spawnPid PID the stubbed spawn returns (0 = spawn failure)
     * @param list<array{url:string, dir:string}> $spawnCalls Captured spawn args (by ref)
     *
     * @return Recorder&MockObject
     */
    private function makeRecorder(
        Connection $db,
        DbTimeShiftSessionStore $store,
        StructuredLogger $logger,
        int $spawnPid,
        array &$spawnCalls
    ): Recorder {
        /** @var Recorder&MockObject $recorder */
        $recorder = $this->getMockBuilder(Recorder::class)
            ->setConstructorArgs([$db, $store, $this->storagePath, 0, $logger])
            ->onlyMethods(['spawnTimeShiftBuffer'])
            ->getMock();

        $recorder->method('spawnTimeShiftBuffer')->willReturnCallback(
            function (string $url, string $dir) use (&$spawnCalls, $spawnPid): int {
                $spawnCalls[] = ['url' => $url, 'dir' => $dir];
                return $spawnPid;
            }
        );

        return $recorder;
    }

    /**
     * A mock db that round-trips one saved session: the INSERT params are echoed
     * back by any subsequent SELECT (findBySessionId / reapBySessionId), so
     * stop/getTimeShift can resolve the session cross-worker. The two-phase write
     * that closes the orphan window is modelled faithfully — the INSERT persists a
     * NULL pid and the follow-up `UPDATE ... SET pid` records the real one, which
     * is reflected into subsequent SELECTs. DELETE/SELECT/UPDATE hits are recorded.
     *
     * @param array{insert: ?array<int,mixed>, pid_update: mixed, deleted: bool} $state By-ref state bag
     * @return Connection&MockObject
     */
    private function roundTripDb(array &$state): Connection
    {
        $state = ['insert' => null, 'pid_update' => null, 'deleted' => false];

        /** @var Connection&MockObject $db */
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use (&$state) {
                if (stripos($sql, 'INSERT') === 0) {
                    $state['insert'] = $params;
                    return null;
                }
                // updatePidBySessionId: "UPDATE ... SET pid = ? WHERE session_id = ?"
                // (params: [pid, sessionId]) — the pid is always params[0].
                if (stripos($sql, 'UPDATE') === 0 && stripos($sql, 'pid') !== false) {
                    $state['pid_update'] = $params[0];
                    return 1;
                }
                if (stripos($sql, 'SELECT') === 0) {
                    if ($state['insert'] === null) {
                        return [];
                    }
                    $p = $state['insert'];
                    return [[
                        'id'              => $p[0],
                        'session_id'      => $p[1],
                        'channel_id'      => $p[2],
                        'buffer_dir'      => $p[3],
                        // Reflect the two-phase write: the recorded pid wins.
                        'pid'             => $state['pid_update'] ?? $p[4],
                        'buffer_start_at' => $p[5],
                        'buffer_end_at'   => $p[6],
                        'window_seconds'  => $p[7],
                        'cursor_position' => $p[8],
                        'status'          => $p[9],
                    ]];
                }
                if (stripos($sql, 'DELETE') === 0) {
                    $state['deleted'] = true;
                    return 1;
                }
                return null;
            }
        );

        return $db;
    }

    private function managerReturning(string $url): LiveTvManager
    {
        /** @var LiveTvManager&MockObject $manager */
        $manager = $this->createMock(LiveTvManager::class);
        $manager->method('buildStreamUrlForChannel')->willReturn($url);
        return $manager;
    }

    public function testStartTimeShiftSpawnsCaptureAndPersistsSession(): void
    {
        /** @var array{insert: ?array<int,mixed>, pid_update: mixed, deleted: bool} $state */
        $state = ['insert' => null, 'pid_update' => null, 'deleted' => false];
        $db = $this->roundTripDb($state);
        $logger = $this->createMock(StructuredLogger::class);
        $store = new DbTimeShiftSessionStore($db);

        /** @var list<array{url:string, dir:string}> $spawnCalls */
        $spawnCalls = [];
        $fakePid = 2_000_000_002;
        $recorder = $this->makeRecorder($db, $store, $logger, $fakePid, $spawnCalls);
        $recorder->setLiveTvManager($this->managerReturning('http://tuner.local/ch1'));

        $result = $recorder->startTimeShift('sess-1', 'ch-1');

        // A capture was spawned with the resolved tuner URL into a per-session
        // buffer dir under <storage>/timeshift/.
        $this->assertCount(1, $spawnCalls);
        $this->assertSame('http://tuner.local/ch1', $spawnCalls[0]['url']);
        $this->assertStringStartsWith($this->storagePath . '/timeshift/', $spawnCalls[0]['dir']);

        // Buffer dir was created on disk.
        $this->assertDirectoryExists($spawnCalls[0]['dir']);

        // The session was persisted with a buffer_dir and active status
        // (positional store INSERT params: id, session_id, channel_id,
        // buffer_dir, pid, buffer_start_at, buffer_end_at, window_seconds,
        // cursor_position, status).
        $this->assertNotNull($state['insert']);
        $insert = $state['insert'];
        $this->assertSame('sess-1', $insert[1]);
        $this->assertSame('ch-1', $insert[2]);
        $this->assertIsString($insert[3]);
        $this->assertStringStartsWith(
            $this->storagePath . '/timeshift/',
            is_string($insert[3]) ? $insert[3] : ''
        );
        // Orphan-window fix: the row is persisted FIRST with a NULL pid, then the
        // real capture pid is recorded via a follow-up updatePid() write.
        $this->assertNull($insert[4], 'persist-first: INSERT records a NULL pid before the spawn');
        $this->assertSame($fakePid, $state['pid_update'], 'real pid recorded via a follow-up updatePid()');
        $this->assertSame(Recorder::TIMESHIFT_BUFFER_SECONDS, $insert[7]);
        $this->assertSame(TimeShiftSession::STATUS_ACTIVE, $insert[9]);

        // Return contract is unchanged (4 keys).
        $this->assertCount(4, $result);
        $this->assertArrayHasKey('time_shift_id', $result);
        $this->assertArrayHasKey('stream_url', $result);
        $this->assertSame('/livetv/timeshift/sess-1/stream', $result['stream_url']);

        // Same-worker fast path recorded it.
        $this->assertSame(1, $recorder->getActiveTimeShiftCount());
    }

    public function testStartTimeShiftWithoutTunerPersistsNullPidAndDoesNotSpawn(): void
    {
        /** @var array{insert: ?array<int,mixed>, pid_update: mixed, deleted: bool} $state */
        $state = ['insert' => null, 'pid_update' => null, 'deleted' => false];
        $db = $this->roundTripDb($state);
        $logger = $this->createMock(StructuredLogger::class);
        $store = new DbTimeShiftSessionStore($db);

        /** @var list<array{url:string, dir:string}> $spawnCalls */
        $spawnCalls = [];
        // No LiveTvManager wired -> resolveTunerStreamUrl() returns null.
        $recorder = $this->makeRecorder($db, $store, $logger, 4242, $spawnCalls);

        $result = $recorder->startTimeShift('sess-2', 'ch-2');

        $this->assertSame([], $spawnCalls, 'no tuner => no spawn');
        $this->assertNotNull($state['insert']);
        $this->assertNull($state['insert'][4], 'pid persisted as NULL when no capture');
        $this->assertNull($state['pid_update'], 'no updatePid() when nothing was spawned');
        $this->assertCount(4, $result);
        $this->assertSame(1, $recorder->getActiveTimeShiftCount());
    }

    public function testStopTimeShiftTerminatesCleansAndDeletesStore(): void
    {
        /** @var array{insert: ?array<int,mixed>, pid_update: mixed, deleted: bool} $state */
        $state = ['insert' => null, 'pid_update' => null, 'deleted' => false];
        $db = $this->roundTripDb($state);
        $logger = $this->createMock(StructuredLogger::class);
        $store = new DbTimeShiftSessionStore($db);

        /** @var list<array{url:string, dir:string}> $spawnCalls */
        $spawnCalls = [];
        // A pid that is definitely not alive on this host, so terminateRecording()
        // resolves instantly and never kills a real process.
        $recorder = $this->makeRecorder($db, $store, $logger, 2_000_000_002, $spawnCalls);
        $recorder->setLiveTvManager($this->managerReturning('http://tuner.local/ch3'));

        $recorder->startTimeShift('sess-3', 'ch-3');
        $bufferDir = $spawnCalls[0]['dir'];
        $this->assertDirectoryExists($bufferDir);

        $stopped = $recorder->stopTimeShift('sess-3');

        $this->assertTrue($stopped);
        $this->assertDirectoryDoesNotExist($bufferDir, 'buffer dir removed on stop');
        $this->assertTrue($state['deleted'], 'store session deleted on stop');
        $this->assertSame(0, $recorder->getActiveTimeShiftCount());
    }

    public function testStopTimeShiftIsFailureSafeWhenBufferDirAlreadyGone(): void
    {
        /** @var array{insert: ?array<int,mixed>, pid_update: mixed, deleted: bool} $state */
        $state = ['insert' => null, 'pid_update' => null, 'deleted' => false];
        $db = $this->roundTripDb($state);
        $logger = $this->createMock(StructuredLogger::class);
        $store = new DbTimeShiftSessionStore($db);

        /** @var list<array{url:string, dir:string}> $spawnCalls */
        $spawnCalls = [];
        $recorder = $this->makeRecorder($db, $store, $logger, 2_000_000_002, $spawnCalls);
        $recorder->setLiveTvManager($this->managerReturning('http://tuner.local/ch4'));

        $recorder->startTimeShift('sess-4', 'ch-4');
        $bufferDir = $spawnCalls[0]['dir'];

        // Simulate the buffer dir having already been reclaimed.
        @rmdir($bufferDir);
        $this->assertDirectoryDoesNotExist($bufferDir);

        // Must not throw and must still tear the session down.
        $stopped = $recorder->stopTimeShift('sess-4');
        $this->assertTrue($stopped);
        $this->assertTrue($state['deleted']);
        $this->assertSame(0, $recorder->getActiveTimeShiftCount());
    }

    public function testStopTimeShiftReturnsFalseWhenNeitherInMemoryNorStore(): void
    {
        /** @var Connection&MockObject $db */
        $db = $this->createMock(Connection::class);
        // Every SELECT returns no rows -> no store session.
        $db->method('query')->willReturn([]);
        $logger = $this->createMock(StructuredLogger::class);
        $store = new DbTimeShiftSessionStore($db);

        /** @var list<array{url:string, dir:string}> $spawnCalls */
        $spawnCalls = [];
        $recorder = $this->makeRecorder($db, $store, $logger, 0, $spawnCalls);

        $this->assertFalse($recorder->stopTimeShift('never-existed'));
    }

    public function testGetTimeShiftFallsBackToStoreForCrossWorkerSession(): void
    {
        // A session that exists ONLY in the store (started on another worker):
        // no in-memory entry on THIS recorder.
        $bufferDir = $this->storagePath . '/timeshift/abc-123';

        /** @var Connection&MockObject $db */
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([[
            'id'              => 'abc-123',
            'session_id'      => 'sess-remote',
            'channel_id'      => 'ch-remote',
            'buffer_dir'      => $bufferDir,
            'pid'             => 5150,
            'buffer_start_at' => 1_700_000_000,
            'buffer_end_at'   => 1_700_000_100,
            'window_seconds'  => Recorder::TIMESHIFT_BUFFER_SECONDS,
            'cursor_position' => 42,
            'status'          => TimeShiftSession::STATUS_ACTIVE,
        ]]);
        $logger = $this->createMock(StructuredLogger::class);
        $store = new DbTimeShiftSessionStore($db);

        /** @var list<array{url:string, dir:string}> $spawnCalls */
        $spawnCalls = [];
        $recorder = $this->makeRecorder($db, $store, $logger, 0, $spawnCalls);

        $timeShift = $recorder->getTimeShift('sess-remote');

        $this->assertIsArray($timeShift);
        $this->assertSame('abc-123', $timeShift['id']);
        $this->assertSame($bufferDir, $timeShift['buffer_dir']);
        $this->assertSame(5150, $timeShift['pid']);
        $this->assertSame(42, $timeShift['current_position']);
        $this->assertSame(1_700_000_000, $timeShift['buffer_start']);
        $this->assertSame(1_700_000_100, $timeShift['buffer_end']);
    }

    public function testGetTimeShiftReturnsNullForStoppedStoreSession(): void
    {
        /** @var Connection&MockObject $db */
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([[
            'id'              => 'z-1',
            'session_id'      => 'sess-stopped',
            'channel_id'      => 'ch-x',
            'buffer_dir'      => '/tmp/none',
            'pid'             => null,
            'buffer_start_at' => 1,
            'buffer_end_at'   => 2,
            'window_seconds'  => Recorder::TIMESHIFT_BUFFER_SECONDS,
            'cursor_position' => 0,
            'status'          => TimeShiftSession::STATUS_STOPPED,
        ]]);
        $logger = $this->createMock(StructuredLogger::class);
        $store = new DbTimeShiftSessionStore($db);

        /** @var list<array{url:string, dir:string}> $spawnCalls */
        $spawnCalls = [];
        $recorder = $this->makeRecorder($db, $store, $logger, 0, $spawnCalls);

        $this->assertNull($recorder->getTimeShift('sess-stopped'));
    }

    /**
     * FINDING 1 (concurrency, goal b): two concurrent starts for one session_id
     * must NOT leave two live captures. The loser of the exclusive UNIQUE(session_id)
     * claim must abort WITHOUT spawning a second ffmpeg (and without creating a
     * second untracked buffer dir).
     *
     * Modelled at the store seam: the reap SELECT at the top of startTimeShift sees
     * nothing (the winner had not claimed yet at reap time — the reachable
     * "both reap first, then race the claim" interleaving), then the loser's plain
     * INSERT collides on the UNIQUE(session_id) (the winner claimed in between) so
     * the driver raises a duplicate-key error and every subsequent lookup resolves
     * the winner's row.
     *
     * MUTATION GUARD: reverting startTimeShift's claim() back to the silent save()
     * upsert (+ always-spawn) makes the loser spawn a second capture — flipping the
     * `$spawnCalls === []` assertion RED.
     */
    public function testConcurrentDuplicateStartLosesClaimAndDoesNotSpawn(): void
    {
        $winnerRow = [
            'id'              => 'winner-id',
            'session_id'      => 'sess-race',
            'channel_id'      => 'ch-race',
            'buffer_dir'      => $this->storagePath . '/timeshift/winner-id',
            'pid'             => 9999,
            'buffer_start_at' => 1_700_000_000,
            'buffer_end_at'   => 1_700_000_050,
            'window_seconds'  => Recorder::TIMESHIFT_BUFFER_SECONDS,
            'cursor_position' => 0,
            'status'          => TimeShiftSession::STATUS_ACTIVE,
        ];

        $winnerVisible = false;

        /** @var Connection&MockObject $db */
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use (&$winnerVisible, $winnerRow) {
                if (stripos($sql, 'INSERT') === 0) {
                    // A concurrent worker won the UNIQUE(session_id) claim first.
                    $winnerVisible = true;
                    throw new \RuntimeException(
                        "Duplicate entry 'sess-race' for key 'uq_session_id'"
                    );
                }
                if (stripos($sql, 'SELECT') === 0) {
                    // Reap (pre-INSERT) sees nothing; post-collision lookups resolve
                    // the winner.
                    return $winnerVisible ? [$winnerRow] : [];
                }
                // No DELETE/UPDATE should fire on the loser path; return benign.
                return 1;
            }
        );

        $logger = $this->createMock(StructuredLogger::class);
        $store = new DbTimeShiftSessionStore($db);

        /** @var list<array{url:string, dir:string}> $spawnCalls */
        $spawnCalls = [];
        $recorder = $this->makeRecorder($db, $store, $logger, 4242, $spawnCalls);
        $recorder->setLiveTvManager($this->managerReturning('http://tuner.local/race'));

        $result = $recorder->startTimeShift('sess-race', 'ch-race');

        // Goal (b): the loser did NOT spawn a second capture...
        $this->assertSame([], $spawnCalls, 'lost claim must not spawn a second ffmpeg');
        // ...nor create a second untracked buffer dir (mkdir is after a won claim)...
        $this->assertDirectoryDoesNotExist(
            $this->storagePath . '/timeshift',
            'lost claim must not create a buffer dir'
        );
        // ...nor add an in-memory fast-path entry on this worker.
        $this->assertSame(0, $recorder->getActiveTimeShiftCount());

        // The caller still gets a usable response pointing at the winner's session.
        $this->assertSame('/livetv/timeshift/sess-race/stream', $result['stream_url']);
        $this->assertSame('winner-id', $result['time_shift_id']);
        $this->assertSame(1_700_000_000, $result['buffer_start']);
        $this->assertSame(1_700_000_050, $result['buffer_end']);
    }

    /**
     * FINDING 1 (persist correctness, goal a): the real capture pid is recorded on
     * the row keyed by SESSION_ID, so it always lands on the row
     * findBySessionId()/getTimeShift() returns — never on a transient row id the
     * upsert may have discarded (which would match zero rows and orphan the tuner).
     *
     * MUTATION GUARD: reverting updatePidBySessionId($sessionId,$pid) back to the
     * id-keyed updatePid($timeShiftId,$pid) changes the WHERE clause to `id = ?`
     * and binds the fresh time-shift id — flipping the assertions below RED.
     */
    public function testCapturePidRecordedOnSessionIdKeyedRow(): void
    {
        $insertParams = null;
        $pidUpdateSql = null;
        $pidUpdateParams = null;

        /** @var Connection&MockObject $db */
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use (&$insertParams, &$pidUpdateSql, &$pidUpdateParams) {
                if (stripos($sql, 'INSERT') === 0) {
                    $insertParams = $params;
                    return null;
                }
                if (stripos($sql, 'UPDATE') === 0 && stripos($sql, 'pid') !== false) {
                    $pidUpdateSql = $sql;
                    $pidUpdateParams = $params;
                    return 1;
                }
                if (stripos($sql, 'SELECT') === 0) {
                    return $insertParams === null ? [] : [];
                }
                return null;
            }
        );

        $logger = $this->createMock(StructuredLogger::class);
        $store = new DbTimeShiftSessionStore($db);

        /** @var list<array{url:string, dir:string}> $spawnCalls */
        $spawnCalls = [];
        $fakePid = 1_234_567;
        $recorder = $this->makeRecorder($db, $store, $logger, $fakePid, $spawnCalls);
        $recorder->setLiveTvManager($this->managerReturning('http://tuner.local/pid'));

        $recorder->startTimeShift('sess-pid', 'ch-pid');

        $this->assertCount(1, $spawnCalls, 'winner spawns exactly one capture');
        $this->assertNotNull($pidUpdateParams, 'the capture pid IS persisted');
        $this->assertIsString($pidUpdateSql);

        // The pid write is keyed on session_id, not the transient row id.
        $this->assertStringContainsString('session_id = ?', (string) $pidUpdateSql);
        $this->assertStringNotContainsString('WHERE id = ?', (string) $pidUpdateSql);

        // params: [pid, sessionId] — the WHERE key is the SESSION id ('sess-pid'),
        // NOT the fresh time-shift row id the INSERT generated. ($pidUpdateParams is
        // already proven a non-null array by assertNotNull above.)
        $this->assertSame($fakePid, $pidUpdateParams[0]);
        $this->assertSame('sess-pid', $pidUpdateParams[1], 'pid write keyed on session_id');
        $this->assertIsArray($insertParams);
        $this->assertNotSame(
            $insertParams[0],
            $pidUpdateParams[1],
            'pid write must NOT key on the transient row id'
        );
    }

    /**
     * FINDING 2 (cross-worker stale read): an in-memory fast-path entry whose
     * buffer_dir has been reclaimed by a restart on another worker must be
     * invalidated so getTimeShift() falls through to the authoritative store's live
     * entry — rather than serving the deleted dir forever on this worker.
     *
     * MUTATION GUARD: reverting the is_dir() self-validation (unconditional
     * fast-path return) makes getTimeShift return the stale in-memory dir1 —
     * flipping the buffer_dir assertion RED.
     */
    public function testGetTimeShiftInvalidatesStaleInMemoryEntryAndFallsThroughToStore(): void
    {
        $staleDir = $this->storagePath . '/timeshift/gone-dir1';   // never created
        $liveDir  = $this->storagePath . '/timeshift/live-dir2';
        @mkdir($liveDir, 0755, true);

        /** @var Connection&MockObject $db */
        $db = $this->createMock(Connection::class);
        // The store (source of truth) resolves the restarted session at dir2.
        $db->method('query')->willReturn([[
            'id'              => 'live-2',
            'session_id'      => 'sess-restart',
            'channel_id'      => 'ch-r',
            'buffer_dir'      => $liveDir,
            'pid'             => 7000,
            'buffer_start_at' => 1_700_000_500,
            'buffer_end_at'   => 1_700_000_600,
            'window_seconds'  => Recorder::TIMESHIFT_BUFFER_SECONDS,
            'cursor_position' => 10,
            'status'          => TimeShiftSession::STATUS_ACTIVE,
        ]]);
        $logger = $this->createMock(StructuredLogger::class);
        $store = new DbTimeShiftSessionStore($db);

        /** @var list<array{url:string, dir:string}> $spawnCalls */
        $spawnCalls = [];
        $recorder = $this->makeRecorder($db, $store, $logger, 0, $spawnCalls);

        // Seed a STALE in-memory entry pointing at the now-deleted dir1.
        $this->injectInMemory($recorder, 'sess-restart', [
            'id'               => 'stale-1',
            'session_id'       => 'sess-restart',
            'channel_id'       => 'ch-r',
            'started_at'       => 1_700_000_000,
            'buffer_start'     => 1_700_000_000,
            'buffer_end'       => 1_700_000_000,
            'buffer_dir'       => $staleDir,
            'pid'              => 6000,
            'current_position' => 1_700_000_000,
        ]);

        $timeShift = $recorder->getTimeShift('sess-restart');

        $this->assertIsArray($timeShift);
        // Fell through to the live store entry (dir2), NOT the stale in-memory dir1.
        $this->assertSame($liveDir, $timeShift['buffer_dir']);
        $this->assertSame('live-2', $timeShift['id']);
        $this->assertSame(7000, $timeShift['pid']);
        // The stale entry was invalidated.
        $this->assertSame(0, $recorder->getActiveTimeShiftCount());
    }

    /**
     * FINDING 2 (fast-path preserved): a VALID in-memory entry (its buffer_dir
     * still exists) is served from memory WITHOUT a store/DB read — so the common
     * case does not incur a DB query per segment.
     */
    public function testGetTimeShiftPrefersValidInMemoryFastPathWithoutHittingStore(): void
    {
        $liveDir = $this->storagePath . '/timeshift/fresh-dir';
        @mkdir($liveDir, 0755, true);

        /** @var Connection&MockObject $db */
        $db = $this->createMock(Connection::class);
        // The fast path must not query the store when the in-memory dir is valid.
        $db->expects($this->never())->method('query');
        $logger = $this->createMock(StructuredLogger::class);
        $store = new DbTimeShiftSessionStore($db);

        /** @var list<array{url:string, dir:string}> $spawnCalls */
        $spawnCalls = [];
        $recorder = $this->makeRecorder($db, $store, $logger, 0, $spawnCalls);

        $this->injectInMemory($recorder, 'sess-fast', [
            'id'               => 'fast-1',
            'session_id'       => 'sess-fast',
            'channel_id'       => 'ch-f',
            'started_at'       => 1_700_000_000,
            'buffer_start'     => 1_700_000_000,
            'buffer_end'       => 1_700_000_000,
            'buffer_dir'       => $liveDir,
            'pid'              => 8000,
            'current_position' => 1_700_000_000,
        ]);

        $timeShift = $recorder->getTimeShift('sess-fast');

        $this->assertIsArray($timeShift);
        $this->assertSame($liveDir, $timeShift['buffer_dir']);
        $this->assertSame('fast-1', $timeShift['id']);
        $this->assertSame(1, $recorder->getActiveTimeShiftCount());
    }

    /**
     * Seed a Recorder's private $activeTimeShifts fast-path map for a session.
     *
     * @param array<string, mixed> $entry
     */
    private function injectInMemory(Recorder $recorder, string $sessionId, array $entry): void
    {
        $ref = new \ReflectionProperty(Recorder::class, 'activeTimeShifts');
        $ref->setAccessible(true);
        /** @var array<string, array<string, mixed>> $current */
        $current = $ref->getValue($recorder);
        $current[$sessionId] = $entry;
        $ref->setValue($recorder, $current);
    }
}
