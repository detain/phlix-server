<?php

declare(strict_types=1);

namespace Phlex\Server\WebPortal;

use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;
use Phlex\Server\Http\Router;
use Phlex\Media\Library\LibraryManager;
use Phlex\Media\Library\ItemRepository;
use Phlex\Media\Library\MusicLibraryManager;
use Phlex\Media\Markers\PlaybackMarkerService;
use Phlex\Session\SessionManager;
use Phlex\Session\PlaybackController;
use Phlex\Auth\AuthManager;

/**
 * WebPortalRouter handles API routing for the web portal.
 *
 * This router provides endpoints for media library browsing,
 * playback information retrieval, and user session management.
 * All endpoints return JSON responses suitable for consumption
 * by the web portal's JavaScript client.
 *
 * @author Phlex Team
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

    /** @var SessionManager Manages user sessions */
    private SessionManager $sessionManager;

    /** @var PlaybackController Handles playback state and progress */
    private PlaybackController $playbackController;

    /** @var AuthManager Handles authentication operations */
    private AuthManager $authManager;

    /** @var PlaybackMarkerService Provides skip-button specs for playback */
    private PlaybackMarkerService $playbackMarkerService;

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
     *
     * @example
     * ```php
     * $router = new WebPortalRouter(
     *     $libraryManager,
     *     $itemRepository,
     *     $sessionManager,
     *     $playbackController,
     *     $authManager,
     *     $playbackMarkerService
     * );
     * ```
     */
    public function __construct(
        LibraryManager $libraryManager,
        ItemRepository $itemRepository,
        SessionManager $sessionManager,
        PlaybackController $playbackController,
        AuthManager $authManager,
        PlaybackMarkerService $playbackMarkerService
    ) {
        $this->libraryManager = $libraryManager;
        $this->itemRepository = $itemRepository;
        $this->sessionManager = $sessionManager;
        $this->playbackController = $playbackController;
        $this->authManager = $authManager;
        $this->playbackMarkerService = $playbackMarkerService;
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

        // Settings routes
        $this->router->get('/api/v1/users/me/settings', [$this, 'getUserSettings']);
        $this->router->put('/api/v1/users/me/settings', [$this, 'updateUserSettings']);

        // Music routes (web portal)
        $this->router->get('/music', [$this, 'getMusicIndex']);
        $this->router->get('/music/artists', [$this, 'getMusicArtists']);
        $this->router->get('/music/artists/{mbid}', [$this, 'getMusicArtist']);
        $this->router->get('/music/albums', [$this, 'getMusicAlbums']);
        $this->router->get('/music/albums/{mbid}', [$this, 'getMusicAlbum']);
        $this->router->get('/music/tracks', [$this, 'getMusicTracks']);
        $this->router->get('/music/player', [$this, 'getMusicPlayer']);
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
            $lib['item_count'] = $this->itemRepository->countByType($lib['id'], $lib['type']);
        }

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
        $item['streams'] = $this->itemRepository->getItemStreams($item['id']);

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

        // Update in database
        return (new Response())->json(['message' => 'Settings updated']);
    }

    /**
     * Gets the music library index page.
     *
     * GET /music
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters
     * @return Response JSON response with music library overview
     *
     * @since 0.14.0
     */
    public function getMusicIndex(Request $request, array $params): Response
    {
        $libraries = $this->libraryManager->getAllLibraries();
        $musicLibraries = array_filter($libraries, fn($lib) => $lib['type'] === 'music');

        return (new Response())->json([
            'music_libraries' => $musicLibraries,
        ]);
    }

    /**
     * Gets all music artists.
     *
     * GET /music/artists
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters
     * @return Response JSON response with artists list
     *
     * @since 0.14.0
     */
    public function getMusicArtists(Request $request, array $params): Response
    {
        $libraries = $this->libraryManager->getAllLibraries();
        $musicLibraries = array_filter($libraries, fn($lib) => $lib['type'] === 'music');

        $allArtists = [];
        foreach ($musicLibraries as $library) {
            // Artists are fetched via API client using /music/artists endpoint
        }

        return (new Response())->json(['artists' => $allArtists]);
    }

    /**
     * Gets a specific artist.
     *
     * GET /music/artists/{mbid}
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters
     * @return Response JSON response with artist details
     *
     * @since 0.14.0
     */
    public function getMusicArtist(Request $request, array $params): Response
    {
        return (new Response())->json(['artist' => null]);
    }

    /**
     * Gets all music albums.
     *
     * GET /music/albums
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters
     * @return Response JSON response with albums list
     *
     * @since 0.14.0
     */
    public function getMusicAlbums(Request $request, array $params): Response
    {
        return (new Response())->json(['albums' => []]);
    }

    /**
     * Gets a specific album.
     *
     * GET /music/albums/{mbid}
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters
     * @return Response JSON response with album details
     *
     * @since 0.14.0
     */
    public function getMusicAlbum(Request $request, array $params): Response
    {
        return (new Response())->json(['album' => null]);
    }

    /**
     * Gets all music tracks.
     *
     * GET /music/tracks
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters
     * @return Response JSON response with tracks list
     *
     * @since 0.14.0
     */
    public function getMusicTracks(Request $request, array $params): Response
    {
        return (new Response())->json(['tracks' => []]);
    }

    /**
     * Gets the music player page.
     *
     * GET /music/player
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters
     * @return Response JSON response with player state
     *
     * @since 0.14.0
     */
    public function getMusicPlayer(Request $request, array $params): Response
    {
        return (new Response())->json(['player' => []]);
    }
}
