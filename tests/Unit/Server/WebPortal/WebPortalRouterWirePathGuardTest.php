<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal;

use Phlix\Auth\AuthManager;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Auth\WatchHistory;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\Http\Router;
use Phlix\Server\WebPortal\WebPortalRouter;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * S236 — WIRE-PATH guard for {@see WebPortalRouter}.
 *
 * ## The measured hole this file closes
 *
 * Changing the literal at `WebPortalRouter::registerRoutes()` from
 * `/api/v1/users/me/next-up` to `…/next-up-MUTATED` and running the WHOLE Unit
 * suite left it green apart from the two known pre-existing
 * `AdminSettingsControllerTest` dev≠CI failures. Every existing test calls
 * `$router->getNextUp(...)` **directly**, so the wire path the SPA depends on
 * could be renamed or deleted with every server gate green. phlix-ui pinned its
 * half of the contract with `endsWith` after S37's audit; the server never got
 * the mirror guard.
 *
 * ## Why the assertions are shaped the way they are
 *
 * - **No substring matching anywhere.** S37 recorded that
 *   `'…/next-up-MUTATED'` *contains* `'…/next-up'`, so an `includes` /
 *   `str_contains` / `assertStringContainsString` assertion passes the exact
 *   mutation it exists to catch. Every path assertion here is either an exact
 *   array-key lookup or a strict whole-list comparison.
 * - **The route table is the PRODUCTION one.** It is read back off the
 *   `WebPortalRouter` instance's own `Router` (the object `dispatch()`
 *   delegates to), never a fresh table the test builds. A test that registers
 *   its own routes proves nothing about the ones production serves — that is
 *   the blind-route pattern S41 measured on the hub.
 * - **Real `dispatch()`, not a direct handler call.** A handler-level call
 *   cannot observe the path, the middleware, or a route being deleted.
 *
 * ## Coverage statement — read this before trusting the file's name
 *
 * `WebPortalRouter` registers **47** routes (44 when the admin dependencies are
 * unwired). This file covers them at two different strengths, and the
 * difference matters:
 *
 * - **All 47 rails, REGISTRATION-level.** {@see testTheRegisteredWirePathsMatchTheManifestExactly()}
 *   pins verb + exact path literal + handler method + middleware stack for
 *   every route, as a whole-list strict comparison. Renaming, deleting,
 *   re-verbing, re-handling or un-auth-gating ANY of the 47 reds it.
 * - **Four rails, DISPATCH-level end-to-end.** Only `GET /api/v1/users/me/next-up`,
 *   `…/continue-watching`, `…/recently-watched`, `GET /api/v1/libraries` and
 *   the auth-gate rejection path are actually driven through `dispatch()` with
 *   an asserted response envelope. Those are the rails whose collaborators a
 *   unit test can wire honestly.
 * - **NOT dispatch-covered (43 rails):** every `/api/v1/media/*` route, search,
 *   facets/index/letter-index, transcode, favorites/ratings/like/watched,
 *   settings, playback preferences, avatars, collections, themes
 *   (`ThemeEndpointsReachabilityTest` dispatch-covers those two separately),
 *   recommendations, music scan, history deletes, and the three admin-gated
 *   poster/delete routes. Their response envelopes are NOT asserted here. Do
 *   not read this file as an end-to-end guard for the whole router.
 */
final class WebPortalRouterWirePathGuardTest extends TestCase
{
    /**
     * The Next-Up wire path, spelled once. Every assertion below compares
     * against this constant by exact equality — never by containment.
     */
    private const NEXT_UP_PATH = '/api/v1/users/me/next-up';

    /**
     * The mutation S236 measured. Kept as a literal so the "a renamed path is
     * not served" test cannot drift away from the mutation it models.
     */
    private const NEXT_UP_MUTATED_PATH = '/api/v1/users/me/next-up-MUTATED';

    /**
     * Lower bound on the size of a sane route table. Its only job is
     * ANTI-VACUITY: if the reflection read, the registrar, or `getRoutes()`
     * ever yields an empty or hollowed-out table, every assertion in this file
     * would otherwise pass or fail for an unrelated reason. This makes that
     * failure explicit and self-describing instead.
     */
    private const MIN_EXPECTED_ROUTES = 40;

    /**
     * The complete registered surface of `WebPortalRouter`, as
     * `"<VERB> <path literal> -> <Handler>::<method> [<Middleware>,…]"`.
     *
     * ## How to change this list
     *
     * Adding, renaming or removing a route in `registerRoutes()` MUST be
     * accompanied by the matching edit here, in the same commit. That is the
     * whole point: this list is the server's half of the wire contract the SPA
     * and the native clients are written against, and an unreviewed edit to it
     * is exactly the silent rename S236 was filed for.
     *
     * Two entries look wrong and are not:
     *
     *  - `GET /api/v1/media/{id}/ratings` carries NO middleware. That is
     *    deliberate (the public ratings endpoint, P1-S1); the registration sits
     *    outside the auth group on purpose.
     *  - `GET /api/v1/media/{id}/similar` carries `AuthMiddleware` TWICE. P4-S1
     *    nests an inner `group('', …, [new AuthMiddleware()])` inside the outer
     *    auth group and `Router::group()` merges rather than replaces. Harmless
     *    (the middleware is idempotent), recorded rather than tidied because
     *    tidying it is a behavioural change and S236 is test-only.
     *
     * @var list<string>
     */
    private const ROUTE_MANIFEST = [
        'DELETE /api/v1/me/recommendations/{mediaItemId} -> WebPortalRouter::dismissRecommendation [AuthMiddleware]',
        'DELETE /api/v1/media/{id} -> WebPortalRouter::deleteMediaItem [AdminMiddleware]',
        'DELETE /api/v1/media/{id}/favorite -> WebPortalRouter::removeFavorite [AuthMiddleware]',
        'DELETE /api/v1/media/{id}/rating -> WebPortalRouter::clearRating [AuthMiddleware]',
        'DELETE /api/v1/users/me/avatar -> WebPortalRouter::deleteAvatar [AuthMiddleware]',
        'DELETE /api/v1/users/me/history -> WebPortalRouter::clearHistory [AuthMiddleware]',
        'DELETE /api/v1/users/me/history/{mediaItemId} -> WebPortalRouter::removeFromHistory [AuthMiddleware]',
        'GET /api/v1/collections/{id} -> WebPortalRouter::getCollection [AuthMiddleware]',
        'GET /api/v1/libraries -> WebPortalRouter::getLibraries [AuthMiddleware]',
        'GET /api/v1/libraries/{id} -> WebPortalRouter::getLibrary [AuthMiddleware]',
        'GET /api/v1/libraries/{id}/items -> WebPortalRouter::getLibraryItems [AuthMiddleware]',
        'GET /api/v1/me/playback/preferences -> WebPortalRouter::getPlaybackPreferences [AuthMiddleware]',
        'GET /api/v1/me/recommendations -> WebPortalRouter::getRecommendations [AuthMiddleware]',
        'GET /api/v1/media -> WebPortalRouter::getMedia [AuthMiddleware]',
        'GET /api/v1/media/facets -> WebPortalRouter::getMediaFacets [AuthMiddleware]',
        'GET /api/v1/media/index -> WebPortalRouter::getMediaIndex [AuthMiddleware]',
        'GET /api/v1/media/letter-index -> WebPortalRouter::getLetterIndex [AuthMiddleware]',
        'GET /api/v1/media/search -> WebPortalRouter::searchMedia [AuthMiddleware]',
        'GET /api/v1/media/search/by-marker -> WebPortalRouter::searchByMarker [AuthMiddleware]',
        'GET /api/v1/media/{id} -> WebPortalRouter::getMediaItem [AuthMiddleware]',
        'GET /api/v1/media/{id}/chapters -> WebPortalRouter::getMediaChapters [AuthMiddleware]',
        'GET /api/v1/media/{id}/collection -> WebPortalRouter::getMediaItemCollection [AuthMiddleware]',
        'GET /api/v1/media/{id}/markers/search -> WebPortalRouter::searchMediaMarkers [AuthMiddleware]',
        'GET /api/v1/media/{id}/playback -> WebPortalRouter::getPlaybackInfo [AuthMiddleware]',
        'GET /api/v1/media/{id}/posters -> MediaPosterController::listPosters [AdminMiddleware]',
        'GET /api/v1/media/{id}/ratings -> WebPortalRouter::getRatings []',
        'GET /api/v1/media/{id}/similar -> WebPortalRouter::getSimilarItems [AuthMiddleware,AuthMiddleware]',
        'GET /api/v1/themes -> WebPortalRouter::listThemes [AuthMiddleware]',
        'GET /api/v1/themes/{id} -> WebPortalRouter::getTheme [AuthMiddleware]',
        'GET /api/v1/transcode/{jobId}/status -> WebPortalRouter::statusTranscode [AuthMiddleware]',
        'GET /api/v1/users/me/continue-watching -> WebPortalRouter::getContinueWatching [AuthMiddleware]',
        'GET /api/v1/users/me/favorites -> WebPortalRouter::listFavorites [AuthMiddleware]',
        'GET /api/v1/users/me/next-up -> WebPortalRouter::getNextUp [AuthMiddleware]',
        'GET /api/v1/users/me/recently-watched -> WebPortalRouter::getRecentlyWatched [AuthMiddleware]',
        'GET /api/v1/users/me/settings -> WebPortalRouter::getUserSettings [AuthMiddleware]',
        'POST /api/v1/media/{id}/favorite -> WebPortalRouter::addFavorite [AuthMiddleware]',
        'POST /api/v1/media/{id}/ratings -> WebPortalRouter::createRating [AuthMiddleware]',
        'POST /api/v1/media/{id}/transcode -> WebPortalRouter::startTranscode [AuthMiddleware]',
        'POST /api/v1/media/{id}/unwatched -> WebPortalRouter::markUnwatched [AuthMiddleware]',
        'POST /api/v1/media/{id}/watched -> WebPortalRouter::markWatched [AuthMiddleware]',
        'POST /api/v1/music/scan -> WebPortalRouter::scanMusicDirectory [AuthMiddleware]',
        'POST /api/v1/users/me/avatar -> WebPortalRouter::uploadAvatar [AuthMiddleware]',
        'PUT /api/v1/me/playback/preferences -> WebPortalRouter::updatePlaybackPreferences [AuthMiddleware]',
        'PUT /api/v1/media/{id}/like -> WebPortalRouter::setLikeLevel [AuthMiddleware]',
        'PUT /api/v1/media/{id}/poster -> MediaPosterController::setPoster [AdminMiddleware]',
        'PUT /api/v1/media/{id}/rating -> WebPortalRouter::setRating [AuthMiddleware]',
        'PUT /api/v1/users/me/settings -> WebPortalRouter::updateUserSettings [AuthMiddleware]',
    ];

    /**
     * The three routes that only exist when both admin collaborators are wired.
     *
     * @var list<string>
     */
    private const ADMIN_GATED_ROUTES = [
        'DELETE /api/v1/media/{id} -> WebPortalRouter::deleteMediaItem [AdminMiddleware]',
        'GET /api/v1/media/{id}/posters -> MediaPosterController::listPosters [AdminMiddleware]',
        'PUT /api/v1/media/{id}/poster -> MediaPosterController::setPoster [AdminMiddleware]',
    ];

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /**
     * A router wired the way production wires it as far as ROUTE REGISTRATION
     * is concerned: both admin collaborators present, so the conditional admin
     * group registers.
     *
     * @param list<array<string, mixed>>|null $nextUp        rows WatchHistory::getNextUp() returns,
     *                                                       or null to leave WatchHistory unwired
     * @param list<array<string, mixed>>      $continue      rows for getContinueWatching()
     * @param list<array<string, mixed>>      $recent        rows for getRecentlyWatched()
     * @param list<array<string, mixed>>      $libraries     rows for LibraryManager::getAllLibraries()
     */
    private function router(
        ?array $nextUp = [],
        array $continue = [],
        array $recent = [],
        array $libraries = [],
        bool $wireAdmin = true
    ): WebPortalRouter {
        $watchHistory = null;
        if ($nextUp !== null) {
            $watchHistory = $this->createMock(WatchHistory::class);
            $watchHistory->method('getNextUp')->willReturn($nextUp);
        }

        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->method('getAllLibraries')->willReturn($libraries);

        $itemRepository = $this->createMock(ItemRepository::class);
        $itemRepository->method('countByType')->willReturn(0);

        $playback = $this->createMock(PlaybackController::class);
        $playback->method('getContinueWatching')->willReturn($continue);
        $playback->method('getRecentlyWatched')->willReturn($recent);

        // An OWNER account, so the parental rating gate is a strict no-op and
        // the envelope under test is the unfiltered one. The gate itself is
        // pinned by WebPortalRouterNextUpTest; duplicating it here would only
        // make this file's failures ambiguous.
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findById')->willReturn(['id' => 'u1', 'is_admin' => 1]);

        $profileManager = $this->createMock(UserProfileManager::class);
        $profileManager->method('getActiveProfile')->willReturn(['id' => 'p1']);
        $profileManager->method('getActiveRatingFilter')->willReturn(null);

        return new WebPortalRouter(
            $libraryManager,
            $itemRepository,
            $this->createMock(SessionManager::class),
            $playback,
            $this->createMock(AuthManager::class),
            $this->createMock(PlaybackMarkerService::class),
            $this->createMock(MarkerService::class),
            null,
            $wireAdmin ? $userRepository : null,
            $watchHistory,
            $profileManager,
            null,
            null,
            $wireAdmin ? $this->createMock(AuditLogger::class) : null,
        );
    }

    private function request(string $path, ?string $userId = 'u1', string $method = 'GET'): Request
    {
        $request = new Request();
        $request->method = $method;
        $request->path = $path;
        $request->userId = $userId;

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Response $response): array
    {
        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded, 'the response body must be a JSON object');

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    // -----------------------------------------------------------------
    // The extractor, with its anti-vacuity guard
    // -----------------------------------------------------------------

    /**
     * The route table of the `Router` instance `WebPortalRouter::dispatch()`
     * delegates to — the production one, not a fresh one built by the test.
     *
     * Fails LOUDLY, naming the reason, if it reads nothing. Without this an
     * emptied `registerRoutes()`, a renamed `$router` property or a
     * `getRoutes()` that stopped merging the static map would turn every
     * assertion in this file into a vacuous pass.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function routeTable(WebPortalRouter $webPortalRouter): array
    {
        $property = (new ReflectionClass($webPortalRouter))->getProperty('router');
        $property->setAccessible(true);
        $router = $property->getValue($webPortalRouter);

        if (!$router instanceof Router) {
            $this->fail(
                'ANTI-VACUITY: WebPortalRouter::$router did not hold a Router instance, so this '
                . 'file could not read the production route table at all. Every path assertion '
                . 'below would be meaningless. Fix the extractor before trusting a green run.'
            );
        }

        /** @var array<string, array<string, array<string, mixed>>> $routes */
        $routes = $router->getRoutes();

        $count = 0;
        foreach ($routes as $entries) {
            $count += count($entries);
        }

        if ($count < self::MIN_EXPECTED_ROUTES) {
            $this->fail(sprintf(
                'ANTI-VACUITY: the production route table holds %d route(s), fewer than the %d '
                . 'floor. Either registerRoutes() was emptied/hollowed, or this file is no longer '
                . 'reading the table WebPortalRouter::dispatch() serves. Either way the wire-path '
                . 'guard is NOT guarding anything.',
                $count,
                self::MIN_EXPECTED_ROUTES
            ));
        }

        return $routes;
    }

    /**
     * Render the production route table as the manifest's line format.
     *
     * @return list<string>
     */
    private function renderedManifest(WebPortalRouter $webPortalRouter): array
    {
        $lines = [];

        foreach ($this->routeTable($webPortalRouter) as $method => $entries) {
            foreach ($entries as $entry) {
                $path = $entry['path'] ?? null;
                $this->assertIsString($path, 'every route entry must carry its literal path');

                $handler = $entry['handler'] ?? null;
                $this->assertIsArray(
                    $handler,
                    "route {$method} {$path} must be a [target, method] pair — a closure handler "
                    . 'cannot be pinned by name, so it would silently weaken this manifest'
                );
                $this->assertIsObject($handler[0]);
                $this->assertIsString($handler[1]);

                $middleware = $entry['middleware'] ?? [];
                $this->assertIsArray($middleware);
                $names = [];
                foreach ($middleware as $item) {
                    $this->assertIsObject($item);
                    $names[] = $this->shortName($item::class);
                }

                $lines[] = sprintf(
                    '%s %s -> %s::%s [%s]',
                    $method,
                    $path,
                    $this->shortName($handler[0]::class),
                    $handler[1],
                    implode(',', $names)
                );
            }
        }

        sort($lines);

        return $lines;
    }

    private function shortName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');

        return $position === false ? $fqcn : substr($fqcn, $position + 1);
    }

    // -----------------------------------------------------------------
    // 1. The Next-Up wire path itself
    // -----------------------------------------------------------------

    /**
     * Exact array-key lookup. This is the assertion the S236 mutation must
     * break: `'/api/v1/users/me/next-up-MUTATED'` is a DIFFERENT key, so no
     * containment relationship between the two strings can rescue it.
     */
    public function testTheNextUpWirePathIsRegisteredUnderItsExactLiteral(): void
    {
        $routes = $this->routeTable($this->router());

        $this->assertArrayHasKey('GET', $routes);
        $this->assertArrayHasKey(
            self::NEXT_UP_PATH,
            $routes['GET'],
            'WebPortalRouter::registerRoutes() must register GET ' . self::NEXT_UP_PATH
            . ' under exactly that literal. The SPA (phlix-ui src/api/nextUp.ts) and the native '
            . 'clients send this path verbatim; any other spelling 404s for every client.'
        );

        // Belt and braces: the mutated spelling must NOT be registered. Stated
        // separately so a green run cannot be explained by "both are present".
        $this->assertArrayNotHasKey(self::NEXT_UP_MUTATED_PATH, $routes['GET']);
    }

    public function testTheNextUpRouteResolvesToTheGetNextUpHandler(): void
    {
        $routes = $this->routeTable($this->router());
        $handler = $routes['GET'][self::NEXT_UP_PATH]['handler'] ?? null;

        $this->assertIsArray($handler, 'the route must be a [target, method] pair, not a closure');
        $this->assertInstanceOf(WebPortalRouter::class, $handler[0]);
        $this->assertSame(
            'getNextUp',
            $handler[1],
            'GET ' . self::NEXT_UP_PATH . ' must be served by WebPortalRouter::getNextUp'
        );
    }

    /**
     * Next Up is per-profile watch data. Moving it out of the auth group would
     * publish one account's viewing history to anonymous callers.
     */
    public function testTheNextUpRouteStaysInsideTheAuthMiddlewareGroup(): void
    {
        $routes = $this->routeTable($this->router());
        $middleware = $routes['GET'][self::NEXT_UP_PATH]['middleware'] ?? null;

        $this->assertIsArray($middleware);
        $names = [];
        foreach ($middleware as $item) {
            $this->assertIsObject($item);
            $names[] = $this->shortName($item::class);
        }

        $this->assertContains('AuthMiddleware', $names, self::NEXT_UP_PATH . ' must stay auth-gated');
    }

    // -----------------------------------------------------------------
    // 2. Real dispatch, and the envelope contract
    // -----------------------------------------------------------------

    /**
     * The S37 endpoint contract: a BARE `{ items: [...] }` envelope, NOT the
     * `{success, data}` shape the admin controllers use. phlix-ui's rail reads
     * `response.items` directly, so wrapping this response would break the rail
     * just as thoroughly as renaming the path.
     */
    public function testDispatchingTheNextUpWirePathServesTheBareItemsEnvelope(): void
    {
        $rows = [
            ['media_item_id' => 'ep-1', 'name' => 'S01E02', 'series_id' => 'sr-1'],
            ['media_item_id' => 'ep-2', 'name' => 'S03E07', 'series_id' => 'sr-2'],
        ];

        $response = $this->router($rows)->dispatch($this->request(self::NEXT_UP_PATH));

        $this->assertSame(
            200,
            $response->statusCode,
            'GET ' . self::NEXT_UP_PATH . ' must be REACHABLE through dispatch(). A 404 here means '
            . 'the route literal changed or the registration was dropped.'
        );

        $body = $this->body($response);
        $this->assertSame(
            ['items'],
            array_keys($body),
            'the Next-Up envelope is a BARE {items:[…]} object. Adding a success/data wrapper — or '
            . 'any other top-level key — breaks phlix-ui BrowsePage.vue, which reads .items.'
        );
        $this->assertArrayNotHasKey('success', $body);
        $this->assertArrayNotHasKey('data', $body);

        $items = $body['items'];
        $this->assertIsArray($items);
        $this->assertSame(['ep-1', 'ep-2'], array_column($items, 'media_item_id'));
        $this->assertSame(['sr-1', 'sr-2'], array_column($items, 'series_id'));
    }

    /**
     * The one assertion that a containment check could never make. The mutated
     * spelling S236 measured must 404 — if this ever returns 200 the router has
     * started serving a path the clients do not send.
     */
    public function testTheMutatedNextUpSpellingIsNotServed(): void
    {
        $response = $this->router()->dispatch($this->request(self::NEXT_UP_MUTATED_PATH));

        $this->assertSame(
            404,
            $response->statusCode,
            self::NEXT_UP_MUTATED_PATH . ' must not resolve. It is a strict superstring of the real '
            . 'path, which is exactly why a str_contains/includes assertion cannot tell the two apart.'
        );
    }

    public function testDispatchingTheNextUpWirePathRejectsAnAnonymousRequest(): void
    {
        $response = $this->router()->dispatch($this->request(self::NEXT_UP_PATH, null));

        $this->assertSame(401, $response->statusCode);
    }

    /**
     * An unwired `WatchHistory` must answer 503, loudly, rather than an empty
     * rail — an empty `items` list reads as "nothing to resume" and would hide
     * a broken container wiring for the life of a release. That failure mode is
     * not hypothetical here: S36 shipped `?WatchHistory $watchHistory = null`,
     * which PHP-DI's `autowire()` silently SKIPS, and only an explicit factory
     * in `WebPortalServicesProvider` kept it non-null.
     */
    public function testDispatchingTheNextUpWirePathAnswers503WhenWatchHistoryIsUnwired(): void
    {
        $response = $this->router(null)->dispatch($this->request(self::NEXT_UP_PATH));

        $this->assertSame(503, $response->statusCode);
        $this->assertSame(
            ['error' => 'Watch history is not configured on this server'],
            $this->body($response)
        );
    }

    // -----------------------------------------------------------------
    // 3. The sibling activity rails, dispatch-level
    // -----------------------------------------------------------------

    /**
     * The two rails registered either side of Next Up share its envelope and
     * its exposure. Driving them through `dispatch()` too means the guard is
     * not a one-route special case.
     */
    public function testDispatchingTheSiblingActivityRailsServesTheSameBareItemsEnvelope(): void
    {
        $router = $this->router(
            [],
            [['media_item_id' => 'cw-1', 'name' => 'Half-watched']],
            [['media_item_id' => 'rw-1', 'name' => 'Finished last night']]
        );

        $expected = [
            '/api/v1/users/me/continue-watching' => 'cw-1',
            '/api/v1/users/me/recently-watched' => 'rw-1',
        ];

        foreach ($expected as $path => $id) {
            $response = $router->dispatch($this->request($path));

            $this->assertSame(200, $response->statusCode, "{$path} must be reachable through dispatch()");

            $body = $this->body($response);
            $this->assertSame(['items'], array_keys($body), "{$path} must serve a bare {items:[…]} envelope");

            $items = $body['items'];
            $this->assertIsArray($items);
            $this->assertSame([$id], array_column($items, 'media_item_id'));
        }
    }

    /**
     * A rail with a DIFFERENT envelope key, so the envelope assertions above
     * are shown to be reading the real response rather than a constant.
     */
    public function testDispatchingTheLibrariesRailServesTheBareLibrariesEnvelope(): void
    {
        $router = $this->router([], [], [], [['id' => 'lib-1', 'name' => 'Movies', 'type' => 'movie']]);

        $response = $router->dispatch($this->request('/api/v1/libraries'));

        $this->assertSame(200, $response->statusCode);

        $body = $this->body($response);
        $this->assertSame(['libraries'], array_keys($body));

        $libraries = $body['libraries'];
        $this->assertIsArray($libraries);
        $this->assertSame(['lib-1'], array_column($libraries, 'id'));
    }

    /**
     * The auth gate is live on the dispatch path for the whole activity family,
     * not merely present in the route table.
     */
    public function testTheActivityRailsRejectAnonymousRequests(): void
    {
        $router = $this->router();
        $paths = [
            self::NEXT_UP_PATH,
            '/api/v1/users/me/continue-watching',
            '/api/v1/users/me/recently-watched',
            '/api/v1/libraries',
        ];

        foreach ($paths as $path) {
            $response = $router->dispatch($this->request($path, null));

            $this->assertSame(401, $response->statusCode, "{$path} must require a signed-in user");
        }
    }

    // -----------------------------------------------------------------
    // 4. The whole registered surface
    // -----------------------------------------------------------------

    /**
     * Whole-list strict comparison of the production route table against
     * {@see self::ROUTE_MANIFEST}. This is the part of the file that covers the
     * SIBLING RAILS: renaming, deleting, re-verbing, re-pointing or
     * un-auth-gating ANY of the 47 registrations reds it with a readable diff.
     *
     * It does NOT assert those rails' response envelopes — see the class
     * docblock's coverage statement for exactly which four are driven through
     * `dispatch()` and which 43 are not.
     */
    public function testTheRegisteredWirePathsMatchTheManifestExactly(): void
    {
        $expected = self::ROUTE_MANIFEST;
        sort($expected);

        $this->assertSame(
            $expected,
            $this->renderedManifest($this->router()),
            'WebPortalRouter\'s registered wire surface no longer matches the manifest in this '
            . 'file. If the change was intended, edit ROUTE_MANIFEST in the SAME commit and say so '
            . 'in the PR — these paths are the contract phlix-ui and the native clients are '
            . 'written against, and a silent rename here 404s every client (S236).'
        );
    }

    /**
     * The manifest must actually contain the route this file is named for.
     * Guards against someone "fixing" a manifest failure by deleting the line
     * instead of the mutation.
     */
    public function testTheManifestItselfStillCarriesTheNextUpRail(): void
    {
        $this->assertContains(
            'GET /api/v1/users/me/next-up -> WebPortalRouter::getNextUp [AuthMiddleware]',
            self::ROUTE_MANIFEST,
            'the Next-Up rail was removed from ROUTE_MANIFEST — restore it, or S236 is undone'
        );
    }

    /**
     * The three admin-gated registrations are CONDITIONAL on both admin
     * collaborators being wired. Pinning that keeps the conditional honest: if
     * it were inverted, the poster/delete routes would register without an
     * `AdminMiddleware` group in some container configurations.
     */
    public function testTheAdminGatedRoutesAreAbsentWhenTheAdminCollaboratorsAreUnwired(): void
    {
        $rendered = $this->renderedManifest($this->router([], [], [], [], false));

        foreach (self::ADMIN_GATED_ROUTES as $route) {
            $this->assertNotContains(
                $route,
                $rendered,
                "{$route} must NOT register without a UserRepository AND an AuditLogger — "
                . 'the AdminMiddleware it is gated by cannot be constructed without both'
            );
        }

        $expected = array_values(array_diff(self::ROUTE_MANIFEST, self::ADMIN_GATED_ROUTES));
        sort($expected);

        $this->assertSame(
            $expected,
            $rendered,
            'the unwired router must register exactly the manifest MINUS the three admin-gated routes'
        );
    }
}
