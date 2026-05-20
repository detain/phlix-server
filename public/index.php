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
use Phlix\Server\Http\Routes\AdminRoutes;
use Phlix\Server\WebPortal\Controllers\PluginAdminPageController;
use Phlix\Server\WebPortal\PageRenderer;

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
    if ($auth) {
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
     * This implementation currently returns a placeholder message.
     * Full API implementation is in WebPortalRouter.
     *
     * @see \Phlix\Server\WebPortal\WebPortalRouter For complete API handling
     */
    header('Content-Type: application/json');
    echo json_encode(['message' => 'API endpoint - implement in Step 5.2']);
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
    } else {
        http_response_code(404);
        echo '<h1>404 - Page not found</h1>';
        exit;
    }

    $response->send();
}
