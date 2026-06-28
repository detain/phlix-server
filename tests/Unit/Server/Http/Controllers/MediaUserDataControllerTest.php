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
}
