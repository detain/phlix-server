<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal;

use Phlix\Auth\AuthManager;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Auth\WatchHistory;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\WebPortal\WebPortalRouter;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use PHPUnit\Framework\TestCase;

/**
 * Router-level tests for the S36 endpoint `GET /api/v1/users/me/next-up`
 * ({@see WebPortalRouter::getNextUp()}). The heavy aggregation is proven against
 * real MySQL in {@see \Phlix\Tests\Integration\Auth\NextUpIntegrationTest}; here
 * we lock the THIN handler wiring: auth gate (401), unconfigured-service (503),
 * the no-active-profile empty response, and the active-profile parental
 * RATING-GATE post-filter (mirrors continue-watching, keyed on `media_item_id`).
 *
 * @covers \Phlix\Server\WebPortal\WebPortalRouter
 */
final class WebPortalRouterNextUpTest extends TestCase
{
    /**
     * @return array{allowedRatings: list<string>, allowUnrated: bool}
     */
    private function pg13Filter(): array
    {
        return [
            'allowedRatings' => ['G', 'TV-Y', 'TV-G', 'TV-Y7', 'PG', 'TV-PG', 'PG-13', 'TV-14'],
            'allowUnrated' => true,
        ];
    }

    /**
     * @param array<string, string|null> $effective media id => effective rating
     * @return ItemRepository&\PHPUnit\Framework\MockObject\MockObject
     */
    private function itemRepo(array $effective = [])
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('effectiveContentRatingsForIds')->willReturnCallback(
            static function (array $ids) use ($effective): array {
                $out = [];
                foreach ($ids as $id) {
                    $out[$id] = $effective[$id] ?? null;
                }
                return $out;
            }
        );
        return $repo;
    }

    /**
     * @param array{id: string}|null                                          $activeProfile
     * @param array{allowedRatings: list<string>, allowUnrated: bool}|null     $filter
     */
    private function profileManager(?array $activeProfile, ?array $filter): UserProfileManager
    {
        $pm = $this->createMock(UserProfileManager::class);
        $pm->method('getActiveProfile')->willReturn($activeProfile);
        $pm->method('getActiveRatingFilter')->willReturn($filter);
        return $pm;
    }

    private function userRepo(bool $isAdmin): UserRepository
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findById')->willReturn(['id' => 'u1', 'is_admin' => $isAdmin ? 1 : 0]);
        return $repo;
    }

    /**
     * @param list<array<string, mixed>> $nextUpItems what WatchHistory::getNextUp returns
     * @param array<string, string|null> $effective   media id => effective rating (for the gate)
     * @param array{id: string}|null     $activeProfile
     */
    private function makeRouter(
        ?WatchHistory $watchHistory,
        array $effective,
        ?array $activeProfile,
        bool $isAdmin,
        ?array $filter
    ): WebPortalRouter {
        return new WebPortalRouter(
            $this->createMock(LibraryManager::class),
            $this->itemRepo($effective),
            $this->createMock(SessionManager::class),
            $this->createMock(PlaybackController::class),
            $this->createMock(AuthManager::class),
            $this->createMock(PlaybackMarkerService::class),
            $this->createMock(MarkerService::class),
            null,
            $this->userRepo($isAdmin),
            $watchHistory,
            $this->profileManager($activeProfile, $filter),
        );
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return WatchHistory&\PHPUnit\Framework\MockObject\MockObject
     */
    private function watchHistory(array $items)
    {
        $wh = $this->createMock(WatchHistory::class);
        $wh->method('getNextUp')->willReturn($items);
        return $wh;
    }

    private function authedRequest(): Request
    {
        $req = new Request();
        $req->userId = 'u1';
        return $req;
    }

    /**
     * @return list<mixed>
     */
    private function itemIds(Response $resp): array
    {
        $body = json_decode($resp->body, true);
        $this->assertIsArray($body);
        $items = $body['items'] ?? null;
        $this->assertIsArray($items);
        $out = [];
        foreach ($items as $row) {
            $this->assertIsArray($row);
            $out[] = $row['media_item_id'] ?? null;
        }
        return $out;
    }

    public function testUnauthorizedWithoutUser(): void
    {
        $router = $this->makeRouter($this->watchHistory([]), [], ['id' => 'p1'], false, null);

        $resp = $router->getNextUp(new Request(), []);

        $this->assertSame(401, $resp->statusCode);
    }

    public function testServiceUnavailableWhenWatchHistoryNotConfigured(): void
    {
        $router = $this->makeRouter(null, [], ['id' => 'p1'], false, null);

        $resp = $router->getNextUp($this->authedRequest(), []);

        $this->assertSame(503, $resp->statusCode);
    }

    public function testReturnsEmptyItemsWhenNoActiveProfile(): void
    {
        // No active profile → the rail is empty and getNextUp is never queried.
        $wh = $this->watchHistory([['media_item_id' => 'a', 'name' => 'A']]);
        $wh->expects($this->never())->method('getNextUp');

        $router = $this->makeRouter($wh, [], null, false, null);
        $resp = $router->getNextUp($this->authedRequest(), []);

        $this->assertSame(200, $resp->statusCode);
        $this->assertSame([], $this->itemIds($resp));
    }

    public function testDropsOverCapEpisodesForCappedProfile(): void
    {
        $wh = $this->watchHistory([
            ['media_item_id' => 'a', 'name' => 'A'],
            ['media_item_id' => 'b', 'name' => 'B'],
        ]);

        // a = PG (allowed under PG-13), b = R (blocked).
        $router = $this->makeRouter($wh, ['a' => 'PG', 'b' => 'R'], ['id' => 'p1'], false, $this->pg13Filter());
        $resp = $router->getNextUp($this->authedRequest(), []);

        $this->assertSame(['a'], $this->itemIds($resp));
    }

    public function testUnfilteredForOwner(): void
    {
        $wh = $this->watchHistory([
            ['media_item_id' => 'a', 'name' => 'A'],
            ['media_item_id' => 'b', 'name' => 'B'],
        ]);

        // Owner/admin: no parental cap applied even with a filter configured.
        $router = $this->makeRouter($wh, ['a' => 'PG', 'b' => 'R'], ['id' => 'p1'], true, $this->pg13Filter());
        $resp = $router->getNextUp($this->authedRequest(), []);

        $this->assertSame(['a', 'b'], $this->itemIds($resp));
    }
}
