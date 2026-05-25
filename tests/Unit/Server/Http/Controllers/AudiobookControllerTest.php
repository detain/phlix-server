<?php

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\AudiobookLibraryManager;
use Phlix\Media\Library\AudiobookProgress;
use Phlix\Media\Library\ItemRepository;
use Phlix\Server\Http\Controllers\AudiobookController;
use Phlix\Server\Http\Request;

class AudiobookControllerTest extends TestCase
{
    private ?string $testMediaDir = null;

    private function createMockItemRepo(): ItemRepository
    {
        return $this->createMock(ItemRepository::class);
    }

    private function createMockLibraryManager(): AudiobookLibraryManager
    {
        return $this->createMock(AudiobookLibraryManager::class);
    }

    /**
     * Creates a test audio file in an allowed media directory.
     * This ensures the file path passes validateMediaPath() security checks.
     */
    private function createTestAudioFile(string $filename, string $content): string
    {
        if ($this->testMediaDir === null) {
            $this->testMediaDir = '/home/my/test_media_' . uniqid();
            mkdir($this->testMediaDir, 0755, true);
        }

        $path = $this->testMediaDir . '/' . $filename;
        file_put_contents($path, $content);

        return $path;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Clean up test media directory
        if ($this->testMediaDir !== null && is_dir($this->testMediaDir)) {
            $files = glob($this->testMediaDir . '/*');
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($this->testMediaDir);
            $this->testMediaDir = null;
        }
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

        $tempFile = $this->createTestAudioFile('test_audiobook_' . uniqid() . '.m4b', 'fake audio content');

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

    public function testStreamAudiobookReturnsRawBinaryNotBase64(): void
    {
        $itemRepo = $this->createMockItemRepo();
        $libraryManager = $this->createMockLibraryManager();

        $originalContent = 'raw audio binary data';
        $tempFile = $this->createTestAudioFile('test_audiobook_' . uniqid() . '.m4b', $originalContent);

        $itemRepo->method('findById')->willReturn([
            'id' => 'audiobook-123',
            'name' => 'Test Audiobook',
            'type' => 'audiobook',
            'path' => $tempFile,
            'metadata' => [
                'duration_ms' => 600000,
                'chapters' => [],
            ],
        ]);

        $controller = new AudiobookController($itemRepo, $libraryManager);

        $request = new Request();
        $response = $controller->streamAudiobook($request, ['id' => 'audiobook-123']);

        $this->assertEquals(200, $response->statusCode);
        $this->assertEquals($originalContent, $response->body);
        // Ensure it's NOT base64 encoded
        $this->assertNotEquals(base64_encode($originalContent), $response->body);
    }

    public function testStreamAudiobookSetsCorrectMimeType(): void
    {
        $itemRepo = $this->createMockItemRepo();
        $libraryManager = $this->createMockLibraryManager();

        $tempFile = $this->createTestAudioFile('test_audiobook_' . uniqid() . '.m4b', 'audio content');

        $itemRepo->method('findById')->willReturn([
            'id' => 'audiobook-123',
            'name' => 'Test Audiobook',
            'type' => 'audiobook',
            'path' => $tempFile,
            'metadata' => [
                'duration_ms' => 600000,
                'chapters' => [],
            ],
        ]);

        $controller = new AudiobookController($itemRepo, $libraryManager);

        $request = new Request();
        $response = $controller->streamAudiobook($request, ['id' => 'audiobook-123']);

        $this->assertEquals(200, $response->statusCode);
        $this->assertArrayHasKey('Content-Type', $response->headers);
        $this->assertEquals('audio/mp4', $response->headers['Content-Type']);
    }

    public function testStreamAudiobookSupportsRangeRequests(): void
    {
        $itemRepo = $this->createMockItemRepo();
        $libraryManager = $this->createMockLibraryManager();

        $originalContent = '0123456789ABCDEF'; // 16 bytes
        $tempFile = $this->createTestAudioFile('test_audiobook_' . uniqid() . '.m4b', $originalContent);

        $itemRepo->method('findById')->willReturn([
            'id' => 'audiobook-123',
            'name' => 'Test Audiobook',
            'type' => 'audiobook',
            'path' => $tempFile,
            'metadata' => [
                'duration_ms' => 600000,
                'chapters' => [],
            ],
        ]);

        $controller = new AudiobookController($itemRepo, $libraryManager);

        // Request bytes 5-10 (partial content)
        $request = new Request();
        $request->headers['Range'] = 'bytes=5-10';

        $response = $controller->streamAudiobook($request, ['id' => 'audiobook-123']);

        $this->assertEquals(206, $response->statusCode); // Partial content
        $this->assertEquals('56789A', $response->body); // bytes 5-10
        $this->assertArrayHasKey('Content-Range', $response->headers);
        $this->assertEquals('bytes 5-10/16', $response->headers['Content-Range']);
    }

    public function testStreamAudiobookAcceptRangesHeader(): void
    {
        $itemRepo = $this->createMockItemRepo();
        $libraryManager = $this->createMockLibraryManager();

        $tempFile = $this->createTestAudioFile('test_audiobook_' . uniqid() . '.mp3', 'audio content');

        $itemRepo->method('findById')->willReturn([
            'id' => 'audiobook-123',
            'name' => 'Test Audiobook',
            'type' => 'audiobook',
            'path' => $tempFile,
            'metadata' => [
                'duration_ms' => 600000,
                'chapters' => [],
            ],
        ]);

        $controller = new AudiobookController($itemRepo, $libraryManager);

        $request = new Request();
        $response = $controller->streamAudiobook($request, ['id' => 'audiobook-123']);

        $this->assertEquals(200, $response->statusCode);
        $this->assertArrayHasKey('Accept-Ranges', $response->headers);
        $this->assertEquals('bytes', $response->headers['Accept-Ranges']);
        $this->assertEquals('audio/mpeg', $response->headers['Content-Type']);
    }

    public function testStreamAudiobookRejectsPathTraversalEscapingRoot(): void
    {
        $itemRepo = $this->createMockItemRepo();
        $libraryManager = $this->createMockLibraryManager();

        // Create a real file OUTSIDE any allowed media root (system temp dir,
        // which is not under /home, /mnt, /media or /data).
        $outsideDir = sys_get_temp_dir() . '/phlix_outside_' . uniqid();
        mkdir($outsideDir, 0755, true);
        $secretFile = $outsideDir . '/secret.m4b';
        file_put_contents($secretFile, 'should never be served');

        // Build a traversal path that *contains* "/home/" as a substring but
        // resolves (via realpath) to the file outside the allowed roots. This is
        // exactly the bypass the old str_contains() allowlist permitted.
        $traversalPath = '/home/my/../../..' . $secretFile;

        $itemRepo->method('findById')->willReturn([
            'id' => 'audiobook-evil',
            'name' => 'Evil',
            'type' => 'audiobook',
            'path' => $traversalPath,
            'metadata' => ['duration_ms' => 1000, 'chapters' => []],
        ]);

        $controller = new AudiobookController($itemRepo, $libraryManager);

        $request = new Request();
        $response = $controller->streamAudiobook($request, ['id' => 'audiobook-evil']);

        // realpath() resolves $traversalPath to $secretFile (outside allowed
        // roots), so validateMediaPath() must reject it.
        $this->assertContains(
            $response->statusCode,
            [403, 404],
            'Traversal escaping the allowed roots must be rejected (403), '
                . 'or 404 if the resolved path is not reachable at all.'
        );
        $this->assertNotEquals(200, $response->statusCode);
        $this->assertNotEquals(206, $response->statusCode);
        $this->assertStringNotContainsString('should never be served', $response->body);

        unlink($secretFile);
        rmdir($outsideDir);
    }

    public function testStreamAudiobookReturnsCompleteFileWithoutRange(): void
    {
        $itemRepo = $this->createMockItemRepo();
        $libraryManager = $this->createMockLibraryManager();

        // Build a fixture larger than the controller's STREAM_CHUNK_BYTES
        // (256 KiB) so a chunk-loop or any per-read cap that truncated the body
        // would be caught here. ~300 KiB of deterministic bytes.
        $originalContent = str_repeat('PhlixAudiobookChunk', 16000); // 19 * 16000 = 304000 bytes
        $expectedSize = strlen($originalContent);
        $this->assertGreaterThan(256 * 1024, $expectedSize, 'Fixture must exceed the stream chunk size');

        $tempFile = $this->createTestAudioFile('test_audiobook_full_' . uniqid() . '.m4b', $originalContent);

        $itemRepo->method('findById')->willReturn([
            'id' => 'audiobook-123',
            'name' => 'Test Audiobook',
            'type' => 'audiobook',
            'path' => $tempFile,
            'metadata' => [
                'duration_ms' => 600000,
                'chapters' => [],
            ],
        ]);

        $controller = new AudiobookController($itemRepo, $libraryManager);

        // No Range header => must return the COMPLETE file.
        $request = new Request();
        $response = $controller->streamAudiobook($request, ['id' => 'audiobook-123']);

        $this->assertEquals(200, $response->statusCode);
        // Content-Length must equal the full file size...
        $this->assertEquals((string) $expectedSize, $response->headers['Content-Length'] ?? '');
        // ...and the body must actually contain that many bytes (no truncation).
        $this->assertEquals($expectedSize, strlen($response->body));
        $this->assertEquals($originalContent, $response->body);
    }

    public function testStreamAudiobookServesFullRequestedRangeLargerThanChunk(): void
    {
        $itemRepo = $this->createMockItemRepo();
        $libraryManager = $this->createMockLibraryManager();

        // Fixture larger than STREAM_CHUNK_BYTES; request a range that also
        // exceeds it, to prove ranged requests are not silently shrunk.
        $originalContent = str_repeat('R', 600 * 1024); // 600 KiB
        $fileSize = strlen($originalContent);
        $tempFile = $this->createTestAudioFile('test_audiobook_range_' . uniqid() . '.m4b', $originalContent);

        $itemRepo->method('findById')->willReturn([
            'id' => 'audiobook-123',
            'name' => 'Test Audiobook',
            'type' => 'audiobook',
            'path' => $tempFile,
            'metadata' => [
                'duration_ms' => 600000,
                'chapters' => [],
            ],
        ]);

        $controller = new AudiobookController($itemRepo, $libraryManager);

        $start = 1000;
        $end = $fileSize - 1; // request to EOF, a range > chunk size
        $request = new Request();
        $request->headers['Range'] = "bytes={$start}-{$end}";

        $response = $controller->streamAudiobook($request, ['id' => 'audiobook-123']);

        $expectedLength = $end - $start + 1;
        $this->assertEquals(206, $response->statusCode);
        $this->assertEquals((string) $expectedLength, $response->headers['Content-Length'] ?? '');
        $this->assertEquals($expectedLength, strlen($response->body));
        $this->assertEquals("bytes {$start}-{$end}/{$fileSize}", $response->headers['Content-Range'] ?? '');
        $this->assertEquals(substr($originalContent, $start), $response->body);
    }

    public function testStreamAudiobookReturns416ForUnsatisfiableRange(): void
    {
        $itemRepo = $this->createMockItemRepo();
        $libraryManager = $this->createMockLibraryManager();

        $tempFile = $this->createTestAudioFile('test_audiobook_' . uniqid() . '.m4b', 'short content');

        $itemRepo->method('findById')->willReturn([
            'id' => 'audiobook-123',
            'name' => 'Test Audiobook',
            'type' => 'audiobook',
            'path' => $tempFile,
            'metadata' => [
                'duration_ms' => 600000,
                'chapters' => [],
            ],
        ]);

        $controller = new AudiobookController($itemRepo, $libraryManager);

        // Request range beyond file size
        $request = new Request();
        $request->headers['Range'] = 'bytes=100-200';

        $response = $controller->streamAudiobook($request, ['id' => 'audiobook-123']);

        $this->assertEquals(416, $response->statusCode);
    }
}
