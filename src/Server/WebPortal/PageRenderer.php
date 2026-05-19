<?php

declare(strict_types=1);

namespace Phlex\Server\WebPortal;

use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;
use Phlex\Media\Library\LibraryManager;
use Phlex\Media\Library\ItemRepository;
use Phlex\Session\PlaybackController;
use Phlex\Theming\ThemeMediaRepository;

/**
 * PageRenderer handles HTML page generation for the web portal.
 *
 * This class uses Smarty templates to render portal pages including
 * the home page, library browser, and authentication pages.
 * It coordinates with library and playback services to fetch
 * the data needed for template rendering.
 *
 * @author Phlex Team
 * @version 1.0.0
 * @description Renders HTML pages using Smarty templates
 *
 * @see WebPortalRouter For API routing
 * @see Smarty For template engine
 */
class PageRenderer
{
    /** @var string Directory containing Smarty templates */
    private string $templateDir;

    /** @var LibraryManager Manages media libraries */
    private LibraryManager $libraryManager;

    /** @var ItemRepository Provides access to media items */
    private ItemRepository $itemRepository;

    /** @var PlaybackController Handles playback state and progress */
    private PlaybackController $playbackController;

    /** @var ThemeMediaRepository|null Repository for theme media */
    private ?ThemeMediaRepository $themeMediaRepository = null;

    /**
     * Constructs a new PageRenderer instance.
     *
     * @param string $templateDir Absolute path to the Smarty template directory
     * @param LibraryManager $libraryManager Manages media library operations
     * @param ItemRepository $itemRepository Provides access to media items
     * @param PlaybackController $playbackController Handles playback state tracking
     *
     * @example
     * ```php
     * $renderer = new PageRenderer(
     *     '/var/www/templates',
     *     $libraryManager,
     *     $itemRepository,
     *     $playbackController
     * );
     * ```
     */
    public function __construct(
        string $templateDir,
        LibraryManager $libraryManager,
        ItemRepository $itemRepository,
        PlaybackController $playbackController
    ) {
        $this->templateDir = $templateDir;
        $this->libraryManager = $libraryManager;
        $this->itemRepository = $itemRepository;
        $this->playbackController = $playbackController;
    }

    /**
     * Sets the theme media repository for theme media lookup.
     *
     * @param ThemeMediaRepository $repository Theme media repository
     *
     * @return void
     *
     * @since 0.14.0
     */
    public function setThemeMediaRepository(ThemeMediaRepository $repository): void
    {
        $this->themeMediaRepository = $repository;
    }

    /**
     * Renders the home page with library overview and user content.
     *
     * The home page displays:
     * - First 3 libraries with up to 10 items each
     * - Recently added items from the first library
     * - User's continue watching list (if authenticated)
     *
     * @param Request $request The HTTP request (userId used for personalization)
     *
     * @return Response HTML response with the rendered home page
     *
     * @template_variables
     * - current_page: string ('home')
     * - user: array (display_name)
     * - libraries: array (library data with items sub-array)
     * - recently_added: array (recently added media items)
     * - continue_watching: array (items in progress)
     *
     * @example Template: home/index.tpl
     */
    public function renderHome(Request $request): Response
    {
        $userId = $request->userId ?? null;

        $template = new \Smarty();
        $template->setTemplateDir($this->templateDir);

        // Load data
        $libraries = $this->libraryManager->getAllLibraries();
        $librariesWithItems = [];

        foreach (array_slice($libraries, 0, 3) as $library) {
            $items = $this->itemRepository->getByLibrary($library['id'], 10, 0);
            $library['items'] = $items;
            $librariesWithItems[] = $library;
        }

        $recentlyAdded = $this->itemRepository->getRecentlyAdded($libraries[0]['id'] ?? '', 20);

        $continueWatching = [];
        if ($userId) {
            $continueWatching = $this->playbackController->getContinueWatching($userId, 10);
        }

        // Assign variables
        $template->assign('current_page', 'home');
        $template->assign('user', ['display_name' => 'User']);
        $template->assign('libraries', $librariesWithItems);
        $template->assign('recently_added', $recentlyAdded);
        $template->assign('continue_watching', $continueWatching);

        $html = $template->fetch('home/index.tpl');

        return (new Response())->html($html);
    }

    /**
     * Renders the library browser page.
     *
     * Displays all items within a specific library with pagination.
     * Returns 404 HTML if the library doesn't exist.
     *
     * @param Request $request The HTTP request (unused)
     * @param array<string, string> $params Route parameters:
     *   - id: Library ID to display
     *
     * @return Response HTML response with the rendered library page or 404
     *
     * @template_variables
     * - current_page: string ('library')
     * - library: array (library data)
     * - items: array (media items in library)
     *
     * @example Template: library/index.tpl
     */
    public function renderLibrary(Request $request, array $params): Response
    {
        $libraryId = $params['id'] ?? '';
        $library = $this->libraryManager->getLibrary($libraryId);

        if (!$library) {
            return (new Response())->status(404)->html('<h1>Library not found</h1>');
        }

        $items = $this->itemRepository->getByLibrary($libraryId, 100, 0);

        // Fetch theme media for this library
        $themeMedia = null;
        if ($this->themeMediaRepository !== null) {
            $themeMedia = $this->themeMediaRepository->findByLibraryId($libraryId);
        }

        $template = new \Smarty();
        $template->setTemplateDir($this->templateDir);
        $template->assign('current_page', 'library');
        $template->assign('library', $library);
        $template->assign('items', $items);
        $template->assign('themeMedia', $themeMedia);

        $html = $template->fetch('library/index.tpl');

        return (new Response())->html($html);
    }

    /**
     * Renders the login page.
     *
     * Displays the authentication form for users to sign in.
     *
     * @param Request $request The HTTP request (unused)
     *
     * @return Response HTML response with the rendered login page
     *
     * @template_variables
     * - (Smarty default variables)
     *
     * @example Template: auth/login.tpl
     */
    public function renderLogin(Request $request): Response
    {
        $template = new \Smarty();
        $template->setTemplateDir($this->templateDir);

        $html = $template->fetch('auth/login.tpl');

        return (new Response())->html($html);
    }

    /**
     * Render an arbitrary Smarty template with the given variables and
     * return the resulting HTML as a string. Centralised so subordinate
     * page controllers (e.g.
     * {@see \Phlex\Server\WebPortal\Controllers\PluginAdminPageController})
     * don't each have to instantiate Smarty directly and so the
     * default-on `escape_html` policy is applied uniformly.
     *
     * @param string               $templateDir Absolute path to the template root.
     * @param string               $template    Template path relative to the root.
     * @param array<string, mixed> $vars        Variables to assign before fetching.
     *
     * @return string Rendered HTML.
     *
     * @since 0.10.0 (Step A.5)
     */
    public static function renderTemplate(string $templateDir, string $template, array $vars): string
    {
        $smarty = new \Smarty();
        $smarty->setTemplateDir($templateDir);
        // Templates MUST use `|escape:'html'` on every user-controlled
        // value. The admin-plugins templates are audited for this; new
        // admin pages should follow the same convention rather than
        // relying on a single "escape everything" toggle which Smarty
        // applies inconsistently across plugin/function/modifier output.
        foreach ($vars as $key => $value) {
            $smarty->assign($key, $value);
        }
        return (string) $smarty->fetch($template);
    }

    /**
     * Renders the admin dashboard page.
     *
     * Displays the admin dashboard with now playing, top users/media,
     * storage usage, and recent activity. The dashboard auto-refreshes
     * via JavaScript fetch() every 30 seconds.
     *
     * @param Request $request The HTTP request
     *
     * @return Response HTML response with the rendered dashboard page
     *
     * @template_variables
     * - current_page: string ('dashboard')
     * - user: array (display_name)
     *
     * @example Template: admin/dashboard.tpl
     *
     * @since 0.14.0 (Step L.4)
     */
    public function renderDashboard(Request $request): Response
    {
        $template = new \Smarty();
        $template->setTemplateDir($this->templateDir);

        // Assign variables
        $template->assign('current_page', 'dashboard');
        $template->assign('user', ['display_name' => 'Admin']);

        $html = $template->fetch('admin/dashboard.tpl');

        return (new Response())->html($html);
    }
}
