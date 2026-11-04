<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal;

use Phlix\Auth\AuthManager;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\WatchHistory;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Router;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use Phlix\Auth\UserRepository;
use Phlix\Server\WebPortal\WebPortalRouter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Review xrepo__server-ui M1 — member-facing GET watch history.
 *
 * The SPA `WatchHistoryPage` fetches `GET /api/v1/me/history` (fallback
 * `/api/v1/me/progress`); the server registered only the DELETE legs of the
 * history family, so the deep-linked page 404'd on every visit. These pins
 * hold the cure: `getWatchHistory` on BOTH wire paths (`/api/v1/me/history`
 * alias twin + canonical `/api/v1/users/me/history`), the HistoryItem
 * envelope the page parses ({id: media item id, media: list-shaped item,
 * progress 0..1, updated_at verbatim}), the S80 profile law, the parental
 * rating cap, the unwired 503 idiom, and the single-batched findByIds
 * (Batch Queries law — no per-row N+1).
 */
final class WebPortalRouterWatchHistoryGetTest extends TestCase
{
    private function makeRouter(
        ?WatchHistory $watchHistory = null,
        ?ItemRepository $itemRepository = null,
        ?UserProfileManager $profileManager = null,
        ?UserRepository $userRepository = null
    ): WebPortalRouter {
        return new WebPortalRouter(
            $this->createMock(LibraryManager::class),
            $itemRepository ?? $this->createMock(ItemRepository::class),
            $this->createMock(SessionManager::class),
            $this->createMock(PlaybackController::class),
            $this->createMock(AuthManager::class),
            $this->createMock(PlaybackMarkerService::class),
            $this->createMock(MarkerService::class),
            null,
            $userRepository,
            $watchHistory,
            $profileManager
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function routeTable(WebPortalRouter $webPortalRouter): array
    {
        $property = (new ReflectionClass($webPortalRouter))->getProperty('router');
        $property->setAccessible(true);
        $router = $property->getValue($webPortalRouter);
        $this->assertInstanceOf(Router::class, $router);

        return $router->getRoutes();
    }

    public function testTheHistoryGetIsRegisteredOnBothWirePaths(): void
    {
        $routes = $this->routeTable($this->makeRouter());

        $this->assertArrayHasKey('GET', $routes);
        $this->assertArrayHasKey(
            '/api/v1/me/history',
            $routes['GET'],
            'The SPA WatchHistoryPage sends this path verbatim; without it the page 404s.'
        );
        $this->assertArrayHasKey(
            '/api/v1/users/me/history',
            $routes['GET'],
            'Canonical form must exist beside the DELETE legs (and the /me/* twin law).'
        );
        $this->assertSame(
            [[$routes['GET']['/api/v1/me/history']['handler'][0] ?? null, 'getWatchHistory']],
            [[$routes['GET']['/api/v1/users/me/history']['handler'][0] ?? null, 'getWatchHistory']],
            'Both literals must resolve to the SAME handler — twins may not diverge.'
        );
    }

    public function testGetWatchHistory401sForAnonymousRequests(): void
    {
        $response = $this->makeRouter()->getWatchHistory(new Request(), []);

        $this->assertSame(401, $response->statusCode);
        $this->assertSame(['error' => 'Unauthorized'], json_decode($response->body, true));
    }

    public function testGetWatchHistory503sWhenWatchHistoryIsUnwired(): void
    {
        $request = new Request();
        $request->userId = 'user-1';

        $response = $this->makeRouter(null)->getWatchHistory($request, []);

        $this->assertSame(503, $response->statusCode);
    }

    public function testGetWatchHistory404sWithoutAResolvableProfile(): void
    {
        $request = new Request();
        $request->userId = 'user-1';

        $response = $this->makeRouter($this->createMock(WatchHistory::class))->getWatchHistory($request, []);

        $this->assertSame(404, $response->statusCode);
        $this->assertSame(['error' => 'No active profile found'], json_decode($response->body, true));
    }

    public function testGetWatchHistoryReturnsHistoryItemsInPageShape(): void
    {
        $watchHistory = $this->createMock(WatchHistory::class);
        $watchHistory->expects($this->once())
            ->method('getHistory')
            ->with('prof-1', 50, 0)
            ->willReturn([
                [
                    'id' => 'wh-1',
                    'profile_id' => 'prof-1',
                    'media_item_id' => 'm1',
                    'position_ticks' => 625,
                    'duration_ticks' => 1000,
                    'playback_status' => 'paused',
                    'progress_percent' => 62.5,
                    'last_watched_at' => '2026-11-04 10:00:00',
                    'created_at' => null,
                    'completed_at' => null,
                ],
                [
                    'id' => 'wh-2',
                    'profile_id' => 'prof-1',
                    'media_item_id' => 'm2',
                    'position_ticks' => 0,
                    'duration_ticks' => 100,
                    'playback_status' => 'completed',
                    'progress_percent' => 0.0,
                    'last_watched_at' => '2026-11-03 09:00:00',
                    'created_at' => null,
                    'completed_at' => '2026-11-03 09:00:00',
                ],
            ]);

        $itemRepository = $this->createMock(ItemRepository::class);
        $itemRepository->expects($this->once())
            ->method('findByIds')
            ->with(['m1', 'm2'])
            ->willReturn([
                ['id' => 'm1', 'name' => 'Movie One', 'type' => 'movie', 'path' => '/m/1.mkv', 'metadata_json' => '{}'],
                ['id' => 'm2', 'name' => 'Movie Two', 'type' => 'movie', 'path' => '/m/2.mkv', 'metadata_json' => '{}'],
            ]);

        $request = new Request();
        $request->userId = 'user-1';
        $request->profileId = 'prof-1';

        $response = $this->makeRouter($watchHistory, $itemRepository)->getWatchHistory($request, []);

        $this->assertSame(200, $response->statusCode);
        /** @var array{items: list<array<string, mixed>>} $body */
        $body = json_decode($response->body, true);
        $this->assertCount(2, $body['items']);

        [$first, $second] = $body['items'];
        // id = the MEDIA item id (the page keys cards/removals on it), not the
        // watch_history row id.
        $this->assertSame('m1', $first['id']);
        $this->assertSame(0.625, $first['progress']);
        $this->assertSame('2026-11-04 10:00:00', $first['updated_at']);
        $this->assertIsArray($first['media']);
        $this->assertSame('m1', $first['media']['id']);
        $this->assertSame('Movie One', $first['media']['name']);
        // Completed rows report a FULL 0..1 progress regardless of the stored
        // percent (the row above carries 0.0 to prove the status arm drives it).
        // JSON renders 1.0 as `1`; the page consumes it numerically.
        $this->assertSame(1.0, (float) $second['progress']);
    }

    public function testGetWatchHistorySkipsRowsWhoseMediaIsGone(): void
    {
        $watchHistory = $this->createMock(WatchHistory::class);
        $watchHistory->method('getHistory')->willReturn([
            [
                'id' => 'wh-1',
                'profile_id' => 'prof-1',
                'media_item_id' => 'gone',
                'position_ticks' => 1,
                'duration_ticks' => 2,
                'playback_status' => 'paused',
                'progress_percent' => 50.0,
                'last_watched_at' => '2026-11-01 00:00:00',
                'created_at' => null,
                'completed_at' => null,
            ],
        ]);
        $itemRepository = $this->createMock(ItemRepository::class);
        $itemRepository->method('findByIds')->willReturn([]);

        $request = new Request();
        $request->userId = 'user-1';
        $request->profileId = 'prof-1';

        $response = $this->makeRouter($watchHistory, $itemRepository)->getWatchHistory($request, []);
        $body = json_decode($response->body, true);

        $this->assertSame(['items' => []], $body);
    }

    public function testGetWatchHistoryEnforcesTheParentalRatingCap(): void
    {
        // A G-capped profile must never see the item rated R. The gate runs on
        // the RAW rows BEFORE shaping — same placement law as listFavorites.
        $watchHistory = $this->createMock(WatchHistory::class);
        $watchHistory->method('getHistory')->willReturn([
            $this->historyRow('wh-1', 'safe'),
            $this->historyRow('wh-2', 'hot'),
        ]);
        $itemRepository = $this->createMock(ItemRepository::class);
        $itemRepository->method('findByIds')->willReturn([
            ['id' => 'safe', 'name' => 'Safe', 'type' => 'movie', 'content_rating' => 'G',
                'path' => '/m/s.mkv', 'metadata_json' => '{}'],
            ['id' => 'hot', 'name' => 'Hot', 'type' => 'movie', 'content_rating' => 'R',
                'path' => '/m/h.mkv', 'metadata_json' => '{}'],
        ]);

        $profileManager = $this->createMock(UserProfileManager::class);
        $profileManager->method('getActiveRatingFilter')
            ->willReturn(['allowedRatings' => ['G'], 'allowUnrated' => false]);
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn(['id' => 'user-1', 'is_admin' => 0]);

        $request = new Request();
        $request->userId = 'user-1';
        $request->profileId = 'prof-1';

        $body = json_decode(
            $this->makeRouter($watchHistory, $itemRepository, $profileManager, $userRepo)
                ->getWatchHistory($request, [])->body,
            true
        );

        $this->assertSame(['safe'], array_column($body['items'], 'id'));
    }

    public function testGetWatchHistoryClampsAndThreadsPagination(): void
    {
        $watchHistory = $this->createMock(WatchHistory::class);
        $watchHistory->expects($this->once())->method('getHistory')->with('prof-1', 100, 10)->willReturn([]);

        $request = new Request();
        $request->userId = 'user-1';
        $request->profileId = 'prof-1';
        $request->query = ['limit' => '100', 'offset' => '10'];

        $response = $this->makeRouter($watchHistory)->getWatchHistory($request, []);
        $this->assertSame(200, $response->statusCode);

        // Out-of-range offset (negative) must not reach SQL: queryOffset() owns
        // that clamp; an absurd limit clamps to the page-size ceiling.
        $request->query = ['limit' => '100000', 'offset' => '-5'];
        $watchHistory2 = $this->createMock(WatchHistory::class);
        $watchHistory2->expects($this->once())
            ->method('getHistory')
            ->with(
                'prof-1',
                $this->callback(static fn (mixed $v): bool => is_int($v) && $v > 0 && $v <= 100),
                $this->callback(static fn (mixed $v): bool => is_int($v) && $v >= 0)
            )
            ->willReturn([]);
        $this->makeRouter($watchHistory2)->getWatchHistory($request, []);
    }

    /**
     * @return array<string, mixed>
     */
    private function historyRow(string $id, string $itemId): array
    {
        return [
            'id' => $id,
            'profile_id' => 'prof-1',
            'media_item_id' => $itemId,
            'position_ticks' => 10,
            'duration_ticks' => 20,
            'playback_status' => 'paused',
            'progress_percent' => 50.0,
            'last_watched_at' => '2026-11-02 00:00:00',
            'created_at' => null,
            'completed_at' => null,
        ];
    }
}
