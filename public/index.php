<?php

/**
 * Web Portal Entry Point
 *
 * This is the main entry point for the Phlix Web Portal. It handles:
 * - Construction of the PSR-11 service container
 * - Request parsing and authentication
 * - Routing to either API endpoints or HTML page renderers
 *
 * @author Phlix Team
 * @version 1.0.0
 * @description Web portal entry point with request routing
 *
 * @see \Phlix\Common\Container\ContainerFactory For service wiring
 * @see \Phlix\Server\WebPortal\PageRenderer For HTML page rendering
 * @see \Phlix\Server\WebPortal\WebPortalRouter For API routing
 */

declare(strict_types=1);

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

use Phlix\Auth\AuthManager;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Plugins\PluginLoader;
use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\Http\Router;
use Phlix\Server\Http\Controllers\BookController;
use Phlix\Server\Http\Controllers\PhotoController;
use Phlix\Server\Http\Routes\AdminRoutes;
use Phlix\Server\WebPortal\Controllers\AudiobookPageController;
use Phlix\Server\WebPortal\Controllers\BookPageController;
use Phlix\Server\WebPortal\Controllers\MusicPageController;
use Phlix\Server\WebPortal\Controllers\PhotoPageController;
use Phlix\Server\WebPortal\Controllers\PluginAdminPageController;
use Phlix\Server\WebPortal\PageRenderer;
use Phlix\Server\WebPortal\WebPortalRouter;

/**
 * Initialize configuration paths and build the PSR-11 container.
 *
 * The bootstrap stays minimal: load config, inject the DB and logger
 * config paths so providers can wire them, then resolve services on
 * demand via the container. Auto-wiring keeps this entry point free of
 * the long `new X(...)` chain that used to live here.
 */
$config = include __DIR__ . '/../config/server.php';
$config['db_config_path']     = __DIR__ . '/../config/database.php';
$config['logger_config_path'] = __DIR__ . '/../config/logger.php';
$config['web_portal']         = array_merge(
    is_array($config['web_portal'] ?? null) ? $config['web_portal'] : [],
    ['template_dir' => __DIR__ . '/templates']
);

$container = ContainerFactory::create($config);

/**
 * Resolve the services this entry point hands to controllers.
 *
 * @var AuthManager $authManager
 */
$authManager = $container->get(AuthManager::class);

/**
 * Create request from global PHP variables
 *
 * Request::fromGlobals() parses HTTP request data including:
 * - HTTP method and path
 * - Query parameters
 * - Headers (including Authorization)
 * - Body content
 */
$request = Request::fromGlobals();

/**
 * Authenticate request if token provided
 *
 * Checks for Bearer token in Authorization header.
 * If valid, sets userId on request for downstream handlers.
 */
$token = $request->getBearerToken();
if ($token) {
    $auth = $authManager->validateAccessToken($token);
    if (is_array($auth) && is_string($auth['user_id'] ?? null)) {
        $request->userId = $auth['user_id'];
    }
}

/**
 * Route handling
 *
 * Routes are split into three categories:
 * - Admin JSON API (/api/v1/admin/*) — handled by the typed Router
 *   with the AdminMiddleware gate so only admin users hit the
 *   controllers. (Step A.5 plugin admin lives here.)
 * - Other API routes (prefixed with /api/) — return JSON via the
 *   placeholder dispatch below; the full WebPortalRouter wiring
 *   arrives in a later phase.
 * - Page routes — return HTML rendered by Smarty.
 */
$path = $request->path;

// Build the typed Router once and register the admin route group
// (Step A.5). Future iterations should migrate the other /api/* routes
// onto the same router so the whole HTTP surface goes through a single
// dispatcher.
$router = new Router();
AdminRoutes::register($router, $container);

if (str_starts_with($path, '/api/v1/admin/')) {
    /** @var \Phlix\Server\Http\Response $response */
    $response = $router->dispatch($request);
    $response->send();
} elseif (str_starts_with($path, '/api/')) {
    /**
     * API routes
     *
     * API endpoints are handled by WebPortalRouter and return JSON.
     * Routes include:
     * - GET /api/v1/libraries - List all libraries with item counts
     * - GET /api/v1/libraries/{id} - Get single library details
     * - GET /api/v1/libraries/{id}/items - Get items in a library
     * - GET /api/v1/media/{id} - Get media item details with streams
     * - GET /api/v1/media/{id}/playback - Get playback information
     * - GET /api/v1/users/me/continue-watching - Get continue watching list
     * - GET /api/v1/users/me/recently-watched - Get recently watched items
     * - GET /api/v1/users/me/settings - Get user settings
     * - PUT /api/v1/users/me/settings - Update user settings
     *
     * @see \Phlix\Server\WebPortal\WebPortalRouter For complete API handling
     */
    /** @var WebPortalRouter $webPortalRouter */
    $webPortalRouter = $container->get(WebPortalRouter::class);
    $response = $webPortalRouter->dispatch($request);
    $response->send();
} else {
    /**
     * Page routes
     *
     * HTML pages are rendered using Smarty templates via PageRenderer.
     * Supported routes:
     * - / or '' : Home page
     * - /login : Login page
     * - /admin/plugins : Plugin admin index (Step A.5)
     * - /admin/plugins/install : Plugin install form (Step A.5)
     * - /admin/plugins/{name} : Plugin detail page (Step A.5)
     * - Other : 404 Not Found
     *
     * @see PageRenderer For page rendering
     */
    /** @var PageRenderer $renderer */
    $renderer = $container->get(PageRenderer::class);

    if ($path === '/' || $path === '') {
        $response = $renderer->renderHome($request);
    } elseif ($path === '/login') {
        $response = $renderer->renderLogin($request);
    } elseif (str_starts_with($path, '/admin/plugins')) {
        // Browser SSR routes for the plugin admin UI. Reuses the same
        // AdminMiddleware role check as the JSON API so the gate logic
        // (lookup + audit logging) lives in one place; only the response
        // envelope differs (HTML vs JSON).
        /** @var AdminMiddleware $adminMiddleware */
        $adminMiddleware = $container->get(AdminMiddleware::class);
        $gateStatus = $adminMiddleware->checkAccess($request);
        if ($gateStatus === 401) {
            $response = (new Response())
                ->status(401)
                ->html('<h1>401 — admin authentication required</h1>');
        } elseif ($gateStatus === 403) {
            $response = (new Response())
                ->status(403)
                ->html('<h1>403 — administrator privileges required</h1>');
        } else {
            /** @var PluginLoader $loader */
            $loader = $container->get(PluginLoader::class);
            $pageController = new PluginAdminPageController(
                $loader,
                __DIR__ . '/templates'
            );
            if ($path === '/admin/plugins') {
                $response = $pageController->index($request, []);
            } elseif ($path === '/admin/plugins/install') {
                $response = $pageController->install($request, []);
            } elseif (preg_match('#^/admin/plugins/(?P<name>[^/]+)$#', $path, $matches) === 1) {
                $response = $pageController->detail($request, ['name' => $matches['name']]);
            } else {
                http_response_code(404);
                echo '<h1>404 - Page not found</h1>';
                exit;
            }
        }
    } elseif ($path === '/admin/dashboard') {
        /** @var AdminMiddleware $adminMiddleware */
        $adminMiddleware = $container->get(AdminMiddleware::class);
        $gateStatus = $adminMiddleware->checkAccess($request);
        if ($gateStatus === 401) {
            $response = (new Response())
                ->status(401)
                ->html('<h1>401 — admin authentication required</h1>');
        } elseif ($gateStatus === 403) {
            $response = (new Response())
                ->status(403)
                ->html('<h1>403 — administrator privileges required</h1>');
        } else {
            $response = $renderer->renderDashboard($request);
        }
    } elseif (str_starts_with($path, '/music')) {
        /**
         * Music portal pages: albums (default), album detail, artists,
         * artist detail, all-tracks, and the standalone player.
         */
        /** @var MusicPageController $music */
        $music = $container->get(MusicPageController::class);
        if ($path === '/music' || $path === '/music/albums') {
            $response = $music->albums($request, []);
        } elseif (preg_match('#^/music/albums/(?P<name>.+)$#', $path, $m) === 1) {
            $response = $music->album($request, ['name' => urldecode($m['name'])]);
        } elseif ($path === '/music/artists') {
            $response = $music->artists($request, []);
        } elseif (preg_match('#^/music/artists/(?P<name>.+)$#', $path, $m) === 1) {
            $response = $music->artist($request, ['name' => urldecode($m['name'])]);
        } elseif ($path === '/music/tracks') {
            $response = $music->tracks($request, []);
        } elseif ($path === '/music/player') {
            $response = $music->player($request, []);
        } else {
            $response = (new Response())->status(404)->html('<h1>404 - Page not found</h1>');
        }
    } elseif (str_starts_with($path, '/books')) {
        /**
         * Book portal pages plus the cover/download file routes (served by
         * the JSON {@see BookController} so the page links resolve locally).
         */
        if (preg_match('#^/books/(?P<id>[^/]+)/cover$#', $path, $m) === 1) {
            /** @var BookController $bookApi */
            $bookApi = $container->get(BookController::class);
            $response = $bookApi->getCover($request, ['id' => $m['id']]);
        } elseif (preg_match('#^/books/(?P<id>[^/]+)/download$#', $path, $m) === 1) {
            /** @var BookController $bookApi */
            $bookApi = $container->get(BookController::class);
            $response = $bookApi->downloadBook($request, ['id' => $m['id']]);
        } elseif (preg_match('#^/books/(?P<id>[^/]+)/read$#', $path, $m) === 1) {
            /** @var BookPageController $bookPage */
            $bookPage = $container->get(BookPageController::class);
            $response = $bookPage->reader($request, ['id' => $m['id']]);
        } elseif (preg_match('#^/books/(?P<id>[^/]+)$#', $path, $m) === 1) {
            /** @var BookPageController $bookPage */
            $bookPage = $container->get(BookPageController::class);
            $response = $bookPage->detail($request, ['id' => $m['id']]);
        } elseif ($path === '/books') {
            /** @var BookPageController $bookPage */
            $bookPage = $container->get(BookPageController::class);
            $response = $bookPage->index($request, []);
        } else {
            $response = (new Response())->status(404)->html('<h1>404 - Page not found</h1>');
        }
    } elseif (str_starts_with($path, '/audiobooks')) {
        /**
         * Audiobook portal pages: library grid, detail (with chapters), and
         * the player. Streaming/progress stay on the JSON API.
         */
        /** @var AudiobookPageController $audiobook */
        $audiobook = $container->get(AudiobookPageController::class);
        if ($path === '/audiobooks') {
            $response = $audiobook->index($request, []);
        } elseif (preg_match('#^/audiobooks/(?P<id>[^/]+)/read$#', $path, $m) === 1) {
            $response = $audiobook->player($request, ['id' => $m['id']]);
        } elseif (preg_match('#^/audiobooks/(?P<id>[^/]+)$#', $path, $m) === 1) {
            $response = $audiobook->detail($request, ['id' => $m['id']]);
        } else {
            $response = (new Response())->status(404)->html('<h1>404 - Page not found</h1>');
        }
    } elseif (str_starts_with($path, '/photo')) {
        /**
         * Photo portal pages plus the thumbnail/full image routes (served by
         * the JSON {@see PhotoController} so the page <img> links resolve).
         */
        if (preg_match('#^/photo/photos/(?P<id>[^/]+)/thumbnail$#', $path, $m) === 1) {
            /** @var PhotoController $photoApi */
            $photoApi = $container->get(PhotoController::class);
            $response = $photoApi->getThumbnail($request, ['id' => $m['id']]);
        } elseif (preg_match('#^/photo/photos/(?P<id>[^/]+)/full$#', $path, $m) === 1) {
            /** @var PhotoController $photoApi */
            $photoApi = $container->get(PhotoController::class);
            $response = $photoApi->getFull($request, ['id' => $m['id']]);
        } elseif ($path === '/photo/albums') {
            /** @var PhotoPageController $photoPage */
            $photoPage = $container->get(PhotoPageController::class);
            $response = $photoPage->albums($request, []);
        } elseif (preg_match('#^/photo/album/(?P<id>[^/]+)$#', $path, $m) === 1) {
            /** @var PhotoPageController $photoPage */
            $photoPage = $container->get(PhotoPageController::class);
            $response = $photoPage->album($request, ['id' => $m['id']]);
        } elseif (preg_match('#^/photo/photo/(?P<id>[^/]+)$#', $path, $m) === 1) {
            /** @var PhotoPageController $photoPage */
            $photoPage = $container->get(PhotoPageController::class);
            $response = $photoPage->photo($request, ['id' => $m['id']]);
        } elseif ($path === '/photo/slideshow') {
            /** @var PhotoPageController $photoPage */
            $photoPage = $container->get(PhotoPageController::class);
            $response = $photoPage->slideshow($request, []);
        } else {
            $response = (new Response())->status(404)->html('<h1>404 - Page not found</h1>');
        }
    } else {
        http_response_code(404);
        echo '<h1>404 - Page not found</h1>';
        exit;
    }

    $response->send();
}
