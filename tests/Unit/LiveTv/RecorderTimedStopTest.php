<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\LiveTv;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\LiveTv\Dto\ResultSet;
use Phlix\LiveTv\Recorder;
use Workerman\MySQL\Connection;

/**
 * SV-3.1c tests for the authoritative timed-stop / post-padding logic on the
 * Recorder:
 *   - effectiveEndTime() applies post_padding (= the single padding formula).
 *   - getRecordingsDueToStop() expresses that formula in the scan SQL.
 *   - endRecording() applies the padding authoritatively and transitions the
 *     row to completed (including the orphan-reconcile safety net).
 *
 * @covers \Phlix\LiveTv\Recorder::effectiveEndTime
 * @covers \Phlix\LiveTv\Recorder::getRecordingsDueToStop
 * @covers \Phlix\LiveTv\Recorder::endRecording
 *
 * @since SV-3.1c
 */
final class RecorderTimedStopTest extends TestCase
{
    /**
     * Build a fake query-result object compatible with the Recorder's RowQuery
     * cursor contract (num_rows + fetch()).
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function fakeResult(array $rows): ResultSet
    {
        return new class ($rows) extends ResultSet {
            /** @var array<int, array<string, mixed>> */
            private array $rows;

            /** @param array<int, array<string, mixed>> $rows */
            public function __construct(array $rows)
            {
                $this->rows = array_values($rows);
                $this->num_rows = count($rows);
            }

            /** @return array<string, mixed>|false */
            public function fetch(): array|false
            {
                if (empty($this->rows)) {
                    return false;
                }
                return array_shift($this->rows);
            }
        };
    }

    /**
     * Build a recording row matching the livetv_recordings schema.
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
            'title'                => 'Test Show',
            'description'          => null,
            'start_time'           => time() - 3600,
            'end_time'             => time() - 1800,
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

    public function testEffectiveEndTimeAppliesPostPadding(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);
        $recorder = new Recorder($db, '/tmp/recordings', 0, $logger);

        $this->assertSame(1_060, $recorder->effectiveEndTime(1_000, 60), 'end + padding');
        $this->assertSame(1_000, $recorder->effectiveEndTime(1_000, 0), 'no padding');
        $this->assertSame(1_000, $recorder->effectiveEndTime(1_000, -5), 'negative padding clamps to 0');
    }

    public function testGetRecordingsDueToStopAppliesPostPaddingInScan(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);
        $recorder = new Recorder($db, '/tmp/recordings', 0, $logger);

        /** @var array{sql:string,params:array<int,mixed>} $captured */
        $captured = ['sql' => '', 'params' => []];
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use (&$captured) {
                $captured = ['sql' => $sql, 'params' => $params];
                return $this->fakeResult([
                    ['recording_id' => 'rec-1'],
                    ['recording_id' => 'rec-2'],
                ]);
            }
        );

        $now = 1_700_000_000;
        $ids = $recorder->getRecordingsDueToStop($now);

        $this->assertSame(['rec-1', 'rec-2'], $ids);

        // The scan stops recordings at end_time + post_padding — the authoritative
        // padding formula expressed in SQL. This is the assertion that the padding
        // is actually applied (not the raw end_time).
        $this->assertStringContainsString(
            'end_time + COALESCE(post_padding_seconds, 60)',
            $captured['sql']
        );
        $this->assertSame(Recorder::STATUS_RECORDING, $captured['params'][0]);
        $this->assertSame($now, $captured['params'][1]);
    }

    public function testEndRecordingReturnsFalseWhenRecordingNotFound(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);
        $recorder = new Recorder($db, '/tmp/recordings', 0, $logger);

        $db->method('query')->willReturn($this->fakeResult([]));

        $this->assertFalse($recorder->endRecording('missing'));
    }

    /**
     * SV-3.1c: endRecording() is authoritative — for an in-progress row with no
     * in-memory handle on this worker (e.g. a timer lost across a restart) it
     * reconciles the row to COMPLETED and fires the onComplete callbacks, so the
     * safety-net scan cannot loop on it forever.
     */
    public function testEndRecordingReconcilesOverdueRecordingToCompleted(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);
        // A directory that does not exist so file_exists()/filesize() short-circuit
        // and the only DB writes are the ones under assertion.
        $recorder = new Recorder($db, '/tmp/phlix-nonexistent-recordings', 0, $logger);

        $deadPid = 2_000_000_002; // Confident this pid is not alive on this host.
        $row = $this->recordingRow([
            'recording_id'         => 'rec-overdue',
            'status'               => Recorder::STATUS_RECORDING,
            'end_time'             => time() - 3600,
            'post_padding_seconds' => 90,
            'pid'                  => $deadPid,
        ]);

        /** @var list<array{sql:string,params:array<int,mixed>}> $calls */
        $calls = [];
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use (&$calls, $row) {
                $calls[] = ['sql' => $sql, 'params' => $params];
                if (stripos($sql, 'SELECT') === 0) {
                    return $this->fakeResult([$row]);
                }
                return null;
            }
        );

        $fired = [];
        $recorder->onComplete(function (string $id, string $path) use (&$fired): void {
            $fired[] = $id;
        });

        $result = $recorder->endRecording('rec-overdue');

        $this->assertTrue($result, 'overdue recording is reconciled');
        $this->assertSame(['rec-overdue'], $fired, 'onComplete fires on reconcile');

        // Exactly one UPDATE, transitioning the row to COMPLETED.
        $updates = array_values(array_filter(
            $calls,
            static fn (array $c): bool => stripos($c['sql'], 'UPDATE') === 0
        ));
        $this->assertCount(1, $updates, 'exactly one UPDATE (the completion write)');
        $this->assertContains(
            Recorder::STATUS_COMPLETED,
            $updates[0]['params'],
            'the recording is transitioned to completed'
        );
    }
}
