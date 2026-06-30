<?php

declare(strict_types=1);

namespace Phlix\Server\WebPortal;

use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\Http\Middleware\AuthMiddleware;
use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Router;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\MediaItemShaper;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Session\SessionManager;
use Phlix\Session\PlaybackController;
use Phlix\Auth\AuthManager;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Auth\WatchHistory;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Media\UserItemDataRepository;
use Phlix\Server\Http\Controllers\MediaUserDataController;

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

    /** @var UserItemDataRepository|null Per-user favorites/ratings; null when not wired */
    private ?UserItemDataRepository $userItemData;

    /** @var MediaUserDataController|null Handles favorite/rating routes; null when not wired */
    private ?MediaUserDataController $mediaUserDataController;

    /** @var AuditLogger|null Security-event logger for admin-gated operations; null when not wired */
    private ?AuditLogger $auditLogger;

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
     * @param UserItemDataRepository|null $userItemData Per-user favorites/ratings (optional)
     * @param MediaUserDataController|null $mediaUserDataController Favorite/rating routes (optional)
     * @param AuditLogger|null $auditLogger Security-event logger for admin operations (optional)
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
        ?UserProfileManager $profileManager = null,
        ?UserItemDataRepository $userItemData = null,
        ?MediaUserDataController $mediaUserDataController = null,
        ?AuditLogger $auditLogger = null
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
        $this->userItemData = $userItemData;
        $this->mediaUserDataController = $mediaUserDataController;
        $this->auditLogger = $auditLogger;
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

        $this->router->group('', function (Router $r): void {
            // Library routes
            $r->get('/api/v1/libraries', [$this, 'getLibraries']);
            $r->get('/api/v1/libraries/{id}', [$this, 'getLibrary']);
            $r->get('/api/v1/libraries/{id}/items', [$this, 'getLibraryItems']);

            // Media routes
            $r->get('/api/v1/media', [$this, 'getMedia']);
            // Static segments registered BEFORE `{id}` so they can't be swallowed as an id.
            $r->get('/api/v1/media/letter-index', [$this, 'getLetterIndex']);
            $r->get('/api/v1/media/facets', [$this, 'getMediaFacets']);
            $r->get('/api/v1/media/{id}', [$this, 'getMediaItem']);
            $r->get('/api/v1/media/{id}/playback', [$this, 'getPlaybackInfo']);

            // User activity routes
            $r->get('/api/v1/users/me/continue-watching', [$this, 'getContinueWatching']);
            $r->get('/api/v1/users/me/recently-watched', [$this, 'getRecentlyWatched']);
            $r->get('/api/v1/users/me/favorites', [$this, 'listFavorites']);

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

            // Settings routes
            $r->get('/api/v1/users/me/settings', [$this, 'getUserSettings']);
            $r->put('/api/v1/users/me/settings', [$this, 'updateUserSettings']);
        }, [$auth]);

        // Admin-only: delete a media item (Step 11.6). Gate with AdminMiddleware
        // so that unauthenticated (401) and non-admin (403) are rejected before
        // the handler runs; 404 is produced by the handler when the item is missing.
        if ($this->userRepository !== null && $this->auditLogger !== null) {
            $adminMiddleware = new AdminMiddleware($this->userRepository, $this->auditLogger);
            $this->router->group(
                '',
                function (Router $r): void {
                    $r->delete('/api/v1/media/{id}', [$this, 'deleteMediaItem']);
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

        // Enrich the row into the same shape the list endpoint returns (poster
        // URLs, genres, overview, season/episode numbers, …) PLUS streams, so the
        // detail/player pages render a cover and metadata instead of a blank hero.
        $itemId = is_string($item['id'] ?? null) ? $item['id'] : '';
        $shaped = MediaItemShaper::shapeDetail($item, $this->itemRepository->getItemStreams($itemId));

        // Mint a signed direct-play URL. The player's `<video src>` can't attach
        // a Bearer header, and `/media/{id}/stream` is no longer world-readable,
        // so this now-gated detail endpoint hands the player a short-lived
        // `?exp&sig` token that the stream handler verifies.
        if ($itemId !== '') {
            $shaped['stream_url'] = \Phlix\Auth\SignedUrl::fromEnv()->mint('/media/' . $itemId . '/stream');
        }

        // Per-user favorite/rating block (E10). ADD-ONLY — never disturb existing
        // keys (e.g. the flat `actors` landmine). `null` when unauthenticated;
        // {favorite:false, rating:null} when authenticated but no row exists yet.
        $shaped['user_data'] = $this->resolveUserData($request, $itemId);

        return (new Response())->json(['item' => $shaped]);
    }

    /**
     * Resolve the per-user favorite/rating block for a media item.
     *
     * @param Request $request The HTTP request (carries the authenticated userId).
     * @param string  $itemId  The media item UUID (already extracted + validated).
     *
     * @return array{favorite: bool, rating: int|null, like_level: int}|null
     *         `null` when the request is unauthenticated or the favorites store
     *         is not wired; the user's data otherwise (defaulting to
     *         not-favorited/unrated/un-loved when no row exists). `like_level`
     *         is the 0-3 multi-level Love axis (Feature 10) — ADD-ONLY alongside
     *         the existing `favorite`/`rating` keys.
     */
    private function resolveUserData(Request $request, string $itemId): ?array
    {
        $userId = $request->userId ?? '';
        if ($userId === '' || $itemId === '' || $this->userItemData === null) {
            return null;
        }

        return $this->userItemData->getItemData($userId, $itemId)
            ?? ['favorite' => false, 'rating' => null, 'like_level' => 0];
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

        $result = $this->itemRepository->query($queryParams, $libraryId);

        $items = array_map(function (array $item): array {
            return $this->shapeMediaItem($item);
        }, $result['items']);

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

        // Fold per-first-character counts into A–Z + a single `#` (non-alpha).
        $byBucket = [];
        foreach ($this->itemRepository->letterCounts($queryParams, $libraryId) as $row) {
            $bucket = preg_match('/^[A-Z]$/', $row['letter']) === 1 ? $row['letter'] : '#';
            $byBucket[$bucket] = ($byBucket[$bucket] ?? 0) + $row['count'];
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
