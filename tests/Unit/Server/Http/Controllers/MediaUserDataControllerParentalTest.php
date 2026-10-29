<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\RatingGate;
use Phlix\Media\UserItemDataRepository;
use Phlix\Server\Http\Controllers\MediaUserDataController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Parental-control coverage for the account-level favorites list: a capped
 * active profile never sees over-cap favorites; the owner sees everything.
 */
class MediaUserDataControllerParentalTest extends TestCase
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
     * Connection whose id SELECT answers from a fixture keyed by the bound id.
     *
     * Handles both hydration shapes of {@see ItemRepository}: the single-row
     * `WHERE id = ?` lookup and, since M-SERVER-16, the batched favorites
     * page `WHERE id IN (?,...)` (rows returned in bind order, mirroring
     * `findByIds()`'s order-preserving contract).
     *
     * @param array<string, array<string, mixed>> $rowsById
     * @return Connection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function connection(array $rowsById)
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql, array $bindings = []) use ($rowsById): array {
                if (str_contains($sql, 'FROM media_items WHERE id = ?')) {
                    $id = $bindings[0] ?? '';
                    return isset($rowsById[$id]) ? [$rowsById[$id]] : [];
                }
                if (str_contains($sql, 'FROM media_items WHERE id IN (')) {
                    $out = [];
                    foreach ($bindings as $binding) {
                        if (is_string($binding) && isset($rowsById[$binding])) {
                            $out[] = $rowsById[$binding];
                        }
                    }
                    return $out;
                }
                return [];
            }
        );
        return $db;
    }

    /**
     * @param array{allowedRatings: list<string>, allowUnrated: bool}|null $filter
     */
    private function gate(?array $filter, bool $isAdmin = false): RatingGate
    {
        $pm = $this->createMock(UserProfileManager::class);
        $pm->method('getActiveRatingFilter')->willReturn($filter);
        $users = $this->createMock(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 'u1', 'is_admin' => $isAdmin ? 1 : 0]);

        return new RatingGate($this->createMock(ItemRepository::class), $pm, $users);
    }

    private function request(): Request
    {
        $req = new Request();
        $req->userId = 'u1';
        return $req;
    }

    /**
     * @return list<string> The item ids present in the favorites response.
     */
    private function listFavoriteIds(RatingGate $gate): array
    {
        $repo = new ItemRepository($this->connection([
            'fav-pg' => ['id' => 'fav-pg', 'name' => 'PG Fav', 'type' => 'movie',
                'content_rating' => 'PG', 'metadata_json' => '{}'],
            'fav-r' => ['id' => 'fav-r', 'name' => 'R Fav', 'type' => 'movie',
                'content_rating' => 'R', 'metadata_json' => '{}'],
        ]));

        $userData = $this->createMock(UserItemDataRepository::class);
        $userData->method('getFavorites')->willReturn([
            ['item_id' => 'fav-pg', 'rating' => null, 'like_level' => 0, 'watched' => 0],
            ['item_id' => 'fav-r', 'rating' => null, 'like_level' => 0, 'watched' => 0],
        ]);

        $controller = new MediaUserDataController($repo, $userData, $gate);
        $resp = $controller->listFavorites($this->request(), []);
        $this->assertSame(200, $resp->statusCode);

        return $this->favoriteIdsFromResponse($resp);
    }

    /**
     * @return list<string>
     */
    private function favoriteIdsFromResponse(\Phlix\Server\Http\Response $resp): array
    {
        $body = json_decode($resp->body, true);
        $this->assertIsArray($body);
        $items = $body['items'] ?? null;
        $this->assertIsArray($items);

        $ids = [];
        foreach ($items as $item) {
            $this->assertIsArray($item);
            $id = $item['id'] ?? null;
            if (is_string($id)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    public function testCappedProfileHidesOverCapFavorite(): void
    {
        $ids = $this->listFavoriteIds($this->gate($this->pg13Filter()));
        $this->assertSame(['fav-pg'], $ids); // R favorite dropped
    }

    public function testOwnerSeesAllFavorites(): void
    {
        $ids = $this->listFavoriteIds($this->gate($this->pg13Filter(), true));
        sort($ids);
        $this->assertSame(['fav-pg', 'fav-r'], $ids);
    }

    public function testNoGateSeesAllFavorites(): void
    {
        $repo = new ItemRepository($this->connection([
            'fav-r' => ['id' => 'fav-r', 'name' => 'R Fav', 'type' => 'movie',
                'content_rating' => 'R', 'metadata_json' => '{}'],
        ]));
        $userData = $this->createMock(UserItemDataRepository::class);
        $userData->method('getFavorites')->willReturn([
            ['item_id' => 'fav-r', 'rating' => null, 'like_level' => 0, 'watched' => 0],
        ]);

        // No RatingGate wired at all → strict no-op.
        $controller = new MediaUserDataController($repo, $userData, null);
        $resp = $controller->listFavorites($this->request(), []);

        $this->assertSame(['fav-r'], $this->favoriteIdsFromResponse($resp));
    }

    /**
     * M-SERVER-16 — perf-shape pin on the REAL {@see ItemRepository} (fake
     * Connection, no doubles inside the repo): a page of N favorites must
     * hydrate through EXACTLY ONE `media_items` query. Before the cure the
     * controller looped `findById()` per row, so this probe counted N (12)
     * queries — up to 100 sequential pool borrows + queries per page, each
     * inside a resident Workerman worker serving every other user. The
     * batched `findByIds()` issues one `WHERE id IN (...)` for the page
     * (plus at most the two constant `profile_tags` queries, not counted
     * here — they do not scale with N either way).
     */
    public function testFavoritesPageHydratesThroughExactlyOneMediaItemsQuery(): void
    {
        $rowsById = [];
        $favorites = [];
        for ($i = 1; $i <= 12; $i++) {
            $id = sprintf('fav-%02d', $i);
            $rowsById[$id] = ['id' => $id, 'name' => 'Fav ' . $i, 'type' => 'movie',
                'content_rating' => 'PG', 'metadata_json' => '{}'];
            $favorites[] = ['item_id' => $id, 'rating' => null, 'like_level' => 0, 'watched' => 0];
        }

        $mediaItemQueries = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $bindings = []) use ($rowsById, &$mediaItemQueries): array {
                if (!str_contains($sql, 'FROM media_items')) {
                    return [];
                }
                $mediaItemQueries++;
                if (str_contains($sql, 'WHERE id IN (')) {
                    $out = [];
                    foreach ($bindings as $binding) {
                        if (is_string($binding) && isset($rowsById[$binding])) {
                            $out[] = $rowsById[$binding];
                        }
                    }
                    return $out;
                }
                $id = $bindings[0] ?? '';
                return is_string($id) && isset($rowsById[$id]) ? [$rowsById[$id]] : [];
            }
        );

        $repo = new ItemRepository($db);
        $userData = $this->createMock(UserItemDataRepository::class);
        $userData->method('getFavorites')->willReturn($favorites);

        $controller = new MediaUserDataController($repo, $userData, null);
        $resp = $controller->listFavorites($this->request(), []);

        // Response shape unaffected by the batching: all 12 items, row order.
        $this->assertSame(
            array_map(static fn (array $f): string => $f['item_id'], $favorites),
            $this->favoriteIdsFromResponse($resp)
        );
        $this->assertSame(
            1,
            $mediaItemQueries,
            'M-SERVER-16: a favorites page must issue one batched media_items query, not one per row'
        );
    }
}
