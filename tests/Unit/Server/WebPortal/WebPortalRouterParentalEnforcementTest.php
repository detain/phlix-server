<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal;

use Phlix\Auth\AuthManager;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Media\CollectionService;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Media\Metadata\TmdbProvider;
use Phlix\Media\RecommendationService;
use Phlix\Media\SimilarityService;
use Phlix\Server\Http\Request;
use Phlix\Server\WebPortal\WebPortalRouter;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Verifies the parental ACCESS gate is enforced on the WebPortalRouter
 * discovery + per-item surfaces: continue-watching / recommendations /
 * collection members are cap-filtered; detail / playback / markers / collection
 * for an over-cap item are 404; episode inheritance keeps allowed series
 * reachable; the owner is never gated.
 */
class WebPortalRouterParentalEnforcementTest extends TestCase
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
     * @param array<string, string|null> $effective id => effective rating stub
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
    private function profileManager(?array $filter): UserProfileManager
    {
        $pm = $this->createMock(UserProfileManager::class);
        $pm->method('getActiveRatingFilter')->willReturn($filter);
        $pm->method('getActiveProfile')->willReturn(['id' => 'p1']);
        return $pm;
    }

    private function userRepo(bool $isAdmin): UserRepository
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findById')->willReturn(['id' => 'u1', 'is_admin' => $isAdmin ? 1 : 0]);
        return $repo;
    }

    /**
     * @param array{allowedRatings: list<string>, allowUnrated: bool}|null $filter
     */
    private function makeRouter(
        ItemRepository $itemRepo,
        bool $isAdmin,
        ?array $filter,
        ?PlaybackController $playback = null,
        ?RecommendationService $recs = null,
        ?CollectionService $collections = null
    ): WebPortalRouter {
        return new WebPortalRouter(
            $this->createMock(LibraryManager::class),
            $itemRepo,
            $this->createMock(SessionManager::class),
            $playback ?? $this->createMock(PlaybackController::class),
            $this->createMock(AuthManager::class),
            $this->createMock(PlaybackMarkerService::class),
            $this->createMock(MarkerService::class),
            null,
            $this->userRepo($isAdmin),
            null,
            $this->profileManager($filter),
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $recs,
            $collections
        );
    }

    private function cappedRequest(): Request
    {
        $req = new Request();
        $req->userId = 'u1';
        return $req;
    }

    /**
     * Extract a column from a list under a top-level JSON body key.
     *
     * @return list<mixed>
     */
    private function column(\Phlix\Server\Http\Response $resp, string $listKey, string $col): array
    {
        $body = json_decode($resp->body, true);
        $this->assertIsArray($body);
        $list = $body[$listKey] ?? null;
        $this->assertIsArray($list);

        $out = [];
        foreach ($list as $row) {
            $this->assertIsArray($row);
            $out[] = $row[$col] ?? null;
        }
        return $out;
    }

    public function testContinueWatchingDropsOverCapForCappedProfile(): void
    {
        $playback = $this->createMock(PlaybackController::class);
        $playback->method('getContinueWatching')->willReturn([
            ['media_item_id' => 'a', 'name' => 'A'],
            ['media_item_id' => 'b', 'name' => 'B'],
        ]);
        $repo = $this->itemRepo(['a' => 'PG', 'b' => 'R']);

        $router = $this->makeRouter($repo, false, $this->pg13Filter(), $playback);
        $resp = $router->getContinueWatching($this->cappedRequest(), []);

        $this->assertSame(['a'], $this->column($resp, 'items', 'media_item_id'));
    }

    public function testContinueWatchingUnfilteredForOwner(): void
    {
        $playback = $this->createMock(PlaybackController::class);
        $playback->method('getContinueWatching')->willReturn([
            ['media_item_id' => 'a', 'name' => 'A'],
            ['media_item_id' => 'b', 'name' => 'B'],
        ]);
        $repo = $this->itemRepo(['a' => 'PG', 'b' => 'R']);

        $router = $this->makeRouter($repo, true, $this->pg13Filter(), $playback);
        $resp = $router->getContinueWatching($this->cappedRequest(), []);

        $this->assertCount(2, $this->column($resp, 'items', 'media_item_id'));
    }

    /**
     * Real (final) RecommendationService backed by a mock Connection that
     * returns the given recommendation rows.
     *
     * @param array<int, array<string, mixed>> $rows user_recommendations rows.
     */
    private function recommendationService(array $rows, ItemRepository $itemRepo): RecommendationService
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql) use ($rows): array {
                return str_contains($sql, 'user_recommendations') ? $rows : [];
            }
        );
        return new RecommendationService($db, new SimilarityService($db, $itemRepo));
    }

    /**
     * Real (final) CollectionService backed by a mock Connection returning the
     * given collection + member rows.
     *
     * @param array<string, mixed>             $collection media_collections row.
     * @param array<int, array<string, mixed>> $members    member rows.
     */
    private function collectionService(array $collection, array $members, ItemRepository $itemRepo): CollectionService
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql) use ($collection, $members): array {
                if (str_contains($sql, 'media_collection_members')) {
                    return $members;
                }
                if (str_contains($sql, 'media_collections')) {
                    return [$collection];
                }
                return [];
            }
        );
        return new CollectionService($db, $itemRepo, $this->createMock(TmdbProvider::class));
    }

    public function testRecommendationsDropOverCap(): void
    {
        $repo = $this->itemRepo(['a' => 'R', 'b' => 'G']);
        $recs = $this->recommendationService([
            ['media_item_id' => 'a', 'reason' => 'x', 'score' => 1.0, 'title' => 'A', 'metadata_json' => '{}'],
            ['media_item_id' => 'b', 'reason' => 'x', 'score' => 0.9, 'title' => 'B', 'metadata_json' => '{}'],
        ], $repo);

        $router = $this->makeRouter($repo, false, $this->pg13Filter(), null, $recs);
        $resp = $router->getRecommendations($this->cappedRequest(), []);

        $this->assertSame(['b'], $this->column($resp, 'recommendations', 'id'));
    }

    public function testCollectionMembersDropOverCap(): void
    {
        $repo = $this->itemRepo(['a' => 'PG-13', 'b' => 'NC-17']);
        $collections = $this->collectionService(
            ['id' => 1, 'tmdb_collection_id' => 10, 'name' => 'Set'],
            [
                ['id' => 'a', 'name' => 'A', 'type' => 'movie', 'tmdb_part_order' => 1],
                ['id' => 'b', 'name' => 'B', 'type' => 'movie', 'tmdb_part_order' => 2],
            ],
            $repo
        );

        $router = $this->makeRouter($repo, false, $this->pg13Filter(), null, null, $collections);
        $resp = $router->getCollection($this->cappedRequest(), ['id' => '1']);

        $this->assertSame(['a'], $this->column($resp, 'members', 'id'));
    }

    public function testGetMediaItemCollectionBlocksOverCapItem(): void
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn(['id' => 'm1', 'content_rating' => 'R']);
        $collections = $this->collectionService(['id' => 1, 'name' => 'Set'], [], $repo);

        $router = $this->makeRouter($repo, false, $this->pg13Filter(), null, null, $collections);
        $resp = $router->getMediaItemCollection($this->cappedRequest(), ['id' => 'm1']);

        $this->assertSame(404, $resp->statusCode);
    }

    public function testGetPlaybackInfoBlocksOverCapItem(): void
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn(['id' => 'm1', 'content_rating' => 'R', 'metadata_json' => '{}']);

        $router = $this->makeRouter($repo, false, $this->pg13Filter());
        $resp = $router->getPlaybackInfo($this->cappedRequest(), ['id' => 'm1']);

        $this->assertSame(404, $resp->statusCode);
    }

    public function testGetMediaChaptersBlocksOverCapItem(): void
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn(['id' => 'm1', 'content_rating' => 'R']);

        $router = $this->makeRouter($repo, false, $this->pg13Filter());
        $resp = $router->getMediaChapters($this->cappedRequest(), ['id' => 'm1']);

        $this->assertSame(404, $resp->statusCode);
    }

    public function testDetailAllowsEpisodeInheritingAllowedSeries(): void
    {
        $repo = $this->itemRepo(['show-1' => 'PG']);
        $repo->method('findById')->willReturn([
            'id' => 'ep-1', 'name' => 'E1', 'type' => 'episode',
            'content_rating' => null, 'parent_id' => 'show-1', 'metadata_json' => '{}',
        ]);
        $repo->method('getItemStreams')->willReturn([]);

        $router = $this->makeRouter($repo, false, $this->pg13Filter());
        $resp = $router->getMediaItem($this->cappedRequest(), ['id' => 'ep-1']);

        $this->assertSame(200, $resp->statusCode);
    }

    public function testDetailBlocksEpisodeInheritingBlockedSeries(): void
    {
        $repo = $this->itemRepo(['show-1' => 'R']);
        $repo->method('findById')->willReturn([
            'id' => 'ep-1', 'name' => 'E1', 'type' => 'episode',
            'content_rating' => null, 'parent_id' => 'show-1', 'metadata_json' => '{}',
        ]);
        $repo->method('getItemStreams')->willReturn([]);

        $router = $this->makeRouter($repo, false, $this->pg13Filter());
        $resp = $router->getMediaItem($this->cappedRequest(), ['id' => 'ep-1']);

        $this->assertSame(404, $resp->statusCode);
    }
}
