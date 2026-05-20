<?php

namespace Phlix\Tests\Unit\LiveTv;

use PHPUnit\Framework\TestCase;
use Phlix\LiveTv\Recorder;
use Phlix\Common\Logger\StructuredLogger;
use Workerman\MySQL\Connection;

class RecorderTest extends TestCase
{
    private Recorder $recorder;
    private $mockDb;
    private $mockLogger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockDb = $this->createMock(Connection::class);
        $this->mockLogger = $this->createMock(StructuredLogger::class);
        $this->recorder = new Recorder($this->mockDb, '/tmp/recordings', 10000000000, $this->mockLogger);
    }

    public function testCanCreateRecorder(): void
    {
        $this->assertInstanceOf(Recorder::class, $this->recorder);
    }

    public function testStatusConstants(): void
    {
        $this->assertEquals('scheduled', Recorder::STATUS_SCHEDULED);
        $this->assertEquals('recording', Recorder::STATUS_RECORDING);
        $this->assertEquals('completed', Recorder::STATUS_COMPLETED);
        $this->assertEquals('failed', Recorder::STATUS_FAILED);
        $this->assertEquals('cancelled', Recorder::STATUS_CANCELLED);
    }

    public function testPriorityConstants(): void
    {
        $this->assertEquals(1, Recorder::PRIORITY_LOW);
        $this->assertEquals(5, Recorder::PRIORITY_NORMAL);
        $this->assertEquals(10, Recorder::PRIORITY_HIGH);
    }

    public function testTimeshiftBufferSecondsConstant(): void
    {
        $this->assertEquals(7200, Recorder::TIMESHIFT_BUFFER_SECONDS);
    }

    public function testStopRecordingReturnsFalseForNonActiveRecording(): void
    {
        $result = $this->recorder->stopRecording('nonexistent');
        $this->assertFalse($result);
    }

    public function testStartTimeShiftReturnsArray(): void
    {
        $result = $this->recorder->startTimeShift('session_1', 'channel_1');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('time_shift_id', $result);
        $this->assertArrayHasKey('stream_url', $result);
        $this->assertArrayHasKey('buffer_start', $result);
        $this->assertArrayHasKey('buffer_end', $result);

        // Clean up
        $this->recorder->stopTimeShift('session_1');
    }

    public function testStopTimeShiftReturnsFalseForNonexistent(): void
    {
        $result = $this->recorder->stopTimeShift('nonexistent_session');
        $this->assertFalse($result);
    }

    public function testStopTimeShiftReturnsTrueForActive(): void
    {
        $this->recorder->startTimeShift('session_1', 'channel_1');
        $result = $this->recorder->stopTimeShift('session_1');
        $this->assertTrue($result);
    }

    public function testGetTimeShiftReturnsNullForNonexistent(): void
    {
        $timeShift = $this->recorder->getTimeShift('nonexistent_session');
        $this->assertNull($timeShift);
    }

    public function testGetTimeShiftPositionReturnsNullForNonexistent(): void
    {
        $position = $this->recorder->getTimeShiftPosition('nonexistent_session');
        $this->assertNull($position);
    }

    public function testSeekTimeShiftReturnsFalseForNonexistent(): void
    {
        $result = $this->recorder->seekTimeShift('nonexistent_session', time());
        $this->assertFalse($result);
    }

    public function testGetActiveRecordingCountReturnsZeroInitially(): void
    {
        $count = $this->recorder->getActiveRecordingCount();
        $this->assertEquals(0, $count);
    }

    public function testGetActiveTimeShiftCountReturnsZeroInitially(): void
    {
        $count = $this->recorder->getActiveTimeShiftCount();
        $this->assertEquals(0, $count);
    }
}
