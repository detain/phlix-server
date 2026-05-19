<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\LiveTv;

use PHPUnit\Framework\TestCase;
use Phlex\Common\Logger\StructuredLogger;
use Phlex\LiveTv\Recorder;
use Workerman\MySQL\Connection;

/**
 * Tests for {@see Recorder::resumeActiveRecordings()}.
 *
 * Exercises the three branches of the recovery loop:
 *   - status=recording + dead pid → marked failed, callbacks fire
 *   - status=recording + live pid → re-attached in memory
 *   - status=scheduled + start_time in past → re-armed via startRecording()
 *
 * @covers \Phlex\LiveTv\Recorder::resumeActiveRecordings
 * @covers \Phlex\LiveTv\Recorder::startRecording
 *
 * @since Wave 2 (post-O.7)
 */
final class RecorderRecoveryTest extends TestCase
{
    /**
     * Build a fake query-result object compatible with the
     * Workerman\MySQL\Connection result interface used by Recorder.
     *
     * @param array<int, array<string, mixed>> $rows Sequence of rows the
     *        fake should hand out via fetch(). Callers can pre-load a
     *        recording row and then an empty row to terminate the loop.
     */
    private function fakeResult(array $rows): object
    {
        return new class ($rows) {
            /** @var array<int, array<string, mixed>> */
            private array $rows;

            public int $num_rows;

            /**
             * @param array<int, array<string, mixed>> $rows
             */
            public function __construct(array $rows)
            {
                $this->rows     = array_values($rows);
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
     * @param array<string, mixed> $overrides Field overrides.
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
            'start_time'           => time() - 60,
            'end_time'             => time() + 3600,
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

    public function testStaleRecordingWithDeadPidIsMarkedFailedAndFiresCallbacks(): void
    {
        $db     = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);
        $recorder = new Recorder($db, '/tmp/recordings', 0, $logger);

        // A pid we are confident does not exist on this host.
        $deadPid = 2_000_000_001;
        $row     = $this->recordingRow(['pid' => $deadPid]);

        // 1st call: SELECT status='recording' (one stale row, then end).
        // 2nd call: UPDATE status=failed.
        // 3rd call: SELECT status='scheduled' AND start_time <= NOW() (empty).
        $db->method('query')->willReturnOnConsecutiveCalls(
            $this->fakeResult([$row]),
            null, // UPDATE result is unused
            $this->fakeResult([]),
        );

        $fired = [];
        $recorder->onComplete(function (string $id, string $path) use (&$fired): void {
            $fired[] = [$id, $path];
        });

        $stats = $recorder->resumeActiveRecordings();

        $this->assertSame(1, $stats['failed']);
        $this->assertSame(0, $stats['resumed']);
        $this->assertSame(0, $stats['rearmed']);
        $this->assertCount(1, $fired, 'onComplete callback must fire for failed recovery');
        $this->assertSame('rec-1', $fired[0][0]);
    }

    public function testScheduledRecordingWithPastStartTimeIsRearmed(): void
    {
        $db     = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);
        $recorder = new Recorder($db, '/tmp/recordings', 0, $logger);

        $row = $this->recordingRow([
            'recording_id' => 'rec-due',
            'status'       => Recorder::STATUS_SCHEDULED,
            'start_time'   => time() - 30,
            'end_time'     => time() + 1800,
        ]);

        // Calls in order:
        //   1. SELECT status='recording'   (empty)
        //   2. SELECT status='scheduled' AND start_time <= NOW()  (one row)
        //   3. startRecording -> getRecording -> SELECT WHERE recording_id=?  (one row, sched)
        //   4. startRecording -> UPDATE livetv_recordings SET status=recording, pid=?…
        $db->method('query')->willReturnOnConsecutiveCalls(
            $this->fakeResult([]),
            $this->fakeResult([$row]),
            $this->fakeResult([$row]),
            null,
        );

        $stats = $recorder->resumeActiveRecordings();

        $this->assertSame(1, $stats['rearmed']);
        $this->assertSame(0, $stats['failed']);
        $this->assertSame(0, $stats['resumed']);
    }

    public function testLiveProcessPidPreservesRecording(): void
    {
        $db     = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);
        $recorder = new Recorder($db, '/tmp/recordings', 0, $logger);

        // The current PHP pid is guaranteed alive.
        $livePid = getmypid();
        $this->assertIsInt($livePid);

        $row = $this->recordingRow(['pid' => $livePid]);

        // Calls in order:
        //   1. SELECT status='recording'  (one live row)
        //   2. SELECT status='scheduled' AND start_time <= NOW()  (empty)
        $db->method('query')->willReturnOnConsecutiveCalls(
            $this->fakeResult([$row]),
            $this->fakeResult([]),
        );

        // The recording must NOT be marked failed and callbacks must NOT fire.
        $fired = [];
        $recorder->onComplete(function (string $id, string $path) use (&$fired): void {
            $fired[] = $id;
        });

        $stats = $recorder->resumeActiveRecordings();

        $this->assertSame(1, $stats['resumed']);
        $this->assertSame(0, $stats['failed']);
        $this->assertSame(1, $recorder->getActiveRecordingCount());
        $this->assertEmpty($fired, 'onComplete must not fire for a live recording');
    }
}
