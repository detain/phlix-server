<?php

namespace Phlex\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Library\AudiobookLibraryManager;
use Phlex\Media\Library\AudiobookProgress;
use Phlex\Media\Library\AudiobookProgressStore;
use Phlex\Media\Library\AudiobookScanner;
use Phlex\Media\Library\ItemRepository;
use Workerman\MySQL\Connection;

class AudiobookLibraryManagerTest extends TestCase
{
    public function testCanCreateAudiobookLibraryManager(): void
    {
        $db = $this->createMock(Connection::class);
        $scanner = $this->createMock(AudiobookScanner::class);
        $itemRepo = $this->createMock(ItemRepository::class);
        $progressStore = $this->createMock(AudiobookProgressStore::class);

        $manager = new AudiobookLibraryManager($scanner, $itemRepo, $progressStore);

        $this->assertInstanceOf(AudiobookLibraryManager::class, $manager);
    }

    public function testUpsertAudiobookStoresChapters(): void
    {
        $db = $this->createMock(Connection::class);
        $scanner = $this->createMock(AudiobookScanner::class);
        $itemRepo = $this->createMock(ItemRepository::class);
        $progressStore = $this->createMock(AudiobookProgressStore::class);

        $itemRepo->method('findByPath')->willReturn(null);

        $scanner->method('isAudiobookExtension')->willReturn(true);
        $scanner->method('harvestAudiobookMetadata')->willReturn([
            'title' => 'Test Audiobook',
            'author' => 'Test Author',
        ]);
        $scanner->method('harvestChapters')->willReturn([
            ['title' => 'Chapter 1', 'start_ms' => 0, 'end_ms' => 300000, 'duration_ms' => 300000],
            ['title' => 'Chapter 2', 'start_ms' => 300000, 'end_ms' => 600000, 'duration_ms' => 300000],
        ]);

        $itemRepo->expects($this->once())
            ->method('create')
            ->with($this->callback(function ($data) {
                return isset($data['metadata_json']['chapters'])
                    && count($data['metadata_json']['chapters']) === 2;
            }))
            ->willReturn('new-audiobook-id');

        $itemRepo->method('findById')->willReturn([
            'id' => 'new-audiobook-id',
            'name' => 'Test Audiobook',
            'type' => 'audiobook',
        ]);

        $manager = new AudiobookLibraryManager($scanner, $itemRepo, $progressStore);
        $result = $manager->upsertAudiobook('lib-123', '/path/to/test.m4b');

        $this->assertIsArray($result);
        $this->assertEquals('new-audiobook-id', $result['id']);
    }

    public function testGetProgressReturnsZeroForNewUser(): void
    {
        $db = $this->createMock(Connection::class);
        $scanner = $this->createMock(AudiobookScanner::class);
        $itemRepo = $this->createMock(ItemRepository::class);
        $progressStore = $this->createMock(AudiobookProgressStore::class);

        $progressStore->method('getProgress')->willReturn(null);

        $manager = new AudiobookLibraryManager($scanner, $itemRepo, $progressStore);
        $progress = $manager->getProgress('new-user', 'audiobook-123');

        $this->assertInstanceOf(AudiobookProgress::class, $progress);
        $this->assertEquals('audiobook-123', $progress->audiobook_id);
        $this->assertEquals('new-user', $progress->user_id);
        $this->assertEquals(0, $progress->position_ms);
        $this->assertEquals(0, $progress->current_chapter_index);
        $this->assertEquals([], $progress->completed_chapters);
        $this->assertEquals(0.0, $progress->percent_complete);
    }

    public function testSaveProgressPersistsToStore(): void
    {
        $db = $this->createMock(Connection::class);
        $scanner = $this->createMock(AudiobookScanner::class);
        $itemRepo = $this->createMock(ItemRepository::class);
        $progressStore = $this->createMock(AudiobookProgressStore::class);

        $progressStore->expects($this->once())
            ->method('saveProgress')
            ->with($this->isInstanceOf(AudiobookProgress::class));

        $manager = new AudiobookLibraryManager($scanner, $itemRepo, $progressStore);

        $progress = new AudiobookProgress(
            'audiobook-123',
            'user-456',
            10000,
            1,
            [0 => 300000],
            25.0,
            time()
        );

        $manager->saveProgress('user-456', 'audiobook-123', $progress);
    }
}
