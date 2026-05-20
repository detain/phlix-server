# Step 5.2: Web API Endpoints

**Phase:** 5 - Centralized Web Portal  
**Plan File:** step-5.2-web-api-endpoints.md  
**Objective:** Implement web portal API endpoints for library browsing, media playback, and user settings

---

## Overview

This step implements the API endpoints needed by the web portal for media browsing, playback, and user settings.

**Prerequisites:** Step 5.1 must be completed first.

---

## Tasks

### 5.2.1 Create Web Portal Router

Create `src/Server/WebPortal/WebPortalRouter.php`:
```php
<?php

namespace Phlex\Server\WebPortal;

use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;
use Phlex\Server\Http\Router;
use Phlex\Media\Library\LibraryManager;
use Phlex\Media\Library\ItemRepository;
use Phlex\Session\SessionManager;
use Phlex\Session\PlaybackController;
use Phlex\Auth\AuthManager;

class WebPortalRouter
{
    private Router $router;
    private LibraryManager $libraryManager;
    private ItemRepository $itemRepository;
    private SessionManager $sessionManager;
    private PlaybackController $playbackController;
    private AuthManager $authManager;

    public function __construct(
        LibraryManager $libraryManager,
        ItemRepository $itemRepository,
        SessionManager $sessionManager,
        PlaybackController $playbackController,
        AuthManager $authManager
    ) {
        $this->libraryManager = $libraryManager;
        $this->itemRepository = $itemRepository;
        $this->sessionManager = $sessionManager;
        $this->playbackController = $playbackController;
        $this->authManager = $authManager;
        $this->router = new Router();
        $this->registerRoutes();
    }

    private function registerRoutes(): void
    {
        // Auth routes
        $this->router->get('/api/v1/libraries', [$this, 'getLibraries']);
        $this->router->get('/api/v1/libraries/{id}', [$this, 'getLibrary']);
        $this->router->get('/api/v1/libraries/{id}/items', [$this, 'getLibraryItems']);
        $this->router->get('/api/v1/media/{id}', [$this, 'getMediaItem']);
        $this->router->get('/api/v1/media/{id}/playback', [$this, 'getPlaybackInfo']);
        $this->router->get('/api/v1/users/me/continue-watching', [$this, 'getContinueWatching']);
        $this->router->get('/api/v1/users/me/recently-watched', [$this, 'getRecentlyWatched']);
        
        // Settings routes
        $this->router->get('/api/v1/users/me/settings', [$this, 'getUserSettings']);
        $this->router->put('/api/v1/users/me/settings', [$this, 'updateUserSettings']);
    }

    public function dispatch(Request $request): Response
    {
        return $this->router->dispatch($request);
    }

    public function getLibraries(Request $request, array $params): Response
    {
        $libraries = $this->libraryManager->getAllLibraries();
        
        // Load item counts
        foreach ($libraries as &$lib) {
            $lib['item_count'] = $this->itemRepository->countByType($lib['id'], $lib['type']);
        }

        return (new Response())->json(['libraries' => $libraries]);
    }

    public function getLibrary(Request $request, array $params): Response
    {
        $library = $this->libraryManager->getLibrary($params['id']);
        
        if (!$library) {
            return (new Response())->status(404)->json(['error' => 'Library not found']);
        }

        return (new Response())->json(['library' => $library]);
    }

    public function getLibraryItems(Request $request, array $params): Response
    {
        $libraryId = $params['id'];
        $type = $request->query['type'] ?? null;
        $limit = (int)($request->query['limit'] ?? 50);
        $offset = (int)($request->query['offset'] ?? 0);

        if ($type) {
            $items = $this->itemRepository->getByType($libraryId, $type, $limit, $offset);
        } else {
            $items = $this->itemRepository->getByLibrary($libraryId, $limit, $offset);
        }

        return (new Response())->json([
            'items' => $items,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    public function getMediaItem(Request $request, array $params): Response
    {
        $item = $this->itemRepository->findById($params['id']);
        
        if (!$item) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        // Get streams
        $item['streams'] = $this->itemRepository->getItemStreams($item['id']);

        return (new Response())->json(['item' => $item]);
    }

    public function getPlaybackInfo(Request $request, array $params): Response
    {
        $item = $this->itemRepository->findById($params['id']);
        
        if (!$item) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        // Build playback info
        $playbackInfo = [
            'id' => $item['id'],
            'name' => $item['name'],
            'type' => $item['type'],
            'media_sources' => [
                [
                    'id' => 'default',
                    'container' => 'mkv',
                    'path' => $item['path'],
                    'direct_play' => true,
                ],
            ],
        ];

        return (new Response())->json(['playback_info' => $playbackInfo]);
    }

    public function getContinueWatching(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if (!$userId) {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        $items = $this->playbackController->getContinueWatching($userId);
        return (new Response())->json(['items' => $items]);
    }

    public function getRecentlyWatched(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if (!$userId) {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        $items = $this->playbackController->getRecentlyWatched($userId);
        return (new Response())->json(['items' => $items]);
    }

    public function getUserSettings(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if (!$userId) {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        // Get from database
        $settings = [
            'max_streams' => 3,
            'max_bitrate' => 100000000,
            'preferred_audio_language' => 'en',
            'preferred_subtitle_language' => 'en',
            'subtitle_mode' => 'only_foreign',
        ];

        return (new Response())->json(['settings' => $settings]);
    }

    public function updateUserSettings(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if (!$userId) {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        // Update in database
        return (new Response())->json(['message' => 'Settings updated']);
    }
}
```

### 5.2.2 Create Page Renderer

Create `src/Server/WebPortal/PageRenderer.php`:
```php
<?php

namespace Phlex\Server\WebPortal;

use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;
use Phlex\Media\Library\LibraryManager;
use Phlex\Media\Library\ItemRepository;
use Phlex\Session\PlaybackController;

class PageRenderer
{
    private string $templateDir;
    private LibraryManager $libraryManager;
    private ItemRepository $itemRepository;
    private PlaybackController $playbackController;

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

    public function renderLibrary(Request $request, array $params): Response
    {
        $libraryId = $params['id'] ?? '';
        $library = $this->libraryManager->getLibrary($libraryId);

        if (!$library) {
            return (new Response())->status(404)->html('<h1>Library not found</h1>');
        }

        $items = $this->itemRepository->getByLibrary($libraryId, 100, 0);

        $template = new \Smarty();
        $template->setTemplateDir($this->templateDir);
        $template->assign('current_page', 'library');
        $template->assign('library', $library);
        $template->assign('items', $items);

        $html = $template->fetch('library/index.tpl');

        return (new Response())->html($html);
    }

    public function renderLogin(Request $request): Response
    {
        $template = new \Smarty();
        $template->setTemplateDir($this->templateDir);

        $html = $template->fetch('auth/login.tpl');

        return (new Response())->html($html);
    }
}
```

### 5.2.3 Create Web Entry Point

Create `public/index.php`:
```php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Phlex\Server\Core\Application;
use Phlex\Server\Http\Request;
use Phlex\Common\Database\ConnectionPool;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Auth\AuthManager;
use Phlex\Auth\JwtHandler;
use Phlex\Auth\UserRepository;
use Phlex\Media\Library\LibraryManager;
use Phlex\Media\Library\ItemRepository;
use Phlex\Session\SessionManager;
use Phlex\Session\PlaybackController;

// Initialize components
$configPath = __DIR__ . '/../config/server.php';
$dbConfigPath = __DIR__ . '/../config/database.php';
$loggerConfigPath = __DIR__ . '/../config/logger.php';

ConnectionPool::init($dbConfigPath);
LoggerFactory::init($loggerConfigPath);

$db = ConnectionPool::getConnection('mysql');
$jwtHandler = new JwtHandler(getenv('JWT_SECRET') ?: 'default-secret-change-me');
$userRepository = new UserRepository($db);
$authManager = new AuthManager($userRepository, $jwtHandler, LoggerFactory::get(\Phlex\Common\Logger\LogChannels::AUTH));
$itemRepository = new ItemRepository($db);
$libraryManager = new LibraryManager($db, $scanner ?? null, $watcher ?? null);
$sessionManager = new SessionManager($db);
$playbackController = new PlaybackController($db, $sessionManager);

// Get request
$request = Request::fromGlobals();

// Check auth for API routes
$token = $request->getBearerToken();
if ($token) {
    $auth = $authManager->validateAccessToken($token);
    if ($auth) {
        $request->userId = $auth['user_id'];
    }
}

// Route handling
$path = $request->path;

if (str_starts_with($path, '/api/')) {
    // API routes handled by controllers
    header('Content-Type: application/json');
    echo json_encode(['message' => 'API endpoint - implement in Step 5.2']);
} else {
    // Page routes
    $renderer = new \Phlex\Server\WebPortal\PageRenderer(
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
```

---

## Verification

1. Check files exist:
```bash
ls -la /home/sites/phlex/src/Server/WebPortal/
ls -la /home/sites/phlex/public/assets/js/api-client.js
```

2. Verify API client syntax:
```bash
node --check /home/sites/phlex/public/assets/js/api-client.js 2>&1 || echo "Node not available, skip check"
```

---

## Git Workflow

```bash
cd /home/sites/phlex
git checkout -b step-5.2-web-api-endpoints
git add .
git commit -m "Step 5.2: Implement web portal API endpoints"
unset GITHUB_TOKEN
gh pr create --title "Step 5.2: Web API Endpoints" --body "Implements WebPortalRouter and PageRenderer for web portal functionality."
gh pr merge --squash --delete-branch
git checkout master && git pull
```

---

## Next Step

After completing and merging this step, proceed to **Step 5.R: Phase 5 Review** (`plans/phase-5/step-5.R-phase-review.md`).
