<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\LiveTv;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\LiveTv\Recorder;
use Phlix\LiveTv\TimeShift\DbTimeShiftSessionStore;
use Workerman\MySQL\Connection;

/**
 * SV-3.1 g (h2 tests) — DVR storage-accounting coverage.
 *
 * Sub-step g wired the DVR pre-flight/accounting onto real files but shipped
 * with zero tests. This locks in the behaviour of the storage surface on
 * {@see Recorder}:
 *   - hasRealDiskSpace()      — real disk_free_space() + the 5% safety margin,
 *                               fail-open when free space is undeterminable.
 *   - estimateRecordingSize() — the fixed ~2 MB/min pre-flight heuristic.
 *   - getUsedStorageBytes()   — SUM(storage_size) over completed recordings.
 *   - getAvailableStorageBytes() / getStorageStats() — max−used consistency.
 *   - startRecording()        — refuses to spawn when hasStorageSpace() is false.
 *
 * The DB is mocked with the PRODUCTION plain-array shape that
 * {@see Connection::query()} actually returns (RowQuery accepts it), not the
 * ResultSet cursor mock — so these tests exercise the same read path as a live
 * database.
 *
 *
 * @since SV-3.1 h2
 */
final class RecorderStorageAccountingTest extends TestCase
{
    /** ~2 MB/min expressed as bytes/second — mirrors Recorder::estimateRecordingSize(). */
    private const BYTES_PER_SECOND = 2 * 1024 * 1024 / 60;

    /** @var list<string> Temp dirs created during a test, cleaned up in tearDown(). */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }
        $this->tempDirs = [];
        parent::tearDown();
    }

    /**
     * Build a Recorder over a mocked Connection.
     *
     * @param string $storagePath      Real (or bogus) storage path.
     * @param int    $maxStorageBytes  0 = unlimited.
     */
    private function makeRecorder(
        Connection $db,
        string $storagePath,
        int $maxStorageBytes = 0
    ): Recorder {
        return new Recorder(
            $db,
            new DbTimeShiftSessionStore($db),
            $storagePath,
            $maxStorageBytes,
            $this->createMock(StructuredLogger::class)
        );
    }

    /** Create a real temp directory so disk_free_space() returns a real value. */
    private function realTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/phlix-dvr-storage-' . uniqid('', true);
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;
        return $dir;
    }

    /**
     * Invoke a private Recorder method.
     *
     * @param array<int, mixed> $args
     */
    private function invokePrivate(Recorder $recorder, string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod(Recorder::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($recorder, $args);
    }

    /**
     * A `scheduled` recording row in the livetv_recordings shape.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function scheduledRow(array $overrides = []): array
    {
        return array_merge([
            'recording_id'         => 'rec-1',
            'channel_id'           => 'ch-1',
            'program_id'           => null,
            'user_id'              => null,
            'title'                => 'Test Show',
            'description'          => null,
            'start_time'           => 1_700_000_000,
            'end_time'             => 1_700_003_600, // +1h
            'priority'             => Recorder::PRIORITY_NORMAL,
            'quality'              => 'default',
            'storage_path'         => null,
            'storage_size'         => 0,
            'status'               => Recorder::STATUS_SCHEDULED,
            'pid'                  => null,
            'error_message'        => null,
            'series_rule_id'       => null,
            'duplicate_group'      => null,
            'pre_padding_seconds'  => 60,
            'post_padding_seconds' => 60,
            'created_at'           => '2024-01-01 00:00:00',
            'updated_at'           => '2024-01-01 00:00:00',
        ], $overrides);
    }

    // ---------------------------------------------------------------------
    // estimateRecordingSize() — the ~2 MB/min heuristic
    // ---------------------------------------------------------------------

    public function testEstimateRecordingSizeUsesTwoMegabytesPerMinute(): void
    {
        $recorder = $this->makeRecorder($this->createMock(Connection::class), '/tmp/x');

        // 1 minute -> exactly 2 MiB.
        $this->assertSame(
            2 * 1024 * 1024,
            $this->invokePrivate($recorder, 'estimateRecordingSize', [0, 60])
        );
        // 30 minutes -> exactly 60 MiB.
        $this->assertSame(
            60 * 1024 * 1024,
            $this->invokePrivate($recorder, 'estimateRecordingSize', [0, 1800])
        );
        // 60 minutes -> exactly 120 MiB.
        $this->assertSame(
            120 * 1024 * 1024,
            $this->invokePrivate($recorder, 'estimateRecordingSize', [0, 3600])
        );
        // Zero duration -> zero bytes.
        $this->assertSame(
            0,
            $this->invokePrivate($recorder, 'estimateRecordingSize', [1000, 1000])
        );
    }

    // ---------------------------------------------------------------------
    // hasRealDiskSpace() — real disk_free_space() + 5% margin + fail-open
    // ---------------------------------------------------------------------

    public function testHasRealDiskSpacePassesForTinyRecordingOnRealDisk(): void
    {
        $dir = $this->realTempDir();
        $recorder = $this->makeRecorder($this->createMock(Connection::class), $dir);

        // A 1-minute (~2 MiB) recording trivially fits on any real temp volume.
        $this->assertTrue(
            $this->invokePrivate($recorder, 'hasRealDiskSpace', [0, 60])
        );
    }

    public function testHasRealDiskSpaceFailsWhenEstimateExceedsFreeSpace(): void
    {
        $dir = $this->realTempDir();
        $recorder = $this->makeRecorder($this->createMock(Connection::class), $dir);

        // A 100-year recording estimates to hundreds of TB — larger than any
        // real free space, so the check must return false.
        $hundredYearsSeconds = 100 * 365 * 24 * 3600;
        $this->assertFalse(
            $this->invokePrivate($recorder, 'hasRealDiskSpace', [0, $hundredYearsSeconds])
        );
    }

    /**
     * MUTATION GUARD (5% safety margin). Picks a recording whose estimated size
     * sits inside the band (free*0.95, free] — it does NOT fit once the 5%
     * reserve is applied, but WOULD fit without it. So:
     *   - with the margin  -> false (asserted here);
     *   - reverting *0.95 to *1.0 -> the estimate now fits -> true -> RED.
     */
    public function testHasRealDiskSpaceReservesFivePercentMargin(): void
    {
        $dir = $this->realTempDir();
        $free = @disk_free_space($dir);
        if ($free === false || $free < 1024 * 1024) {
            $this->markTestSkipped('disk_free_space() unavailable on this volume');
        }

        $recorder = $this->makeRecorder($this->createMock(Connection::class), $dir);

        // Target an estimate ~97.5% of free space: comfortably above the 95%
        // usable ceiling, comfortably below raw free space. Derive the duration
        // that produces it, then re-derive the exact estimate the code computes.
        $targetBytes = (int) ($free * 0.975);
        $durationSeconds = (int) ($targetBytes / self::BYTES_PER_SECOND);

        /** @var int $estimate */
        $estimate = $this->invokePrivate($recorder, 'estimateRecordingSize', [0, $durationSeconds]);

        // Self-check the fixture genuinely lands in the margin band, so this test
        // is a real 5%-margin guard and not an accidental pass.
        $this->assertGreaterThan(
            (int) ($free * 0.95),
            $estimate,
            'fixture must exceed the 95% usable ceiling (else it is not a margin test)'
        );
        $this->assertLessThanOrEqual(
            $free,
            $estimate,
            'fixture must fit within raw free space (else the margin is irrelevant)'
        );

        // With the 5% reserve applied, the recording does NOT fit.
        $this->assertFalse(
            $this->invokePrivate($recorder, 'hasRealDiskSpace', [0, $durationSeconds]),
            'a recording between 95% and 100% of free space is rejected by the safety margin'
        );
    }

    public function testHasRealDiskSpaceFailsOpenWhenFreeSpaceUndeterminable(): void
    {
        // A non-existent path makes disk_free_space() return false; the method
        // then FAILS OPEN (returns true) so a misconfigured mount does not block
        // every recording. This locks in that intended behaviour.
        $bogusPath = '/phlix-nonexistent-mount-' . uniqid('', true);
        $this->assertFalse(is_dir($bogusPath), 'precondition: path must not exist');

        $recorder = $this->makeRecorder($this->createMock(Connection::class), $bogusPath);

        $this->assertTrue(
            $this->invokePrivate($recorder, 'hasRealDiskSpace', [0, 3600]),
            'undeterminable free space fails open (allows the recording)'
        );
    }

    // ---------------------------------------------------------------------
    // getUsedStorageBytes() — SUM(storage_size) over completed recordings
    // ---------------------------------------------------------------------

    public function testGetUsedStorageBytesSumsCompletedRecordings(): void
    {
        $db = $this->createMock(Connection::class);

        /** @var array{sql:string,params:array<int,mixed>} $captured */
        $captured = ['sql' => '', 'params' => []];
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use (&$captured) {
                $captured = ['sql' => $sql, 'params' => $params];
                // MySQL SUM() returns a numeric STRING — model that exactly.
                return [['total' => '15728640']]; // 15 MiB
            }
        );

        $recorder = $this->makeRecorder($db, '/tmp/x');

        $this->assertSame(15728640, $recorder->getUsedStorageBytes());

        // The sum is scoped to completed recordings via SUM(storage_size).
        $this->assertStringContainsStringIgnoringCase('SUM(storage_size)', $captured['sql']);
        $this->assertSame([Recorder::STATUS_COMPLETED], $captured['params']);
    }

    public function testGetUsedStorageBytesReturnsZeroWhenNoCompletedRows(): void
    {
        // MySQL SUM() over zero rows yields a NULL total.
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['total' => null]]);

        $recorder = $this->makeRecorder($db, '/tmp/x');
        $this->assertSame(0, $recorder->getUsedStorageBytes());
    }

    public function testGetUsedStorageBytesReturnsZeroForEmptyResult(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $recorder = $this->makeRecorder($db, '/tmp/x');
        $this->assertSame(0, $recorder->getUsedStorageBytes());
    }

    // ---------------------------------------------------------------------
    // getAvailableStorageBytes() — max − used (or unlimited)
    // ---------------------------------------------------------------------

    public function testGetAvailableStorageBytesIsIntMaxWhenUnlimited(): void
    {
        $db = $this->createMock(Connection::class);
        // Unlimited: getUsedStorageBytes() must NOT even be queried.
        $db->expects($this->never())->method('query');

        $recorder = $this->makeRecorder($db, '/tmp/x', 0);
        $this->assertSame(PHP_INT_MAX, $recorder->getAvailableStorageBytes());
    }

    public function testGetAvailableStorageBytesIsMaxMinusUsed(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['total' => 40 * 1024 * 1024]]); // 40 MiB used

        $max = 100 * 1024 * 1024; // 100 MiB
        $recorder = $this->makeRecorder($db, '/tmp/x', $max);

        $this->assertSame(60 * 1024 * 1024, $recorder->getAvailableStorageBytes());
    }

    public function testGetAvailableStorageBytesClampsToZeroWhenOverBudget(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['total' => 150 * 1024 * 1024]]); // over budget

        $max = 100 * 1024 * 1024;
        $recorder = $this->makeRecorder($db, '/tmp/x', $max);

        $this->assertSame(0, $recorder->getAvailableStorageBytes(), 'never negative');
    }

    // ---------------------------------------------------------------------
    // getStorageStats() — consistency across the accounting surface
    // ---------------------------------------------------------------------

    public function testGetStorageStatsIsConsistentWithComponentAccessors(): void
    {
        $used = 30 * 1024 * 1024;
        $max = 100 * 1024 * 1024;

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, ?array $params = null) use ($used) {
                if (stripos($sql, 'GROUP BY status') !== false) {
                    return [
                        ['status' => Recorder::STATUS_COMPLETED, 'cnt' => 3],
                        ['status' => Recorder::STATUS_RECORDING, 'cnt' => 1],
                    ];
                }
                // SUM(storage_size) query (used, called by used_bytes AND available_bytes).
                return [['total' => $used]];
            }
        );

        $recorder = $this->makeRecorder($db, '/tmp/x', $max);
        $stats = $recorder->getStorageStats();

        $this->assertSame($used, $stats['used_bytes']);
        $this->assertSame($max, $stats['max_bytes']);
        // available = max − used (the invariant the two accessors must agree on).
        $this->assertSame($max - $used, $stats['available_bytes']);
        $this->assertSame(0, $stats['active_recordings']);
        $this->assertSame(0, $stats['active_timeshifts']);
        $this->assertSame(
            [Recorder::STATUS_COMPLETED => 3, Recorder::STATUS_RECORDING => 1],
            $stats['recordings_by_status']
        );
    }

    // ---------------------------------------------------------------------
    // startRecording() gates on hasStorageSpace()
    // ---------------------------------------------------------------------

    /**
     * MUTATION GUARD (the startRecording storage gate). With used storage already
     * at the cap, hasStorageSpace() is false, so startRecording() must:
     *   - return false,
     *   - mark the row FAILED with 'Insufficient storage space',
     *   - NEVER transition to `recording` (i.e. never spawn ffmpeg).
     * Removing the gate would fall through to tuner resolution and fail with a
     * DIFFERENT message ('No tuner available'), flipping the message assertion RED.
     */
    public function testStartRecordingRefusedWhenStorageInsufficient(): void
    {
        $max = 50 * 1024 * 1024; // 50 MiB cap

        /** @var list<array{sql:string,params:array<int,mixed>}> $calls */
        $calls = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use (&$calls, $max) {
                $calls[] = ['sql' => $sql, 'params' => $params];
                if (stripos($sql, 'SUM(storage_size)') !== false) {
                    // Used storage is already AT the cap -> any estimate overflows.
                    return [['total' => $max]];
                }
                if (stripos($sql, 'SELECT') === 0) {
                    return [$this->scheduledRow()];
                }
                return 1; // UPDATE affected rows
            }
        );

        // Real temp dir so hasRealDiskSpace() is NOT the limiter — the DB quota is.
        $recorder = $this->makeRecorder($db, $this->realTempDir(), $max);

        $this->assertFalse($recorder->startRecording('rec-1'));

        $updates = array_values(array_filter(
            $calls,
            static fn (array $c): bool => stripos($c['sql'], 'UPDATE') === 0
        ));
        $this->assertCount(1, $updates, 'exactly one status UPDATE (the FAILED write)');
        $this->assertContains(
            Recorder::STATUS_FAILED,
            $updates[0]['params'],
            'the recording is marked failed'
        );
        $this->assertContains(
            'Insufficient storage space',
            $updates[0]['params'],
            'failure reason is the storage gate, not the tuner'
        );

        // Proof no capture was spawned: nothing ever transitioned to `recording`.
        foreach ($calls as $c) {
            $this->assertNotContains(
                Recorder::STATUS_RECORDING,
                $c['params'],
                'no query may set status=recording when the storage gate blocks'
            );
        }
    }

    /**
     * Counterpart to the gate test: when storage IS sufficient the gate passes
     * and startRecording() proceeds PAST it (here it then fails at tuner
     * resolution, since no LiveTvManager is wired). Asserting the failure reason
     * is 'No tuner available' (NOT 'Insufficient storage space') proves the
     * storage gate did not block — so an always-block mutation of the gate would
     * flip this RED.
     */
    public function testStartRecordingPassesStorageGateWhenSpaceAvailable(): void
    {
        /** @var list<array{sql:string,params:array<int,mixed>}> $calls */
        $calls = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use (&$calls) {
                $calls[] = ['sql' => $sql, 'params' => $params];
                if (stripos($sql, 'SUM(storage_size)') !== false) {
                    return [['total' => 0]]; // nothing used
                }
                if (stripos($sql, 'SELECT') === 0) {
                    return [$this->scheduledRow()];
                }
                return 1;
            }
        );

        // Huge cap + real temp dir => the storage gate passes.
        $recorder = $this->makeRecorder($db, $this->realTempDir(), PHP_INT_MAX);

        // No LiveTvManager wired -> tuner URL is unresolved -> fails downstream.
        $this->assertFalse($recorder->startRecording('rec-1'));

        $updates = array_values(array_filter(
            $calls,
            static fn (array $c): bool => stripos($c['sql'], 'UPDATE') === 0
        ));
        $this->assertCount(1, $updates);
        $this->assertContains('No tuner available', $updates[0]['params'], 'blocked at tuner, not storage');
        $this->assertNotContains(
            'Insufficient storage space',
            $updates[0]['params'],
            'the storage gate did NOT block (space was available)'
        );
    }

    public function testStartRecordingReturnsFalseForUnknownRecording(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]); // getRecording -> no row

        $recorder = $this->makeRecorder($db, '/tmp/x', 0);
        $this->assertFalse($recorder->startRecording('missing'));
    }
}
