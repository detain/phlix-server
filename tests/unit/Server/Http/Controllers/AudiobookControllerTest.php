<?php

namespace Phlex\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Library\AudiobookLibraryManager;
use Phlex\Media\Library\AudiobookProgress;
use Phlex\Media\Library\ItemRepository;
use Phlex\Server\Http\Controllers\AudiobookController;
use Phlex\Server\Http\Request;

class AudiobookControllerTest extends TestCase
{
    private function createMockItemRepo(): ItemRepository
    {
        return $this->createMock(ItemRepository::class);
    }

    private function createMockLibraryManager(): AudiobookLibraryManager
    {
        return $this->createMock(AudiobookLibraryManager::class);
    }

    public function testCanCreateAudiobookController(): void
    {
        $itemRepo = $this->createMockItemRepo();
        $libraryManager = $this->createMockLibraryManager();

        $controller = new AudiobookController($itemRepo, $libraryManager);

        $this->assertInstanceOf(AudiobookController::class, $controller);
    }

    public function testListAudiobooksReturnsJson(): void
    {
        $itemRepo = $this->createMockItemRepo();
        $libraryManager = $this->createMockLibraryManager();

        $itemRepo->method('getByLibrary')->willReturn([]);
        $itemRepo->method('searchFuzzy')->willReturn([]);

        $controller = new AudiobookController($itemRepo, $libraryManager);

        $request = new Request();
        $request->query = [];

        $response = $controller->listAudiobooks($request);

        $this->assertEquals(200, $response->statusCode);
        $this->assertStringContainsString('application/json', $response->headers['Content-Type'] ?? '');
    }

    public function testGetAudiobookReturnsJsonWithChapters(): void
    {
        $itemRepo = $this->createMockItemRepo();
        $libraryManager = $this->createMockLibraryManager();

        $itemRepo->method('findById')->willReturn([
            'id' => 'audiobook-123',
            'name' => 'Test Audiobook',
            'type' => 'audiobook',
            'path' => '/path/to/test.m4b',
            'metadata' => [
                'title' => 'Test Audiobook',
                'author' => 'Test Author',
                'narrator' => 'Test Narrator',
                'chapters' => [
                    ['title' => 'Chapter 1', 'start_ms' => 0, 'end_ms' => 300000, 'duration_ms' => 300000],
                    ['title' => 'Chapter 2', 'start_ms' => 300000, 'end_ms' => 600000, 'duration_ms' => 300000],
                ],
            ],
        ]);

        $controller = new AudiobookController($itemRepo, $libraryManager);

        $request = new Request();
        $response = $controller->getAudiobook($request, ['id' => 'audiobook-123']);

        $this->assertEquals(200, $response->statusCode);
        $this->assertStringContainsString('application/json', $response->headers['Content-Type'] ?? '');
    }

    public function testGetChaptersReturnsChapterList(): void
    {
        $itemRepo = $this->createMockItemRepo();
        $libraryManager = $this->createMockLibraryManager();

        $itemRepo->method('findById')->willReturn([
            'id' => 'audiobook-123',
            'name' => 'Test Audiobook',
            'type' => 'audiobook',
            'metadata' => [
                'chapters' => [
                    ['title' => 'Chapter 1', 'start_ms' => 0, 'end_ms' => 300000, 'duration_ms' => 300000],
                    ['title' => 'Chapter 2', 'start_ms' => 300000, 'end_ms' => 600000, 'duration_ms' => 300000],
                ],
            ],
        ]);

        $controller = new AudiobookController($itemRepo, $libraryManager);

        $request = new Request();
        $response = $controller->getChapters($request, ['id' => 'audiobook-123']);

        $this->assertEquals(200, $response->statusCode);
    }

    public function testGetProgressReturnsUserProgress(): void
    {
        $itemRepo = $this->createMockItemRepo();
        $libraryManager = $this->createMockLibraryManager();

        $progress = new AudiobookProgress(
            'audiobook-123',
            'user-456',
            10000,
            1,
            [0 => 300000],
            25.0,
            time()
        );

        $libraryManager->method('getProgress')->willReturn($progress);

        $controller = new AudiobookController($itemRepo, $libraryManager);
        $controller->setUserId('user-456');

        $request = new Request();
        $response = $controller->getProgress($request, ['id' => 'audiobook-123']);

        $this->assertEquals(200, $response->statusCode);
    }

    public function testSaveProgressAcceptsProgressPayload(): void
    {
        $itemRepo = $this->createMockItemRepo();
        $libraryManager = $this->createMockLibraryManager();

        $libraryManager->expects($this->once())
            ->method('saveProgress')
            ->with(
                'user-456',
                'audiobook-123',
                $this->isInstanceOf(AudiobookProgress::class)
            );

        $controller = new AudiobookController($itemRepo, $libraryManager);
        $controller->setUserId('user-456');

        $request = new Request();
        $request->query = [
            'body' => json_encode([
                'position_ms' => 5000,
                'current_chapter_index' => 1,
                'completed_chapters' => [0 => 300000],
                'percent_complete' => 15.5,
            ]),
        ];

        $response = $controller->saveProgress($request, ['id' => 'audiobook-123']);

        $this->assertEquals(200, $response->statusCode);
    }

    public function testStreamAudiobookResumesInChapter(): void
    {
        $itemRepo = $this->createMockItemRepo();
        $libraryManager = $this->createMockLibraryManager();

        $tempFile = sys_get_temp_dir() . '/test_audiobook_' . uniqid() . '.m4b';
        file_put_contents($tempFile, 'fake audio content');

        $itemRepo->method('findById')->willReturn([
            'id' => 'audiobook-123',
            'name' => 'Test Audiobook',
            'type' => 'audiobook',
            'path' => $tempFile,
            'metadata' => [
                'duration_ms' => 600000,
                'chapters' => [
                    ['title' => 'Chapter 1', 'start_ms' => 0, 'end_ms' => 300000],
                    ['title' => 'Chapter 2', 'start_ms' => 300000, 'end_ms' => 600000],
                ],
            ],
        ]);

        $controller = new AudiobookController($itemRepo, $libraryManager);

        $request = new Request();
        $request->query = [
            'chapter' => '1',
            'offset' => '5000',
        ];

        $response = $controller->streamAudiobook($request, ['id' => 'audiobook-123']);

        $this->assertEquals(200, $response->statusCode);

        // Clean up
        unlink($tempFile);
    }

    public function testGetAudiobookReturns404ForNonExistent(): void
    {
        $itemRepo = $this->createMockItemRepo();
        $libraryManager = $this->createMockLibraryManager();

        $itemRepo->method('findById')->willReturn(null);

        $controller = new AudiobookController($itemRepo, $libraryManager);

        $request = new Request();
        $response = $controller->getAudiobook($request, ['id' => 'non-existent']);

        $this->assertEquals(404, $response->statusCode);
    }

    public function testGetProgressReturns401WhenNotAuthenticated(): void
    {
        $itemRepo = $this->createMockItemRepo();
        $libraryManager = $this->createMockLibraryManager();

        $controller = new AudiobookController($itemRepo, $libraryManager);
        // Don't set user ID

        $request = new Request();
        $response = $controller->getProgress($request, ['id' => 'audiobook-123']);

        $this->assertEquals(401, $response->statusCode);
    }
}
