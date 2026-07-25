<?php

namespace Phlix\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\AudiobookLibraryManager;
use Phlix\Media\Library\AudiobookProgress;
use Phlix\Media\Library\AudiobookProgressStore;
use Phlix\Media\Library\AudiobookScanner;
use Phlix\Media\Library\ItemRepository;
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

    /**
     * S96(f) / review r1 LOW-6: a rescan that could not create an item must report it.
     *
     * `rescanLibrary()` has always counted these failures into a local `$errors` and
     * then built its {@see \Phlix\Media\Library\ScanResult} without them, so the
     * failure count was computed and discarded three lines apart. Once S96 added
     * `failed` to `toArray()` and to `library_scan_jobs.items_failed`, that silence
     * became an actively FALSE clean success on a path that — unlike the video
     * scanner — genuinely knows its per-file outcome.
     */
    public function testRescanReportsFailedItemCreationsInScanResult(): void
    {
        $dir = sys_get_temp_dir() . '/phlix_audiobook_rescan_' . uniqid();
        mkdir($dir, 0777, true);

        try {
            $scanner = $this->createMock(AudiobookScanner::class);
            $itemRepo = $this->createMock(ItemRepository::class);
            $progressStore = $this->createMock(AudiobookProgressStore::class);

            $scanner->method('scanAudiobookLibrary')->willReturnCallback(
                static function (string $libraryId, string $libraryPath): \Generator {
                    unset($libraryId);
                    foreach (['a', 'b', 'c'] as $name) {
                        yield ['name' => $name, 'path' => $libraryPath . '/' . $name . '.m4b'];
                    }
                }
            );

            // The middle audiobook cannot be written; the other two land.
            $calls = 0;
            $itemRepo->method('create')->willReturnCallback(
                static function () use (&$calls): string {
                    $calls++;
                    if ($calls === 2) {
                        throw new \RuntimeException('INJECTED: item create failed');
                    }

                    return 'item-' . $calls;
                }
            );

            $manager = new AudiobookLibraryManager($scanner, $itemRepo, $progressStore);
            $result = $manager->rescanLibrary('lib-audiobook', [$dir]);

            $this->assertSame(3, $result->scanned);
            $this->assertSame(2, $result->added);
            $this->assertSame(
                1,
                $result->failed,
                'the failure was already counted internally; reporting 0 here is a FALSE clean success '
                . 'that reaches both POST-scan responses and library_scan_jobs.items_failed'
            );
            $this->assertSame(1, $result->toArray()['failed']);
            $this->assertSame(
                $result->scanned,
                $result->added + $result->updated + $result->failed,
                'every audiobook read must be accounted for as added, updated or failed'
            );
        } finally {
            @rmdir($dir);
        }
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
