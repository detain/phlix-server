<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\LiveTv;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\LiveTv\Dto\ResultSet;
use Phlix\LiveTv\Recorder;
use Phlix\LiveTv\TimeShift\DbTimeShiftSessionStore;
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
        $recorder = new Recorder($db, new DbTimeShiftSessionStore($db), '/tmp/recordings', 0, $logger);

        $this->assertSame(1_060, $recorder->effectiveEndTime(1_000, 60), 'end + padding');
        $this->assertSame(1_000, $recorder->effectiveEndTime(1_000, 0), 'no padding');
        $this->assertSame(1_000, $recorder->effectiveEndTime(1_000, -5), 'negative padding clamps to 0');
    }

    public function testGetRecordingsDueToStopAppliesPostPaddingInScan(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);
        $recorder = new Recorder($db, new DbTimeShiftSessionStore($db), '/tmp/recordings', 0, $logger);

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
        // is actually applied (not the raw end_time). GREATEST(0, …) clamps a
        // (mis-stored) negative padding, mirroring effectiveEndTime()'s max(0, …)
        // so the scan cannot fire earlier than the timer path (SV-3.1c finding 3).
        $this->assertStringContainsString(
            'end_time + GREATEST(0, COALESCE(post_padding_seconds, 60))',
            $captured['sql']
        );
        $this->assertSame(Recorder::STATUS_RECORDING, $captured['params'][0]);
        $this->assertSame($now, $captured['params'][1]);
    }

    public function testEndRecordingReturnsFalseWhenRecordingNotFound(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);
        $recorder = new Recorder($db, new DbTimeShiftSessionStore($db), '/tmp/recordings', 0, $logger);

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
        $recorder = new Recorder(
            $db,
            new DbTimeShiftSessionStore($db),
            '/tmp/phlix-nonexistent-recordings',
            0,
            $logger
        );

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
                // Completion CAS UPDATE: 1 affected row = this completer won.
                return 1;
            }
        );

        $fired = [];
        $recorder->onComplete(function (string $id, string $path) use (&$fired): void {
            $fired[] = $id;
        });

        $result = $recorder->endRecording('rec-overdue');

        $this->assertTrue($result, 'overdue recording is reconciled');
        $this->assertSame(['rec-overdue'], $fired, 'onComplete fires on reconcile');

        // Exactly one UPDATE, transitioning the row to COMPLETED, guarded by a
        // conditional WHERE ... status='recording' (the atomic completion CAS).
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
        $this->assertStringContainsString(
            'status = ?',
            $updates[0]['sql'],
            'completion UPDATE is a conditional compare-and-swap on status'
        );
        // The last bound param is the expected prior status (recording) — the CAS
        // guard so only the completer that observes it still `recording` wins.
        $this->assertContains(
            Recorder::STATUS_RECORDING,
            $updates[0]['params'],
            'CAS guards on the prior recording status'
        );
    }

    /**
     * SV-3.1c finding 1: the completion path is idempotent under a
     * timer-vs-scan race. Two completers (the one-shot stop timer AND the
     * safety-net scan) reconcile the SAME still-`recording` orphan row; the
     * atomic completion compare-and-swap (WHERE ... status='recording' with an
     * affected-rows==1 gate) must let exactly ONE win — firing onComplete once
     * and applying exactly one status→completed UPDATE. Driven deterministically:
     * the DB mock returns affected-rows 1 for the first UPDATE, 0 for the second.
     */
    public function testCompletionIsIdempotentUnderTimerVsScanRace(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);
        $recorder = new Recorder(
            $db,
            new DbTimeShiftSessionStore($db),
            '/tmp/phlix-nonexistent-recordings',
            0,
            $logger
        );

        // pid=null so no process kill / cooperative sleep — fully deterministic.
        $row = $this->recordingRow([
            'recording_id'         => 'rec-race',
            'status'               => Recorder::STATUS_RECORDING,
            'end_time'             => time() - 3600,
            'post_padding_seconds' => 60,
            'pid'                  => null,
        ]);

        $updateCount = 0;
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use (&$updateCount, $row) {
                if (stripos($sql, 'SELECT') === 0) {
                    // Both completers still observe the row as `recording` (the
                    // race window): the CAS — not the read — must arbitrate.
                    return $this->fakeResult([$row]);
                }
                // Completion CAS UPDATE: first caller wins (1), the second loses (0).
                $updateCount++;
                return $updateCount === 1 ? 1 : 0;
            }
        );

        $fired = [];
        $recorder->onComplete(function (string $id, string $path) use (&$fired): void {
            $fired[] = $id;
        });

        // Simulate the one-shot timer AND the scan both completing rec-race.
        $first = $recorder->endRecording('rec-race');
        $second = $recorder->endRecording('rec-race');

        $this->assertTrue($first, 'first completer wins the CAS');
        $this->assertFalse($second, 'second completer loses the CAS (affected-rows 0)');
        $this->assertSame(['rec-race'], $fired, 'onComplete fires EXACTLY once despite two completers');
        $this->assertSame(2, $updateCount, 'both completers attempted the conditional UPDATE');
    }

    /**
     * SV-3.1c finding 2: the Recorder's onStop hooks fire on EVERY manual stop
     * path — stopRecording (live), cancelRecording, deleteRecording — so a
     * scheduler-registered hook can cancel the pending one-shot stop timer and
     * return activeStopTimerCount() to baseline. Uses pid=0 for the live stop so
     * no real process is signalled.
     */
    public function testOnStopFiresOnManualStopPaths(): void
    {
        // (a) live stopRecording — seed an active recording with pid 0 (no kill).
        $dbA = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);
        $dbA->method('query')->willReturnCallback(
            fn (string $sql, array $params = []) =>
                stripos($sql, 'SELECT') === 0
                    ? $this->fakeResult([$this->recordingRow(['recording_id' => 'rec-live'])])
                    : 1
        );
        $recorderA = new Recorder(
            $dbA,
            new DbTimeShiftSessionStore($dbA),
            '/tmp/phlix-nonexistent-recordings',
            0,
            $logger
        );
        $active = new \ReflectionProperty(Recorder::class, 'activeRecordings');
        $active->setValue($recorderA, [
            'rec-live' => [
                'id'          => 'rec-live',
                'started_at'  => time(),
                'channel_id'  => 'ch-1',
                'stream_url'  => '',
                'pid'         => 0,
            ],
        ]);
        $stoppedA = [];
        $recorderA->onStop(function (string $id) use (&$stoppedA): void {
            $stoppedA[] = $id;
        });
        $this->assertTrue($recorderA->stopRecording('rec-live'));
        $this->assertSame(['rec-live'], $stoppedA, 'onStop fires on live stopRecording');

        // (b) cancelRecording on a non-live row (fires onStop via the fallback).
        $dbB = $this->createMock(Connection::class);
        $dbB->method('query')->willReturnCallback(
            fn (string $sql, array $params = []) =>
                stripos($sql, 'SELECT') === 0
                    ? $this->fakeResult([$this->recordingRow([
                        'recording_id' => 'rec-cancel',
                        'status'       => Recorder::STATUS_SCHEDULED,
                    ])])
                    : 1
        );
        $recorderB = new Recorder(
            $dbB,
            new DbTimeShiftSessionStore($dbB),
            '/tmp/phlix-nonexistent-recordings',
            0,
            $logger
        );
        $stoppedB = [];
        $recorderB->onStop(function (string $id) use (&$stoppedB): void {
            $stoppedB[] = $id;
        });
        $this->assertTrue($recorderB->cancelRecording('rec-cancel'));
        $this->assertSame(['rec-cancel'], $stoppedB, 'onStop fires on cancelRecording');

        // (c) deleteRecording on a non-live row.
        $dbC = $this->createMock(Connection::class);
        $dbC->method('query')->willReturnCallback(
            fn (string $sql, array $params = []) =>
                stripos($sql, 'SELECT') === 0
                    ? $this->fakeResult([$this->recordingRow([
                        'recording_id' => 'rec-delete',
                        'status'       => Recorder::STATUS_COMPLETED,
                    ])])
                    : 1
        );
        $recorderC = new Recorder(
            $dbC,
            new DbTimeShiftSessionStore($dbC),
            '/tmp/phlix-nonexistent-recordings',
            0,
            $logger
        );
        $stoppedC = [];
        $recorderC->onStop(function (string $id) use (&$stoppedC): void {
            $stoppedC[] = $id;
        });
        $this->assertTrue($recorderC->deleteRecording('rec-delete'));
        $this->assertSame(['rec-delete'], $stoppedC, 'onStop fires on deleteRecording');
    }
}
