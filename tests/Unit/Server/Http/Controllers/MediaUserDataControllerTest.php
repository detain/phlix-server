<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\UserItemDataRepository;
use Phlix\Server\Http\Controllers\MediaUserDataController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see MediaUserDataController} (E10 favorites/ratings routes).
 *
 * Covers the auth (401) / missing-id (400) / missing-item (404) guards and the
 * 200 success envelope for each of the four routes, plus rating-body validation.
 */
class MediaUserDataControllerTest extends TestCase
{
    private function existingItemRepo(): ItemRepository
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn(['id' => 'item-1', 'name' => 'X']);
        return $repo;
    }

    private function authedRequest(): Request
    {
        $req = new Request();
        $req->userId = 'user-1';
        return $req;
    }

    public function testAddFavoriteReturns401WhenUnauthenticated(): void
    {
        $userData = $this->createMock(UserItemDataRepository::class);
        $userData->expects($this->never())->method('setFavorite');

        $controller = new MediaUserDataController($this->existingItemRepo(), $userData);
        $response = $controller->addFavorite(new Request(), ['id' => 'item-1']);

        $this->assertSame(401, $response->statusCode);
    }

    public function testAddFavoriteReturns400WhenIdMissing(): void
    {
        $controller = new MediaUserDataController(
            $this->existingItemRepo(),
            $this->createMock(UserItemDataRepository::class)
        );
        $response = $controller->addFavorite($this->authedRequest(), ['id' => '']);

        $this->assertSame(400, $response->statusCode);
    }

    public function testAddFavoriteReturns404WhenItemMissing(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('findById')->willReturn(null);

        $controller = new MediaUserDataController(
            $itemRepo,
            $this->createMock(UserItemDataRepository::class)
        );
        $response = $controller->addFavorite($this->authedRequest(), ['id' => 'nope']);

        $this->assertSame(404, $response->statusCode);
    }

    public function testAddFavoritePersistsAndReturnsMessage(): void
    {
        $userData = $this->createMock(UserItemDataRepository::class);
        $userData->expects($this->once())
            ->method('setFavorite')
            ->with('user-1', 'item-1', true);

        $controller = new MediaUserDataController($this->existingItemRepo(), $userData);
        $response = $controller->addFavorite($this->authedRequest(), ['id' => 'item-1']);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('message', $body);
    }

    public function testRemoveFavoritePersistsFalse(): void
    {
        $userData = $this->createMock(UserItemDataRepository::class);
        $userData->expects($this->once())
            ->method('setFavorite')
            ->with('user-1', 'item-1', false);

        $controller = new MediaUserDataController($this->existingItemRepo(), $userData);
        $response = $controller->removeFavorite($this->authedRequest(), ['id' => 'item-1']);

        $this->assertSame(200, $response->statusCode);
    }

    public function testSetRatingPersistsIntValue(): void
    {
        $userData = $this->createMock(UserItemDataRepository::class);
        $userData->expects($this->once())
            ->method('setRating')
            ->with('user-1', 'item-1', 8);

        $controller = new MediaUserDataController($this->existingItemRepo(), $userData);
        $req = $this->authedRequest();
        $req->body = ['rating' => 8];

        $response = $controller->setRating($req, ['id' => 'item-1']);

        $this->assertSame(200, $response->statusCode);
    }

    public function testSetRatingAcceptsNullToClear(): void
    {
        $userData = $this->createMock(UserItemDataRepository::class);
        $userData->expects($this->once())
            ->method('setRating')
            ->with('user-1', 'item-1', null);

        $controller = new MediaUserDataController($this->existingItemRepo(), $userData);
        $req = $this->authedRequest();
        $req->body = ['rating' => null];

        $response = $controller->setRating($req, ['id' => 'item-1']);

        $this->assertSame(200, $response->statusCode);
    }

    public function testSetRatingRejectsNonNumeric(): void
    {
        $userData = $this->createMock(UserItemDataRepository::class);
        $userData->expects($this->never())->method('setRating');

        $controller = new MediaUserDataController($this->existingItemRepo(), $userData);
        $req = $this->authedRequest();
        $req->body = ['rating' => 'great'];

        $response = $controller->setRating($req, ['id' => 'item-1']);

        $this->assertSame(400, $response->statusCode);
    }

    public function testSetRatingRejectsNonIntegerNumeric(): void
    {
        $userData = $this->createMock(UserItemDataRepository::class);
        $userData->expects($this->never())->method('setRating');

        $controller = new MediaUserDataController($this->existingItemRepo(), $userData);
        $req = $this->authedRequest();
        $req->body = ['rating' => 4.5];

        $response = $controller->setRating($req, ['id' => 'item-1']);

        $this->assertSame(400, $response->statusCode);
    }

    public function testSetRatingRejectsOutOfRangeFromRepository(): void
    {
        // The repository enforces the 1-10 range; the controller maps the
        // InvalidArgumentException to a 400 rather than letting it surface.
        $userData = $this->createMock(UserItemDataRepository::class);
        $userData->method('setRating')
            ->willThrowException(new \InvalidArgumentException('out of range'));

        $controller = new MediaUserDataController($this->existingItemRepo(), $userData);
        $req = $this->authedRequest();
        $req->body = ['rating' => 99];

        $response = $controller->setRating($req, ['id' => 'item-1']);

        $this->assertSame(400, $response->statusCode);
    }

    public function testSetLikeLevelReturns401WhenUnauthenticated(): void
    {
        $userData = $this->createMock(UserItemDataRepository::class);
        $userData->expects($this->never())->method('setLikeLevel');

        $controller = new MediaUserDataController($this->existingItemRepo(), $userData);
        $req = new Request();
        $req->body = ['level' => 2];

        $response = $controller->setLikeLevel($req, ['id' => 'item-1']);

        $this->assertSame(401, $response->statusCode);
    }

    public function testSetLikeLevelReturns400WhenIdMissing(): void
    {
        $controller = new MediaUserDataController(
            $this->existingItemRepo(),
            $this->createMock(UserItemDataRepository::class)
        );
        $req = $this->authedRequest();
        $req->body = ['level' => 2];

        $response = $controller->setLikeLevel($req, ['id' => '']);

        $this->assertSame(400, $response->statusCode);
    }

    public function testSetLikeLevelReturns404WhenItemMissing(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('findById')->willReturn(null);

        $controller = new MediaUserDataController(
            $itemRepo,
            $this->createMock(UserItemDataRepository::class)
        );
        $req = $this->authedRequest();
        $req->body = ['level' => 2];

        $response = $controller->setLikeLevel($req, ['id' => 'nope']);

        $this->assertSame(404, $response->statusCode);
    }

    /**
     * Each valid level 0-3 persists and returns a 200 `{message}` envelope.
     *
     * @dataProvider validLikeLevels
     */
    public function testSetLikeLevelPersistsValidLevel(int $level): void
    {
        $userData = $this->createMock(UserItemDataRepository::class);
        $userData->expects($this->once())
            ->method('setLikeLevel')
            ->with('user-1', 'item-1', $level);

        $controller = new MediaUserDataController($this->existingItemRepo(), $userData);
        $req = $this->authedRequest();
        $req->body = ['level' => $level];

        $response = $controller->setLikeLevel($req, ['id' => 'item-1']);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('message', $body);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function validLikeLevels(): array
    {
        return [
            'level 0' => [0],
            'level 1' => [1],
            'level 2' => [2],
            'level 3' => [3],
        ];
    }

    /**
     * Non-integer, non-numeric and boolean input are rejected by the
     * controller's coercion guard up front (400) and never reach the
     * repository (mirrors setRating's coercion guard — the controller does not
     * itself enforce the numeric range; that is the repository's job, exactly
     * like setRating defers the 1-10 range to setRating()).
     *
     * @param mixed $level
     *
     * @dataProvider malformedLikeLevels
     */
    public function testSetLikeLevelRejectsMalformedInput($level): void
    {
        $userData = $this->createMock(UserItemDataRepository::class);
        $userData->expects($this->never())->method('setLikeLevel');

        $controller = new MediaUserDataController($this->existingItemRepo(), $userData);
        $req = $this->authedRequest();
        $req->body = ['level' => $level];

        $response = $controller->setLikeLevel($req, ['id' => 'item-1']);

        $this->assertSame(400, $response->statusCode);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function malformedLikeLevels(): array
    {
        return [
            'non-numeric string' => ['x'],
            'non-integer numeric' => [1.5],
            'boolean true' => [true],
        ];
    }

    /**
     * Out-of-range but otherwise-integer input (4, -1) is a clean int to the
     * controller, so it reaches the repository, whose InvalidArgumentException
     * (0-3 range) is mapped to a 400 — exactly mirroring setRating's
     * testSetRatingRejectsOutOfRangeFromRepository. Net result for the client:
     * 4/-1 → 400.
     *
     * @dataProvider outOfRangeLikeLevels
     */
    public function testSetLikeLevelOutOfRangeReturns400ViaRepository(int $level): void
    {
        $userData = $this->createMock(UserItemDataRepository::class);
        $userData->expects($this->once())
            ->method('setLikeLevel')
            ->with('user-1', 'item-1', $level)
            ->willThrowException(new \InvalidArgumentException('out of range'));

        $controller = new MediaUserDataController($this->existingItemRepo(), $userData);
        $req = $this->authedRequest();
        $req->body = ['level' => $level];

        $response = $controller->setLikeLevel($req, ['id' => 'item-1']);

        $this->assertSame(400, $response->statusCode);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function outOfRangeLikeLevels(): array
    {
        return [
            'above range (4)' => [4],
            'below range (-1)' => [-1],
        ];
    }

    public function testSetLikeLevelReturns400WhenLevelMissing(): void
    {
        $userData = $this->createMock(UserItemDataRepository::class);
        $userData->expects($this->never())->method('setLikeLevel');

        $controller = new MediaUserDataController($this->existingItemRepo(), $userData);
        // No `level` key in the body at all.
        $response = $controller->setLikeLevel($this->authedRequest(), ['id' => 'item-1']);

        $this->assertSame(400, $response->statusCode);
    }

    public function testClearRatingPersistsNull(): void
    {
        $userData = $this->createMock(UserItemDataRepository::class);
        $userData->expects($this->once())
            ->method('setRating')
            ->with('user-1', 'item-1', null);

        $controller = new MediaUserDataController($this->existingItemRepo(), $userData);
        $response = $controller->clearRating($this->authedRequest(), ['id' => 'item-1']);

        $this->assertSame(200, $response->statusCode);
    }

    public function testListFavoritesReturns401WhenUnauthenticated(): void
    {
        $userData = $this->createMock(UserItemDataRepository::class);
        $userData->expects($this->never())->method('getFavorites');

        $controller = new MediaUserDataController($this->existingItemRepo(), $userData);
        $response = $controller->listFavorites(new Request(), []);

        $this->assertSame(401, $response->statusCode);
    }

    public function testListFavoritesReturnsShapedItemsWithUserData(): void
    {
        $userData = $this->createMock(UserItemDataRepository::class);
        $userData->method('getFavorites')->willReturn([
            // item-1 carries a real like_level (3); item-2 omits the column entirely
            // (exercises the default-0 path).
            ['item_id' => 'item-1', 'rating' => 7, 'like_level' => 3, 'updated_at' => '2026-06-27 00:00:00'],
            ['item_id' => 'item-2', 'rating' => null, 'updated_at' => '2026-06-26 00:00:00'],
        ]);

        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('findById')->willReturnCallback(
            fn (string $id): ?array => ['id' => $id, 'name' => 'Title ' . $id, 'type' => 'movie']
        );

        $controller = new MediaUserDataController($itemRepo, $userData);
        $response = $controller->listFavorites($this->authedRequest(), []);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertCount(2, $body['items']);

        $first = $body['items'][0];
        $this->assertSame('item-1', $first['id']);
        $this->assertSame(
            ['favorite' => true, 'rating' => 7, 'like_level' => 3],
            $first['user_data']
        );

        $second = $body['items'][1];
        // like_level absent on the row → defaults to 0.
        $this->assertSame(
            ['favorite' => true, 'rating' => null, 'like_level' => 0],
            $second['user_data']
        );

        $this->assertSame(50, $body['limit']);
        $this->assertSame(0, $body['offset']);
    }

    public function testListFavoritesRespectsLimitAndOffset(): void
    {
        $userData = $this->createMock(UserItemDataRepository::class);
        // Limit clamps to 100 (200 requested), offset floors to 0 (negative requested).
        $userData->expects($this->once())
            ->method('getFavorites')
            ->with('user-1', 100, 0)
            ->willReturn([]);

        $controller = new MediaUserDataController($this->existingItemRepo(), $userData);
        $req = $this->authedRequest();
        $req->query = ['limit' => '200', 'offset' => '-5'];

        $response = $controller->listFavorites($req, []);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame(100, $body['limit']);
        $this->assertSame(0, $body['offset']);
        $this->assertSame([], $body['items']);
    }

    public function testListFavoritesClampsLimitToAtLeastOne(): void
    {
        $userData = $this->createMock(UserItemDataRepository::class);
        $userData->expects($this->once())
            ->method('getFavorites')
            ->with('user-1', 1, 10)
            ->willReturn([]);

        $controller = new MediaUserDataController($this->existingItemRepo(), $userData);
        $req = $this->authedRequest();
        $req->query = ['limit' => '0', 'offset' => '10'];

        $response = $controller->listFavorites($req, []);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame(1, json_decode($response->body, true)['limit']);
    }

    public function testListFavoritesSkipsMissingMediaItems(): void
    {
        $userData = $this->createMock(UserItemDataRepository::class);
        $userData->method('getFavorites')->willReturn([
            ['item_id' => 'gone', 'rating' => null, 'updated_at' => '2026-06-27 00:00:00'],
            ['item_id' => 'item-2', 'rating' => 5, 'updated_at' => '2026-06-26 00:00:00'],
        ]);

        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('findById')->willReturnCallback(
            fn (string $id): ?array => $id === 'item-2' ? ['id' => 'item-2', 'name' => 'Kept'] : null
        );

        $controller = new MediaUserDataController($itemRepo, $userData);
        $response = $controller->listFavorites($this->authedRequest(), []);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertCount(1, $body['items']);
        $this->assertSame('item-2', $body['items'][0]['id']);
    }
}
