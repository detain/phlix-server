<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Media\Library\AudiobookLibraryManager;
use Phlix\Media\Library\AudiobookProgress;
use Phlix\Media\Library\ItemRepository;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

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
 * @author Phlix Development Team
 * @version 1.0.0
 * @description REST API for audiobook library browsing, chapters, and progress tracking
 * @since 0.18.0
 */
class AudiobookController
{
    /**
     * Size of each bounded read while assembling the response body.
     *
     * The framework {@see Response} only supports a fully-materialised string
     * body and {@see Response::send()} emits it with a single `echo`, so there
     * is no streamed/chunked-write primitive to hand off a file handle to. We
     * therefore still buffer the served bytes, but we read them in bounded
     * chunks (rather than one huge `fread()`) and we serve EXACTLY what the
     * client asked for: the whole file for a plain GET and the full requested
     * range for a Range request. We must never silently truncate, because a
     * short body with a full-size Content-Length makes clients hang, and a
     * short Content-Length silently corrupts the download.
     *
     * TODO: stream without buffering once Response supports a file/callable
     * body (e.g. a Workerman chunked-write response or readfile/fpassthru in
     * the emit path). Until then correctness (complete bytes) takes priority
     * over the memory cost of buffering a large body.
     */
    private const STREAM_CHUNK_BYTES = 256 * 1024; // 256 KiB

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
        $chaptersRaw = $metadata['chapters'] ?? [];
        $chapters = is_array($chaptersRaw) ? $chaptersRaw : [];

        $formattedChapters = [];
        foreach ($chapters as $index => $chapter) {
            if (!is_array($chapter)) {
                continue;
            }
            $formattedChapters[] = [
                'index' => $index,
                'title' => is_string($chapter['title'] ?? null) ? $chapter['title'] : "Chapter " . ($index + 1),
                'start_ms' => is_int($chapter['start_ms'] ?? null) ? $chapter['start_ms'] : 0,
                'end_ms' => is_int($chapter['end_ms'] ?? null) ? $chapter['end_ms'] : 0,
                'duration_ms' => is_int($chapter['duration_ms'] ?? null) ? $chapter['duration_ms'] : 0,
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
        $rawData = is_string($body) ? json_decode($body, true) : null;
        $data = is_array($rawData) ? $rawData : [];

        $positionMsRaw = $data['position_ms'] ?? 0;
        $positionMs = is_int($positionMsRaw)
            ? $positionMsRaw
            : (is_numeric($positionMsRaw) ? (int) $positionMsRaw : 0);
        $positionMs = max(0, $positionMs);

        $currentChapterIndexRaw = $data['current_chapter_index'] ?? 0;
        $currentChapterIndex = is_int($currentChapterIndexRaw)
            ? $currentChapterIndexRaw
            : (is_numeric($currentChapterIndexRaw) ? (int) $currentChapterIndexRaw : 0);
        $currentChapterIndex = max(0, $currentChapterIndex);

        $completedChaptersRaw = is_array($data['completed_chapters'] ?? null) ? $data['completed_chapters'] : [];
        $completedChapters = array_values(array_filter($completedChaptersRaw, 'is_int'));

        $percentCompleteRaw = $data['percent_complete'] ?? 0.0;
        $percentComplete = is_int($percentCompleteRaw) || is_float($percentCompleteRaw)
            ? (float) $percentCompleteRaw
            : (is_numeric($percentCompleteRaw) ? (float) $percentCompleteRaw : 0.0);
        $percentComplete = min(100.0, max(0.0, $percentComplete));

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
     * Supports HTTP Range requests for seeking within the file.
     * Streams file directly without base64 encoding.
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
            return (new Response())->status(404)->json(['error' => 'Audiobook not found']);
        }

        // Validate path is within allowed media directory
        if (!$this->validateMediaPath($path)) {
            return (new Response())->status(403)->json(['error' => 'Forbidden']);
        }

        $fileSize = filesize($path);
        if ($fileSize === false) {
            return (new Response())->status(500)->json(['error' => 'Failed to get file size']);
        }

        $chapterParam = $request->query['chapter'] ?? null;
        $offsetParam = $request->query['offset'] ?? null;

        $chapterIndex = is_numeric($chapterParam) ? (int) $chapterParam : 0;
        $offsetMs = is_numeric($offsetParam) ? (int) $offsetParam : 0;

        // Get chapters for byte offset calculation
        /** @var array<string, mixed> $metadata */
        $metadata = is_array($audiobook['metadata'] ?? null) ? $audiobook['metadata'] : [];
        $chaptersRaw = $metadata['chapters'] ?? null;
        $chapters = is_array($chaptersRaw) ? $chaptersRaw : [];

        $byteOffset = 0;
        $currentChapter = $chapters[$chapterIndex] ?? null;
        if (is_array($currentChapter) && $offsetMs > 0) {
            // For M4B files, we can seek to chapter start + offset
            // This requires an M4B-aware streamer or FFmpeg
            // For now, we serve the file directly and let the client handle chapter boundaries
            $chapterStartMsRaw = $currentChapter['start_ms'] ?? null;
            $chapterStartMs = is_int($chapterStartMsRaw) || is_float($chapterStartMsRaw) ? $chapterStartMsRaw : 0;
            $seekMs = $chapterStartMs + $offsetMs;

            // Calculate approximate byte offset based on duration
            // This is a simplification; real implementation would use FFmpeg for precise seeking
            $durationMsRaw = $metadata['duration_ms'] ?? null;
            $durationMs = is_int($durationMsRaw) || is_float($durationMsRaw) ? $durationMsRaw : 0;
            if ($durationMs > 0) {
                $byteOffset = (int)(($seekMs / $durationMs) * $fileSize);
            }
        }

        // Use finfo for actual MIME detection if available
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($path);
        if ($detectedMime !== false && str_starts_with($detectedMime, 'audio/')) {
            $mimeType = $detectedMime;
        } else {
            // Fallback to extension-based detection
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mimeType = match ($extension) {
                'm4b', 'm4a' => 'audio/mp4',
                'mp3' => 'audio/mpeg',
                default => 'application/octet-stream',
            };
        }

        // Handle Range requests for seeking.
        // Read via getHeader() (case-insensitive) rather than the raw
        // $request->headers['Range'] array access: parseHeaders() stores
        // header keys upper-cased (e.g. "RANGE"), so a mixed-case lookup
        // never matched and range requests silently fell through to 200.
        $rangeHeader = $request->getHeader('Range');
        $start = $byteOffset;
        $end = $fileSize - 1;

        if ($rangeHeader !== null) {
            // Parse Range header (e.g., "bytes=1024-2048" or "bytes=1024-")
            if (preg_match('/bytes=(\d+)-(\d*)/', $rangeHeader, $matches)) {
                $start = (int) $matches[1];
                $end = $matches[2] !== '' ? (int) $matches[2] : $fileSize - 1;

                if ($start > $end || $start >= $fileSize) {
                    return (new Response())
                        ->status(416)
                        ->header('Content-Range', "bytes */{$fileSize}")
                        ->json(['error' => 'Range not satisfiable']);
                }
            }
        }

        // Open file and seek to start position
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return (new Response())->status(500)->json(['error' => 'Failed to open file']);
        }

        if (fseek($handle, $start) === -1) {
            fclose($handle);
            return (new Response())->status(500)->json(['error' => 'Failed to seek file']);
        }

        $length = $end - $start + 1;
        if ($length < 1) {
            fclose($handle);
            return (new Response())->status(416)->json(['error' => 'Invalid range length']);
        }

        // Read the full requested length in bounded chunks rather than a single
        // large fread(). This keeps per-iteration memory at STREAM_CHUNK_BYTES
        // while still serving EXACTLY what was asked for: the whole file for a
        // plain GET, or the complete requested range for a Range request. We
        // never cap/shrink the served bytes here — a truncated body either
        // hangs the client (Content-Length too large) or corrupts the download
        // (Content-Length too small).
        $content = '';
        $remaining = $length;
        while ($remaining > 0) {
            $chunkSize = (int) min(self::STREAM_CHUNK_BYTES, $remaining);
            /** @var positive-int $chunkSize */
            $chunk = fread($handle, $chunkSize);
            if ($chunk === false) {
                fclose($handle);
                return (new Response())->status(500)->json(['error' => 'Failed to read file']);
            }
            if ($chunk === '') {
                // Reached EOF earlier than expected; stop and serve what we have.
                break;
            }
            $content .= $chunk;
            $remaining -= strlen($chunk);
        }
        fclose($handle);

        // The actual number of bytes read may be shorter than $length if EOF was
        // hit; reflect the true served range in the headers.
        $served = strlen($content);
        $end = $start + max(0, $served - 1);

        $response = (new Response())
            ->status($rangeHeader !== null ? 206 : 200)
            ->header('Content-Type', $mimeType)
            ->header('Content-Length', (string) $served)
            ->header('Accept-Ranges', 'bytes');

        if ($rangeHeader !== null) {
            $response->header('Content-Range', "bytes {$start}-{$end}/{$fileSize}");
        }

        return $response->body($content);
    }

    /**
     * Validates that a media path is within allowed media directories.
     *
     * @param string $path The path to validate
     * @return bool True if path is within allowed directories, false otherwise
     *
     * @since 2.1.0
     */
    private function validateMediaPath(string $path): bool
    {
        // Resolve symlinks and `../` segments to a canonical absolute path.
        // realpath() returns false for non-existent paths, which also rejects
        // any traversal target that does not actually resolve to a real file.
        $realPath = realpath($path);
        if ($realPath === false) {
            return false;
        }

        // Media files must live UNDER one of the allowed roots. We compare with
        // str_starts_with() against the canonical real path (not str_contains),
        // so a path that merely *contains* an allowed segment somewhere in the
        // middle (e.g. "/etc/passwd" reached via "/home/../etc/passwd", or
        // "/var/www/home/secrets") cannot escape the allowed roots.
        //
        // NOTE: there is currently no configured library-root list available to
        // this controller; until one is wired in, we fall back to the well-known
        // mount prefixes. Each prefix ends with a trailing slash so "/home/" can
        // never match a sibling directory such as "/home-backup/".
        $allowedRoots = [
            '/media/',
            '/mnt/',
            '/data/',
            '/home/',
        ];

        foreach ($allowedRoots as $root) {
            if (str_starts_with($realPath . '/', $root)) {
                return true;
            }
        }

        return false;
    }
}
