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
 * @see \Phlix\Server\WebPortal\WebPortalRouter For API routing
 */

declare(strict_types=1);

// -----------------------------------------------------------------------------
// Enable Swoole coroutine hooks for the current process/thread.
// This allows blocking I/O to yield in coroutine contexts.
// Degrades gracefully when ext-swoole is not yet available.
// -----------------------------------------------------------------------------
if (extension_loaded('swoole')) {
    \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
}

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

use Phlix\Auth\AuthManager;
use Phlix\Auth\RateLimitException;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Middleware\AccessScheduleMiddleware;
use Phlix\Server\Http\Middleware\CorsManager;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestAuthenticator;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Http\Response;
use Phlix\Server\Http\Router;
use Phlix\Server\Http\Controllers\BookController;
use Phlix\Server\Http\Controllers\PhotoController;
use Phlix\Server\Http\Routes\AdminRoutes;
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

// ---------------------------------------------------------------------------
// SV-4.15(c): central RateLimitException -> 429 mapping for the CGI/FPM
// dispatch path.
//
// public/index.php dispatches through several routers (admin Router,
// WebPortalRouter, page renderers) WITHOUT a wrapping try/catch, so a
// rate-limiter trip that bubbles out of a controller would otherwise surface as
// an uncaught fatal (HTTP 500, no Retry-After) — the same latent bug the
// Workerman HttpHandler had. Registering the shared canonical envelope as the
// exception handler mirrors the Workerman HttpHandler and Application::run()
// central catches so all three entrypoints emit identical 429 output. Any other
// throwable is re-thrown so its existing (fatal 500) behaviour is unchanged.
// ---------------------------------------------------------------------------
set_exception_handler(static function (\Throwable $e): void {
    if ($e instanceof RateLimitException) {
        Application::rateLimitResponse($e)->send();
        return;
    }

    throw $e;
});

// ---------------------------------------------------------------------------
// Bootstrap storage snapshot for admin dashboard (PHP-FPM fallback).
//
// When served via PHP-FPM instead of the Workerman daemon, the storage
// snapshot timer never runs. Record one snapshot now so the admin dashboard
// has data rather than showing "No storage data".
// ---------------------------------------------------------------------------
if ($container->has(\Phlix\Stats\StatsCollector::class) && $container->has(\Workerman\MySQL\Connection::class)) {
    try {
        /** @var \Phlix\Stats\StatsCollector $statsCollector */
        $statsCollector = $container->get(\Phlix\Stats\StatsCollector::class);
        /** @var \Workerman\MySQL\Connection $db */
        $db = $container->get(\Workerman\MySQL\Connection::class);
        \Phlix\Stats\StorageSnapshotHelper::bootstrapSnapshot($statsCollector, $db);
    } catch (\Throwable) {
        // Log but do not fail - dashboard degrades gracefully without storage data
    }
}

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
$requestUid = substr(md5((string)($request->userId ?? '') . $request->path . (string)microtime(true)), 0, 16);
error_log('[DEBUG] ' . date('Y-m-d H:i:s.v') . ' public/index.php START ' . $request->method . ' ' . $request->path . ' [uid=' . $requestUid . '] [userId=' . ($request->userId ?? 'anonymous') . ']');

/**
 * C6/B4: Shared request authentication.
 *
 * C6/B4: Uses the shared RequestAuthenticator to authenticate requests,
 * which handles Bearer token AND the phlix_session cookie fallback.
 * This ensures the same auth behavior as the Workerman daemon.
 *
 * S6: After authentication, validates Origin/Referer for cookie-authenticated
 * state-changing requests to prevent CSRF attacks.
 */
$authenticator = new RequestAuthenticator($authManager);
$authenticator->authenticate($request);

// S6: CSRF validation for cookie-authenticated state-changing requests.
if ($authenticator->isCookieAuthenticated($request)) {
    if (!$authenticator->validateCsrfOrigin($request)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'CSRF validation failed', 'code' => 'csrf.invalid_origin']);
        exit;
    }
}

// P5-S1: Populate RequestContext for downstream middleware/services.
// This mirrors what AuthMiddleware does in the Workerman daemon entrypoint.
// Must happen AFTER authentication but BEFORE any route dispatch.
if ($request->userId !== null && $request->userId !== '') {
    RequestContext::setUserId($request->userId);
}

// P5-S1: AccessScheduleMiddleware enforces time-based access restrictions.
// Without this, parental controls configured via the web UI would only be
// enforced on the daemon entrypoint (Workerman), not on FPM.
if ($container->has(AccessScheduleMiddleware::class)) {
    /** @var AccessScheduleMiddleware */
    $accessScheduleMiddleware = $container->get(AccessScheduleMiddleware::class);
    $scheduleResponse = $accessScheduleMiddleware($request);
    if ($scheduleResponse !== null) {
        $scheduleResponse->send();
        exit;
    }
}

/**
 * Credentialed CORS (shared seam with the Workerman daemon — see
 * {@see \Phlix\Server\Workerman\HttpHandler}). The SAME {@see CorsManager}
 * answers a cross-origin preflight here and decorates the final response, so
 * the dual-entry-point behavior cannot drift. With an empty allowlist
 * (the default) this is a no-op and same-origin behavior is unchanged.
 */
$cors = CorsManager::fromEnv();
$preflight = $cors->preflightResponse($request);
if ($preflight !== null) {
    $preflight->send();
    exit;
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
    error_log('[DEBUG] ' . date('Y-m-d H:i:s.v') . ' public/index.php dispatching admin route [uid=' . $requestUid . ']');
    /** @var \Phlix\Server\Http\Response $response */
    $response = $router->dispatch($request);
    error_log('[DEBUG] ' . date('Y-m-d H:i:s.v') . ' public/index.php admin route completed [uid=' . $requestUid . '] [status=' . $response->statusCode . ']');
    $cors->decorate($request, $response)->send();
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
    error_log('[DEBUG] ' . date('Y-m-d H:i:s.v') . ' public/index.php dispatching WebPortalRouter [uid=' . $requestUid . ']');
    $response = $webPortalRouter->dispatch($request);
    error_log('[DEBUG] ' . date('Y-m-d H:i:s.v') . ' public/index.php WebPortalRouter completed [uid=' . $requestUid . '] [status=' . $response->statusCode . ']');
    $cors->decorate($request, $response)->send();
} else {
    /**
     * Page routes
     *
     * The former Smarty SSR pages are fully replaced by the Vue SPA under
     * `/app` (D-SRV-DEL). Legacy page paths now redirect to their /app
     * equivalents; the SPA shell is served for `/app` + `/app/*`. Legacy
     * unsigned binary routes (book cover/download, photo thumbnail/full) are
     * kept and delegate to the JSON API controllers.
     * - / or '' : redirect to /app
     * - Other : 404 Not Found
     */
    if ($path === '/' || $path === '') {
        // The redesigned Vue SPA is the front door — send the bare root to /app.
        $response = (new Response())->redirect('/app');
    } elseif ($path === '/login') {
        $response = (new Response())->redirect('/app/login');
    } elseif ($path === '/register' || $path === '/auth/register') {
        $response = (new Response())->redirect('/app/signup');
    } elseif ($path === '/library' || $path === '/library/') {
        $response = (new Response())->redirect('/app');
    } elseif (preg_match('#^/library/item/(?P<id>[^/]+)$#', $path, $m) === 1) {
        $response = (new Response())->redirect('/app/media/' . $m['id']);
    } elseif (preg_match('#^/player/(?P<id>[^/]+)$#', $path, $m) === 1) {
        $response = (new Response())->redirect('/app/player/' . $m['id']);
    } elseif (preg_match('#^/library/(?P<id>[^/]+)$#', $path, $m) === 1) {
        $response = (new Response())->redirect('/app/library/' . $m['id']);
    } elseif ($path === '/search') {
        $response = (new Response())->redirect('/app/search');
    } elseif ($path === '/settings') {
        $response = (new Response())->redirect('/app/settings');
    } elseif (str_starts_with($path, '/admin/plugins')) {
        $response = (new Response())->redirect('/app/admin/plugins');
    } elseif ($path === '/admin/dashboard') {
        $response = (new Response())->redirect('/app/admin/dashboard');
    } elseif (str_starts_with($path, '/music')) {
        // Music portal pages are now Vue SPA pages under /app/music/*.
        if ($path === '/music' || $path === '/music/albums') {
            $response = (new Response())->redirect('/app/music');
        } elseif (preg_match('#^/music/albums/(?P<name>.+)$#', $path, $m) === 1) {
            $response = (new Response())->redirect('/app/music/album/' . urldecode($m['name']));
        } elseif ($path === '/music/artists') {
            $response = (new Response())->redirect('/app/music/artists');
        } elseif (preg_match('#^/music/artists/(?P<name>.+)$#', $path, $m) === 1) {
            $response = (new Response())->redirect('/app/music/artist/' . urldecode($m['name']));
        } elseif ($path === '/music/tracks') {
            $response = (new Response())->redirect('/app/music/tracks');
        } elseif ($path === '/music/player') {
            $response = (new Response())->redirect('/app/music/player');
        } else {
            $response = (new Response())->status(404)->html('<h1>404 - Page not found</h1>');
        }
    } elseif (str_starts_with($path, '/books')) {
        /**
         * Book pages are now Vue SPA pages; the cover/download file routes are
         * still served by the JSON {@see BookController} for legacy clients.
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
            $response = (new Response())->redirect('/app/books/' . $m['id'] . '/read');
        } elseif (preg_match('#^/books/(?P<id>[^/]+)$#', $path, $m) === 1) {
            $response = (new Response())->redirect('/app/books/' . $m['id']);
        } elseif ($path === '/books') {
            $response = (new Response())->redirect('/app/books');
        } else {
            $response = (new Response())->status(404)->html('<h1>404 - Page not found</h1>');
        }
    } elseif (str_starts_with($path, '/audiobooks')) {
        // Audiobook pages are now Vue SPA pages under /app/audiobooks/*.
        if ($path === '/audiobooks') {
            $response = (new Response())->redirect('/app/audiobooks');
        } elseif (preg_match('#^/audiobooks/(?P<id>[^/]+)/read$#', $path, $m) === 1) {
            $response = (new Response())->redirect('/app/audiobooks/' . $m['id'] . '/read');
        } elseif (preg_match('#^/audiobooks/(?P<id>[^/]+)$#', $path, $m) === 1) {
            $response = (new Response())->redirect('/app/audiobooks/' . $m['id']);
        } else {
            $response = (new Response())->status(404)->html('<h1>404 - Page not found</h1>');
        }
    } elseif (str_starts_with($path, '/photo')) {
        /**
         * Photo pages are now Vue SPA pages; the thumbnail/full image routes are
         * still served by the JSON {@see PhotoController} for legacy clients.
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
            $qs = count($request->query) > 0 ? '?' . http_build_query($request->query) : '';
            $response = (new Response())->redirect('/app/photo/albums' . $qs);
        } elseif (preg_match('#^/photo/album/(?P<id>[^/]+)$#', $path, $m) === 1) {
            $qs = count($request->query) > 0 ? '?' . http_build_query($request->query) : '';
            $response = (new Response())->redirect('/app/photo/album/' . $m['id'] . $qs);
        } elseif (preg_match('#^/photo/photo/(?P<id>[^/]+)$#', $path, $m) === 1) {
            $qs = count($request->query) > 0 ? '?' . http_build_query($request->query) : '';
            $response = (new Response())->redirect('/app/photo/photo/' . $m['id'] . $qs);
        } elseif ($path === '/photo/slideshow') {
            $qs = count($request->query) > 0 ? '?' . http_build_query($request->query) : '';
            $response = (new Response())->redirect('/app/photo/slideshow' . $qs);
        } else {
            $response = (new Response())->status(404)->html('<h1>404 - Page not found</h1>');
        }
    } elseif ($path === '/app' || str_starts_with($path, '/app/')) {
        /**
         * Shared Vue 3 SPA shell (Phase C). Registered at the TOP LEVEL of the
         * page router — NOT nested under /photo — so every /app deep-link reload
         * resolves (mirrors HttpHandler::dispatch in the Workerman daemon, where
         * /app is already a top-level check). The built `index.html` is served
         * from `public/assets/app/`; client-side routing handles `/app/*` deep
         * links. No auth gate here — the SPA authenticates via `ApiClient`.
         */
        $sharedUi = new \Phlix\Server\WebPortal\Controllers\SharedUiController(__DIR__);
        $response = $sharedUi->shell($request, []);
    } else {
        http_response_code(404);
        echo '<h1>404 - Page not found</h1>';
        exit;
    }

    $cors->decorate($request, $response)->send();
}
