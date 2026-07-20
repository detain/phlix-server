<?php

/**
 * Phlix media server component: WebPortal.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\WebPortal;

use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\Http\Middleware\AuthMiddleware;
use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Router;
use Phlix\Media\ChapterSearchService;
use Phlix\Media\CollectionService;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Music\MusicLibraryService;
use Phlix\Media\RecommendationService;
use Phlix\Media\Library\IndexBuckets;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\RatingGate;
use Phlix\Media\Library\MediaItemShaper;
use Phlix\Media\Library\StreamProbeBackfill;
use Phlix\Media\Library\StreamTrackShaper;
use Phlix\Media\MarkerType;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Media\SimilarityService;
use Phlix\Session\SessionManager;
use Phlix\Session\PlaybackController;
use Phlix\Auth\AuthManager;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Auth\WatchHistory;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Media\UserItemDataRepository;
use Phlix\Media\Metadata\TmdbProvider;
use Phlix\Media\Playback\PlaybackPreferences;
use Phlix\Media\Streaming\ClientCapabilities;
use Phlix\Server\Http\Controllers\MediaUserDataController;
use Phlix\Server\Http\Controllers\MediaPosterController;
use Phlix\Server\Http\Controllers\MediaRatingsController;
use Phlix\Server\Http\Controllers\TranscodeController;
use Phlix\Server\Http\Controllers\UserAvatarController;
use Phlix\Media\Storage\AvatarStorage;

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

    /** @var MarkerService Provides chapter marker storage and retrieval */
    private MarkerService $markerService;

    /** @var ChapterSearchService|null Searches media by marker content (P3B-S8); null when not wired */
    private ?ChapterSearchService $chapterSearchService;

    /** @var UserRepository|null Persists/reads user settings; null when not wired */
    private ?UserRepository $userRepository;

    /** @var WatchHistory|null Tracks watch history per profile; null when not wired */
    private ?WatchHistory $watchHistory;

    /** @var UserProfileManager|null Resolves user profiles; null when not wired */
    private ?UserProfileManager $profileManager;

    /** @var UserItemDataRepository|null Per-user favorites/ratings; null when not wired */
    private ?UserItemDataRepository $userItemData;

    /** @var MediaUserDataController|null Handles favorite/rating routes; null when not wired */
    private ?MediaUserDataController $mediaUserDataController;

    /** @var MediaRatingsController|null Handles media-item ratings endpoints; null when not wired */
    private ?MediaRatingsController $mediaRatingsController;

    /** @var AuditLogger|null Security-event logger for admin-gated operations; null when not wired */
    private ?AuditLogger $auditLogger;

    /** @var AvatarStorage|null Stores user avatar images; null when not wired */
    private ?AvatarStorage $avatarStorage;

    /** @var UserAvatarController|null Handles avatar upload/delete routes; null when not wired */
    private ?UserAvatarController $userAvatarController;

    /** @var TranscodeController|null Handles transcode job start/status routes; null when not wired */
    private ?TranscodeController $transcodeController;

    /** @var SimilarityService|null Computes and retrieves item similarity scores; null when not wired */
    private ?SimilarityService $similarityService;

    /** @var RecommendationService|null Computes and retrieves because-you-watched recommendations; null when not wired */
    private ?RecommendationService $recommendationService;

    /** @var CollectionService|null Manages TMDB box-set collections; null when not wired */
    private ?CollectionService $collectionService;

    /** @var MusicLibraryService|null Manages music Artist→Album→Track hierarchy; null when not wired */
    private ?MusicLibraryService $musicLibraryService;

    /**
     * @var StreamProbeBackfill|null Lazy one-shot stream backfill for pre-071
     *      items (see getPlaybackInfo()); built on first use when not injected
     *      (the optional ctor arg is a test seam)
     */
    private ?StreamProbeBackfill $streamBackfill;

    /**
     * @var RatingGate|null Shared parental-control access gate; built lazily from
     *      the item repository + profile/user managers (null only when the
     *      profile manager is unwired, in which case gating is a strict no-op).
     */
    private ?RatingGate $ratingGate = null;

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
     * @param MarkerService $markerService Provides chapter marker storage and retrieval
     * @param UserRepository|null $userRepository Persists user settings (optional;
     *        when null the settings endpoints respond 503 instead of faking success)
     * @param WatchHistory|null $watchHistory Tracks watch history per profile (optional;
     *        when null the history endpoints respond 503 instead of faking success)
     * @param UserProfileManager|null $profileManager Resolves user profiles (optional;
     *        when null the history endpoints respond 503 instead of faking success)
     * @param UserItemDataRepository|null $userItemData Per-user favorites/ratings (optional)
     * @param MediaUserDataController|null $mediaUserDataController Favorite/rating routes (optional)
     * @param AuditLogger|null $auditLogger Security-event logger for admin operations (optional)
     * @param AvatarStorage|null $avatarStorage Stores user avatar images (optional)
     * @param UserAvatarController|null $userAvatarController Avatar upload/delete routes (optional)
     * @param SimilarityService|null $similarityService Computes item similarity scores (optional)
     * @param RecommendationService|null $recommendationService Computes because-you-watched recs (optional)
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
        MarkerService $markerService,
        ?ChapterSearchService $chapterSearchService = null,
        ?UserRepository $userRepository = null,
        ?WatchHistory $watchHistory = null,
        ?UserProfileManager $profileManager = null,
        ?UserItemDataRepository $userItemData = null,
        ?MediaUserDataController $mediaUserDataController = null,
        ?AuditLogger $auditLogger = null,
        ?AvatarStorage $avatarStorage = null,
        ?UserAvatarController $userAvatarController = null,
        ?MediaRatingsController $mediaRatingsController = null,
        ?TranscodeController $transcodeController = null,
        ?SimilarityService $similarityService = null,
        ?RecommendationService $recommendationService = null,
        ?CollectionService $collectionService = null,
        ?MusicLibraryService $musicLibraryService = null,
        ?StreamProbeBackfill $streamBackfill = null
    ) {
        // SessionManager and AuthManager are accepted for future middleware wiring
        // but not stored — see WebPortalRouter routes for authenticated endpoints.
        unset($sessionManager, $authManager);

        $this->libraryManager = $libraryManager;
        $this->itemRepository = $itemRepository;
        $this->playbackController = $playbackController;
        $this->playbackMarkerService = $playbackMarkerService;
        $this->markerService = $markerService;
        $this->chapterSearchService = $chapterSearchService;
        $this->userRepository = $userRepository;
        $this->watchHistory = $watchHistory;
        $this->profileManager = $profileManager;
        $this->userItemData = $userItemData;
        $this->mediaUserDataController = $mediaUserDataController;
        $this->auditLogger = $auditLogger;
        $this->avatarStorage = $avatarStorage;
        $this->userAvatarController = $userAvatarController;
        $this->mediaRatingsController = $mediaRatingsController;
        $this->transcodeController = $transcodeController;
        $this->similarityService = $similarityService;
        $this->recommendationService = $recommendationService;
        $this->collectionService = $collectionService;
        $this->musicLibraryService = $musicLibraryService;
        $this->streamBackfill = $streamBackfill;
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
        // Every route here exposes per-user library / media data (listings,
        // search, single-item detail, watch activity, settings), so require a
        // signed-in user — otherwise the whole library was enumerable without a
        // token. `$request->userId` is populated from the Bearer token (or the
        // `phlix_session` cookie) by BOTH entry points (public/index.php and
        // HttpHandler) before dispatch; AuthMiddleware just enforces its presence.
        $auth = new AuthMiddleware();

        // Public media-item ratings endpoint (P1-S1): no auth required.
        $this->router->get('/api/v1/media/{id}/ratings', [$this, 'getRatings']);

        $this->router->group('', function (Router $r): void {
            // P4-S1: similar items endpoint — wrapped in its own auth group
            // to require auth middleware (data leakage prevention).
            // Cannot pass middleware to Router::get() directly; use a nested group.
            $r->group('', function (Router $r): void {
                $r->get('/api/v1/media/{id}/similar', [$this, 'getSimilarItems']);
            }, [new AuthMiddleware()]);

            // Library routes
            $r->get('/api/v1/libraries', [$this, 'getLibraries']);
            $r->get('/api/v1/libraries/{id}', [$this, 'getLibrary']);
            $r->get('/api/v1/libraries/{id}/items', [$this, 'getLibraryItems']);

            // Media routes
            $r->get('/api/v1/media', [$this, 'getMedia']);
            // Static segments registered BEFORE `{id}` so they can't be swallowed as an id.
            $r->get('/api/v1/media/letter-index', [$this, 'getLetterIndex']);
            $r->get('/api/v1/media/facets', [$this, 'getMediaFacets']);
            $r->get('/api/v1/media/index', [$this, 'getMediaIndex']);
            // P3B-S8: marker-based search — registered before {id} so path is not swallowed
            $r->get('/api/v1/media/search/by-marker', [$this, 'searchByMarker']);
            // Full-text + fuzzy media search — registered before {id} so path is not swallowed
            $r->get('/api/v1/media/search', [$this, 'searchMedia']);
            $r->get('/api/v1/media/{id}', [$this, 'getMediaItem']);
            $r->get('/api/v1/media/{id}/playback', [$this, 'getPlaybackInfo']);
            $r->get('/api/v1/media/{id}/chapters', [$this, 'getMediaChapters']);
            // P3B-S8: marker-type search for a specific media item
            $r->get('/api/v1/media/{id}/markers/search', [$this, 'searchMediaMarkers']);

            // P4-S3: TMDB box-set collection membership for a media item
            $r->get('/api/v1/media/{id}/collection', [$this, 'getMediaItemCollection']);

            // Transcode routes (HLS job start + status polling)
            $r->post('/api/v1/media/{id}/transcode', [$this, 'startTranscode']);
            $r->get('/api/v1/transcode/{jobId}/status', [$this, 'statusTranscode']);

            // User activity routes
            $r->get('/api/v1/users/me/continue-watching', [$this, 'getContinueWatching']);
            $r->get('/api/v1/users/me/recently-watched', [$this, 'getRecentlyWatched']);
            $r->get('/api/v1/users/me/favorites', [$this, 'listFavorites']);

            // P4-S2: because-you-watched recommendations
            $r->get('/api/v1/me/recommendations', [$this, 'getRecommendations']);
            $r->delete('/api/v1/me/recommendations/{mediaItemId}', [$this, 'dismissRecommendation']);

            // Watch history routes
            $r->delete('/api/v1/users/me/history/{mediaItemId}', [$this, 'removeFromHistory']);
            $r->delete('/api/v1/users/me/history', [$this, 'clearHistory']);

            // Per-user favorites + ratings (E10). Handlers live on
            // MediaUserDataController (referenced from here, the single place
            // both entry points dispatch /api/* to). When the controller is not
            // wired the routes respond 503, mirroring the history/settings
            // routes' "not configured" behaviour.
            $r->post('/api/v1/media/{id}/favorite', [$this, 'addFavorite']);
            $r->delete('/api/v1/media/{id}/favorite', [$this, 'removeFavorite']);
            $r->put('/api/v1/media/{id}/rating', [$this, 'setRating']);
            $r->delete('/api/v1/media/{id}/rating', [$this, 'clearRating']);
            $r->put('/api/v1/media/{id}/like', [$this, 'setLikeLevel']);
            $r->post('/api/v1/media/{id}/watched', [$this, 'markWatched']);
            $r->post('/api/v1/media/{id}/unwatched', [$this, 'markUnwatched']);

            // User ratings for media items (P1-S1). Delegates to MediaRatingsController.
            $r->post('/api/v1/media/{id}/ratings', [$this, 'createRating']);

            // Settings routes
            $r->get('/api/v1/users/me/settings', [$this, 'getUserSettings']);
            $r->put('/api/v1/users/me/settings', [$this, 'updateUserSettings']);

            // P7-S3: Playback preferences (gapless / crossfade)
            $r->get('/api/v1/me/playback/preferences', [$this, 'getPlaybackPreferences']);
            $r->put('/api/v1/me/playback/preferences', [$this, 'updatePlaybackPreferences']);

            // Avatar routes (Step 12.3)
            $r->post('/api/v1/users/me/avatar', [$this, 'uploadAvatar']);
            $r->delete('/api/v1/users/me/avatar', [$this, 'deleteAvatar']);

            // P4-S3: TMDB box-set collection with members
            $r->get('/api/v1/collections/{id}', [$this, 'getCollection']);

            // P7-S1: Music library API (Artist→Album→Track hierarchy)
            $r->get('/api/v1/music/artists', [$this, 'getMusicArtists']);
            $r->get('/api/v1/music/artists/{id}', [$this, 'getMusicArtist']);
            $r->get('/api/v1/music/albums/{id}', [$this, 'getMusicAlbum']);
            $r->get('/api/v1/music/tracks', [$this, 'getMusicTracks']);
            $r->get('/api/v1/music/tracks/{id}', [$this, 'getMusicTrack']);
            $r->post('/api/v1/music/scan', [$this, 'scanMusicDirectory']);
        }, [$auth]);

        // Admin-only: delete a media item (Step 11.6). Gate with AdminMiddleware
        // so that unauthenticated (401) and non-admin (403) are rejected before
        // the handler runs; 404 is produced by the handler when the item is missing.
        if ($this->userRepository !== null && $this->auditLogger !== null) {
            $adminMiddleware = new AdminMiddleware($this->userRepository, $this->auditLogger);
            $this->router->group(
                '',
                function (Router $r) use ($adminMiddleware): void {
                    $r->delete('/api/v1/media/{id}', [$this, 'deleteMediaItem']);

                    // Candidate poster listing (Step 15.1) and poster selection (Step 15.2).
                    $posterController = new MediaPosterController(
                        $this->itemRepository,
                        new TmdbProvider($this->tmdbApiKey()),
                    );
                    $posterController->setAdminMiddleware($adminMiddleware);
                    $r->get('/api/v1/media/{id}/posters', [$posterController, 'listPosters']);
                    $r->put('/api/v1/media/{id}/poster', [$posterController, 'setPoster']);
                },
                [$adminMiddleware]
            );
        }
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
        // SECURITY: page size is clamped to PageLimit::MAX server-side. An
        // unclamped `?limit=` here reaches `LIMIT ?` and can OOM the resident
        // Workerman worker that is serving every other user.
        $limit = $request->queryPageSize('limit', 50);
        $offset = $request->queryOffset();

        // Enforce the active profile's parental cap (null → no cap, permissive).
        $ratingFilter = $this->resolveRatingFilter($request);

        if ($type !== null && $type !== '') {
            $items = $this->itemRepository->getByType(
                $libraryId,
                $type,
                $limit,
                $offset,
                $ratingFilter['allowedRatings'] ?? null,
                $ratingFilter['allowUnrated'] ?? true
            );
        } elseif ($ratingFilter !== null) {
            // Capped profile browsing the whole library → route through the
            // existing rating-aware query (keeps the cap in SQL, not PHP).
            $items = $this->itemRepository->getByAllowedRatings(
                $libraryId,
                $ratingFilter['allowedRatings'],
                $limit,
                $offset,
                $ratingFilter['allowUnrated']
            );
        } else {
            $items = $this->itemRepository->getByLibrary($libraryId, $limit, $offset);
        }

        $shapedItems = array_map(
            fn(array $item): array => $this->shapeMediaItem($item),
            $items
        );

        return (new Response())->json([
            'items' => $shapedItems,
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

        // Parental cap on the detail read path. A capped profile that deep-links
        // an over-cap item is blocked with a 404 (not 403) so the response can't
        // confirm the item exists — and, crucially, so it never receives the
        // signed stream_url minted below. The gate uses the item's EFFECTIVE
        // rating (its own content_rating, else the inherited series rating), so a
        // legitimate drill-down of an allowed series keeps working while an
        // episode of a blocked series is denied; a genuinely-unrated item is
        // blocked only when the profile's allow_unrated is false (Finding 5).
        $ratingFilter = $this->resolveRatingFilter($request);
        $gate = $this->gate();
        if ($ratingFilter !== null && $gate !== null && !$gate->isAllowed($item, $ratingFilter)) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        // Determine whether the requesting user is an admin so the shaper can
        // include the admin-gated `files` block (full paths + file sizes).
        $isAdmin = $this->isAdminUser($request->userId ?? '');

        // Enrich the row into the same shape the list endpoint returns (poster
        // URLs, genres, overview, season/episode numbers, …) PLUS streams, so the
        // detail/player pages render a cover and metadata instead of a blank hero.
        $itemId = is_string($item['id'] ?? null) ? $item['id'] : '';
        $shaped = MediaItemShaper::shapeDetail(
            $item,
            $this->itemRepository->getItemStreams($itemId),
            $isAdmin
        );

        // Mint a signed direct-play URL only when the user has an active profile.
        // The player's `<video src>` can't attach a Bearer header, and
        // `/media/{id}/stream` is no longer world-readable, so this now-gated
        // detail endpoint hands the player a short-lived `?exp&sig` token that
        // the stream handler verifies. Users without a profile must not receive
        // a stream_url — they have no streaming rights.
        $userId = $request->userId ?? '';
        if ($itemId !== '' && $userId !== '') {
            $hasProfile = $this->profileManager !== null
                && $this->profileManager->getActiveProfile($userId) !== null;
            if ($hasProfile) {
                $shaped['stream_url'] = \Phlix\Auth\SignedUrl::fromEnv()->mint('/media/' . $itemId . '/stream');
            }
        }

        // Per-user favorite/rating block (E10). ADD-ONLY — never disturb existing
        // keys (e.g. the flat `actors` landmine). `null` when unauthenticated;
        // {favorite:false, rating:null} when authenticated but no row exists yet.
        $shaped['user_data'] = $this->resolveUserData($request, $itemId);

        return (new Response())->json(['item' => $shaped]);
    }

    /**
     * Determines whether the given user ID belongs to an admin user.
     *
     * @param string $userId The authenticated user's ID (empty string if unauthenticated).
     *
     * @return bool True when the user exists and has is_admin = 1; false otherwise.
     */
    private function isAdminUser(string $userId): bool
    {
        if ($userId === '' || $this->userRepository === null) {
            return false;
        }

        $user = $this->userRepository->findById($userId);

        return $user !== null && ($user['is_admin'] ?? 0) == 1;
    }

    /**
     * Resolve the per-user favorite/rating block for a media item.
     *
     * @param Request $request The HTTP request (carries the authenticated userId).
     * @param string  $itemId  The media item UUID (already extracted + validated).
     *
     * @return array{favorite: bool, rating: int|null, like_level: int, watched: bool}|null
     *         `null` when the request is unauthenticated or the favorites store
     *         is not wired; the user's data otherwise (defaulting to
     *         not-favorited/unrated/un-loved/un-watched when no row exists).
     *         `like_level` is the 0-3 multi-level Love axis (Feature 10) and
     *         `watched` is the seen/unseen flag (Step 11.6) — both ADD-ONLY
     *         alongside the existing `favorite`/`rating` keys.
     */
    private function resolveUserData(Request $request, string $itemId): ?array
    {
        $userId = $request->userId ?? '';
        if ($userId === '' || $itemId === '' || $this->userItemData === null) {
            return null;
        }

        return $this->userItemData->getItemData($userId, $itemId)
            ?? ['favorite' => false, 'rating' => null, 'like_level' => 0, 'watched' => false];
    }

    /**
     * Resolve the active profile ID for an authenticated user.
     *
     * Used to determine which profile's watch history to check when excluding
     * already-watched items from search results.
     *
     * @param Request $request The HTTP request (carries the authenticated userId)
     * @param string  $userId  The authenticated user's ID (already validated non-empty)
     *
     * @return string|null The profile ID string, or null when the profile manager
     *                     is unavailable or no active profile exists for the user.
     */
    private function resolveProfileId(Request $request, string $userId): ?string
    {
        if ($this->profileManager === null) {
            return null;
        }

        $profile = $this->profileManager->getActiveProfile($userId);
        if ($profile === null) {
            return null;
        }

        $profileId = is_string($profile['id'] ?? null) ? $profile['id'] : '';
        return $profileId !== '' ? $profileId : null;
    }

    /**
     * Resolve the parental content-rating filter for the CURRENT request's
     * active profile, for the browse/listing/detail read path.
     *
     * Returns `null` — meaning "apply NO filtering", the permissive default —
     * whenever the profile context is absent, unknown, or non-restrictive, so a
     * restricted view is never applied by accident:
     *
     *   - the profile manager is not wired;
     *   - the request is unauthenticated (no `userId`);
     *   - the requesting account is an admin (the owner/manager — their browse
     *     stays exactly as today, regardless of any per-profile cap);
     *   - {@see UserProfileManager::getActiveRatingFilter()} returns null (no
     *     active profile, an `is_admin` profile, no cap configured, or the
     *     most-permissive `UNRATED`/max cap).
     *
     * Otherwise returns `['allowedRatings' => string[], 'allowUnrated' => bool]`
     * — the concrete allow-list threaded into the listing SQL.
     *
     * @param Request $request The HTTP request (carries the authenticated userId).
     *
     * @return array{allowedRatings: list<string>, allowUnrated: bool}|null
     */
    private function resolveRatingFilter(Request $request): ?array
    {
        $gate = $this->gate();
        if ($gate === null) {
            return null;
        }

        return $gate->resolveFilterForUser($request->userId ?? '');
    }

    /**
     * Finding 2 — drill-down inheritance: whether the `?parentId=` subtree the
     * browse request is scoped to is BLOCKED for the active profile.
     *
     * The listing SQL ({@see ItemRepository::query()} / {@see ItemRepository::valueBuckets()})
     * caps on each row's OWN `content_rating` only, so a drill-down of an over-cap
     * series (`GET /api/v1/media?parentId=<blocked-series-id>`) would still surface
     * that series' NULL-rated episodes as cards whenever the cap permits unrated
     * content. Effective (inherited) rating closes this: if the PARENT itself is
     * over-cap, the WHOLE subtree beneath it is blocked, so the caller returns an
     * empty result (0 items / 0 total / 0 buckets) consistently.
     *
     * Strict no-op — returns false — for the owner/admin, an un-capped profile, an
     * unauthenticated request, a non-drill-down (no `parentId`) browse, or when the
     * gate is unwired.
     *
     * @param Request $request The HTTP request (carries userId + `parentId`).
     */
    private function parentSubtreeBlocked(Request $request): bool
    {
        $gate = $this->gate();
        if ($gate === null) {
            return false;
        }

        $filter = $gate->resolveFilterForUser($request->userId ?? '');
        if ($filter === null) {
            return false;
        }

        $parentId = $request->queryString('parentId');
        if ($parentId === null || $parentId === '') {
            return false;
        }

        // Effective-rating check of the parent (series/season): if the parent is
        // over-cap, the entire drill-down subtree is blocked.
        return !$gate->isAllowed($parentId, $filter);
    }

    /**
     * Lazily build (and memoize) the shared {@see RatingGate} from this router's
     * already-injected dependencies.
     *
     * Returns null only when the profile manager is unwired (test/legacy
     * contexts) — in which case every caller treats a null gate/filter as a
     * strict no-op, so the owner and un-profiled requests are never gated.
     */
    private function gate(): ?RatingGate
    {
        if ($this->profileManager === null) {
            return null;
        }

        return $this->ratingGate ??= new RatingGate(
            $this->itemRepository,
            $this->profileManager,
            $this->userRepository,
        );
    }

    /**
     * Merge the active profile's parental content-rating cap into a media-query
     * `$params` array so the shared listing SQL ({@see ItemRepository::query()},
     * {@see ItemRepository::valueBuckets()}, {@see ItemRepository::letterCounts()})
     * enforces it uniformly across items, counts, pagination and the A-Z rail.
     *
     * A no-op (returns `$params` unchanged) whenever {@see resolveRatingFilter()}
     * decides no filtering applies — the permissive default.
     *
     * @param Request              $request The HTTP request.
     * @param array<string, mixed> $params  Media-query params to augment.
     *
     * @return array<string, mixed> The params, plus `allowedRatings`/`allowUnrated`
     *                              when a cap is active.
     */
    private function applyRatingFilter(Request $request, array $params): array
    {
        $filter = $this->resolveRatingFilter($request);
        if ($filter !== null) {
            $params['allowedRatings'] = $filter['allowedRatings'];
            $params['allowUnrated'] = $filter['allowUnrated'];
        }

        return $params;
    }

    /**
     * Queries media items with flexible filtering, sorting, and pagination.
     *
     * Accepts the full set of library-query schema parameters and returns
     * a paginated list of media items shaped according to media-item.schema.json.
     *
     * @param Request $request The HTTP request with query parameters:
     *   - search (string): Full-text or fuzzy name search
     *   - genres (string[]): Filter to items with any of these genres
     *   - yearFrom (int): Minimum release year (inclusive)
     *   - yearTo (int): Maximum release year (inclusive)
     *   - ratings (string[]): Filter to items with any of these ratings
     *   - actors (string[]): Filter to items featuring any of these actors
     *   - sort (string): Sort field — name|year|rating|date_added|runtime
     *   - order (string): Sort direction — asc|desc
     *   - limit (int): Max items to return 1-100 (default: 50)
     *   - offset (int): Items to skip for pagination (default: 0)
     *   - libraryId (string): Scope results (and total) to a single library
     *   - parentId (string): Scope to the direct children (seasons/episodes) of
     *     one item — drives the series detail drill-down
     *   - topLevel (1|true): Return only parent-less items (movies + series),
     *     excluding seasons/episodes — drives Browse rails/library grids; ignored
     *     when `search` is set
     * @param array<string, string> $params Route parameters (unused)
     *
     * @return Response JSON response with items array and pagination info
     *
     * @api_endpoint GET /api/v1/media
     *
     * @example Response structure:
     * ```json
     * {
     *   "items": [
     *     {
     *       "id": "item_xyz789",
     *       "name": "Movie Title",
     *       "type": "movie",
     *       "poster_url": "http://example.com/poster.jpg",
     *       "genres": ["Action", "Drama"],
     *       "year": 2020,
     *       "rating": "PG-13",
     *       "runtime": 7200,
     *       "overview": "A great movie...",
     *       "actors": ["Actor One", "Actor Two"],
     *       "director": "Director Name",
     *       "parent_id": null,
     *       "season_number": null,
     *       "episode_number": null,
     *       "episode_title": null
     *     }
     *   ],
     *   "total": 100,
     *   "limit": 50,
     *   "offset": 0
     * }
     * ```
     */
    public function getMedia(Request $request, array $params): Response
    {
        $queryParams = $this->extractMediaQueryParams($request);

        // Optional per-library scoping: `?libraryId=<uuid>` confines the result
        // (and its total) to one library so the Browse surface can render a
        // section/rail per library. Absent/blank → an all-libraries query
        // (backward-compatible with the original global Browse grid).
        $libraryIdRaw = $request->queryString('libraryId');
        $libraryId = ($libraryIdRaw !== null && $libraryIdRaw !== '') ? $libraryIdRaw : null;

        // Finding 2 — drill-down inheritance: an over-cap parent blocks its whole
        // subtree, so a capped profile drilling into a blocked series gets an empty
        // page (0 items / 0 total) rather than that series' NULL-rated episodes.
        if ($this->parentSubtreeBlocked($request)) {
            $limit = isset($queryParams['limit']) && is_numeric($queryParams['limit'])
                ? (int) $queryParams['limit'] : 50;
            $offset = isset($queryParams['offset']) && is_numeric($queryParams['offset'])
                ? (int) $queryParams['offset'] : 0;
            return (new Response())->json([
                'items' => [],
                'total' => 0,
                'limit' => $limit,
                'offset' => $offset,
            ]);
        }

        $result = $this->itemRepository->query($queryParams, $libraryId);

        // Finding 2 backstop: apply the effective-rating gate to the returned rows
        // so any row whose EFFECTIVE (inherited) rating is over-cap is dropped even
        // if the own-column SQL cap admitted it. Idempotent (drops nothing) for the
        // top-level browse and for an allowed drill-down, so counts stay consistent;
        // a strict no-op for the owner / un-capped profile.
        $rows = $result['items'];
        $gate = $this->gate();
        if ($gate !== null) {
            $filter = $gate->resolveFilterForUser($request->userId ?? '');
            if ($filter !== null) {
                $rows = $gate->filterItems($rows, $filter, 'id');
            }
        }

        $items = array_map(function (array $item): array {
            return $this->shapeMediaItem($item);
        }, $rows);

        return (new Response())->json([
            'items' => $items,
            'total' => $result['total'],
            'limit' => $result['limit'],
            'offset' => $result['offset'],
        ]);
    }

    /**
     * A-Z jump index for the media list: for the SAME filters as
     * `GET /api/v1/media`, the absolute item offset of the first title in each
     * first-letter bucket (assuming the default name-ascending sort, which the
     * UI gates the rail on). Non-alphabetic first characters fold into `#`,
     * placed first to match name-ascending collation. The UI scrolls the
     * pre-sized grid to `offset` and disables empty buckets.
     *
     * `GET /api/v1/media/letter-index?<same filters as /media>`
     *
     * @param Request              $request The HTTP request.
     * @param array<string,string> $params  Path params (unused).
     *
     * @return Response `{ "letters": [{letter, offset, count}], "total": int }`.
     */
    public function getLetterIndex(Request $request, array $params): Response
    {
        $queryParams = $this->extractMediaQueryParams($request);
        $libraryIdRaw = $request->queryString('libraryId');
        $libraryId = ($libraryIdRaw !== null && $libraryIdRaw !== '') ? $libraryIdRaw : null;

        // Finding 2 — drill-down inheritance: an over-cap parent blocks its whole
        // subtree, so the A-Z rail for a blocked drill-down is entirely empty
        // (every bucket count 0), consistent with getMedia returning no items.
        if ($this->parentSubtreeBlocked($request)) {
            $letters = [];
            foreach (array_merge(['#'], range('A', 'Z')) as $bucket) {
                $letters[] = ['letter' => $bucket, 'offset' => 0, 'count' => 0];
            }
            return (new Response())->json(['letters' => $letters, 'total' => 0]);
        }

        // Use valueBuckets (same internal query as getMediaIndex) to get per-letter counts.
        // valueBuckets groups by first-letter expression (article-stripped), matching
        // the letter-grid's expected sort order.
        $rawBuckets = $this->itemRepository->valueBuckets('name', $queryParams, $libraryId);

        // Fold per-first-character counts into A–Z + a single `#` (non-alpha).
        $byBucket = [];
        foreach ($rawBuckets as $item) {
            $bucket = preg_match('/^[A-Z]$/', (string) $item['value']) === 1 ? (string) $item['value'] : '#';
            $byBucket[$bucket] = ($byBucket[$bucket] ?? 0) + $item['count'];
        }

        // Cumulative offsets in name-ascending order: `#` first, then A–Z. Every
        // bucket is returned (empty ones carry count 0) so the rail can render
        // the full alphabet and disable the letters with nothing behind them.
        $letters = [];
        $offset = 0;
        foreach (array_merge(['#'], range('A', 'Z')) as $bucket) {
            $count = $byBucket[$bucket] ?? 0;
            $letters[] = ['letter' => $bucket, 'offset' => $offset, 'count' => $count];
            $offset += $count;
        }

        return (new Response())->json(['letters' => $letters, 'total' => $offset]);
    }

    /**
     * Authoritative filter-facet list for the media surface.
     *
     * The SPA derives its genre filter from whatever items happen to be loaded,
     * which is incomplete under sparse/random-access paging. This returns the
     * server's full, DISTINCT, sorted genre set so the client can render a
     * complete filter list (falling back to its derived set when this endpoint
     * is absent). Scoped to one library with `?libraryId=<uuid>`; absent/blank
     * → facets span every library the request is allowed to see (the route is
     * auth-gated, exactly like `GET /api/v1/media`).
     *
     * The response is an object so it can grow more facet keys later; this step
     * populates `genres` only.
     *
     * `GET /api/v1/media/facets?libraryId=<id>` → `{ "genres": string[] }`.
     *
     * @param Request              $request The HTTP request.
     * @param array<string,string> $params  Path params (unused).
     *
     * @return Response `{ "genres": [...] }` — sorted, de-duplicated, non-empty.
     */
    public function getMediaFacets(Request $request, array $params): Response
    {
        $libraryIdRaw = $request->queryString('libraryId');
        $libraryId = ($libraryIdRaw !== null && $libraryIdRaw !== '') ? $libraryIdRaw : null;

        $genres = $this->itemRepository->distinctGenres($libraryId);

        return (new Response())->json(['genres' => $genres]);
    }

    /**
     * Dynamic index bucket endpoint: returns cumulative-offset buckets for any
     * indexable media field (name, year, rating, runtime, date_added), scoped to
     * the same filters as `GET /api/v1/media`.
     *
     * `GET /api/v1/media/index?field=name&order=asc&libraryId=<uuid>`
     *
     * @param Request              $request The HTTP request.
     * @param array<string,string> $params  Path params (unused).
     *
     * @return Response `{ "field": string, "buckets": [{key, label, offset, count}], "total": int }`.
     */
    public function getMediaIndex(Request $request, array $params): Response
    {
        $queryParams = $this->extractMediaQueryParams($request);
        $libraryIdRaw = $request->queryString('libraryId');
        $libraryId = ($libraryIdRaw !== null && $libraryIdRaw !== '') ? $libraryIdRaw : null;

        $field = $request->queryString('field') ?? 'name';
        // Resolve unknown field to the default (same logic as IndexBuckets::build).
        $validIndexFields = [
            IndexBuckets::FIELD_NAME,
            IndexBuckets::FIELD_YEAR,
            IndexBuckets::FIELD_RATING,
            IndexBuckets::FIELD_RUNTIME,
            IndexBuckets::FIELD_DATE_ADDED,
            IndexBuckets::FIELD_GENRE,
            IndexBuckets::FIELD_ARTIST,
        ];
        if (!in_array($field, $validIndexFields, true)) {
            $field = IndexBuckets::FIELD_NAME;
        }
        $order = strtolower($request->queryString('order') ?? 'asc');

        // Finding 2 — drill-down inheritance: an over-cap parent blocks its whole
        // subtree, so the index for a blocked drill-down carries no buckets / 0
        // total, consistent with getMedia returning no items.
        if ($this->parentSubtreeBlocked($request)) {
            return (new Response())->json([
                'field' => $field,
                'buckets' => [],
                'total' => 0,
            ]);
        }

        $rawBuckets = $this->itemRepository->valueBuckets($field, $queryParams, $libraryId);

        $indexBuckets = new IndexBuckets();
        $buckets = $indexBuckets->build($field, $rawBuckets, $order);
        $buckets = $indexBuckets->withOffsets($buckets);

        $total = array_sum(array_column($buckets, 'count'));

        return (new Response())->json([
            'field' => $field,
            'buckets' => $buckets,
            'total' => $total,
        ]);
    }

    /**
     * Extracts and normalizes media query parameters from the request.
     *
     * @param Request $request The HTTP request
     * @return array<string, mixed> Normalized query parameters
     */
    private function extractMediaQueryParams(Request $request): array
    {
        $params = [];
        $query = $request->query;

        $search = $request->queryString('search');
        if ($search !== null && $search !== '') {
            $params['search'] = $search;
        }

        $genres = $query['genres'] ?? null;
        if (is_array($genres) && count($genres) > 0) {
            $params['genres'] = array_filter($genres, 'is_string');
        }

        $yearFrom = $request->queryString('yearFrom');
        if ($yearFrom !== null && is_numeric($yearFrom)) {
            $params['yearFrom'] = (int) $yearFrom;
        }

        $yearTo = $request->queryString('yearTo');
        if ($yearTo !== null && is_numeric($yearTo)) {
            $params['yearTo'] = (int) $yearTo;
        }

        $ratings = $query['ratings'] ?? null;
        if (is_array($ratings) && count($ratings) > 0) {
            $params['ratings'] = array_filter($ratings, 'is_string');
        }

        $actors = $query['actors'] ?? null;
        if (is_array($actors) && count($actors) > 0) {
            $params['actors'] = array_filter($actors, 'is_string');
        }

        // `?companies[]=…` filters on production company / studio name (matches
        // the rich `metadata.production_companies[*].name` array OR the legacy
        // single `metadata.studio` string — see ItemRepository::buildFilters).
        $companies = $query['companies'] ?? null;
        if (is_array($companies) && count($companies) > 0) {
            $params['companies'] = array_filter($companies, 'is_string');
        }

        // Match status: `?match=matched|unmatched` filters on whether the item
        // has ever been through metadata matching (metadata_refreshed_at).
        $match = $request->queryString('match');
        if ($match === 'matched' || $match === 'unmatched') {
            $params['match'] = $match;
        }

        $sort = $request->queryString('sort');
        if ($sort !== null && $sort !== '') {
            $params['sort'] = $sort;
        }

        $order = $request->queryString('order');
        if ($order !== null && $order !== '') {
            $params['order'] = $order;
        }

        $limit = $request->queryString('limit');
        if ($limit !== null && is_numeric($limit)) {
            $params['limit'] = (int) $limit;
        }

        $offset = $request->queryString('offset');
        if ($offset !== null && is_numeric($offset)) {
            $params['offset'] = (int) $offset;
        }

        // Hierarchy scoping. `parentId` fetches the direct children (seasons/
        // episodes) of one item for the series detail drill-down; `topLevel`
        // restricts a Browse rail / library grid to parent-less items (movies +
        // series) so a series library shows shows, not every episode. Both are
        // forwarded into ItemRepository::query() via $params.
        $parentId = $request->queryString('parentId');
        if ($parentId !== null && $parentId !== '') {
            $params['parentId'] = $parentId;
        }

        $topLevel = $request->queryString('topLevel');
        if ($topLevel === '1' || $topLevel === 'true') {
            $params['topLevel'] = true;
        }

        // min_rating filter (e.g. ?minRating=7.5) — filters by numeric average
        // score from metadata_ratings (TMDB/IMDb/user), not the MPAA content_rating.
        $minRating = $request->queryString('minRating');
        if ($minRating !== null && is_numeric($minRating)) {
            $params['minRating'] = (float) $minRating;
        }

        // Parental content-rating cap of the active profile (if any). Injected
        // here — the one place all three browse surfaces (getMedia, letter-index,
        // media-index) build their params — so the cap enforces itself on the
        // items, the COUNT(*) total, pagination and the A-Z rail alike. A no-op
        // for the account owner, unauthenticated requests, and profiles with no
        // (or a most-permissive) cap.
        $params = $this->applyRatingFilter($request, $params);

        return $params;
    }

    /**
     * Shapes a raw media item DB row into the media-item.schema.json format.
     *
     * @param array<string, mixed> $item Raw hydrated media item
     * @return array<string, mixed> Media-item shaped response
     */
    private function shapeMediaItem(array $item): array
    {
        return MediaItemShaper::shape($item);
    }

    /**
     * Retrieves playback information for a media item.
     *
     * Returns playback information including available media sources
     * and direct play capabilities. This is used by the player
     * to initialize playback.
     *
     * @param Request $request The HTTP request; the parental filter is resolved
     *        from it and, for SV-3.3, the optional `X-Phlix-Client-Capabilities`
     *        header gates the `direct_play` verdict.
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

        // Parental cap: an over-cap item (by effective rating) is 404 here too, so
        // a capped profile never gets its media source path / tracks. No-op for
        // the owner / un-capped profile.
        $filter = $this->resolveRatingFilter($request);
        $gate = $this->gate();
        if ($filter !== null && $gate !== null && !$gate->isAllowed($item, $filter)) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        // Get marker data for skip buttons
        $skipSpec = $this->playbackMarkerService->getFullSpec($params['id']);

        // P3B: audio + subtitle track metadata from media_streams for the
        // player's selection menus. Shaped by the shared StreamTrackShaper so
        // this portal path and MediaItemController::getPlaybackInfo() (the
        // other dispatch path) emit byte-identical shapes. The lazy backfill
        // runs a ONE-SHOT ffprobe for pre-071 items (≤1 audio row, no subtitle
        // rows) so existing libraries expose their full track set without a
        // rescan.
        $itemId = is_string($item['id'] ?? null) ? $item['id'] : '';
        $streams = $this->streamBackfill()->ensureFor(
            $item,
            $this->itemRepository->getItemStreams($itemId)
        );
        $audioTracks = StreamTrackShaper::audioTracks($streams);
        $subtitleTracks = StreamTrackShaper::subtitleTracks($streams, $itemId);

        // SV-3.3(2A): capability-gated direct-play. When the client declares its
        // decoder capabilities via X-Phlix-Client-Capabilities and cannot decode
        // the source's FIRST audio codec (e.g. E-AC-3), report direct_play=false
        // so the player transcodes instead of receiving silent audio. An
        // absent/empty header preserves the historical always-true verdict
        // (backward compat). This gates on the SAME audio stream the transcode
        // path selects — the FIRST audio stream (TranscodeManager::computeHlsParams()
        // → firstStreamOfType($probe,'audio') → ClientCapabilities::supportsCodec())
        // so this verdict and the actual transcode decision agree on what
        // "playable" means. (SV-3.3 fix: previously keyed on the default-disposition
        // track, which could differ from computeHlsParams' first-stream choice.)
        $clientCapabilities = ClientCapabilities::fromJson(
            $request->getHeader('X-Phlix-Client-Capabilities')
        );
        $directPlay = true;
        if ($clientCapabilities->hasExplicitCapabilities()) {
            $directPlay = $clientCapabilities->supportsCodec(
                self::firstAudioCodec($audioTracks)
            );
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
                    'direct_play' => $directPlay,
                ],
            ],
            'markers' => $skipSpec->toArray(),
            'audio_tracks' => $audioTracks,
            'subtitle_tracks' => $subtitleTracks,
        ];

        return (new Response())->json(['playback_info' => $playbackInfo]);
    }

    /**
     * Returns the codec of the item's FIRST audio track, or '' when the item has
     * no audio streams.
     *
     * This is the codec the SV-3.3 direct-play verdict is gated on. It selects the
     * SAME audio stream the transcode path derives its audio codec from — the
     * FIRST audio stream in ffprobe order
     * ({@see \Phlix\Media\Transcoding\TranscodeManager::computeHlsParams()} →
     * `firstStreamOfType($probe,'audio')` → {@see ClientCapabilities::supportsCodec()}).
     * {@see StreamTrackShaper::audioTracks()} sorts tracks by global `stream_index`
     * ascending, so `$audioTracks[0]` is exactly that first ffprobe audio stream —
     * making this verdict and the actual transcode decision genuinely agree (they
     * previously diverged whenever a non-first track carried the default
     * disposition).
     *
     * @param list<array<string, mixed>> $audioTracks Shaped audio tracks from
     *        {@see StreamTrackShaper::audioTracks()} (each has `codec`; ordered by
     *        `stream_index` ascending, matching ffprobe stream order).
     */
    private static function firstAudioCodec(array $audioTracks): string
    {
        $first = $audioTracks[0]['codec'] ?? null;

        return is_string($first) ? (string) $first : '';
    }

    /**
     * Returns the lazy stream backfill, building it on first use when none was
     * injected (mirrors MediaItemController::streamBackfill() so both dispatch
     * paths behave identically).
     */
    private function streamBackfill(): StreamProbeBackfill
    {
        return $this->streamBackfill ??= new StreamProbeBackfill($this->itemRepository);
    }

    /**
     * Retrieves chapter markers for a media item.
     *
     * @api_endpoint GET /api/v1/media/{id}/chapters
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters (id => media item ID)
     *
     * @return Response JSON response with chapter list:
     *   {chapters: [{start_seconds: int, end_seconds: int, title: string|null}, ...]}
     *
     * @since 0.13.0
     */
    public function getMediaChapters(Request $request, array $params): Response
    {
        $item = $this->itemRepository->findById($params['id']);

        if (!$item) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        // Parental cap: an over-cap item (by effective rating) is 404 here too.
        // No-op for the owner / un-capped profile.
        $filter = $this->resolveRatingFilter($request);
        $gate = $this->gate();
        if ($filter !== null && $gate !== null && !$gate->isAllowed($item, $filter)) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        $markerSet = $this->markerService->getMarkers($params['id']);

        $chapters = array_map(
            static fn (\Phlix\Media\Markers\ChapterMarker $chapter): array => [
                'start_seconds' => $chapter->start_seconds,
                'end_seconds' => $chapter->end_seconds,
                'title' => $chapter->title,
            ],
            $markerSet->chapters
        );

        return (new Response())->json(['chapters' => $chapters]);
    }

    /**
     * Search for media items with markers near a specific playhead position (P3B-S8).
     *
     * Finds media items that have a marker of the specified type within $around
     * seconds of the given position. Used for "similar content" recommendations.
     * Excludes items the user has already watched.
     *
     * @param Request $request The HTTP request with query params:
     *   - type: Marker type (intro|outro|credits|ad)
     *   - around: Search window in seconds (default: 30)
     *   - position: Current playhead position in milliseconds (default: 0)
     *   - limit: Maximum results (default: 20)
     * @param array<string, string> $params Route parameters (unused)
     *
     * @return Response JSON response with items array:
     *   {items: [...], marker_type: string, around: int, position: int}
     *
     * @api_endpoint GET /api/v1/media/search/by-marker
     *
     * @requires Authentication
     *
     * @since 0.14.0
     */
    public function searchByMarker(Request $request, array $params): Response
    {
        if ($this->chapterSearchService === null) {
            return (new Response())->status(503)->json(['error' => 'Chapter search not available']);
        }

        $userId = $request->userId ?? '';
        if ($userId === '') {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        $typeStr = $request->queryString('type');
        if ($typeStr === null || $typeStr === '') {
            return (new Response())->status(400)->json(['error' => 'type query parameter is required']);
        }

        $type = MarkerType::tryFrom($typeStr);
        if ($type === null) {
            return (new Response())->status(400)->json(['error' => 'Invalid marker type']);
        }

        $position = $request->queryInt('position', 0);
        $around = $request->queryInt('around', 30);
        $limit = $request->queryInt('limit', 20);

        if ($around < 1 || $around > 300) {
            return (new Response())->status(400)->json(['error' => 'around must be between 1 and 300']);
        }

        if ($limit < 1 || $limit > 100) {
            return (new Response())->status(400)->json(['error' => 'limit must be between 1 and 100']);
        }

        // Resolve profile ID from user for watch-history exclusion
        $profileId = $this->resolveProfileId($request, $userId);
        if ($profileId === null) {
            return (new Response())->status(503)->json(['error' => 'Profile not available']);
        }

        $items = $this->chapterSearchService->searchByMarkerProximity(
            $type,
            $position,
            $around,
            $profileId,
            $limit
        );

        // Parental cap: drop over-cap results (by effective rating) for a capped
        // active profile. No-op for the owner / un-capped profile.
        $filter = $this->resolveRatingFilter($request);
        $gate = $this->gate();
        if ($filter !== null && $gate !== null) {
            $items = $gate->filterItems($items, $filter, 'id');
        }

        return (new Response())->json([
            'items' => $items,
            'marker_type' => $type->value,
            'around' => $around,
            'position' => $position,
        ]);
    }

    /**
     * Search media items by name (D-SRV-1).
     *
     * Performs full-text search with fuzzy fallback, applies parental rating
     * filters, and returns shaped media items.
     *
     * @param Request $request The HTTP request with query params:
     *   - q: Search query string (required)
     *   - limit: Maximum results (default 50, max 100)
     * @param array<string, string> $params Route parameters (unused)
     *
     * @return Response JSON response with items array:
     *   {items: [...], query: string, total: int}
     *
     * @api_endpoint GET /api/v1/media/search
     *
     * @requires Authentication
     *
     * @since 0.15.0
     */
    public function searchMedia(Request $request, array $params): Response
    {
        $query = $request->queryString('q') ?? '';

        if ($query === '') {
            return (new Response())->status(400)->json(['error' => 'Query parameter "q" is required']);
        }

        // SECURITY: single shared clamp policy (PageLimit) rather than a
        // hand-rolled bound that can drift from the rest of the API.
        $limit = $request->queryPageSize('limit', 50);

        // Run the search via ItemRepository
        $items = $this->itemRepository->search($query, $limit);

        // Parental cap: drop over-cap results (by effective rating) for a capped
        // active profile. No-op for the owner / un-capped profile.
        $filter = $this->resolveRatingFilter($request);
        $gate = $this->gate();
        if ($filter !== null && $gate !== null) {
            $items = $gate->filterItems($items, $filter, 'id');
        }

        // Shape items like getMedia does
        $shapedItems = array_map(function (array $item): array {
            return $this->shapeMediaItem($item);
        }, $items);

        return (new Response())->json([
            'items' => $shapedItems,
            'query' => $query,
            'total' => count($shapedItems),
        ]);
    }

    /**
     * Get markers of a specific type for a media item (P3B-S8).
     *
     * Returns all markers of the specified type for a media item,
     * useful for finding all ad markers, all intro markers, etc.
     *
     * @param Request $request The HTTP request with query params:
     *   - type: Marker type (intro|outro|credits|ad)
     * @param array<string, string> $params Route parameters (id => media item ID)
     *
     * @return Response JSON response with markers array:
     *   {markers: [{id, type, startMs, endMs, label}, ...]}
     *
     * @api_endpoint GET /api/v1/media/{id}/markers/search
     *
     * @requires Authentication
     *
     * @since 0.14.0
     */
    public function searchMediaMarkers(Request $request, array $params): Response
    {
        if ($this->chapterSearchService === null) {
            return (new Response())->status(503)->json(['error' => 'Chapter search not available']);
        }

        $item = $this->itemRepository->findById($params['id']);
        if (!$item) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        // Parental cap: an over-cap item (by effective rating) is 404 here too, so
        // its markers are not disclosed. No-op for the owner / un-capped profile.
        $filter = $this->resolveRatingFilter($request);
        $gate = $this->gate();
        if ($filter !== null && $gate !== null && !$gate->isAllowed($item, $filter)) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        $typeStr = $request->queryString('type');
        if ($typeStr === null || $typeStr === '') {
            return (new Response())->status(400)->json(['error' => 'type query parameter is required']);
        }

        $type = MarkerType::tryFrom($typeStr);
        if ($type === null) {
            return (new Response())->status(400)->json(['error' => 'Invalid marker type']);
        }

        $markers = $this->chapterSearchService->getMarkersByType($params['id'], $type);

        return (new Response())->json([
            'markers' => array_map(fn($m) => $m->toArray(), $markers),
        ]);
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

        // Continue-watching is account-level, but the ACTIVE profile's cap still
        // governs what it can see: drop over-cap titles (by effective rating).
        // The media id lives under `media_item_id` here. No-op for the owner.
        $filter = $this->resolveRatingFilter($request);
        $gate = $this->gate();
        if ($filter !== null && $gate !== null) {
            $items = $gate->filterItems($items, $filter, 'media_item_id');
        }

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

        // Account-level history, still capped for the active profile (by
        // effective rating). Media id is under `media_item_id`. Owner → no-op.
        $filter = $this->resolveRatingFilter($request);
        $gate = $this->gate();
        if ($filter !== null && $gate !== null) {
            $items = $gate->filterItems($items, $filter, 'media_item_id');
        }

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
     * Mark a media item as a favorite for the authenticated user (E10).
     *
     * Thin delegate to {@see MediaUserDataController::addFavorite()}; responds
     * 503 when the favorites feature is not wired (mirrors history/settings).
     *
     * @param array<string, string> $params Route params including 'id'.
     *
     * @api_endpoint POST /api/v1/media/{id}/favorite
     */
    public function addFavorite(Request $request, array $params): Response
    {
        if ($this->mediaUserDataController === null) {
            return (new Response())->status(503)->json([
                'error' => 'Favorites are not configured on this server',
            ]);
        }
        return $this->mediaUserDataController->addFavorite($request, $params);
    }

    /**
     * Remove a media item from the authenticated user's favorites (E10).
     *
     * @param array<string, string> $params Route params including 'id'.
     *
     * @api_endpoint DELETE /api/v1/media/{id}/favorite
     */
    public function removeFavorite(Request $request, array $params): Response
    {
        if ($this->mediaUserDataController === null) {
            return (new Response())->status(503)->json([
                'error' => 'Favorites are not configured on this server',
            ]);
        }
        return $this->mediaUserDataController->removeFavorite($request, $params);
    }

    /**
     * Set the authenticated user's personal rating for a media item (E10).
     *
     * @param array<string, string> $params Route params including 'id'.
     *
     * @api_endpoint PUT /api/v1/media/{id}/rating
     */
    public function setRating(Request $request, array $params): Response
    {
        if ($this->mediaUserDataController === null) {
            return (new Response())->status(503)->json([
                'error' => 'Favorites are not configured on this server',
            ]);
        }
        return $this->mediaUserDataController->setRating($request, $params);
    }

    /**
     * Set the authenticated user's "love" level for a media item (Feature 10).
     *
     * Thin delegate to {@see MediaUserDataController::setLikeLevel()}; responds
     * 503 when the favorites feature is not wired (mirrors the rating routes).
     *
     * @param array<string, string> $params Route params including 'id'.
     *
     * @api_endpoint PUT /api/v1/media/{id}/like
     */
    public function setLikeLevel(Request $request, array $params): Response
    {
        if ($this->mediaUserDataController === null) {
            return (new Response())->status(503)->json([
                'error' => 'Favorites are not configured on this server',
            ]);
        }
        return $this->mediaUserDataController->setLikeLevel($request, $params);
    }

    /**
     * Clear the authenticated user's personal rating for a media item (E10).
     *
     * @param array<string, string> $params Route params including 'id'.
     *
     * @api_endpoint DELETE /api/v1/media/{id}/rating
     */
    public function clearRating(Request $request, array $params): Response
    {
        if ($this->mediaUserDataController === null) {
            return (new Response())->status(503)->json([
                'error' => 'Favorites are not configured on this server',
            ]);
        }
        return $this->mediaUserDataController->clearRating($request, $params);
    }

    /**
     * Mark a media item as watched for the authenticated user (Step 11.6).
     *
     * Thin delegate to {@see MediaUserDataController::markWatched()}.
     *
     * @param array<string, string> $params Route params including 'id'.
     *
     * @api_endpoint POST /api/v1/media/{id}/watched
     */
    public function markWatched(Request $request, array $params): Response
    {
        if ($this->mediaUserDataController === null) {
            return (new Response())->status(503)->json([
                'error' => 'Favorites are not configured on this server',
            ]);
        }
        return $this->mediaUserDataController->markWatched($request, $params);
    }

    /**
     * Clear the "watched" flag for the authenticated user (Step 11.6).
     *
     * Thin delegate to {@see MediaUserDataController::markUnwatched()}.
     *
     * @param array<string, string> $params Route params including 'id'.
     *
     * @api_endpoint POST /api/v1/media/{id}/unwatched
     */
    public function markUnwatched(Request $request, array $params): Response
    {
        if ($this->mediaUserDataController === null) {
            return (new Response())->status(503)->json([
                'error' => 'Favorites are not configured on this server',
            ]);
        }
        return $this->mediaUserDataController->markUnwatched($request, $params);
    }

    /**
     * Delete a media item (admin only, Step 11.6).
     *
     * Uses the same logic as MediaItemController::delete() since that
     * controller is not stored in WebPortalRouter.
     *
     * @param array<string, string> $params Route params including 'id'.
     *
     * @api_endpoint DELETE /api/v1/media/{id}
     */
    public function deleteMediaItem(Request $request, array $params): Response
    {
        $item = $this->itemRepository->findById($params['id']);

        if (!$item) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        $this->itemRepository->delete($params['id']);

        return (new Response())->json(['message' => 'Item deleted successfully']);
    }

    /**
     * List the authenticated user's favorited media items (E10).
     *
     * Thin delegate to {@see MediaUserDataController::listFavorites()}; responds
     * 503 when the favorites feature is not wired (mirrors history/settings).
     *
     * @param array<string, string> $params Route params (unused).
     *
     * @api_endpoint GET /api/v1/users/me/favorites
     */
    public function listFavorites(Request $request, array $params): Response
    {
        if ($this->mediaUserDataController === null) {
            return (new Response())->status(503)->json([
                'error' => 'Favorites are not configured on this server',
            ]);
        }
        return $this->mediaUserDataController->listFavorites($request, $params);
    }

    /**
     * Return all ratings for a media item (P1-S1).
     *
     * Thin delegate to {@see MediaRatingsController::getRatings()}; responds
     * 503 when the ratings service is not wired.
     *
     * @param array<string, string> $params Route params including 'id'.
     *
     * @api_endpoint GET /api/v1/media/{id}/ratings
     */
    public function getRatings(array $params): Response
    {
        if ($this->mediaRatingsController === null) {
            return (new Response())->status(503)->json([
                'error' => 'Ratings are not configured on this server',
            ]);
        }
        $ratings = $this->mediaRatingsController->getRatings($params);

        return (new Response())->json(['ratings' => $ratings]);
    }

    /**
     * Create or update the authenticated user's rating for a media item (P1-S1).
     *
     * Thin delegate to {@see MediaRatingsController::createRating()}; responds
     * 503 when the ratings service is not wired.
     *
     * @param Request $request The HTTP request (userId set from auth).
     * @param array<string, string> $params Route params including 'id'.
     *
     * @api_endpoint POST /api/v1/media/{id}/ratings
     */
    public function createRating(Request $request, array $params): Response
    {
        if ($this->mediaRatingsController === null) {
            return (new Response())->status(503)->json([
                'error' => 'Ratings are not configured on this server',
            ]);
        }
        return $this->mediaRatingsController->createRating($request, $params);
    }

    /**
     * Retrieves similar items for a media item (P4-S1).
     *
     * Returns the top-K most similar items based on pre-computed similarity
     * scores using genre overlap, actor overlap, director overlap, rating
     * proximity, and year proximity.
     *
     * @param Request $request The HTTP request (unused).
     * @param array<string, string> $params Route params including 'id'.
     *
     * @api_endpoint GET /api/v1/media/{id}/similar
     *
     * @example Response structure:
     * ```json
     * {
     *   "items": [
     *     {
     *       "id": "abc-123",
     *       "title": "Similar Movie Title",
     *       "posterUrl": "https://example.com/poster.jpg",
     *       "score": 0.875,
     *       "reason": "genre"
     *     }
     *   ]
     * }
     * ```
     */
    public function getSimilarItems(Request $request, array $params): Response
    {
        if ($this->similarityService === null) {
            return (new Response())->status(503)->json([
                'error' => 'Similar items are not configured on this server',
            ]);
        }

        $itemId = $params['id'] ?? '';
        if ($itemId === '') {
            return (new Response())->status(400)->json(['error' => 'Item ID is required']);
        }

        // Check that the item exists.
        $item = $this->itemRepository->findById($itemId);
        if ($item === null) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        // SECURITY: clamped to PageLimit::MAX server-side before it reaches `LIMIT ?`.
        $limit = $request->queryPageSize('limit', 10);
        $items = $this->similarityService->getSimilar($itemId, $limit);

        if ($items !== []) {
            // P5-S2: filter by profile tag restrictions — fetch full items so we
            // can inspect tags_json, then return only items the profile allows.
            $ids = array_column($items, 'id');
            $fullItems = $this->itemRepository->findByIds($ids);
            $fullItems = $this->itemRepository->filterItemsByTags($fullItems);
            // Build a map of id => filtered item for O(1) lookup.
            $filteredMap = [];
            foreach ($fullItems as $fi) {
                $fid = is_string($fi['id'] ?? null) ? $fi['id'] : '';
                if ($fid !== '') {
                    $filteredMap[$fid] = $fi;
                }
            }
            // Re-build response: keep similar-item metadata (score, reason) from
            // $items but use filtered full items for title/posterUrl/year.
            $filteredItems = [];
            foreach ($items as $similarItem) {
                $sid = is_string($similarItem['id'] ?? null) ? $similarItem['id'] : '';
                if (isset($filteredMap[$sid])) {
                    $full = $filteredMap[$sid];
                    $filteredItems[] = [
                        'id' => $sid,
                        'title' => $full['name'] ?? $similarItem['title'] ?? '',
                        // Raw rows bypass MediaItemShaper::shape(), so re-mint the
                        // (scan-time signed, now-expired) internal artwork signature
                        // here too; external covers/null pass through unchanged.
                        'posterUrl' => \Phlix\Auth\SignedUrl::refreshArtworkUrl(
                            is_string($full['poster_url'] ?? null)
                                ? $full['poster_url']
                                : (is_string($similarItem['posterUrl'] ?? null) ? $similarItem['posterUrl'] : null)
                        ),
                        'year' => $full['year'] ?? $similarItem['year'] ?? null,
                        'score' => $similarItem['score'] ?? 0.0,
                        'reason' => $similarItem['reason'] ?? 'genre',
                    ];
                }
            }
            $items = $filteredItems;
        }

        // Parental cap: drop over-cap similar items for a capped active profile
        // (by effective rating). No-op for the owner / un-capped profile.
        $filter = $this->resolveRatingFilter($request);
        $gate = $this->gate();
        if ($filter !== null && $gate !== null) {
            $items = $gate->filterItems($items, $filter, 'id');
        }

        return (new Response())->json(['items' => $items]);
    }

    /**
     * Retrieves the TMDB box-set collection that a media item belongs to (P4-S3).
     *
     * Returns the collection details if the item is part of a box-set,
     * including the collection name, overview, poster, and backdrop images.
     * Requires authentication.
     *
     * @param Request $request The HTTP request (unused).
     * @param array<string, string> $params Route parameters including 'id' (media item ID).
     *
     * @return Response JSON response with collection object or 404 if not in a collection.
     *
     * @api_endpoint GET /api/v1/media/{id}/collection
     *
     * @requires Authentication
     *
     * @example Response structure:
     * ```json
     * {
     *   "collection": {
     *     "id": 123,
     *     "tmdb_collection_id": 10,
     *     "name": "The Lord of the Rings Collection",
     *     "overview": "...]",
     *     "poster_url": "https://image.tmdb.org/t/p/w500/...",
     *     "backdrop_url": "https://image.tmdb.org/t/p/w1280/..."
     *   }
     * }
     * ```
     */
    public function getMediaItemCollection(Request $request, array $params): Response
    {
        if ($this->collectionService === null) {
            return (new Response())->status(503)->json([
                'error' => 'Collection service is not configured on this server',
            ]);
        }

        $itemId = $params['id'] ?? '';
        if ($itemId === '') {
            return (new Response())->status(400)->json(['error' => 'Item ID is required']);
        }

        // Check that the item exists.
        $item = $this->itemRepository->findById($itemId);
        if ($item === null) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        // Parental cap: an over-cap item (by effective rating) is 404 here too, so
        // its collection membership can't be probed. No-op for the owner.
        $filter = $this->resolveRatingFilter($request);
        $gate = $this->gate();
        if ($filter !== null && $gate !== null && !$gate->isAllowed($item, $filter)) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        $collection = $this->collectionService->getCollectionForItem($itemId);

        if ($collection === null) {
            return (new Response())->status(404)->json(['error' => 'Item is not part of any collection']);
        }

        return (new Response())->json(['collection' => $collection]);
    }

    /**
     * Retrieves a TMDB box-set collection with all its member items (P4-S3).
     *
     * Returns the collection details including all movies in the collection,
     * ordered by their TMDB part order.
     * Requires authentication.
     *
     * @param Request $request The HTTP request (unused).
     * @param array<string, string> $params Route parameters including 'id' (collection ID).
     *
     * @return Response JSON response with collection object, members array, or 404 error.
     *
     * @api_endpoint GET /api/v1/collections/{id}
     *
     * @requires Authentication
     *
     * @example Response structure:
     * ```json
     * {
     *   "collection": {
     *     "id": 123,
     *     "tmdb_collection_id": 10,
     *     "name": "The Lord of the Rings Collection",
     *     "overview": "...]",
     *     "poster_url": "https://image.tmdb.org/t/p/w500/...",
     *     "backdrop_url": "https://image.tmdb.org/t/p/w1280/..."
     *   },
     *   "members": [
     *     {
     *       "id": "abc-123",
     *       "name": "The Lord of the Rings: The Fellowship of the Ring",
     *       "type": "movie",
     *       "poster_url": "...",
     *       "backdrop_url": "...",
     *       "tmdb_part_order": 1
     *     }
     *   ]
     * }
     * ```
     */
    public function getCollection(Request $request, array $params): Response
    {
        if ($this->collectionService === null) {
            return (new Response())->status(503)->json([
                'error' => 'Collection service is not configured on this server',
            ]);
        }

        $collectionId = $params['id'] ?? '';
        if ($collectionId === '') {
            return (new Response())->status(400)->json(['error' => 'Collection ID is required']);
        }

        if (!is_numeric($collectionId)) {
            return (new Response())->status(400)->json(['error' => 'Invalid collection ID']);
        }

        $collection = $this->collectionService->getCollectionById((int) $collectionId);

        if ($collection === null) {
            return (new Response())->status(404)->json(['error' => 'Collection not found']);
        }

        $members = $this->collectionService->getCollectionMembers((int) $collectionId);

        // Parental cap: drop over-cap members (by effective rating) for a capped
        // active profile. No-op for the owner / un-capped profile.
        $filter = $this->resolveRatingFilter($request);
        $gate = $this->gate();
        if ($filter !== null && $gate !== null) {
            $members = $gate->filterItems($members, $filter, 'id');
        }

        // Re-index members array so keys are strings (required by Response::json())
        return (new Response())->json([
            'collection' => $collection,
            'members' => array_values($members),
        ]);
    }

    /**
     * Retrieves because-you-watched recommendations for the authenticated user (P4-S2).
     *
     * Returns the user's pre-computed recommendations ordered by score.
     * Requires authentication.
     *
     * @param Request $request The HTTP request with optional `limit` query param.
     * @param array<string, string> $params Route parameters (unused).
     *
     * @return Response JSON response with recommendations array or 401/503 error.
     *
     * @api_endpoint GET /api/v1/me/recommendations
     *
     * @requires Authentication
     *
     * @example Response structure:
     * ```json
     * {
     *   "recommendations": [
     *     {
     *       "id": "abc-123",
     *       "title": "Recommended Movie Title",
     *       "posterUrl": "https://example.com/poster.jpg",
     *       "reason": "because_you_watched",
     *       "score": 1.234
     *     }
     *   ]
     * }
     * ```
     */
    public function getRecommendations(Request $request, array $params): Response
    {
        if ($this->recommendationService === null) {
            return (new Response())->status(503)->json([
                'error' => 'Recommendations are not configured on this server',
            ]);
        }

        $userId = $request->userId ?? '';
        if ($userId === '') {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        // SECURITY: single shared clamp policy (PageLimit); an over-large limit
        // is clamped down rather than silently reset to the default.
        $limit = $request->queryPageSize('limit', 20);

        $recommendations = $this->recommendationService->getBecauseYouWatched($userId, $limit);

        // Parental cap: drop over-cap recommendations (by effective rating) for a
        // capped active profile. No-op for the owner / un-capped profile.
        $filter = $this->resolveRatingFilter($request);
        $gate = $this->gate();
        if ($filter !== null && $gate !== null) {
            $recommendations = $gate->filterItems($recommendations, $filter, 'id');
        }

        return (new Response())->json(['recommendations' => $recommendations]);
    }

    /**
     * Dismisses a because-you-watched recommendation (P4-S2).
     *
     * Marks the recommendation as dismissed so it no longer appears.
     * Requires authentication.
     *
     * @param Request $request The HTTP request (userId set from auth).
     * @param array<string, string> $params Route params including 'mediaItemId'.
     *
     * @return Response JSON response with success message or 401/400 error.
     *
     * @api_endpoint DELETE /api/v1/me/recommendations/{mediaItemId}
     *
     * @requires Authentication
     */
    public function dismissRecommendation(Request $request, array $params): Response
    {
        if ($this->recommendationService === null) {
            return (new Response())->status(503)->json([
                'error' => 'Recommendations are not configured on this server',
            ]);
        }

        $userId = $request->userId ?? '';
        if ($userId === '') {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        $mediaItemId = $params['mediaItemId'] ?? '';
        if ($mediaItemId === '') {
            return (new Response())->status(400)->json(['error' => 'Media item ID is required']);
        }

        $this->recommendationService->dismissRecommendation($userId, $mediaItemId);

        return (new Response())->json(['message' => 'Recommendation dismissed']);
    }

    /**
     * Starts an on-demand HLS transcode job for a media item.
     *
     * Thin delegate to {@see TranscodeController::start()}; responds
     * 503 when the transcode feature is not wired.
     *
     * @param Request $request The HTTP request.
     * @param array<string, string> $params Route params including 'id'.
     *
     * @api_endpoint POST /api/v1/media/{id}/transcode
     */
    public function startTranscode(Request $request, array $params): Response
    {
        if ($this->transcodeController === null) {
            return (new Response())->status(503)->json([
                'error' => 'Transcode is not configured on this server',
            ]);
        }
        return $this->transcodeController->start($request, $params);
    }

    /**
     * Returns the status of an HLS transcode job.
     *
     * Thin delegate to {@see TranscodeController::status()}; responds
     * 503 when the transcode feature is not wired.
     *
     * @param Request $request The HTTP request.
     * @param array<string, string> $params Route params including 'jobId'.
     *
     * @api_endpoint GET /api/v1/transcode/{jobId}/status
     */
    public function statusTranscode(Request $request, array $params): Response
    {
        if ($this->transcodeController === null) {
            return (new Response())->status(503)->json([
                'error' => 'Transcode is not configured on this server',
            ]);
        }
        return $this->transcodeController->status($request, $params);
    }

    /**
     * Retrieves the current user's playback preferences (P7-S3).
     *
     * Returns crossfade duration and fade fraction settings for gapless
     * playback and crossfade mixing. Falls back to server config defaults
     * when no persisted preferences exist or when the settings service
     * is not wired.
     *
     * @param Request $request The HTTP request (userId set from auth)
     * @param array<string, string> $params Route parameters (unused)
     *
     * @return Response JSON response with preferences object
     *
     * @api_endpoint GET /api/v1/me/playback/preferences
     *
     * @requires Authentication
     *
     * @example Response structure:
     * ```json
     * {
     *   "preferences": {
     *     "crossfadeDuration": 5,
     *     "crossfadeFadeOut": 0.3,
     *     "crossfadeFadeIn": 0.3
     *   }
     * }
     * ```
     */
    public function getPlaybackPreferences(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        // Load server config defaults
        $configPath = dirname(__DIR__, 3) . '/config/playback.php';
        $configDefaults = is_file($configPath) ? require $configPath : [];
        $preferences = PlaybackPreferences::fromConfig($configDefaults);

        // Override with persisted user preferences if available
        if ($this->userRepository !== null) {
            $settings = $this->userRepository->getSettings($userId);
            if ($settings !== null && isset($settings['playback_preferences'])) {
                $stored = is_string($settings['playback_preferences'])
                    ? json_decode($settings['playback_preferences'], true)
                    : $settings['playback_preferences'];
                if (is_array($stored)) {
                    $preferences = PlaybackPreferences::fromRaw(
                        $stored['crossfadeDuration'] ?? null,
                        $stored['crossfadeFadeOut'] ?? null,
                        $stored['crossfadeFadeIn'] ?? null
                    );
                }
            }
        }

        return (new Response())->json(['preferences' => $preferences->toArray()]);
    }

    /**
     * Updates the current user's playback preferences (P7-S3).
     *
     * Saves crossfade duration and fade fraction settings for gapless
     * playback and crossfade mixing. Persists to user_settings when
     * the settings service is wired; returns 503 when it is not.
     *
     * Body: `{"crossfadeDuration": <int 0-300>, "crossfadeFadeOut": <float 0.0-1.0>,
     * "crossfadeFadeIn": <float 0.0-1.0> }`
     * All fields are optional; omitted fields retain their current value.
     *
     * @param Request $request The HTTP request (userId set from auth)
     * @param array<string, string> $params Route parameters (unused)
     *
     * @return Response JSON response with success message or error
     *
     * @api_endpoint PUT /api/v1/me/playback/preferences
     *
     * @requires Authentication
     *
     * @example Request body:
     * ```json
     * {
     *   "crossfadeDuration": 5,
     *   "crossfadeFadeOut": 0.3,
     *   "crossfadeFadeIn": 0.3
     * }
     * ```
     *
     * @example Response structure:
     * ```json
     * {
     *   "message": "Playback preferences updated"
     * }
     * ```
     */
    public function updatePlaybackPreferences(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        if ($this->userRepository === null) {
            return (new Response())->status(503)->json([
                'error' => 'Settings persistence is not configured on this server',
            ]);
        }

        // Fetch current preferences to merge with updates
        $settings = $this->userRepository->getSettings($userId) ?? [];
        $currentPrefs = isset($settings['playback_preferences'])
            ? (is_string($settings['playback_preferences'])
                ? json_decode($settings['playback_preferences'], true)
                : $settings['playback_preferences'])
            : [];

        if (!is_array($currentPrefs)) {
            $currentPrefs = [];
        }

        // Parse and validate incoming values
        $rawDuration = $request->input('crossfadeDuration');
        $rawFadeOut = $request->input('crossfadeFadeOut');
        $rawFadeIn = $request->input('crossfadeFadeIn');

        $newPrefs = PlaybackPreferences::fromRaw(
            $rawDuration ?? ($currentPrefs['crossfadeDuration'] ?? 0),
            $rawFadeOut ?? ($currentPrefs['crossfadeFadeOut'] ?? 0.3),
            $rawFadeIn ?? ($currentPrefs['crossfadeFadeIn'] ?? 0.3)
        );

        // Persist within user_settings.playback_preferences
        $settings['playback_preferences'] = json_encode($newPrefs->toArray());
        $this->userRepository->updateSettings($userId, $settings);

        return (new Response())->json(['message' => 'Playback preferences updated']);
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
     * Upload an avatar for the authenticated user (Step 12.3).
     *
     * Thin delegate to {@see UserAvatarController::uploadAvatar()}; responds
     * 503 when the avatar feature is not wired.
     *
     * @param Request              $request The HTTP request (userId set from auth)
     * @param array<string,string> $params  Route parameters (unused)
     *
     * @return Response JSON response with avatar_url or error
     *
     * @api_endpoint POST /api/v1/users/me/avatar
     *
     * @requires Authentication
     */
    public function uploadAvatar(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if (!$userId) {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        if ($this->avatarStorage === null || $this->userAvatarController === null) {
            return (new Response())->status(503)->json([
                'error' => 'Avatar service not configured',
            ]);
        }

        return $this->userAvatarController->uploadAvatar($request, $params);
    }

    /**
     * Delete the avatar for the authenticated user (Step 12.3).
     *
     * Thin delegate to {@see UserAvatarController::deleteAvatar()}; responds
     * 503 when the avatar feature is not wired.
     *
     * @param Request              $request The HTTP request (userId set from auth)
     * @param array<string,string> $params  Route parameters (unused)
     *
     * @return Response JSON response with success message or error
     *
     * @api_endpoint DELETE /api/v1/users/me/avatar
     *
     * @requires Authentication
     */
    public function deleteAvatar(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if (!$userId) {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        if ($this->avatarStorage === null || $this->userAvatarController === null) {
            return (new Response())->status(503)->json([
                'error' => 'Avatar service not configured',
            ]);
        }

        return $this->userAvatarController->deleteAvatar($request, $params);
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

    // -------------------------------------------------------------------------
    // P7-S1: Music library API handlers (Artist→Album→Track hierarchy)
    // -------------------------------------------------------------------------

    /**
     * Lists all music artists.
     *
     * GET /api/v1/music/artists
     *
     * @param Request $request The HTTP request with optional query params:
     *   - limit: Maximum artists to return (default: 50, max: 100)
     *   - offset: Number of artists to skip for pagination (default: 0)
     * @param array<string, string> $params Route parameters (unused)
     *
     * @return Response JSON response with artists array and pagination info
     *
     * @api_endpoint GET /api/v1/music/artists
     */
    public function getMusicArtists(Request $request, array $params): Response
    {
        if ($this->musicLibraryService === null) {
            return (new Response())->status(503)->json(['error' => 'Music library service not configured']);
        }

        // SECURITY: single shared clamp policy (PageLimit).
        $limit = $request->queryPageSize('limit', 50);
        $offset = $request->queryOffset();

        $artists = $this->musicLibraryService->getAllArtists($limit, $offset);
        $total = $this->musicLibraryService->getArtistsCount();

        return (new Response())->json([
            'artists' => array_map(fn($a) => $a->toArray(), $artists),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * Gets a single music artist with their albums.
     *
     * GET /api/v1/music/artists/{id}
     *
     * @param Request $request The HTTP request (unused)
     * @param array<string, string> $params Route parameters including 'id'
     *
     * @return Response JSON response with artist + albums or 404 error
     *
     * @api_endpoint GET /api/v1/music/artists/{id}
     */
    public function getMusicArtist(Request $request, array $params): Response
    {
        if ($this->musicLibraryService === null) {
            return (new Response())->status(503)->json(['error' => 'Music library service not configured']);
        }

        $id = $this->parseIntParam($params['id'] ?? '');
        if ($id === null) {
            return (new Response())->status(400)->json(['error' => 'Invalid artist ID']);
        }

        $artistData = $this->musicLibraryService->getArtistWithAlbums($id);
        if ($artistData === null) {
            return (new Response())->status(404)->json(['error' => 'Artist not found']);
        }

        return (new Response())->json($artistData->toArray());
    }

    /**
     * Gets a single album with its tracks.
     *
     * GET /api/v1/music/albums/{id}
     *
     * @param Request $request The HTTP request (unused)
     * @param array<string, string> $params Route parameters including 'id'
     *
     * @return Response JSON response with album + tracks or 404 error
     *
     * @api_endpoint GET /api/v1/music/albums/{id}
     */
    public function getMusicAlbum(Request $request, array $params): Response
    {
        if ($this->musicLibraryService === null) {
            return (new Response())->status(503)->json(['error' => 'Music library service not configured']);
        }

        $id = $this->parseIntParam($params['id'] ?? '');
        if ($id === null) {
            return (new Response())->status(400)->json(['error' => 'Invalid album ID']);
        }

        $albumData = $this->musicLibraryService->getAlbum($id);
        if ($albumData === null) {
            return (new Response())->status(404)->json(['error' => 'Album not found']);
        }

        return (new Response())->json($albumData->toArray());
    }

    /**
     * Gets all tracks with pagination.
     *
     * GET /api/v1/music/tracks
     *
     * @param Request $request The HTTP request with optional limit/offset query params
     * @param array<string, string> $params Route parameters (unused)
     *
     * @return Response JSON response with tracks array and pagination info
     *
     * @api_endpoint GET /api/v1/music/tracks
     */
    public function getMusicTracks(Request $request, array $params): Response
    {
        if ($this->musicLibraryService === null) {
            return (new Response())->status(503)->json(['error' => 'Music library service not configured']);
        }

        // SECURITY: single shared clamp policy (PageLimit).
        $limit = $request->queryPageSize('limit', 100);
        $offset = $request->queryOffset();

        $tracks = $this->musicLibraryService->getAllTracks($limit, $offset);
        $total = $this->musicLibraryService->getTracksCount();

        // Shape tracks for the client with the fields normalizeMusicTrack expects
        $shapedTracks = [];
        foreach ($tracks as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rawId = $row['id'] ?? null;
            $shapedTracks[] = [
                'id' => is_string($rawId) ? $rawId : (is_scalar($rawId) ? (string) $rawId : ''),
                'title' => is_string($row['title'] ?? null) ? $row['title'] : 'Unknown Track',
                'artist' => is_string($row['artist_name'] ?? null) ? $row['artist_name'] : null,
                'album' => is_string($row['album_name'] ?? null) ? $row['album_name'] : null,
                'track_number' => is_numeric($row['track_number'] ?? null) ? (int)$row['track_number'] : null,
                'duration_secs' => is_numeric($row['duration_secs'] ?? null) ? (int)$row['duration_secs'] : 0,
                'stream_url' => null, // Client resolves via getTrack(id) lazily
            ];
        }

        return (new Response())->json([
            'tracks' => $shapedTracks,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * Gets a single track.
     *
     * GET /api/v1/music/tracks/{id}
     *
     * @param Request $request The HTTP request (unused)
     * @param array<string, string> $params Route parameters including 'id'
     *
     * @return Response JSON response with track or 404 error
     *
     * @api_endpoint GET /api/v1/music/tracks/{id}
     */
    public function getMusicTrack(Request $request, array $params): Response
    {
        if ($this->musicLibraryService === null) {
            return (new Response())->status(503)->json(['error' => 'Music library service not configured']);
        }

        $id = $this->parseIntParam($params['id'] ?? '');
        if ($id === null) {
            return (new Response())->status(400)->json(['error' => 'Invalid track ID']);
        }

        $track = $this->musicLibraryService->getTrack($id);
        if ($track === null) {
            return (new Response())->status(404)->json(['error' => 'Track not found']);
        }

        return (new Response())->json(['track' => $track->toArray()]);
    }

    /**
     * Triggers a music directory scan.
     *
     * POST /api/v1/music/scan
     *
     * Request body: {"path": "/music/rock"}
     *
     * @param Request $request The HTTP request with JSON body containing 'path'
     * @param array<string, string> $params Route parameters (unused)
     *
     * @return Response JSON response with scan result
     *
     * @api_endpoint POST /api/v1/music/scan
     */
    public function scanMusicDirectory(Request $request, array $params): Response
    {
        if ($this->musicLibraryService === null) {
            return (new Response())->status(503)->json(['error' => 'Music library service not configured']);
        }

        $body = $request->body;
        if (!is_array($body)) {
            return (new Response())->status(400)->json(['error' => 'Invalid request body']);
        }

        $path = $body['path'] ?? null;
        if (!is_string($path) || $path === '') {
            return (new Response())->status(400)->json(['error' => 'Path is required']);
        }

        if (!is_dir($path) || !is_readable($path)) {
            return (new Response())->status(400)->json(['error' => 'Path is not a readable directory']);
        }

        $result = $this->musicLibraryService->scanDirectory($path);

        return (new Response())->json($result->toArray());
    }

    /**
     * Parses an integer route parameter safely.
     *
     * @param string $value Raw parameter value
     *
     * @return int|null Parsed integer or null if invalid
     */
    private function parseIntParam(string $value): ?int
    {
        if ($value === '' || !is_numeric($value)) {
            return null;
        }
        $intVal = (int)$value;
        return $intVal > 0 ? $intVal : null;
    }

    /**
     * Returns the configured TMDB API key, or an empty string when not set.
     */
    private function tmdbApiKey(): string
    {
        $tmdbConfigRaw = @include dirname(__DIR__, 2) . '/../../config/tmdb.php';
        return is_array($tmdbConfigRaw)
            && isset($tmdbConfigRaw['api_key'])
            && is_string($tmdbConfigRaw['api_key'])
            ? $tmdbConfigRaw['api_key']
            : (getenv('TMDB_API_KEY') ?: '');
    }
}
