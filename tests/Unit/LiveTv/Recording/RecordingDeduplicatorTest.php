<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\LiveTv\Recording;

use PHPUnit\Framework\TestCase;
use Phlix\LiveTv\Recording\RecordingDeduplicator;
use Workerman\MySQL\Connection;

class RecordingDeduplicatorTest extends TestCase
{
    private RecordingDeduplicator $deduplicator;
    private $mockDb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockDb = $this->createMock(Connection::class);
        $this->deduplicator = new RecordingDeduplicator($this->mockDb);
    }

    public function testCanCreateDeduplicator(): void
    {
        $this->assertInstanceOf(RecordingDeduplicator::class, $this->deduplicator);
    }

    public function testIsDuplicateQueriesCorrectly(): void
    {
        $mockResult = new class {
            public $num_rows = 0;
            public function fetch() { return false; }
        };

        $this->mockDb->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('SELECT recording_id FROM livetv_recordings'),
                $this->anything()
            )
            ->willReturn($mockResult);

        $result = $this->deduplicator->isDuplicate('prog_123', 'ch_1', time());

        $this->assertFalse($result);
    }

    public function testIsDuplicateReturnsTrueForExisting(): void
    {
        $mockResult = new class {
            public $num_rows = 1;
            public function fetch() { return ['recording_id' => 'rec_existing']; }
        };

        $this->mockDb->expects($this->once())
            ->method('query')
            ->willReturn($mockResult);

        $result = $this->deduplicator->isDuplicate('prog_123', 'ch_1', time());

        $this->assertTrue($result);
    }

    public function testGetCanonicalReturnsNullWhenEmpty(): void
    {
        $mockResult = new class {
            public $num_rows = 0;
            public function fetch() { return false; }
        };

        $this->mockDb->expects($this->once())
            ->method('query')
            ->willReturn($mockResult);

        $canonical = $this->deduplicator->getCanonical('nonexistent');

        $this->assertNull($canonical);
    }

    public function testFindDuplicatesReturnsEmptyWhenNone(): void
    {
        $mockResult = new class {
            public $num_rows = 0;
            public function fetch() { return false; }
        };

        $this->mockDb->expects($this->once())
            ->method('query')
            ->willReturn($mockResult);

        $duplicates = $this->deduplicator->findDuplicates('prog_123');

        $this->assertIsArray($duplicates);
        $this->assertEmpty($duplicates);
    }

    public function testAssignDuplicateGroupCallsUpdate(): void
    {
        $this->mockDb->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('UPDATE livetv_recordings SET duplicate_group'),
                $this->callback(function ($params) {
                    return $params[0] === md5('prog_123:ch_1')
                        && $params[1] === 'rec_1';
                })
            );

        $this->deduplicator->assignDuplicateGroup('rec_1', 'prog_123', 'ch_1');
    }

    public function testDeduplicatorWithCustomWindow(): void
    {
        $customWindow = 3600; // 1 hour window
        $deduplicator = new RecordingDeduplicator($this->mockDb, $customWindow);

        $this->assertInstanceOf(RecordingDeduplicator::class, $deduplicator);
    }
}
