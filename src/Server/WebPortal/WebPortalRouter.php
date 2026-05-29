<?php

declare(strict_types=1);

namespace Phlix\Server\WebPortal;

use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\Http\Router;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Session\SessionManager;
use Phlix\Session\PlaybackController;
use Phlix\Auth\AuthManager;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Auth\WatchHistory;

/**
 * WebPortalRouter handles API routing for the web portal.
 *
 * This router provides endpoints for media library browsing,
 * playback information retrieval, and user session management.
 * All endpoints return JSON responses suitable for consumption
 * by the web portal's JavaScript client.
 *
 * @author Phlix Team
 * @version 1.0.0
 * @description Handles REST API routing for the web portal interface
 *
 * @see PageRenderer For HTML page rendering
 * @see Request For request object structure
 * @see Response For response object structure
 */
class WebPortalRouter
{
    /** @var Router The underlying router instance for dispatching requests */
    private Router $router;

    /** @var LibraryManager Manages media libraries */
    private LibraryManager $libraryManager;

    /** @var ItemRepository Provides access to media items */
    private ItemRepository $itemRepository;

    /** @var PlaybackController Handles playback state and progress */
    private PlaybackController $playbackController;

    /** @var PlaybackMarkerService Provides skip-button specs for playback */
    private PlaybackMarkerService $playbackMarkerService;

    /** @var UserRepository|null Persists/reads user settings; null when not wired */
    private ?UserRepository $userRepository;

    /** @var WatchHistory|null Tracks watch history per profile; null when not wired */
    private ?WatchHistory $watchHistory;

    /** @var UserProfileManager|null Resolves user profiles; null when not wired */
    private ?UserProfileManager $profileManager;

    /**
     * Constructs a new WebPortalRouter instance.
     *
     * Initializes the router with required service dependencies and registers
     * all API route handlers for the web portal.
     *
     * @param LibraryManager $libraryManager Manages media library operations
     * @param ItemRepository $itemRepository Provides access to media items
     * @param SessionManager $sessionManager Manages user/device sessions
     * @param PlaybackController $playbackController Handles playback state tracking
     * @param AuthManager $authManager Handles authentication operations
     * @param PlaybackMarkerService $playbackMarkerService Provides skip-button specs
     * @param UserRepository|null $userRepository Persists user settings (optional;
     *        when null the settings endpoints respond 503 instead of faking success)
     * @param WatchHistory|null $watchHistory Tracks watch history per profile (optional;
     *        when null the history endpoints respond 503 instead of faking success)
     * @param UserProfileManager|null $profileManager Resolves user profiles (optional;
     *        when null the history endpoints respond 503 instead of faking success)
     *
     * @example
     * ```php
     * $router = new WebPortalRouter(
     *     $libraryManager,
     *     $itemRepository,
     *     $sessionManager,
     *     $playbackController,
     *     $authManager,
     *     $playbackMarkerService,
     *     $userRepository,
     *     $watchHistory,
     *     $profileManager
     * );
     * ```
     */
    public function __construct(
        LibraryManager $libraryManager,
        ItemRepository $itemRepository,
        SessionManager $sessionManager,
        PlaybackController $playbackController,
        AuthManager $authManager,
        PlaybackMarkerService $playbackMarkerService,
        ?UserRepository $userRepository = null,
        ?WatchHistory $watchHistory = null,
        ?UserProfileManager $profileManager = null
    ) {
        // SessionManager and AuthManager are accepted for future middleware wiring
        // but not stored — see WebPortalRouter routes for authenticated endpoints.
        unset($sessionManager, $authManager);

        $this->libraryManager = $libraryManager;
        $this->itemRepository = $itemRepository;
        $this->playbackController = $playbackController;
        $this->playbackMarkerService = $playbackMarkerService;
        $this->userRepository = $userRepository;
        $this->watchHistory = $watchHistory;
        $this->profileManager = $profileManager;
        $this->router = new Router();
        $this->registerRoutes();
    }

    /**
     * Registers all API routes for the web portal.
     *
     * Route structure:
     * - GET /api/v1/libraries - List all libraries with item counts
     * - GET /api/v1/libraries/{id} - Get single library details
     * - GET /api/v1/libraries/{id}/items - Get items in a library
     * - GET /api/v1/media/{id} - Get media item details with streams
     * - GET /api/v1/media/{id}/playback - Get playback information
     * - GET /api/v1/users/me/continue-watching - Get user's continue watching list
     * - GET /api/v1/users/me/recently-watched - Get user's recently watched items
     * - GET /api/v1/users/me/settings - Get user settings
     * - PUT /api/v1/users/me/settings - Update user settings
     *
     * @return void
     */
    private function registerRoutes(): void
    {
        // Library routes
        $this->router->get('/api/v1/libraries', [$this, 'getLibraries']);
        $this->router->get('/api/v1/libraries/{id}', [$this, 'getLibrary']);
        $this->router->get('/api/v1/libraries/{id}/items', [$this, 'getLibraryItems']);

        // Media routes
        $this->router->get('/api/v1/media/{id}', [$this, 'getMediaItem']);
        $this->router->get('/api/v1/media/{id}/playback', [$this, 'getPlaybackInfo']);

        // User activity routes
        $this->router->get('/api/v1/users/me/continue-watching', [$this, 'getContinueWatching']);
        $this->router->get('/api/v1/users/me/recently-watched', [$this, 'getRecentlyWatched']);

        // Watch history routes
        $this->router->delete('/api/v1/users/me/history/{mediaItemId}', [$this, 'removeFromHistory']);
        $this->router->delete('/api/v1/users/me/history', [$this, 'clearHistory']);

        // Settings routes
        $this->router->get('/api/v1/users/me/settings', [$this, 'getUserSettings']);
        $this->router->put('/api/v1/users/me/settings', [$this, 'updateUserSettings']);
    }

    /**
     * Dispatches the request to the appropriate handler.
     *
     * @param Request $request The HTTP request to dispatch
     *
     * @return Response The response from the matched route handler
     *
     * @see Router::dispatch() For dispatching details
     */
    public function dispatch(Request $request): Response
    {
        return $this->router->dispatch($request);
    }

    /**
     * Retrieves all libraries with item counts.
     *
     * Returns a list of all media libraries, each enriched with
     * an item_count property indicating the number of items in that library.
     *
     * @param Request $request The HTTP request (unused)
     * @param array<string, string> $params Route parameters (unused)
     *
     * @return Response JSON response with libraries array
     *
     * @api_endpoint GET /api/v1/libraries
     *
     * @example Response structure:
     * ```json
     * {
     *   "libraries": [
     *     {
     *       "id": "lib_abc123",
     *       "name": "Movies",
     *       "type": "video",
     *       "item_count": 42
     *     }
     *   ]
     * }
     * ```
     */
    public function getLibraries(Request $request, array $params): Response
    {
        $libraries = $this->libraryManager->getAllLibraries();

        // Load item counts
        foreach ($libraries as &$lib) {
            $libId = is_string($lib['id'] ?? null) ? $lib['id'] : '';
            $libType = is_string($lib['type'] ?? null) ? $lib['type'] : '';
            $lib['item_count'] = $this->itemRepository->countByType($libId, $libType);
        }
        unset($lib);

        return (new Response())->json(['libraries' => $libraries]);
    }

    /**
     * Retrieves a single library by ID.
     *
     * @param Request $request The HTTP request (unused)
     * @param array<string, string> $params Route parameters including 'id'
     *
     * @return Response JSON response with library object or 404 error
     *
     * @api_endpoint GET /api/v1/libraries/{id}
     *
     * @example Response structure:
     * ```json
     * {
     *   "library": {
     *     "id": "lib_abc123",
     *     "name": "Movies",
     *     "type": "video",
     *     "paths": ["/mnt/media/movies"]
     *   }
     * }
     * ```
     */
    public function getLibrary(Request $request, array $params): Response
    {
        $library = $this->libraryManager->getLibrary($params['id']);

        if (!$library) {
            return (new Response())->status(404)->json(['error' => 'Library not found']);
        }

        return (new Response())->json(['library' => $library]);
    }

    /**
     * Retrieves items from a specific library with optional filtering.
     *
     * @param Request $request The HTTP request with query parameters:
     *   - type: Filter by media type (video, audio, image)
     *   - limit: Maximum items to return (default: 50)
     *   - offset: Pagination offset (default: 0)
     * @param array<string, string> $params Route parameters including 'id' (library ID)
     *
     * @return Response JSON response with items array and pagination info
     *
     * @api_endpoint GET /api/v1/libraries/{id}/items
     *
     * @example Response structure:
     * ```json
     * {
     *   "items": [
     *     {
     *       "id": "item_xyz789",
     *       "name": "Movie Title",
     *       "type": "movie",
     *       "path": "/mnt/media/movies/movie.mkv"
     *     }
     *   ],
     *   "limit": 50,
     *   "offset": 0
     * }
     * ```
     */
    public function getLibraryItems(Request $request, array $params): Response
    {
        $libraryId = $params['id'];
        $type = $request->queryString('type');
        $limit = $request->queryInt('limit', 50);
        $offset = $request->queryInt('offset', 0);

        if ($type !== null && $type !== '') {
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

    /**
     * Retrieves a single media item with its stream information.
     *
     * @param Request $request The HTTP request (unused)
     * @param array<string, string> $params Route parameters including 'id'
     *
     * @return Response JSON response with item object and streams, or 404 error
     *
     * @api_endpoint GET /api/v1/media/{id}
     *
     * @example Response structure:
     * ```json
     * {
     *   "item": {
     *     "id": "item_xyz789",
     *     "name": "Movie Title",
     *     "type": "movie",
     *     "path": "/mnt/media/movies/movie.mkv",
     *     "streams": [
     *       {
     *         "stream_index": 0,
     *         "stream_type": "video",
     *         "codec": "h264"
     *       }
     *     ]
     *   }
     * }
     * ```
     */
    public function getMediaItem(Request $request, array $params): Response
    {
        $item = $this->itemRepository->findById($params['id']);

        if (!$item) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        // Get streams
        $itemId = is_string($item['id'] ?? null) ? $item['id'] : '';
        $item['streams'] = $this->itemRepository->getItemStreams($itemId);

        return (new Response())->json(['item' => $item]);
    }

    /**
     * Retrieves playback information for a media item.
     *
     * Returns playback information including available media sources
     * and direct play capabilities. This is used by the player
     * to initialize playback.
     *
     * @param Request $request The HTTP request (unused)
     * @param array<string, string> $params Route parameters including 'id'
     *
     * @return Response JSON response with playback_info object or 404 error
     *
     * @api_endpoint GET /api/v1/media/{id}/playback
     *
     * @example Response structure:
     * ```json
     * {
     *   "playback_info": {
     *     "id": "item_xyz789",
     *     "name": "Movie Title",
     *     "type": "movie",
     *     "media_sources": [
     *       {
     *         "id": "default",
     *         "container": "mkv",
     *         "path": "/mnt/media/movies/movie.mkv",
     *         "direct_play": true
     *       }
     *     ]
     *   }
     * }
     * ```
     */
    public function getPlaybackInfo(Request $request, array $params): Response
    {
        $item = $this->itemRepository->findById($params['id']);

        if (!$item) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        // Get marker data for skip buttons
        $skipSpec = $this->playbackMarkerService->getFullSpec($params['id']);

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
            'markers' => $skipSpec->toArray(),
        ];

        return (new Response())->json(['playback_info' => $playbackInfo]);
    }

    /**
     * Retrieves the user's continue watching list.
     *
     * Returns media items that the user has partially watched and
     * may want to resume. Requires authentication.
     *
     * @param Request $request The HTTP request (userId set from auth)
     * @param array<string, string> $params Route parameters (unused)
     *
     * @return Response JSON response with items array or 401 error
     *
     * @api_endpoint GET /api/v1/users/me/continue-watching
     *
     * @requires Authentication
     *
     * @example Response structure:
     * ```json
     * {
     *   "items": [
     *     {
     *       "id": "item_xyz789",
     *       "name": "Movie Title",
     *       "progress_percent": 45.5,
     *       "position_ticks": 36000000000
     *     }
     *   ]
     * }
     * ```
     */
    public function getContinueWatching(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if (!$userId) {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        $items = $this->playbackController->getContinueWatching($userId);
        return (new Response())->json(['items' => $items]);
    }

    /**
     * Retrieves the user's recently watched items.
     *
     * Returns a list of media items the user has watched,
     * ordered by most recent first. Requires authentication.
     *
     * @param Request $request The HTTP request (userId set from auth)
     * @param array<string, string> $params Route parameters (unused)
     *
     * @return Response JSON response with items array or 401 error
     *
     * @api_endpoint GET /api/v1/users/me/recently-watched
     *
     * @requires Authentication
     *
     * @example Response structure:
     * ```json
     * {
     *   "items": [
     *     {
     *       "id": "item_xyz789",
     *       "name": "Movie Title",
     *       "watched_at": "2024-01-15T10:30:00+00:00"
     *     }
     *   ]
     * }
     * ```
     */
    public function getRecentlyWatched(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if (!$userId) {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        $items = $this->playbackController->getRecentlyWatched($userId);
        return (new Response())->json(['items' => $items]);
    }

    /**
     * Removes a single item from the user's watch history.
     *
     * @param Request $request The HTTP request (userId set from auth)
     * @param array<string, string> $params Route parameters including 'mediaItemId'
     *
     * @return Response JSON response with success message, 401 if not authenticated,
     *         or 404 if the item was not found in history
     *
     * @api_endpoint DELETE /api/v1/users/me/history/{mediaItemId}
     *
     * @requires Authentication
     */
    public function removeFromHistory(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if (!$userId) {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        if ($this->watchHistory === null) {
            return (new Response())->status(503)->json([
                'error' => 'Watch history is not configured on this server',
            ]);
        }

        if ($this->profileManager === null) {
            return (new Response())->status(503)->json([
                'error' => 'Profile manager is not configured on this server',
            ]);
        }

        $profile = $this->profileManager->getActiveProfile($userId);
        if ($profile === null) {
            return (new Response())->status(404)->json(['error' => 'No active profile found']);
        }

        $profileId = is_string($profile['id'] ?? null) ? $profile['id'] : '';
        if ($profileId === '') {
            return (new Response())->status(500)->json(['error' => 'Invalid profile ID']);
        }

        $mediaItemId = $params['mediaItemId'] ?? '';
        if ($mediaItemId === '') {
            return (new Response())->status(400)->json(['error' => 'Media item ID is required']);
        }

        $this->watchHistory->removeFromHistory($profileId, $mediaItemId);

        return (new Response())->json(['message' => 'Removed from watch history']);
    }

    /**
     * Clears all watch history for the user's active profile.
     *
     * @param Request $request The HTTP request (userId set from auth)
     * @param array<string, string> $params Route parameters (unused)
     *
     * @return Response JSON response with success message or 401 if not authenticated
     *
     * @api_endpoint DELETE /api/v1/users/me/history
     *
     * @requires Authentication
     */
    public function clearHistory(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if (!$userId) {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        if ($this->watchHistory === null) {
            return (new Response())->status(503)->json([
                'error' => 'Watch history is not configured on this server',
            ]);
        }

        if ($this->profileManager === null) {
            return (new Response())->status(503)->json([
                'error' => 'Profile manager is not configured on this server',
            ]);
        }

        $profile = $this->profileManager->getActiveProfile($userId);
        if ($profile === null) {
            return (new Response())->status(404)->json(['error' => 'No active profile found']);
        }

        $profileId = is_string($profile['id'] ?? null) ? $profile['id'] : '';
        if ($profileId === '') {
            return (new Response())->status(500)->json(['error' => 'Invalid profile ID']);
        }

        $this->watchHistory->clearHistory($profileId);

        return (new Response())->json(['message' => 'Watch history cleared']);
    }

    /**
     * Retrieves the current user's settings.
     *
     * Returns user preferences including streaming limits,
     * audio/subtitle language preferences. Requires authentication.
     *
     * @param Request $request The HTTP request (userId set from auth)
     * @param array<string, string> $params Route parameters (unused)
     *
     * @return Response JSON response with settings object or 401 error
     *
     * @api_endpoint GET /api/v1/users/me/settings
     *
     * @requires Authentication
     *
     * @example Response structure:
     * ```json
     * {
     *   "settings": {
     *     "max_streams": 3,
     *     "max_bitrate": 100000000,
     *     "preferred_audio_language": "en",
     *     "preferred_subtitle_language": "en",
     *     "subtitle_mode": "only_foreign"
     *   }
     * }
     * ```
     */
    public function getUserSettings(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if (!$userId) {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        // Sensible defaults applied when a user has never saved settings (or when
        // no persistence layer is wired). Persisted values override these.
        $defaults = [
            'max_streams' => 3,
            'max_bitrate' => 100000000,
            'preferred_audio_language' => 'en',
            'preferred_subtitle_language' => 'en',
            'subtitle_mode' => 'only_foreign',
        ];

        $settings = $defaults;
        if ($this->userRepository !== null) {
            $stored = $this->userRepository->getSettings($userId);
            if ($stored !== null) {
                // Drop internal columns the client doesn't need.
                unset($stored['user_id']);
                $settings = array_merge($defaults, $stored);
            }
        }

        return (new Response())->json(['settings' => $settings]);
    }

    /**
     * Updates the current user's settings.
     *
     * Saves user preferences including streaming limits,
     * audio/subtitle language preferences. Requires authentication.
     *
     * @param Request $request The HTTP request (userId set from auth)
     * @param array<string, string> $params Route parameters (unused)
     *
     * @return Response JSON response with success message or 401 error
     *
     * @api_endpoint PUT /api/v1/users/me/settings
     *
     * @requires Authentication
     *
     * @example Response structure:
     * ```json
     * {
     *   "message": "Settings updated"
     * }
     * ```
     */
    public function updateUserSettings(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if (!$userId) {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        $settings = $this->extractSettingsPayload($request);
        if ($settings === null) {
            return (new Response())->status(400)->json(['error' => 'Invalid settings payload']);
        }

        if ($this->userRepository === null) {
            // No persistence layer wired; cannot honestly claim success.
            return (new Response())->status(503)->json([
                'error' => 'Settings persistence is not configured on this server',
            ]);
        }

        $this->userRepository->updateSettings($userId, $settings);

        return (new Response())->json(['message' => 'Settings updated']);
    }

    /**
     * Extracts a sanitized settings payload from the request body.
     *
     * Only known, whitelisted keys are forwarded to the repository; unknown
     * keys are ignored. Returns null if the body is present but not decodable
     * as a JSON object.
     *
     * @param Request $request The HTTP request
     *
     * @return array<string, mixed>|null Sanitized settings, or null on malformed body
     */
    private function extractSettingsPayload(Request $request): ?array
    {
        // For a JSON PUT, Request::fromGlobals() decodes the request body into
        // $request->body (an array) and keeps the raw JSON in $request->rawBody.
        // The decoded body is the source of truth; only fall back to decoding
        // rawBody ourselves if body came through empty but raw bytes exist.
        $decoded = $request->body;
        if ($decoded === [] && $request->rawBody !== '') {
            $fromRaw = json_decode($request->rawBody, true);
            if (!is_array($fromRaw)) {
                return null;
            }
            $decoded = $fromRaw;
        }

        if ($decoded === []) {
            return [];
        }

        $allowed = [
            'max_streams',
            'max_bitrate',
            'preferred_audio_language',
            'preferred_subtitle_language',
            'subtitle_mode',
            'default_content_rating',
            'transcoding_preferences',
            'theme',
        ];

        $settings = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $decoded)) {
                $settings[$key] = $decoded[$key];
            }
        }

        return $settings;
    }
}
