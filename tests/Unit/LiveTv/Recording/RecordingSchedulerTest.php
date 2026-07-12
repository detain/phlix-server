<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\LiveTv\Recording;

use PHPUnit\Framework\TestCase;
use Phlix\LiveTv\Dto\ResultSet;
use Phlix\LiveTv\LiveTvManager;
use Phlix\LiveTv\Recorder;
use Phlix\LiveTv\Recording\RecordingScheduler;
use Phlix\Common\Logger\StructuredLogger;
use PHPUnit\Framework\MockObject\MockObject;
use Workerman\MySQL\Connection;
use Workerman\Worker;

class RecordingSchedulerTest extends TestCase
{
    private RecordingScheduler $scheduler;
    /** @var Connection&MockObject */
    private $mockDb;
    /** @var Recorder&MockObject */
    private $mockRecorder;
    /** @var LiveTvManager&MockObject */
    private $mockLiveTvManager;
    /** @var StructuredLogger&MockObject */
    private $mockLogger;

    protected function setUp(): void
    {
        parent::setUp();

        // Workerman\Timer::add() (used by the SV-3.1c per-recording stop timer)
        // throws unless at least one Worker exists in the process. Construct a
        // bare (non-listening) worker so the timer subsystem is usable under
        // PHPUnit's SIGALRM-scheduler path.
        if (!Worker::getAllWorkers()) {
            new Worker();
        }

        $this->mockDb = $this->createMock(Connection::class);
        $this->mockRecorder = $this->createMock(Recorder::class);
        $this->mockLiveTvManager = $this->createMock(LiveTvManager::class);
        $this->mockLogger = $this->createMock(StructuredLogger::class);

        $this->scheduler = new RecordingScheduler(
            $this->mockDb,
            $this->mockRecorder,
            $this->mockLiveTvManager,
            $this->mockLogger
        );
    }

    /**
     * Build a fake query-result object compatible with the LiveTv Recorder's
     * RowQuery cursor contract (num_rows + fetch()).
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

    public function testCanCreateScheduler(): void
    {
        $this->assertInstanceOf(RecordingScheduler::class, $this->scheduler);
    }

    public function testProcessDueRecordingsWithNoRecordings(): void
    {
        $mockResult = new class extends ResultSet {
            public int $num_rows = 0;
            public function fetch(): array|false
            {
                return false;
            }
        };

        $this->mockDb->expects($this->once())
            ->method('query')
            ->willReturn($mockResult);

        $stats = $this->scheduler->processDueRecordings();

        $this->assertEquals(0, $stats['started']);
        $this->assertEquals(0, $stats['skipped']);
        $this->assertEquals(0, $stats['errors']);
    }

    public function testGetNextRecordingReturnsNullWhenEmpty(): void
    {
        $mockResult = new class extends ResultSet {
            public int $num_rows = 0;
            public function fetch(): array|false
            {
                return false;
            }
        };

        $this->mockDb->expects($this->once())
            ->method('query')
            ->willReturn($mockResult);

        $next = $this->scheduler->getNextRecording();

        $this->assertNull($next);
    }

    public function testGetUpcomingRecordingsReturnsEmptyWhenNone(): void
    {
        $mockResult = new class extends ResultSet {
            public int $num_rows = 0;
            public function fetch(): array|false
            {
                return false;
            }
        };

        $this->mockDb->expects($this->once())
            ->method('query')
            ->willReturn($mockResult);

        $upcoming = $this->scheduler->getUpcomingRecordings(10);

        $this->assertCount(0, $upcoming);
        $this->assertEmpty($upcoming);
    }

    public function testGetAvailableTunerCountReturnsZeroWhenNoneIdle(): void
    {
        $this->mockLiveTvManager->expects($this->once())
            ->method('getTuners')
            ->willReturn([
                ['id' => 'tuner_1', 'status' => LiveTvManager::TUNER_STATUS_STREAMING],
                ['id' => 'tuner_2', 'status' => LiveTvManager::TUNER_STATUS_TUNING],
            ]);

        $count = $this->scheduler->getAvailableTunerCount();

        $this->assertEquals(0, $count);
    }

    public function testGetAvailableTunerCountReturnsIdleCount(): void
    {
        $this->mockLiveTvManager->expects($this->once())
            ->method('getTuners')
            ->willReturn([
                ['id' => 'tuner_1', 'status' => LiveTvManager::TUNER_STATUS_IDLE],
                ['id' => 'tuner_2', 'status' => LiveTvManager::TUNER_STATUS_STREAMING],
                ['id' => 'tuner_3', 'status' => LiveTvManager::TUNER_STATUS_IDLE],
            ]);

        $count = $this->scheduler->getAvailableTunerCount();

        $this->assertEquals(2, $count);
    }

    /**
     * SV-3.1c: starting a due recording arms a per-recording one-shot stop timer
     * whose fire moment is computed via the authoritative
     * Recorder::effectiveEndTime(end_time, post_padding) — i.e. the post-padding
     * is applied when scheduling the stop.
     */
    public function testProcessDueRecordingsArmsStopTimerAtEffectiveEnd(): void
    {
        $now = time();
        $endTime = $now + 3600;
        $postPadding = 90;

        $row = [
            'recording_id' => 'rec-1',
            'channel_id' => 'ch-1',
            'start_time' => $now - 30,
            'end_time' => $endTime,
            'pre_padding_seconds' => 60,
            'post_padding_seconds' => $postPadding,
            'priority' => 5,
            'status' => 'scheduled',
        ];

        $this->mockDb->method('query')->willReturn($this->fakeResult([$row]));

        $this->mockLiveTvManager->method('getTuners')->willReturn([
            ['id' => 'tuner-1', 'status' => LiveTvManager::TUNER_STATUS_IDLE],
        ]);

        $this->mockRecorder->method('startRecording')->with('rec-1')->willReturn(true);

        // The padding is applied through the authoritative Recorder helper; the
        // scheduler must NOT re-derive end_time + post_padding itself.
        $this->mockRecorder->expects($this->once())
            ->method('effectiveEndTime')
            ->with($endTime, $postPadding)
            ->willReturn($endTime + $postPadding);

        $this->assertSame(0, $this->scheduler->activeStopTimerCount());

        $stats = $this->scheduler->processDueRecordings();

        $this->assertSame(1, $stats['started']);
        $this->assertSame(
            1,
            $this->scheduler->activeStopTimerCount(),
            'a one-shot stop timer must be armed for the started recording'
        );
    }

    /**
     * SV-3.1c: the safety-net scan ends every recording the Recorder reports as
     * due-to-stop (end_time + post_padding <= now) via the authoritative
     * Recorder::endRecording().
     */
    public function testProcessCompletedRecordingsEndsAllDueRecordings(): void
    {
        $this->mockRecorder->method('getRecordingsDueToStop')
            ->willReturn(['rec-a', 'rec-b']);

        $this->mockRecorder->expects($this->exactly(2))
            ->method('endRecording')
            ->willReturn(true);

        $stats = $this->scheduler->processCompletedRecordings();

        $this->assertSame(2, $stats['ended']);
        $this->assertSame(0, $stats['errors']);
    }

    /**
     * SV-3.1c: a failure ending one recording is isolated (counted as an error)
     * and does not abort the rest of the scan.
     */
    public function testProcessCompletedRecordingsIsolatesFailures(): void
    {
        $this->mockRecorder->method('getRecordingsDueToStop')
            ->willReturn(['rec-a', 'rec-b']);

        $this->mockRecorder->method('endRecording')
            ->willReturnCallback(function (string $id): bool {
                if ($id === 'rec-a') {
                    throw new \RuntimeException('boom');
                }
                return true;
            });

        $stats = $this->scheduler->processCompletedRecordings();

        $this->assertSame(1, $stats['ended'], 'rec-b still stopped after rec-a threw');
        $this->assertSame(1, $stats['errors']);
    }

    /**
     * SV-3.1c: tick() runs BOTH passes (start-due + stop-completed) and returns
     * their combined stats.
     */
    public function testTickRunsBothStartAndStopPasses(): void
    {
        // No scheduled rows due to start.
        $this->mockDb->method('query')->willReturn($this->fakeResult([]));

        // One recording due to stop.
        $this->mockRecorder->method('getRecordingsDueToStop')->willReturn(['rec-x']);
        $this->mockRecorder->method('endRecording')->willReturn(true);

        $result = $this->scheduler->tick();

        $this->assertSame(0, $result['due']['started']);
        $this->assertSame(1, $result['completed']['ended']);
    }

    /**
     * SV-3.1c finding 1(b): the safety-net scan must NOT compete with the live
     * one-shot stop timer. A recording that has just been started (and therefore
     * holds an armed per-recording stop timer) is reported by the Recorder as
     * due-to-stop, but the scan skips it — that timer is the primary stop path.
     * endRecording() must never be called for it via the scan.
     */
    public function testScanSkipsRecordingsWithAnArmedStopTimer(): void
    {
        $now = time();
        $endTime = $now + 3600;

        $row = [
            'recording_id' => 'rec-1',
            'channel_id' => 'ch-1',
            'start_time' => $now - 30,
            'end_time' => $endTime,
            'pre_padding_seconds' => 60,
            'post_padding_seconds' => 90,
            'priority' => 5,
            'status' => 'scheduled',
        ];

        $this->mockDb->method('query')->willReturn($this->fakeResult([$row]));
        $this->mockLiveTvManager->method('getTuners')->willReturn([
            ['id' => 'tuner-1', 'status' => LiveTvManager::TUNER_STATUS_IDLE],
        ]);
        $this->mockRecorder->method('startRecording')->with('rec-1')->willReturn(true);
        $this->mockRecorder->method('effectiveEndTime')->willReturn($endTime + 90);

        // Arm the one-shot stop timer for rec-1.
        $this->scheduler->processDueRecordings();
        $this->assertSame(1, $this->scheduler->activeStopTimerCount());

        // The scan reports rec-1 as due-to-stop, but its timer is still armed.
        $this->mockRecorder->method('getRecordingsDueToStop')->willReturn(['rec-1']);
        $this->mockRecorder->expects($this->never())->method('endRecording');

        $stats = $this->scheduler->processCompletedRecordings();

        $this->assertSame(0, $stats['ended'], 'recording with an armed timer is skipped by the scan');
        $this->assertSame(0, $stats['errors']);
        $this->assertSame(1, $this->scheduler->activeStopTimerCount(), 'the armed timer is left intact');
    }

    /**
     * SV-3.1c finding 2: a manual stop must cancel the pending one-shot stop
     * timer. Uses a REAL Recorder (only startRecording stubbed, to avoid spawning
     * ffmpeg) so the scheduler's onStop hook is genuinely wired: after arming a
     * timer via processDueRecordings(), a manual cancelRecording() fires the
     * Recorder's onStop hook, which cancels the timer and returns
     * activeStopTimerCount() to baseline.
     */
    public function testManualCancelCancelsArmedStopTimer(): void
    {
        $now = time();
        $endTime = $now + 3600;

        $scheduledRow = [
            'recording_id' => 'rec-1',
            'channel_id' => 'ch-1',
            'start_time' => $now - 30,
            'end_time' => $endTime,
            'pre_padding_seconds' => 60,
            'post_padding_seconds' => 90,
            'priority' => 5,
            'status' => 'scheduled',
        ];
        $recordingRow = array_merge($scheduledRow, [
            'status' => 'recording',
            'pid' => null,
        ]);

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use ($scheduledRow, $recordingRow) {
                if (strpos($sql, "r.status = 'scheduled'") !== false) {
                    return $this->fakeResult([$scheduledRow]); // processDueRecordings
                }
                if (stripos($sql, 'SELECT') === 0) {
                    return $this->fakeResult([$recordingRow]);  // getRecording
                }
                return 1; // UPDATE / DELETE affected-rows
            }
        );

        // Real Recorder; only startRecording is stubbed so no ffmpeg is spawned
        // and activeRecordings stays empty (the cancel exercises the non-live
        // onStop fallback path).
        $recorder = $this->getMockBuilder(Recorder::class)
            ->setConstructorArgs([$db, '/tmp/phlix-nonexistent', 0, $this->mockLogger, null, '/usr/bin/ffmpeg', null])
            ->onlyMethods(['startRecording'])
            ->getMock();
        $recorder->method('startRecording')->willReturn(true);

        $liveTvManager = $this->createMock(LiveTvManager::class);
        $liveTvManager->method('getTuners')->willReturn([
            ['id' => 'tuner-1', 'status' => LiveTvManager::TUNER_STATUS_IDLE],
        ]);

        $scheduler = new RecordingScheduler($db, $recorder, $liveTvManager, $this->mockLogger);

        // Arm the one-shot stop timer.
        $stats = $scheduler->processDueRecordings();
        $this->assertSame(1, $stats['started']);
        $this->assertSame(1, $scheduler->activeStopTimerCount(), 'a stop timer is armed after start');

        // Manual cancel must cancel the armed timer via the onStop hook.
        $this->assertTrue($recorder->cancelRecording('rec-1'));
        $this->assertSame(
            0,
            $scheduler->activeStopTimerCount(),
            'the one-shot stop timer is cancelled on manual cancel — count back to baseline'
        );
    }
}
