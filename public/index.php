<?php

/**
 * Web Portal Entry Point
 *
 * This is the main entry point for the Phlex Web Portal. It handles:
 * - Construction of the PSR-11 service container
 * - Request parsing and authentication
 * - Routing to either API endpoints or HTML page renderers
 *
 * @author Phlex Team
 * @version 1.0.0
 * @description Web portal entry point with request routing
 *
 * @see \Phlex\Common\Container\ContainerFactory For service wiring
 * @see \Phlex\Server\WebPortal\PageRenderer For HTML page rendering
 * @see \Phlex\Server\WebPortal\WebPortalRouter For API routing
 */

declare(strict_types=1);

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

use Phlex\Auth\AuthManager;
use Phlex\Common\Container\ContainerFactory;
use Phlex\Media\Library\ItemRepository;
use Phlex\Media\Library\LibraryManager;
use Phlex\Server\Http\Request;
use Phlex\Server\WebPortal\PageRenderer;
use Phlex\Session\PlaybackController;

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

$container = ContainerFactory::create($config);

/**
 * Resolve the services this entry point hands to controllers.
 *
 * @var AuthManager        $authManager
 * @var LibraryManager     $libraryManager
 * @var ItemRepository     $itemRepository
 * @var PlaybackController $playbackController
 */
$authManager        = $container->get(AuthManager::class);
$libraryManager     = $container->get(LibraryManager::class);
$itemRepository     = $container->get(ItemRepository::class);
$playbackController = $container->get(PlaybackController::class);

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
 * Routes are split into two categories:
 * - API routes (prefixed with /api/) - Return JSON
 * - Page routes - Return HTML rendered by Smarty
 */
$path = $request->path;

if (str_starts_with($path, '/api/')) {
    /**
     * API routes
     *
     * API endpoints are handled by WebPortalRouter and return JSON.
     * This implementation currently returns a placeholder message.
     * Full API implementation is in WebPortalRouter.
     *
     * @see \Phlex\Server\WebPortal\WebPortalRouter For complete API handling
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
     * - /library/{id} : Library browser (via PageRenderer::renderLibrary)
     * - Other : 404 Not Found
     *
     * @see PageRenderer For page rendering
     */
    $renderer = new PageRenderer(
        __DIR__ . '/templates',
        $libraryManager,
        $itemRepository,
        $playbackController
    );

    if ($path === '/' || $path === '') {
        $response = $renderer->renderHome($request);
    } elseif ($path === '/login') {
        $response = $renderer->renderLogin($request);
    } else {
        http_response_code(404);
        echo '<h1>404 - Page not found</h1>';
        exit;
    }

    $response->send();
}
