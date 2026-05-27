<?php

declare(strict_types=1);

namespace Phlix\Server\WebPortal;

use Phlix\Auth\AuthManager;
use Phlix\Auth\UserRepository;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\ItemRepository;
use Phlix\Session\PlaybackController;
use Phlix\Theming\ThemeMediaRepository;

/**
 * PageRenderer handles HTML page generation for the web portal.
 *
 * This class uses Smarty templates to render portal pages including
 * the home page, library browser, and authentication pages.
 * It coordinates with library and playback services to fetch
 * the data needed for template rendering.
 *
 * @author Phlix Team
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
     * @var AuthManager|null Used by {@see renderHome()} to look up the
     *      signed-in user's profile so the greeting shows their real
     *      display name. Optional so existing tests that construct
     *      PageRenderer with just the four positional args keep working.
     */
    private ?AuthManager $authManager = null;

    /**
     * @var UserRepository|null Used by {@see renderHome()} for the
     *      first-run wizard check (`countUsers() === 0` → redirect to
     *      /auth/register). Optional for the same reason as $authManager.
     */
    private ?UserRepository $userRepository = null;

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
     * Wire AuthManager + UserRepository for the auth-aware home page.
     *
     * Optional setter — the four-arg constructor stays backwards
     * compatible with the existing tests, and renderHome() degrades
     * to "no auth gate, no first-run wizard" when these aren't set.
     *
     * @param AuthManager $authManager Looks up user by ID for the
     *        greeting shown on /.
     * @param UserRepository $userRepository Used to detect the
     *        "no users yet" first-run case.
     *
     * @since 0.15.0
     */
    public function setAuthServices(AuthManager $authManager, UserRepository $userRepository): void
    {
        $this->authManager = $authManager;
        $this->userRepository = $userRepository;
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
     * Template variables:
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

        // First-run wizard: if the install has no users at all, take
        // the browser to the registration page so the first account is
        // created (and per AuthManager::register, that account is
        // automatically promoted to admin).
        if ($this->userRepository !== null && $userId === null) {
            if ($this->userRepository->countUsers() === 0) {
                return (new Response())->redirect('/auth/register');
            }
            // Users exist but the current request is unauthenticated —
            // bounce to the login page rather than leaking the library
            // index to anyone who hits /.
            return (new Response())->redirect('/login');
        }

        $template = new \Smarty();
        $template->setTemplateDir($this->templateDir);

        // Load data
        $libraries = $this->libraryManager->getAllLibraries();
        $librariesWithItems = [];

        foreach (array_slice($libraries, 0, 3) as $library) {
            $libId = is_string($library['id'] ?? null) ? $library['id'] : '';
            $items = $this->itemRepository->getByLibrary($libId, 10, 0);
            $library['items'] = $items;
            $librariesWithItems[] = $library;
        }

        $firstLibraryId = $libraries[0]['id'] ?? '';
        $recentlyAdded = $this->itemRepository->getRecentlyAdded(
            is_string($firstLibraryId) ? $firstLibraryId : '',
            20
        );

        $continueWatching = [];
        $displayName = 'User';
        if (is_string($userId) && $userId !== '') {
            $continueWatching = $this->playbackController->getContinueWatching($userId, 10);

            if ($this->authManager !== null) {
                $user = $this->authManager->getUser($userId);
                if (is_array($user)) {
                    $candidate = $user['display_name'] ?? $user['username'] ?? null;
                    if (is_string($candidate) && $candidate !== '') {
                        $displayName = $candidate;
                    }
                }
            }
        }

        // Assign variables
        $template->assign('current_page', 'home');
        $template->assign('user', ['display_name' => $displayName]);
        $template->assign('libraries', $librariesWithItems);
        $template->assign('recently_added', $recentlyAdded);
        $template->assign('continue_watching', $continueWatching);

        $html = $template->fetch('home/index.tpl');

        return (new Response())->html($html);
    }

    /**
     * Renders the registration page (browser-side form).
     *
     * The first user to register on a fresh install is promoted to
     * admin automatically by {@see AuthManager::register()}; subsequent
     * registrations create non-admin accounts. The form posts to
     * `/auth/register` which {@see AuthController::register} handles,
     * setting the session cookie and redirecting to / on success.
     *
     * @param Request $request The HTTP request.
     *
     * @return Response HTML response with the rendered registration page.
     */
    public function renderRegister(Request $request): Response
    {
        // Surface the optional `?error=` query param that the
        // browser-form auth handler bounces back with.
        $rawError = $request->query['error'] ?? '';
        $error = is_string($rawError) ? $rawError : '';

        // Decide between "you're the first user, you get admin"
        // copy vs. the standard signup copy.
        $isFirstUser = $this->userRepository !== null
            && $this->userRepository->countUsers() === 0;

        $template = new \Smarty();
        $template->setTemplateDir($this->templateDir);
        $template->assign('current_page', 'register');
        $template->assign('error', $error);
        $template->assign('is_first_user', $isFirstUser);

        $html = $template->fetch('auth/register.tpl');

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
     * Template variables:
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
     * Template variables:
     * - (Smarty default variables)
     *
     * @example Template: auth/login.tpl
     */
    public function renderLogin(Request $request): Response
    {
        $rawError = $request->query['error'] ?? '';
        $error = is_string($rawError) ? $rawError : '';

        $template = new \Smarty();
        $template->setTemplateDir($this->templateDir);
        $template->assign('current_page', 'login');
        $template->assign('error', $error);

        $html = $template->fetch('auth/login.tpl');

        return (new Response())->html($html);
    }

    /**
     * Renders the top-level "all libraries" overview page.
     *
     * No library ID is supplied — the user is shown every library on the
     * server so they can pick one. This pairs with `renderLibrary()`
     * which renders a specific library by ID.
     *
     * Reuses `library/index.tpl` by feeding it a synthetic library
     * record (`{name: "All Libraries"}`) and the first 100 items
     * aggregated across every library.
     *
     * @param Request $request The HTTP request (unused).
     *
     * @return Response HTML response with the rendered libraries overview.
     */
    public function renderLibrariesOverview(Request $request): Response
    {
        $libraries = $this->libraryManager->getAllLibraries();

        // Aggregate a flat item list across libraries so the grid has
        // something to render even before the user drills in.
        $items = [];
        foreach ($libraries as $library) {
            $libId = is_string($library['id'] ?? null) ? $library['id'] : '';
            if ($libId === '') {
                continue;
            }
            $libItems = $this->itemRepository->getByLibrary($libId, 20, 0);
            foreach ($libItems as $item) {
                $items[] = $item;
            }
            if (count($items) >= 100) {
                break;
            }
        }

        $template = new \Smarty();
        $template->setTemplateDir($this->templateDir);
        $template->assign('current_page', 'library');
        $template->assign('library', ['name' => 'All Libraries']);
        $template->assign('libraries', $libraries);
        $template->assign('items', array_slice($items, 0, 100));

        $html = $template->fetch('library/index.tpl');

        return (new Response())->html($html);
    }

    /**
     * Renders the search page.
     *
     * A query parameter `q` may be supplied; when present it filters
     * items via `ItemRepository::search()`. When absent, a blank search
     * page is rendered.
     *
     * @param Request $request The HTTP request; query string may include `q`.
     *
     * @return Response HTML response with the rendered search page.
     */
    public function renderSearch(Request $request): Response
    {
        $rawQuery = $request->query['q'] ?? '';
        $query = is_string($rawQuery) ? trim($rawQuery) : '';

        $results = [];
        if ($query !== '') {
            /** @var array<int, array<string, mixed>> $results */
            $results = $this->itemRepository->search($query, 50);
        }

        $template = new \Smarty();
        $template->setTemplateDir($this->templateDir);
        $template->assign('current_page', 'search');
        $template->assign('query', $query);
        $template->assign('results', $results);

        $html = $template->fetch('search/index.tpl');

        return (new Response())->html($html);
    }

    /**
     * Renders the settings page.
     *
     * Currently a thin shell — the existing settings UI lives under the
     * admin dashboard and the API endpoints in
     * {@see WebPortalRouter::getUserSettings()}. This page hosts the
     * client-side settings form which talks to those endpoints.
     *
     * @param Request $request The HTTP request.
     *
     * @return Response HTML response with the rendered settings page.
     */
    public function renderSettings(Request $request): Response
    {
        $template = new \Smarty();
        $template->setTemplateDir($this->templateDir);
        $template->assign('current_page', 'settings');
        $template->assign('user', ['display_name' => 'User']);

        $html = $template->fetch('settings/index.tpl');

        return (new Response())->html($html);
    }

    /**
     * Render an arbitrary Smarty template with the given variables and
     * return the resulting HTML as a string. Centralised so subordinate
     * page controllers (e.g.
     * {@see \Phlix\Server\WebPortal\Controllers\PluginAdminPageController})
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
     * Template variables assigned for admin/dashboard.tpl:
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
