<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Auth\SignedUrl;
use Phlix\Common\Fs\LibraryRootGuard;
use Phlix\Media\Library\BookProgress;
use Phlix\Media\Library\BookProgressStore;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\RatingGate;
use Phlix\Media\Metadata\OpdsFeedBuilder;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\WebPortal\PageRenderer;

/**
 * BookController handles book library and OPDS feed endpoints.
 *
 * Provides:
 * - OPDS 1.2 compliant feeds at /opds/v1.2/*
 * - Web portal endpoints at /books/*
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description REST API for book library browsing and OPDS feeds
 * @since 0.17.0
 */
class BookController
{
    /** @var ItemRepository Repository for media item access */
    private ItemRepository $itemRepo;

    /** @var LibraryManager Library manager for library operations */
    private LibraryManager $libraryManager;

    /** @var OpdsFeedBuilder OPDS feed builder */
    private OpdsFeedBuilder $opdsBuilder;

    /** @var string|null Current user ID (from auth context) */
    private ?string $userId = null;

    /** @var BookProgressStore|null Progress store for reading progress */
    private ?BookProgressStore $progressStore = null;

    /**
     * Shared parental-control access gate. Null in legacy/no-container contexts,
     * in which case every gate check is a strict no-op (owner-safe).
     */
    private ?RatingGate $ratingGate;

    /**
     * Sets the progress store for reading progress tracking.
     *
     * @param BookProgressStore $progressStore Progress store
     * @return void
     */
    public function setProgressStore(BookProgressStore $progressStore): void
    {
        $this->progressStore = $progressStore;
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
     * Constructor for BookController.
     *
     * @param ItemRepository $itemRepo Repository for media item access
     * @param LibraryManager $libraryManager Library manager
     * @param OpdsFeedBuilder $opdsBuilder OPDS feed builder
     *
     * @since 0.17.0
     */
    public function __construct(
        ItemRepository $itemRepo,
        LibraryManager $libraryManager,
        OpdsFeedBuilder $opdsBuilder,
        ?RatingGate $ratingGate = null
    ) {
        $this->itemRepo = $itemRepo;
        $this->libraryManager = $libraryManager;
        $this->opdsBuilder = $opdsBuilder;
        $this->ratingGate = $ratingGate;
    }

    /**
     * Whether a book row is over the requesting profile's parental content-rating
     * cap (Finding 3). A book is a `media_items` row, so it can carry a
     * `content_rating`; a capped profile must not obtain its signed read/download
     * URLs (mint gate) nor its bytes (serve gate). Strict no-op — returns false —
     * for the owner/admin, an un-capped profile, an unauthenticated request (no
     * resolvable userId — e.g. a bare signed-token serve), or when the gate is
     * unwired.
     *
     * @param array<string, mixed> $book The book row.
     */
    private function bookOverCap(Request $request, array $book): bool
    {
        if ($this->ratingGate === null) {
            return false;
        }

        $filter = $this->ratingGate->resolveFilterForUser($request->userId ?? '');
        if ($filter === null) {
            return false;
        }

        return !$this->ratingGate->isAllowed($book, $filter);
    }

    /**
     * Returns the OPDS root feed.
     *
     * GET /opds/v1.2
     *
     * @param Request $request The HTTP request
     * @return Response OPDS Atom XML feed
     *
     * @since 0.17.0
     */
    public function opdsRoot(Request $request): Response
    {
        $xml = $this->opdsBuilder->buildRootFeed();

        return (new Response())
            ->text($xml)
            ->header('Content-Type', 'application/atom+xml; charset=utf-8; profile=opds-catalog');
    }

    /**
     * Returns the OPDS navigation feed for libraries.
     *
     * GET /opds/v1.2/libraries
     *
     * @param Request $request The HTTP request
     * @return Response OPDS Atom XML navigation feed
     *
     * @since 0.17.0
     */
    public function opdsLibraries(Request $request): Response
    {
        $libraries = $this->libraryManager->getAllLibraries();
        $bookLibraries = array_filter($libraries, fn($lib) => ($lib['type'] ?? '') === 'book');

        $xml = $this->opdsBuilder->buildNavigationFeed(array_values($bookLibraries));

        return (new Response())
            ->text($xml)
            ->header('Content-Type', 'application/atom+xml; charset=utf-8; profile=opds-catalog; kind=navigation');
    }

    /**
     * Returns the OPDS acquisition feed for books in a library.
     *
     * GET /opds/v1.2/libraries/{id}
     *
     * Query params:
     *   - offset: Pagination offset (default 0)
     *   - limit: Maximum items per page (default 50)
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters including 'id'
     * @return Response OPDS Atom XML acquisition feed
     *
     * @since 0.17.0
     */
    public function opdsLibraryBooks(Request $request, array $params): Response
    {
        $libraryId = $params['id'] ?? null;

        if ($libraryId === null) {
            return (new Response())->status(400)->json(['error' => 'Library ID is required']);
        }

        $library = $this->libraryManager->getLibrary($libraryId);
        if ($library === null) {
            return (new Response())->status(404)->json(['error' => 'Library not found']);
        }

        if (($library['type'] ?? '') !== 'book') {
            return (new Response())->status(404)->json(['error' => 'Not a book library']);
        }

        $offsetParam = $request->query['offset'] ?? null;
        $limitParam = $request->query['limit'] ?? null;
        $offset = max(0, is_numeric($offsetParam) ? (int) $offsetParam : 0);
        $limit = min(100, max(1, is_numeric($limitParam) ? (int) $limitParam : 50));

        // Get total count for pagination (use COUNT query instead of loading 10K items)
        $total = $this->itemRepo->countByType($libraryId, 'book');

        $xml = $this->opdsBuilder->buildAcquisitionFeed($libraryId, $limit, $offset, $total);

        return (new Response())
            ->text($xml)
            ->header('Content-Type', 'application/atom+xml; charset=utf-8; profile=opds-catalog; kind=acquisition');
    }

    /**
     * Returns the cover image for a book.
     *
     * GET /opds/v1.2/books/{id}/cover
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters including 'id'
     * @return Response Image response with cover or 404
     *
     * @since 0.17.0
     */
    public function opdsBookCover(Request $request, array $params): Response
    {
        return $this->getCover($request, $params);
    }

    /**
     * Lists all books (web portal API).
     *
     * GET /books
     *
     * Query params:
     *   - library_id: Filter by library
     *   - limit: Maximum items (default 50)
     *   - offset: Pagination offset (default 0)
     *
     * @param Request $request The HTTP request
     * @return Response JSON response with books array
     *
     * @since 0.17.0
     */
    public function listBooks(Request $request): Response
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
            // Get all book items across all libraries
            $items = $this->itemRepo->searchFuzzy('', 1000);
        }

        $books = array_filter($items, fn($item) => ($item['type'] ?? '') === 'book');

        return (new Response())->json([
            'books' => array_values($books),
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * Gets a single book by ID.
     *
     * GET /books/{id}
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters including 'id'
     * @return Response JSON response with book data or 404
     *
     * @since 0.17.0
     */
    public function getBook(Request $request, array $params): Response
    {
        $bookId = $params['id'] ?? null;

        if ($bookId === null) {
            return (new Response())->status(400)->json(['error' => 'Book ID is required']);
        }

        $book = $this->itemRepo->findById($bookId);

        if ($book === null || ($book['type'] ?? '') !== 'book') {
            return (new Response())->status(404)->json(['error' => 'Book not found']);
        }

        // Parental cap (Finding 3): a capped profile requesting an over-cap book
        // gets a 404 so no signed read/download/cover URLs are ever minted for it.
        if ($this->bookOverCap($request, $book)) {
            return (new Response())->status(404)->json(['error' => 'Book not found']);
        }

        return (new Response())->json(['book' => $this->withSignedUrls($book, $bookId)]);
    }

    /**
     * Adds short-lived signed `cover_url`/`read_url`/`download_url` fields to a
     * book row.
     *
     * The cover/read/download routes can't carry a Bearer header from an
     * `<img>`/reader/`<a download>`, so this now-gated detail endpoint mints the
     * tokens the {@see \Phlix\Server\Http\Middleware\SignedUrlMiddleware} verifies.
     *
     * @param array<string, mixed> $book   The raw book row.
     * @param string               $bookId The book id (for URL construction).
     *
     * @return array<string, mixed> The book row with signed-URL fields added.
     */
    private function withSignedUrls(array $book, string $bookId): array
    {
        $signer = SignedUrl::fromEnv();
        $base = '/api/v1/books/' . $bookId;
        $book['cover_url'] = $signer->mint($base . '/cover');
        $book['read_url'] = $signer->mint($base . '/read');
        $book['download_url'] = $signer->mint($base . '/download');

        return $book;
    }

    /**
     * Returns the HTML reader page for a book.
     *
     * GET /books/{id}/read?page=1
     *
     * Returns JSON with book info, signed URLs, and reading progress for
     * client-side EPUB rendering.
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters including 'id'
     * @return Response JSON response with book reader data
     *
     * @since 0.17.0
     */
    public function readBook(Request $request, array $params): Response
    {
        $bookId = $params['id'] ?? null;

        if ($bookId === null) {
            return (new Response())->status(400)->json(['error' => 'Book ID is required']);
        }

        $book = $this->itemRepo->findById($bookId);

        if ($book === null || ($book['type'] ?? '') !== 'book') {
            return (new Response())->status(404)->json(['error' => 'Book not found']);
        }

        // Parental cap (Finding 3): defense-in-depth for a session-authenticated
        // reader — a capped profile never gets book data or signed URLs for an
        // over-cap book. No-op for a bare signed-token fetch (no userId).
        if ($this->bookOverCap($request, $book)) {
            return (new Response())->status(404)->json(['error' => 'Book not found']);
        }

        /** @var array<string, mixed> $metadata */
        $metadata = is_array($book['metadata'] ?? null) ? $book['metadata'] : [];
        $pageParam = $request->query['page'] ?? null;
        $page = max(1, is_numeric($pageParam) ? (int) $pageParam : 1);

        // Build chapters/spine from metadata for client-side rendering
        $chapters = $this->buildChapterList($metadata);
        $totalPages = is_int($metadata['pages'] ?? null) ? (int) $metadata['pages'] : count($chapters);

        // Get reading progress if user is authenticated and progress store is available
        $progress = null;
        if ($this->userId !== null && $this->progressStore !== null) {
            $progress = $this->progressStore->getProgress($this->userId, $bookId);
        }

        // Return book data with signed URLs and progress info for client reader
        return (new Response())->json([
            'book' => $this->withSignedUrls($book, $bookId),
            'metadata' => $metadata,
            'current_page' => $progress !== null ? $progress->current_page : $page,
            'total_pages' => $totalPages,
            'chapters' => $chapters,
            'progress' => $progress?->toArray(),
            'message' => 'Reader ready',
        ]);
    }

    /**
     * Gets the user's reading progress for a book.
     *
     * GET /books/{id}/progress
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters including 'id'
     * @return Response JSON response with user's reading progress
     *
     * @since 0.17.0
     */
    public function getBookProgress(Request $request, array $params): Response
    {
        $bookId = $params['id'] ?? null;

        if ($bookId === null) {
            return (new Response())->status(400)->json(['error' => 'Book ID is required']);
        }

        if ($this->userId === null) {
            return (new Response())->status(401)->json(['error' => 'Authentication required']);
        }

        if ($this->progressStore === null) {
            return (new Response())->status(503)->json(['error' => 'Progress tracking not available']);
        }

        $progress = $this->progressStore->getProgress($this->userId, $bookId);

        return (new Response())->json([
            'progress' => $progress?->toArray() ?? BookProgress::fresh($bookId, $this->userId)->toArray(),
        ]);
    }

    /**
     * Saves the user's reading progress for a book.
     *
     * POST /books/{id}/progress
     *
     * Request body (JSON):
     *   - position_ms: int (current position within book in milliseconds)
     *   - current_page: int (current page number, 1-based)
     *   - total_pages: int (total pages in the book)
     *   - percent_complete: float (0.0-100.0)
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters including 'id'
     * @return Response JSON response confirming save
     *
     * @since 0.17.0
     */
    public function saveBookProgress(Request $request, array $params): Response
    {
        $bookId = $params['id'] ?? null;

        if ($bookId === null) {
            return (new Response())->status(400)->json(['error' => 'Book ID is required']);
        }

        if ($this->userId === null) {
            return (new Response())->status(401)->json(['error' => 'Authentication required']);
        }

        if ($this->progressStore === null) {
            return (new Response())->status(503)->json(['error' => 'Progress tracking not available']);
        }

        $body = $request->query['body'] ?? '{}';
        $rawData = is_string($body) ? json_decode($body, true) : null;
        $data = is_array($rawData) ? $rawData : [];

        $positionMsRaw = $data['position_ms'] ?? 0;
        $positionMs = is_int($positionMsRaw) || is_float($positionMsRaw)
            ? (int) $positionMsRaw
            : (is_numeric($positionMsRaw) ? (int) $positionMsRaw : 0);
        $positionMs = max(0, $positionMs);

        $currentPageRaw = $data['current_page'] ?? 1;
        $currentPage = is_int($currentPageRaw)
            ? $currentPageRaw
            : (is_numeric($currentPageRaw) ? (int) $currentPageRaw : 1);
        $currentPage = max(1, $currentPage);

        $totalPagesRaw = $data['total_pages'] ?? 0;
        $totalPages = is_int($totalPagesRaw)
            ? $totalPagesRaw
            : (is_numeric($totalPagesRaw) ? (int) $totalPagesRaw : 0);
        $totalPages = max(0, $totalPages);

        $percentCompleteRaw = $data['percent_complete'] ?? 0.0;
        $percentComplete = is_int($percentCompleteRaw) || is_float($percentCompleteRaw)
            ? (float) $percentCompleteRaw
            : (is_numeric($percentCompleteRaw) ? (float) $percentCompleteRaw : 0.0);
        $percentComplete = min(100.0, max(0.0, $percentComplete));

        $progress = new BookProgress(
            $bookId,
            $this->userId,
            $positionMs,
            $currentPage,
            $totalPages,
            $percentComplete,
            time()
        );

        $this->progressStore->saveProgress($progress);

        return (new Response())->json([
            'message' => 'Progress saved',
            'progress' => $progress->toArray(),
        ]);
    }

    /**
     * Builds a chapter/spine list from book metadata for client-side rendering.
     *
     * @param array<string, mixed> $metadata Book metadata
     * @return list<array{index:int, title:string, start_ms:int, end_ms:int, href:string}>
     */
    private function buildChapterList(array $metadata): array
    {
        $chapters = [];
        $spine = $metadata['spine'] ?? $metadata['chapters'] ?? [];

        if (!is_array($spine)) {
            // Fallback: single chapter representing the whole book
            return [[
                'index' => 0,
                'title' => is_string($metadata['title'] ?? null) ? $metadata['title'] : 'Start',
                'start_ms' => 0,
                'end_ms' => 0,
                'href' => 'chapter0.xhtml',
            ]];
        }

        foreach ($spine as $index => $chapter) {
            if (!is_array($chapter)) {
                continue;
            }

            $title = is_string($chapter['title'] ?? null)
                ? $chapter['title']
                : (is_string($chapter['name'] ?? null) ? $chapter['name'] : "Chapter " . ($index + 1));
            $href = is_string($chapter['href'] ?? null) ? $chapter['href'] : "chapter{$index}.xhtml";

            $chapters[] = [
                'index' => (int) $index,
                'title' => $title,
                'start_ms' => is_int($chapter['start_ms'] ?? null) ? $chapter['start_ms'] : 0,
                'end_ms' => is_int($chapter['end_ms'] ?? null) ? $chapter['end_ms'] : 0,
                'href' => $href,
            ];
        }

        // If no chapters found, add a placeholder
        if ($chapters === []) {
            $chapters[] = [
                'index' => 0,
                'title' => 'Start Reading',
                'start_ms' => 0,
                'end_ms' => 0,
                'href' => 'chapter0.xhtml',
            ];
        }

        return $chapters;
    }

    /**
     * Gets the cover image for a book.
     *
     * GET /books/{id}/cover
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters including 'id'
     * @return Response Image response or 404
     *
     * @since 0.17.0
     */
    public function getCover(Request $request, array $params): Response
    {
        $bookId = $params['id'] ?? null;

        if ($bookId === null) {
            return (new Response())->status(400)->json(['error' => 'Book ID is required']);
        }

        $book = $this->itemRepo->findById($bookId);

        if ($book === null || ($book['type'] ?? '') !== 'book') {
            return (new Response())->status(404)->json(['error' => 'Book not found']);
        }

        /** @var array<string, mixed> $metadata */
        $metadata = is_array($book['metadata'] ?? null) ? $book['metadata'] : [];
        $coverPath = is_string($metadata['cover_path'] ?? null) ? $metadata['cover_path'] : null;

        // Jail the (untrusted) stored cover_path within the configured library
        // roots before reading. realpath()-based containment implies existence,
        // so a missing OR escaping path yields the same 404 (no disclosure).
        if ($coverPath === null || !LibraryRootGuard::assertWithinLibraryRoots($coverPath)) {
            return (new Response())->status(404)->json(['error' => 'Cover not found']);
        }

        $content = file_get_contents($coverPath);
        if ($content === false) {
            return (new Response())->status(500)->json(['error' => 'Failed to read cover']);
        }

        $ext = strtolower(pathinfo($coverPath, PATHINFO_EXTENSION));
        $mimeType = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };

        return (new Response())
            ->header('Content-Type', $mimeType)
            ->header('Cache-Control', 'public, max-age=86400')
            ->header('Content-Length', (string)strlen($content))
            ->body($content);
    }

    /**
     * Downloads a book file.
     *
     * GET /books/{id}/download
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters including 'id'
     * @return Response File download response or error
     *
     * @since 0.17.0
     */
    public function downloadBook(Request $request, array $params): Response
    {
        $bookId = $params['id'] ?? null;

        if ($bookId === null) {
            return (new Response())->status(400)->json(['error' => 'Book ID is required']);
        }

        $book = $this->itemRepo->findById($bookId);

        if ($book === null || ($book['type'] ?? '') !== 'book') {
            return (new Response())->status(404)->json(['error' => 'Book not found']);
        }

        // Parental cap (Finding 3): serve-time backstop for a session-authenticated
        // download — a capped profile never reads the file bytes of an over-cap
        // book. No-op for a bare signed-token fetch (no userId); the getBook mint
        // gate above is the primary defense there.
        if ($this->bookOverCap($request, $book)) {
            return (new Response())->status(404)->json(['error' => 'Book not found']);
        }

        $path = is_string($book['path'] ?? null) ? $book['path'] : '';

        // Jail the (untrusted) stored path within the configured library roots
        // before reading. realpath()-based containment implies existence, so a
        // missing OR escaping path yields the same 404 (no path disclosure).
        if ($path === '' || !LibraryRootGuard::assertWithinLibraryRoots($path)) {
            return (new Response())->status(404)->json(['error' => 'File not found']);
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimeType = match ($ext) {
            'epub' => 'application/epub+zip',
            'pdf' => 'application/pdf',
            'cbz' => 'application/vnd.comicbook+zip',
            default => 'application/octet-stream',
        };

        $bookName = is_string($book['name'] ?? null) ? $book['name'] : 'book';
        $filename = $bookName . '.' . $ext;
        $content = file_get_contents($path);

        if ($content === false) {
            return (new Response())->status(500)->json(['error' => 'Failed to read file']);
        }

        return (new Response())
            ->header('Content-Type', $mimeType)
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->header('Content-Length', (string)strlen($content))
            ->body($content);
    }
}
