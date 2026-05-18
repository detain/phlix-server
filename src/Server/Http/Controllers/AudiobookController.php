<?php

declare(strict_types=1);

namespace Phlex\Server\Http\Controllers;

use Phlex\Media\Library\AudiobookLibraryManager;
use Phlex\Media\Library\AudiobookProgress;
use Phlex\Media\Library\ItemRepository;
use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;

/**
 * AudiobookController handles audiobook library API endpoints.
 *
 * Provides:
 * - GET /audiobooks — list all audiobooks
 * - GET /audiobooks/{id} — get single audiobook with chapters
 * - GET /audiobooks/{id}/chapters — get chapter list
 * - GET /audiobooks/{id}/progress — get user's progress
 * - POST /audiobooks/{id}/progress — save user's progress
 * - GET /audiobooks/{id}/read — HTML audiobook player
 * - GET /audiobooks/{id}/stream?chapter=N&offset=MS — stream with chapter resume
 *
 * @author Phlex Development Team
 * @version 1.0.0
 * @description REST API for audiobook library browsing, chapters, and progress tracking
 * @since 0.18.0
 */
class AudiobookController
{
    /** @var ItemRepository Repository for media item access */
    private ItemRepository $itemRepo;

    /** @var AudiobookLibraryManager Library manager for audiobook operations */
    private AudiobookLibraryManager $libraryManager;

    /** @var string|null Current user ID (from auth context) */
    private ?string $userId = null;

    /**
     * Constructor for AudiobookController.
     *
     * @param ItemRepository $itemRepo Repository for media item access
     * @param AudiobookLibraryManager $libraryManager Library manager
     *
     * @since 0.18.0
     */
    public function __construct(
        ItemRepository $itemRepo,
        AudiobookLibraryManager $libraryManager
    ) {
        $this->itemRepo = $itemRepo;
        $this->libraryManager = $libraryManager;
    }

    /**
     * Sets the current user ID from the request context.
     *
     * @param string $userId The authenticated user's ID
     * @return void
     */
    public function setUserId(string $userId): void
    {
        $this->userId = $userId;
    }

    /**
     * Lists all audiobooks (web portal API).
     *
     * GET /audiobooks
     *
     * Query params:
     *   - library_id: Filter by library
     *   - limit: Maximum items (default 50)
     *   - offset: Pagination offset (default 0)
     *
     * @param Request $request The HTTP request
     * @return Response JSON response with audiobooks array
     *
     * @since 0.18.0
     */
    public function listAudiobooks(Request $request): Response
    {
        $libraryIdParam = $request->query['library_id'] ?? null;
        $limitParam = $request->query['limit'] ?? null;
        $offsetParam = $request->query['offset'] ?? null;
        $libraryId = is_string($libraryIdParam) ? $libraryIdParam : null;
        $limit = min(100, max(1, is_numeric($limitParam) ? (int) $limitParam : 50));
        $offset = max(0, is_numeric($offsetParam) ? (int) $offsetParam : 0);

        if ($libraryId !== null) {
            $items = $this->itemRepo->getByLibrary($libraryId, $limit, $offset);
        } else {
            // Get all audiobook items across all libraries
            $items = $this->itemRepo->searchFuzzy('', 1000);
        }

        $audiobooks = array_filter($items, fn($item) => ($item['type'] ?? '') === 'audiobook');

        return (new Response())->json([
            'audiobooks' => array_values($audiobooks),
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * Gets a single audiobook by ID with full chapter list.
     *
     * GET /audiobooks/{id}
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters including 'id'
     * @return Response JSON response with audiobook data and chapters
     *
     * @since 0.18.0
     */
    public function getAudiobook(Request $request, array $params): Response
    {
        $audiobookId = $params['id'] ?? null;

        if ($audiobookId === null) {
            return (new Response())->status(400)->json(['error' => 'Audiobook ID is required']);
        }

        $audiobook = $this->itemRepo->findById($audiobookId);

        if ($audiobook === null || ($audiobook['type'] ?? '') !== 'audiobook') {
            return (new Response())->status(404)->json(['error' => 'Audiobook not found']);
        }

        /** @var array<string, mixed> $metadata */
        $metadata = is_array($audiobook['metadata'] ?? null) ? $audiobook['metadata'] : [];
        $chapters = $metadata['chapters'] ?? [];

        return (new Response())->json([
            'audiobook' => [
                'id' => $audiobook['id'],
                'title' => $audiobook['name'],
                'author' => $metadata['author'] ?? null,
                'narrator' => $metadata['narrator'] ?? null,
                'series' => $metadata['series'] ?? null,
                'series_position' => $metadata['series_position'] ?? null,
                'description' => $metadata['description'] ?? null,
                'duration_ms' => $metadata['duration_ms'] ?? null,
                'language' => $metadata['language'] ?? null,
                'cover_url' => $metadata['cover_path'] ?? null,
                'chapters' => $chapters,
            ],
        ]);
    }

    /**
     * Gets the chapter list for an audiobook.
     *
     * GET /audiobooks/{id}/chapters
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters including 'id'
     * @return Response JSON response with chapter list
     *
     * @since 0.18.0
     */
    public function getChapters(Request $request, array $params): Response
    {
        $audiobookId = $params['id'] ?? null;

        if ($audiobookId === null) {
            return (new Response())->status(400)->json(['error' => 'Audiobook ID is required']);
        }

        $audiobook = $this->itemRepo->findById($audiobookId);

        if ($audiobook === null || ($audiobook['type'] ?? '') !== 'audiobook') {
            return (new Response())->status(404)->json(['error' => 'Audiobook not found']);
        }

        /** @var array<string, mixed> $metadata */
        $metadata = is_array($audiobook['metadata'] ?? null) ? $audiobook['metadata'] : [];
        $chapters = $metadata['chapters'] ?? [];

        $formattedChapters = [];
        foreach ($chapters as $index => $chapter) {
            $formattedChapters[] = [
                'index' => $index,
                'title' => $chapter['title'] ?? "Chapter " . ($index + 1),
                'start_ms' => $chapter['start_ms'] ?? 0,
                'end_ms' => $chapter['end_ms'] ?? 0,
                'duration_ms' => $chapter['duration_ms'] ?? 0,
            ];
        }

        return (new Response())->json([
            'chapters' => $formattedChapters,
        ]);
    }

    /**
     * Gets the user's progress for an audiobook.
     *
     * GET /audiobooks/{id}/progress
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters including 'id'
     * @return Response JSON response with user's progress
     *
     * @since 0.18.0
     */
    public function getProgress(Request $request, array $params): Response
    {
        $audiobookId = $params['id'] ?? null;

        if ($audiobookId === null) {
            return (new Response())->status(400)->json(['error' => 'Audiobook ID is required']);
        }

        if ($this->userId === null) {
            return (new Response())->status(401)->json(['error' => 'Authentication required']);
        }

        $progress = $this->libraryManager->getProgress($this->userId, $audiobookId);

        return (new Response())->json([
            'progress' => $progress->toArray(),
        ]);
    }

    /**
     * Saves the user's progress for an audiobook.
     *
     * POST /audiobooks/{id}/progress
     *
     * Request body (JSON):
     *   - position_ms: int (current position within chapter in milliseconds)
     *   - current_chapter_index: int (0-based chapter index)
     *   - completed_chapters: array<int, int> (chapter_index => position_ms when completed)
     *   - percent_complete: float (0.0-100.0)
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters including 'id'
     * @return Response JSON response confirming save
     *
     * @since 0.18.0
     */
    public function saveProgress(Request $request, array $params): Response
    {
        $audiobookId = $params['id'] ?? null;

        if ($audiobookId === null) {
            return (new Response())->status(400)->json(['error' => 'Audiobook ID is required']);
        }

        if ($this->userId === null) {
            return (new Response())->status(401)->json(['error' => 'Authentication required']);
        }

        $body = $request->query['body'] ?? '{}';
        if (is_string($body)) {
            $data = json_decode($body, true) ?? [];
        } else {
            $data = is_array($body) ? $body : [];
        }

        $positionMs = max(0, (int)($data['position_ms'] ?? 0));
        $currentChapterIndex = max(0, (int)($data['current_chapter_index'] ?? 0));
        $completedChapters = is_array($data['completed_chapters'] ?? null) ? $data['completed_chapters'] : [];
        $percentComplete = min(100.0, max(0.0, (float)($data['percent_complete'] ?? 0.0)));

        $progress = new AudiobookProgress(
            $audiobookId,
            $this->userId,
            $positionMs,
            $currentChapterIndex,
            $completedChapters,
            $percentComplete,
            time()
        );

        $this->libraryManager->saveProgress($this->userId, $audiobookId, $progress);

        return (new Response())->json([
            'message' => 'Progress saved',
            'progress' => $progress->toArray(),
        ]);
    }

    /**
     * Returns the HTML audiobook player page.
     *
     * GET /audiobooks/{id}/read
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters including 'id'
     * @return Response HTML response with audiobook player stub
     *
     * @since 0.18.0
     */
    public function readAudiobook(Request $request, array $params): Response
    {
        $audiobookId = $params['id'] ?? null;

        if ($audiobookId === null) {
            return (new Response())->status(400)->json(['error' => 'Audiobook ID is required']);
        }

        $audiobook = $this->itemRepo->findById($audiobookId);

        if ($audiobook === null || ($audiobook['type'] ?? '') !== 'audiobook') {
            return (new Response())->status(404)->json(['error' => 'Audiobook not found']);
        }

        /** @var array<string, mixed> $metadata */
        $metadata = is_array($audiobook['metadata'] ?? null) ? $audiobook['metadata'] : [];

        // Return JSON with audiobook info for client-side player
        return (new Response())->json([
            'audiobook' => $audiobook,
            'metadata' => $metadata,
            'message' => 'Audiobook player ready',
        ]);
    }

    /**
     * Streams an audiobook with optional chapter resume.
     *
     * GET /audiobooks/{id}/stream?chapter=N&offset=MS
     *
     * Supports resuming in-chapter with chapter index and millisecond offset.
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters including 'id'
     * @return Response Streaming response or error
     *
     * @since 0.18.0
     */
    public function streamAudiobook(Request $request, array $params): Response
    {
        $audiobookId = $params['id'] ?? null;

        if ($audiobookId === null) {
            return (new Response())->status(400)->json(['error' => 'Audiobook ID is required']);
        }

        $audiobook = $this->itemRepo->findById($audiobookId);

        if ($audiobook === null || ($audiobook['type'] ?? '') !== 'audiobook') {
            return (new Response())->status(404)->json(['error' => 'Audiobook not found']);
        }

        $path = is_string($audiobook['path'] ?? null) ? $audiobook['path'] : '';

        if (empty($path) || !file_exists($path)) {
            return (new Response())->status(404)->json(['error' => 'File not found']);
        }

        $chapterParam = $request->query['chapter'] ?? null;
        $offsetParam = $request->query['offset'] ?? null;

        $chapterIndex = is_numeric($chapterParam) ? (int) $chapterParam : 0;
        $offsetMs = is_numeric($offsetParam) ? (int) $offsetParam : 0;

        // Get chapters for byte offset calculation
        /** @var array<string, mixed> $metadata */
        $metadata = is_array($audiobook['metadata'] ?? null) ? $audiobook['metadata'] : [];
        $chapters = $metadata['chapters'] ?? [];

        $byteOffset = 0;
        if (isset($chapters[$chapterIndex]) && $offsetMs > 0) {
            // For M4B files, we can seek to chapter start + offset
            // This requires an M4B-aware streamer or FFmpeg
            // For now, we serve the file directly and let the client handle chapter boundaries
            $chapterStartMs = $chapters[$chapterIndex]['start_ms'] ?? 0;
            $seekMs = $chapterStartMs + $offsetMs;

            // Calculate approximate byte offset based on duration
            // This is a simplification; real implementation would use FFmpeg for precise seeking
            $durationMs = $metadata['duration_ms'] ?? 0;
            if ($durationMs > 0) {
                $fileSize = filesize($path) ?: 0;
                $byteOffset = (int)(($seekMs / $durationMs) * $fileSize);
            }
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimeType = match ($extension) {
            'm4b' => 'audio/mp4',
            'm4a' => 'audio/mp4',
            'mp3' => 'audio/mpeg',
            default => 'application/octet-stream',
        };

        // Read file and serve
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return (new Response())->status(500)->json(['error' => 'Failed to open file']);
        }

        fseek($handle, $byteOffset);
        $content = '';
        while (!feof($handle)) {
            $chunk = fread($handle, 65536);
            if ($chunk === false) {
                break;
            }
            $content .= $chunk;
        }
        fclose($handle);

        if ($content === '' && $byteOffset > 0) {
            return (new Response())->status(416)->json(['error' => 'Range not satisfiable']);
        }

        $contentLength = strlen($content);

        return (new Response())
            ->header('Content-Type', $mimeType)
            ->header('Content-Length', (string)$contentLength)
            ->header('Accept-Ranges', 'bytes')
            ->text(base64_encode($content));
    }
}
