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
}
