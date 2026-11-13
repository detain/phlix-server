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
use Phlix\Media\UserItemDataRepository;
use Phlix\Server\Http\Request;
use Phlix\Server\WebPortal\WebPortalRouter;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use PHPUnit\Framework\TestCase;

/**
 * Drill-down rows (`GET /api/v1/media?parentId=`) carry an ADD-ONLY per-row
 * `user_data` block (watched + progress) so season/episode lists can paint
 * indicators without an N+1 detail fetch. Top-level browse is untouched.
 */
class WebPortalRouterChildUserDataTest extends TestCase
{
    private function makeRouter(?UserItemDataRepository $uid, ?WatchHistory $wh): WebPortalRouter
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('query')->willReturn([
            'items' => [
                ['id' => 'e1', 'name' => 'E1', 'type' => 'episode', 'library_id' => 'l', 'path' => '/e1.mkv', 'metadata' => []],
                ['id' => 'e2', 'name' => 'E2', 'type' => 'episode', 'library_id' => 'l', 'path' => '/e2.mkv', 'metadata' => []],
                ['id' => 'e3', 'name' => 'E3', 'type' => 'episode', 'library_id' => 'l', 'path' => '/e3.mkv', 'metadata' => []],
            ],
            'total' => 3,
            'limit' => 50,
            'offset' => 0,
        ]);
        $repo->method('effectiveContentRatingsForIds')->willReturn([]);

        $pm = $this->createMock(UserProfileManager::class);
        $pm->method('getActiveProfile')->willReturn(['id' => 'p1']);

        return new WebPortalRouter(
            $this->createMock(LibraryManager::class),
            $repo,
            $this->createMock(SessionManager::class),
            $this->createMock(PlaybackController::class),
            $this->createMock(AuthManager::class),
            $this->createMock(PlaybackMarkerService::class),
            $this->createMock(MarkerService::class),
            null,
            null,
            $wh,
            $pm,
            $uid
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(WebPortalRouter $router, Request $req): array
    {
        $resp = $router->getMedia($req, []);
        /** @var array{items: list<array<string, mixed>>} $body */
        $body = json_decode($resp->body, true);
        return $body['items'];
    }

    public function testChildRowsCarryWatchedAndProgress(): void
    {
        $uid = $this->createMock(UserItemDataRepository::class);
        $uid->method('getWatchedMap')->willReturn(['e1' => true]);
        $wh = $this->createMock(WatchHistory::class);
        $wh->method('getProgressMap')->willReturn([
            'e2' => ['position_ticks' => 300, 'duration_ticks' => 1000, 'progress_percent' => 30.0, 'completed' => false],
            'e3' => ['position_ticks' => 990, 'duration_ticks' => 1000, 'progress_percent' => 99.0, 'completed' => true],
        ]);

        $req = new Request();
        $req->userId = 'u1';
        $req->query = ['parentId' => 'series-1'];
        $items = $this->items($this->makeRouter($uid, $wh), $req);

        $ud = array_map(static fn(array $i): array => (array) ($i['user_data'] ?? []), $items);
        $this->assertCount(3, $ud);
        $this->assertTrue($ud[0]['watched']);
        $this->assertSame(0, $ud[0]['position_ticks']);
        $this->assertFalse($ud[1]['watched']);
        $this->assertSame(300, $ud[1]['position_ticks']);
        $this->assertSame(1000, $ud[1]['duration_ticks']);
        $this->assertEquals(30.0, $ud[1]['progress_percent']);
        $this->assertTrue($ud[2]['watched']);
    }

    public function testTopLevelBrowseHasNoRowUserData(): void
    {
        $uid = $this->createMock(UserItemDataRepository::class);
        $uid->expects($this->never())->method('getWatchedMap');
        $req = new Request();
        $req->userId = 'u1';
        $req->query = [];
        $items = $this->items($this->makeRouter($uid, null), $req);
        foreach ($items as $item) {
            $this->assertArrayNotHasKey('user_data', $item);
        }
    }

    public function testUnauthenticatedChildRowsUnchanged(): void
    {
        $req = new Request();
        $req->query = ['parentId' => 'series-1'];
        $items = $this->items($this->makeRouter(null, null), $req);
        // An unidentified request is deny-all at the rating gate; whatever rows
        // survive must not carry a per-user block.
        $withBlock = array_filter($items, static fn(array $i): bool => array_key_exists('user_data', $i));
        $this->assertSame([], $withBlock);
    }
}
