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
     * back by any subsequent SELECT (findBySessionId), so stop/getTimeShift can
     * resolve the session cross-worker. DELETE/SELECT hits are recorded.
     *
     * @param array{insert: ?array<int,mixed>, deleted: bool} $state By-ref state bag
     * @return Connection&MockObject
     */
    private function roundTripDb(array &$state): Connection
    {
        $state = ['insert' => null, 'deleted' => false];

        /** @var Connection&MockObject $db */
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use (&$state) {
                if (stripos($sql, 'INSERT') === 0) {
                    $state['insert'] = $params;
                    return null;
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
                        'pid'             => $p[4],
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
        /** @var array{insert: ?array<int,mixed>, deleted: bool} $state */
        $state = ['insert' => null, 'deleted' => false];
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

        // The session was persisted with a buffer_dir, the real pid, and active
        // status (positional store INSERT params: id, session_id, channel_id,
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
        $this->assertSame($fakePid, $insert[4]);
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
        /** @var array{insert: ?array<int,mixed>, deleted: bool} $state */
        $state = ['insert' => null, 'deleted' => false];
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
        $this->assertCount(4, $result);
        $this->assertSame(1, $recorder->getActiveTimeShiftCount());
    }

    public function testStopTimeShiftTerminatesCleansAndDeletesStore(): void
    {
        /** @var array{insert: ?array<int,mixed>, deleted: bool} $state */
        $state = ['insert' => null, 'deleted' => false];
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
        /** @var array{insert: ?array<int,mixed>, deleted: bool} $state */
        $state = ['insert' => null, 'deleted' => false];
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
}
