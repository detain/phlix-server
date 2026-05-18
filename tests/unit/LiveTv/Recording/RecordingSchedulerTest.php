<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\LiveTv\Recording;

use PHPUnit\Framework\TestCase;
use Phlex\LiveTv\LiveTvManager;
use Phlex\LiveTv\Recorder;
use Phlex\LiveTv\Recording\RecordingScheduler;
use Phlex\Common\Logger\StructuredLogger;
use Workerman\MySQL\Connection;

class RecordingSchedulerTest extends TestCase
{
    private RecordingScheduler $scheduler;
    private $mockDb;
    private $mockRecorder;
    private $mockLiveTvManager;
    private $mockLogger;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function testCanCreateScheduler(): void
    {
        $this->assertInstanceOf(RecordingScheduler::class, $this->scheduler);
    }

    public function testProcessDueRecordingsWithNoRecordings(): void
    {
        $mockResult = new class {
            public $num_rows = 0;
            public function fetch() { return false; }
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
        $mockResult = new class {
            public $num_rows = 0;
            public function fetch() { return false; }
        };

        $this->mockDb->expects($this->once())
            ->method('query')
            ->willReturn($mockResult);

        $next = $this->scheduler->getNextRecording();

        $this->assertNull($next);
    }

    public function testGetUpcomingRecordingsReturnsEmptyWhenNone(): void
    {
        $mockResult = new class {
            public $num_rows = 0;
            public function fetch() { return false; }
        };

        $this->mockDb->expects($this->once())
            ->method('query')
            ->willReturn($mockResult);

        $upcoming = $this->scheduler->getUpcomingRecordings(10);

        $this->assertIsArray($upcoming);
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
}
