<?php

namespace Phlex\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Library\AudiobookProgress;
use Phlex\Media\Library\AudiobookProgressStore;
use Workerman\MySQL\Connection;

class AudiobookProgressStoreTest extends TestCase
{
    private function createMockConnection(): Connection
    {
        return $this->createMock(Connection::class);
    }

    public function testCanCreateAudiobookProgressStore(): void
    {
        $db = $this->createMockConnection();
        $store = new AudiobookProgressStore($db);

        $this->assertInstanceOf(AudiobookProgressStore::class, $store);
    }

    public function testSaveAndRetrieveProgress(): void
    {
        $db = $this->createMockConnection();

        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('INSERT INTO audiobook_progress'),
                $this->callback(function ($params) {
                    return count($params) === 7
                        && $params[0] === 'user-123'
                        && $params[1] === 'audiobook-456'
                        && $params[2] === 5000
                        && $params[3] === 2
                        && is_string($params[4]);
                })
            );

        $progress = new AudiobookProgress(
            'audiobook-456',
            'user-123',
            5000,
            2,
            [0 => 300000, 1 => 600000],
            15.5,
            time()
        );

        $store = new AudiobookProgressStore($db);
        $store->saveProgress($progress);
    }

    public function testMarkChapterComplete(): void
    {
        $db = $this->createMockConnection();

        // First call - getProgress returns existing progress
        $db->method('query')
            ->willReturnOnConsecutiveCalls(
                [[
                    'user_id' => 'user-123',
                    'audiobook_id' => 'audiobook-456',
                    'position_ms' => 5000,
                    'current_chapter_index' => 2,
                    'completed_chapters' => '[]',
                    'percent_complete' => 10.5,
                    'last_played_at' => time(),
                ]],
                // Second call - saveProgress
                [[]],
                // Third call - saveProgress for markChapterComplete
                [[]]
            );

        $store = new AudiobookProgressStore($db);

        // First, get existing progress
        $existingProgress = $store->getProgress('user-123', 'audiobook-456');

        $this->assertInstanceOf(AudiobookProgress::class, $existingProgress);
        $this->assertEquals('user-123', $existingProgress->user_id);
        $this->assertEquals('audiobook-456', $existingProgress->audiobook_id);

        // Mark chapter 1 complete
        $store->markChapterComplete('user-123', 'audiobook-456', 1);
    }

    public function testGetProgressReturnsNullForNewUser(): void
    {
        $db = $this->createMockConnection();
        $db->method('query')->willReturn([]);

        $store = new AudiobookProgressStore($db);
        $result = $store->getProgress('user-new', 'audiobook-new');

        $this->assertNull($result);
    }
}
