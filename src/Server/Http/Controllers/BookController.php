<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
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
        OpdsFeedBuilder $opdsBuilder
    ) {
        $this->itemRepo = $itemRepo;
        $this->libraryManager = $libraryManager;
        $this->opdsBuilder = $opdsBuilder;
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

        // Get total count for pagination
        $items = $this->itemRepo->getByLibrary($libraryId, 10000, 0);
        $books = array_filter($items, fn($item) => ($item['type'] ?? '') === 'book');
        $total = count($books);

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

        return (new Response())->json(['book' => $book]);
    }

    /**
     * Returns the HTML reader page for a book.
     *
     * GET /books/{id}/read?page=1
     *
     * This is a stub reader that provides paginated content.
     * Full EPUB rendering via browser is a future enhancement.
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters including 'id'
     * @return Response HTML response with reader stub
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

        $pageParam = $request->query['page'] ?? null;
        $page = max(1, is_numeric($pageParam) ? (int) $pageParam : 1);
        $metadata = is_array($book['metadata'] ?? null) ? $book['metadata'] : [];

        // Return JSON with book info for client-side EPUB rendering
        return (new Response())->json([
            'book' => $book,
            'metadata' => $metadata,
            'current_page' => $page,
            'message' => 'EPUB reader rendering not yet implemented',
        ]);
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

        if ($coverPath === null || !file_exists($coverPath)) {
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

        $path = is_string($book['path'] ?? null) ? $book['path'] : '';

        if (empty($path) || !file_exists($path)) {
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
