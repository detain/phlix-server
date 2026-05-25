<?php

declare(strict_types=1);

namespace Phlix\Server\WebPortal\Controllers;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\WebPortal\PageRenderer;

/**
 * BookPageController renders the book web-portal HTML pages.
 *
 * Serves the browser-facing book section (library grid, book detail and the
 * minimal reader) using the Smarty templates under `public/templates/books/`.
 * File-serving routes (`/books/{id}/cover`, `/books/{id}/download`) are handled
 * by the JSON {@see \Phlix\Server\Http\Controllers\BookController}.
 *
 * @author Phlix Team
 * @version 1.0.0
 * @description Renders book portal pages (index/detail/reader)
 *
 * @see ItemRepository For book item access
 * @see PageRenderer::renderTemplate() For Smarty rendering
 */
class BookPageController
{
    /** @var ItemRepository Provides access to media items. */
    private ItemRepository $itemRepo;

    /** @var LibraryManager Enumerates configured libraries. */
    private LibraryManager $libraryManager;

    /** @var string Absolute path to the Smarty template root. */
    private string $templateDir;

    /**
     * @param ItemRepository $itemRepo       Media item repository.
     * @param LibraryManager $libraryManager Library enumeration manager.
     * @param string         $templateDir    Absolute path to templates.
     */
    public function __construct(
        ItemRepository $itemRepo,
        LibraryManager $libraryManager,
        string $templateDir
    ) {
        $this->itemRepo = $itemRepo;
        $this->libraryManager = $libraryManager;
        $this->templateDir = $templateDir;
    }

    /**
     * Renders the books library grid.
     *
     * GET /books
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string,string> $params  Route parameters (unused).
     * @return Response HTML response with the books index page.
     */
    public function index(Request $request, array $params): Response
    {
        $books = [];
        foreach ($this->bookLibraryIds() as $libraryId) {
            foreach ($this->itemRepo->getByLibrary($libraryId, 1000, 0) as $item) {
                if (is_array($item) && ($item['type'] ?? '') === 'book') {
                    $books[] = $item;
                }
            }
        }

        return $this->render('books/books.tpl', [
            'current_page' => 'books',
            'books' => $books,
        ]);
    }

    /**
     * Renders a single book's detail page.
     *
     * GET /books/{id}
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string,string> $params  Route parameters including 'id'.
     * @return Response HTML response with the book page, or 404.
     */
    public function detail(Request $request, array $params): Response
    {
        $book = $this->findBook($params['id'] ?? '');
        if ($book === null) {
            return (new Response())->status(404)->html('<h1>404 — book not found</h1>');
        }

        return $this->render('books/book.tpl', [
            'current_page' => 'books',
            'book' => $book,
        ]);
    }

    /**
     * Renders the minimal book reader page.
     *
     * GET /books/{id}/read
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string,string> $params  Route parameters including 'id'.
     * @return Response HTML response with the reader page, or 404.
     */
    public function reader(Request $request, array $params): Response
    {
        $book = $this->findBook($params['id'] ?? '');
        if ($book === null) {
            return (new Response())->status(404)->html('<h1>404 — book not found</h1>');
        }

        return $this->render('books/reader.tpl', [
            'current_page' => 'books',
            'book' => $book,
            'theme' => 'light',
        ]);
    }

    /**
     * Looks up a book item by ID, returning null when missing or non-book.
     *
     * @param string $id Book item ID.
     * @return array<string,mixed>|null The book item, or null.
     */
    private function findBook(string $id): ?array
    {
        if ($id === '') {
            return null;
        }
        $book = $this->itemRepo->findById($id);
        if (!is_array($book) || ($book['type'] ?? '') !== 'book') {
            return null;
        }
        return $book;
    }

    /**
     * Collects the IDs of all book-type libraries.
     *
     * @return list<string> Book library IDs.
     */
    private function bookLibraryIds(): array
    {
        $ids = [];
        foreach ($this->libraryManager->getAllLibraries() as $library) {
            if (!is_array($library) || ($library['type'] ?? null) !== 'book') {
                continue;
            }
            $id = $library['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Renders a template to an HTML response.
     *
     * @param string              $template Template path relative to the root.
     * @param array<string,mixed> $vars     Variables to assign.
     * @return Response HTML response.
     */
    private function render(string $template, array $vars): Response
    {
        $vars['user'] = $vars['user'] ?? ['display_name' => 'Guest'];
        $html = PageRenderer::renderTemplate($this->templateDir, $template, $vars);
        return (new Response())->html($html);
    }
}
