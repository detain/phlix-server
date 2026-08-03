<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\LiveTv;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\LiveTv\Recorder;
use Phlix\LiveTv\TimeShift\DbTimeShiftSessionStore;
use Workerman\MySQL\Connection;

/**
 * REGRESSION GUARD (SV-3.1-rowquery): the LiveTv Recorder read path against the
 * ACTUAL production DB result shape.
 *
 * The rest of the LiveTv suite (RecorderTimedStopTest, RecorderRecoveryTest, …)
 * injects `ResultSet`-shaped cursor mocks. Production, however, returns a PLAIN
 * `array<int, array<string, mixed>>` from `PhlixMySQLConnection::query("SELECT …")`
 * (Workerman's `Connection::query()` → `fetchAll()`), and nothing in `src/` ever
 * constructs a `ResultSet`. Before the RowQuery fix, `RowQuery::firstRow/rows`
 * narrowed ONLY on `instanceof ResultSet`, so every real SELECT yielded
 * `null`/`[]` and the whole DVR read path (`getRecording()`,
 * `getRecordingsDueToStop()`, `resumeActiveRecordings()`, and hence the SV-3.1d
 * onComplete registrar) was silently inert against a live database while unit
 * tests stayed green.
 *
 * These tests feed the Recorder the plain-array shape a mocked
 * `Connection::query()` actually returns and assert real rows come back. They
 * FAIL against the pre-fix `instanceof ResultSet`-only RowQuery.
 *
 *
 * @since SV-3.1-rowquery
 */
final class RecorderPlainArrayReadPathTest extends TestCase
{
    /**
     * A recording row matching the livetv_recordings schema.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function recordingRow(array $overrides = []): array
    {
        return array_merge([
            'recording_id'         => 'rec-1',
            'channel_id'           => 'ch-1',
            'program_id'           => null,
            'user_id'              => null,
            'title'                => 'The Evening News',
            'description'          => null,
            'start_time'           => 1_700_000_000,
            'end_time'             => 1_700_003_600,
            'priority'             => Recorder::PRIORITY_NORMAL,
            'quality'              => 'default',
            'storage_path'         => '/tmp/recordings/rec-1.ts',
            'storage_size'         => 0,
            'status'               => Recorder::STATUS_RECORDING,
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

    public function testGetRecordingReturnsRowFromPlainArrayResult(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        // The PROD shape: a plain list of associative row arrays (NOT a ResultSet).
        $db->method('query')->willReturn([$this->recordingRow()]);

        $recorder = new Recorder($db, new DbTimeShiftSessionStore($db), '/tmp/recordings', 0, $logger);

        $recording = $recorder->getRecording('rec-1');

        $this->assertNotNull($recording, 'plain-array SELECT must yield a real recording');
        $this->assertSame('rec-1', $recording['recording_id']);
        $this->assertSame('The Evening News', $recording['title']);
        $this->assertSame(Recorder::STATUS_RECORDING, $recording['status']);
    }

    public function testGetRecordingReturnsNullForEmptyPlainArrayResult(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        // A SELECT that matched no rows returns [] in production.
        $db->method('query')->willReturn([]);

        $recorder = new Recorder($db, new DbTimeShiftSessionStore($db), '/tmp/recordings', 0, $logger);

        $this->assertNull($recorder->getRecording('missing'));
    }

    public function testGetRecordingsDueToStopReturnsIdsFromPlainArrayResult(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $db->method('query')->willReturn([
            ['recording_id' => 'rec-1'],
            ['recording_id' => 'rec-2'],
        ]);

        $recorder = new Recorder($db, new DbTimeShiftSessionStore($db), '/tmp/recordings', 0, $logger);

        $ids = $recorder->getRecordingsDueToStop(1_700_000_000);

        $this->assertSame(['rec-1', 'rec-2'], $ids, 'the timed-stop scan must read real ids from a plain array');
    }

    /**
     * Boot recovery (SV-3.1e) reads status='recording' rows via the same RowQuery
     * path. With a plain-array result and a dead/absent pid, the row must be
     * reconciled to FAILED and the onComplete callbacks fired — proving the read
     * path actually saw the row. Pre-fix this SELECT yielded [] so recovery was a
     * silent no-op (every stat 0, no callback).
     */
    public function testResumeActiveRecordingsProcessesPlainArrayRows(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $interrupted = $this->recordingRow([
            'recording_id' => 'rec-interrupted',
            'status'       => Recorder::STATUS_RECORDING,
            'pid'          => null, // no live ffmpeg child after restart → mark failed
        ]);

        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use ($interrupted) {
                if (stripos($sql, 'SELECT') === 0) {
                    // First scan: interrupted 'recording' rows. Second scan:
                    // due 'scheduled' rows (none).
                    if (($params[0] ?? null) === Recorder::STATUS_RECORDING) {
                        return [$interrupted];
                    }
                    return [];
                }
                // updateRecordingStatus UPDATE.
                return 1;
            }
        );

        $recorder = new Recorder(
            $db,
            new DbTimeShiftSessionStore($db),
            '/tmp/phlix-nonexistent-recordings',
            0,
            $logger
        );

        $fired = [];
        $recorder->onComplete(function (string $id, string $path) use (&$fired): void {
            $fired[] = $id;
        });

        $stats = $recorder->resumeActiveRecordings();

        $this->assertSame(1, $stats['failed'], 'the interrupted row is read from the plain array and reconciled');
        $this->assertSame(0, $stats['resumed']);
        $this->assertSame(['rec-interrupted'], $fired, 'onComplete fires for the recovered row');
    }
}
