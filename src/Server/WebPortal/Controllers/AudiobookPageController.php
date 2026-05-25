<?php

declare(strict_types=1);

namespace Phlix\Server\WebPortal\Controllers;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\WebPortal\PageRenderer;

/**
 * AudiobookPageController renders the audiobook web-portal HTML pages.
 *
 * Serves the browser-facing audiobook section (library grid, detail with the
 * chapter list, and the player) using the Smarty templates under
 * `public/templates/audiobooks/`. Streaming and progress are handled by the
 * JSON {@see \Phlix\Server\Http\Controllers\AudiobookController}.
 *
 * The templates consume the raw item shape (`name` plus a nested `metadata`
 * map containing `chapters`, `author`, `duration_ms`, etc.), so this controller
 * passes through items from {@see ItemRepository} unmodified rather than the
 * API's reshaped envelope.
 *
 * @author Phlix Team
 * @version 1.0.0
 * @description Renders audiobook portal pages (index/detail/player)
 *
 * @see ItemRepository For audiobook item access
 * @see PageRenderer::renderTemplate() For Smarty rendering
 */
class AudiobookPageController
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
     * Renders the audiobooks library grid.
     *
     * GET /audiobooks
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string,string> $params  Route parameters (unused).
     * @return Response HTML response with the audiobooks index page.
     */
    public function index(Request $request, array $params): Response
    {
        $audiobooks = [];
        foreach ($this->audiobookLibraryIds() as $libraryId) {
            foreach ($this->itemRepo->getByLibrary($libraryId, 1000, 0) as $item) {
                if (is_array($item) && ($item['type'] ?? '') === 'audiobook') {
                    $audiobooks[] = $item;
                }
            }
        }

        return $this->render('audiobooks/audiobooks.tpl', [
            'current_page' => 'audiobooks',
            'audiobooks' => $audiobooks,
        ]);
    }

    /**
     * Renders a single audiobook's detail page with its chapter list.
     *
     * GET /audiobooks/{id}
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string,string> $params  Route parameters including 'id'.
     * @return Response HTML response with the audiobook page, or 404.
     */
    public function detail(Request $request, array $params): Response
    {
        $audiobook = $this->findAudiobook($params['id'] ?? '');
        if ($audiobook === null) {
            return (new Response())->status(404)->html('<h1>404 — audiobook not found</h1>');
        }

        return $this->render('audiobooks/audiobook.tpl', [
            'current_page' => 'audiobooks',
            'audiobook' => $audiobook,
        ]);
    }

    /**
     * Renders the audiobook player page.
     *
     * GET /audiobooks/{id}/read
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string,string> $params  Route parameters including 'id'.
     * @return Response HTML response with the player page, or 404.
     */
    public function player(Request $request, array $params): Response
    {
        $audiobook = $this->findAudiobook($params['id'] ?? '');
        if ($audiobook === null) {
            return (new Response())->status(404)->html('<h1>404 — audiobook not found</h1>');
        }

        return $this->render('audiobooks/player.tpl', [
            'current_page' => 'audiobooks',
            'audiobook' => $audiobook,
        ]);
    }

    /**
     * Looks up an audiobook item by ID, returning null when missing/non-audiobook.
     *
     * @param string $id Audiobook item ID.
     * @return array<string,mixed>|null The audiobook item, or null.
     */
    private function findAudiobook(string $id): ?array
    {
        if ($id === '') {
            return null;
        }
        $audiobook = $this->itemRepo->findById($id);
        if (!is_array($audiobook) || ($audiobook['type'] ?? '') !== 'audiobook') {
            return null;
        }

        // The detail/player templates call count() and iterate over
        // metadata.chapters; guarantee it is always an array so a missing
        // `chapters` key cannot raise a TypeError at render time.
        $metadata = is_array($audiobook['metadata'] ?? null) ? $audiobook['metadata'] : [];
        $metadata['chapters'] = is_array($metadata['chapters'] ?? null) ? $metadata['chapters'] : [];
        $audiobook['metadata'] = $metadata;

        return $audiobook;
    }

    /**
     * Collects the IDs of all audiobook-type libraries.
     *
     * @return list<string> Audiobook library IDs.
     */
    private function audiobookLibraryIds(): array
    {
        $ids = [];
        foreach ($this->libraryManager->getAllLibraries() as $library) {
            if (!is_array($library) || ($library['type'] ?? null) !== 'audiobook') {
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
