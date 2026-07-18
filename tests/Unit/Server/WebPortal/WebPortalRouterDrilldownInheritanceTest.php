<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal;

use Phlix\Auth\AuthManager;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Server\Http\Request;
use Phlix\Server\WebPortal\WebPortalRouter;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use PHPUnit\Framework\TestCase;

/**
 * Finding 2 — drill-down inheritance for the browse surfaces. A capped profile
 * drilling into an over-cap series (`GET /api/v1/media?parentId=<blocked-series>`)
 * must get an EMPTY result — the whole subtree is blocked — instead of that
 * series' NULL-rated episodes leaking through the own-column SQL cap. An allowed
 * series' episodes stay reachable; the owner is never gated; a top-level browse
 * (no parentId) is untouched.
 */
class WebPortalRouterDrilldownInheritanceTest extends TestCase
{
    /**
     * @return array{allowedRatings: list<string>, allowUnrated: bool}
     */
    private function pg13Filter(): array
    {
        return ['allowedRatings' => ['G', 'PG', 'PG-13', 'TV-14'], 'allowUnrated' => true];
    }

    /**
     * @param array<string, string|null> $effective id => effective rating
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
     * @param array{allowedRatings: list<string>, allowUnrated: bool}|null $filter
     */
    private function makeRouter(ItemRepository $itemRepo, bool $isAdmin, ?array $filter): WebPortalRouter
    {
        $pm = $this->createMock(UserProfileManager::class);
        $pm->method('getActiveRatingFilter')->willReturn($filter);
        $pm->method('getActiveProfile')->willReturn(['id' => 'p1']);

        $users = $this->createMock(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 'u1', 'is_admin' => $isAdmin ? 1 : 0]);

        return new WebPortalRouter(
            $this->createMock(LibraryManager::class),
            $itemRepo,
            $this->createMock(SessionManager::class),
            $this->createMock(PlaybackController::class),
            $this->createMock(AuthManager::class),
            $this->createMock(PlaybackMarkerService::class),
            $this->createMock(MarkerService::class),
            null,
            $users,
            null,
            $pm
        );
    }

    private function drilldownRequest(string $parentId): Request
    {
        $req = new Request();
        $req->userId = 'u1';
        $req->query = ['parentId' => $parentId];
        return $req;
    }

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     letters: list<array<string, mixed>>,
     *     buckets: list<array<string, mixed>>,
     *     total: mixed
     * }
     */
    private function decode(\Phlix\Server\Http\Response $resp): array
    {
        /**
         * @var array{
         *     items: list<array<string, mixed>>,
         *     letters: list<array<string, mixed>>,
         *     buckets: list<array<string, mixed>>,
         *     total: mixed
         * } $body
         */
        $body = json_decode($resp->body, true);
        return $body;
    }

    public function testGetMediaReturnsEmptyForBlockedSeriesSubtree(): void
    {
        // Blocked series → query() must never run: the whole subtree is denied.
        $repo = $this->itemRepo(['show-1' => 'R']);
        $repo->expects($this->never())->method('query');

        $router = $this->makeRouter($repo, false, $this->pg13Filter());
        $resp = $router->getMedia($this->drilldownRequest('show-1'), []);

        $this->assertSame(200, $resp->statusCode);
        $body = $this->decode($resp);
        $this->assertSame([], $body['items']);
        $this->assertSame(0, $body['total']);
    }

    public function testGetMediaKeepsEpisodesOfAllowedSeries(): void
    {
        $repo = $this->itemRepo(['show-1' => 'PG']);
        $repo->method('query')->willReturn([
            'items' => [
                ['id' => 'ep-1', 'name' => 'E1', 'type' => 'episode',
                    'content_rating' => null, 'parent_id' => 'show-1', 'metadata' => []],
                ['id' => 'ep-2', 'name' => 'E2', 'type' => 'episode',
                    'content_rating' => null, 'parent_id' => 'show-1', 'metadata' => []],
            ],
            'total' => 2,
            'limit' => 50,
            'offset' => 0,
        ]);

        $router = $this->makeRouter($repo, false, $this->pg13Filter());
        $resp = $router->getMedia($this->drilldownRequest('show-1'), []);

        $this->assertSame(200, $resp->statusCode);
        $body = $this->decode($resp);
        $this->assertCount(2, $body['items']);
    }

    public function testGetMediaUnfilteredForOwnerOnBlockedSeries(): void
    {
        // Owner → null filter → subtree never blocked; query runs and rows show.
        $repo = $this->itemRepo(['show-1' => 'R']);
        $repo->method('query')->willReturn([
            'items' => [
                ['id' => 'ep-1', 'name' => 'E1', 'type' => 'episode',
                    'content_rating' => null, 'parent_id' => 'show-1', 'metadata' => []],
            ],
            'total' => 1,
            'limit' => 50,
            'offset' => 0,
        ]);

        $router = $this->makeRouter($repo, true, $this->pg13Filter());
        $resp = $router->getMedia($this->drilldownRequest('show-1'), []);

        $this->assertSame(200, $resp->statusCode);
        $body = $this->decode($resp);
        $this->assertCount(1, $body['items']);
    }

    public function testGetLetterIndexEmptyForBlockedSeriesSubtree(): void
    {
        $repo = $this->itemRepo(['show-1' => 'R']);
        $repo->expects($this->never())->method('valueBuckets');

        $router = $this->makeRouter($repo, false, $this->pg13Filter());
        $resp = $router->getLetterIndex($this->drilldownRequest('show-1'), []);

        $this->assertSame(200, $resp->statusCode);
        $body = $this->decode($resp);
        $this->assertSame(0, $body['total']);
        // Every bucket present but zeroed (rail renders disabled).
        $this->assertCount(27, $body['letters']);
        foreach ($body['letters'] as $bucket) {
            $this->assertSame(0, $bucket['count']);
        }
    }

    public function testGetMediaIndexEmptyForBlockedSeriesSubtree(): void
    {
        $repo = $this->itemRepo(['show-1' => 'R']);
        $repo->expects($this->never())->method('valueBuckets');

        $router = $this->makeRouter($repo, false, $this->pg13Filter());
        $resp = $router->getMediaIndex($this->drilldownRequest('show-1'), []);

        $this->assertSame(200, $resp->statusCode);
        $body = $this->decode($resp);
        $this->assertSame(0, $body['total']);
        $this->assertSame([], $body['buckets']);
    }

    public function testTopLevelBrowseUntouchedByDrilldownGate(): void
    {
        // No parentId → the drill-down gate is a strict no-op; query runs as usual.
        $repo = $this->itemRepo();
        $repo->expects($this->once())->method('query')->willReturn([
            'items' => [
                ['id' => 'm-1', 'name' => 'A Movie', 'type' => 'movie',
                    'content_rating' => 'PG', 'metadata' => []],
            ],
            'total' => 1,
            'limit' => 50,
            'offset' => 0,
        ]);

        $req = new Request();
        $req->userId = 'u1';
        $req->query = [];

        $router = $this->makeRouter($repo, false, $this->pg13Filter());
        $resp = $router->getMedia($req, []);

        $this->assertSame(200, $resp->statusCode);
        $body = $this->decode($resp);
        $this->assertCount(1, $body['items']);
    }
}
