<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal;

use Phlix\Auth\AuthManager;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Media\UserItemDataRepository;
use Phlix\Server\Http\Controllers\MediaUserDataController;
use Phlix\Server\Http\Request;
use Phlix\Server\WebPortal\WebPortalRouter;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the per-user favorites/ratings wiring on {@see WebPortalRouter}
 * (E10): the `user_data` block on the media-detail response, the 503 "not
 * configured" fallback, and the AuthMiddleware gate on the favorite/rating
 * routes.
 */
class WebPortalRouterUserDataTest extends TestCase
{
    private function makeRouter(
        ItemRepository $itemRepository,
        ?UserItemDataRepository $userItemData = null,
        ?MediaUserDataController $controller = null
    ): WebPortalRouter {
        return new WebPortalRouter(
            $this->createMock(LibraryManager::class),
            $itemRepository,
            $this->createMock(SessionManager::class),
            $this->createMock(PlaybackController::class),
            $this->createMock(AuthManager::class),
            $this->createMock(PlaybackMarkerService::class),
            null,
            null,
            null,
            $userItemData,
            $controller,
        );
    }

    /**
     * @return array{item: array<string, mixed>}
     */
    private function decodeBody(string $json): array
    {
        /** @var array{item: array<string, mixed>} $decoded */
        $decoded = json_decode($json, true);
        return $decoded;
    }

    private function itemRepoWithItem(): ItemRepository
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn([
            'id' => 'item-1',
            'name' => 'Movie',
            'type' => 'movie',
            'path' => '/m/a.mkv',
            'metadata' => [],
        ]);
        $repo->method('getItemStreams')->willReturn([]);
        return $repo;
    }

    public function testMediaItemCarriesUserDataWhenAuthedAndWired(): void
    {
        $userItemData = $this->createMock(UserItemDataRepository::class);
        $userItemData->method('getItemData')->with('user-1', 'item-1')
            ->willReturn(['favorite' => true, 'rating' => 9, 'like_level' => 2, 'watched' => true]);

        $router = $this->makeRouter($this->itemRepoWithItem(), $userItemData);

        $req = new Request();
        $req->userId = 'user-1';
        $body = $this->decodeBody($router->getMediaItem($req, ['id' => 'item-1'])->body);

        $this->assertSame(
            ['favorite' => true, 'rating' => 9, 'like_level' => 2, 'watched' => true],
            $body['item']['user_data']
        );
    }

    public function testMediaItemUserDataDefaultsWhenNoRow(): void
    {
        $userItemData = $this->createMock(UserItemDataRepository::class);
        $userItemData->method('getItemData')->willReturn(null);

        $router = $this->makeRouter($this->itemRepoWithItem(), $userItemData);

        $req = new Request();
        $req->userId = 'user-1';
        $body = $this->decodeBody($router->getMediaItem($req, ['id' => 'item-1'])->body);

        $this->assertSame(
            ['favorite' => false, 'rating' => null, 'like_level' => 0, 'watched' => false],
            $body['item']['user_data']
        );
    }

    public function testMediaItemUserDataIsNullWhenUnauthenticated(): void
    {
        $userItemData = $this->createMock(UserItemDataRepository::class);
        $userItemData->expects($this->never())->method('getItemData');

        $router = $this->makeRouter($this->itemRepoWithItem(), $userItemData);

        // Handler called directly (no userId) — the detail shape still carries the
        // key, but null, so the client can distinguish "unknown" from "not set".
        $body = $this->decodeBody($router->getMediaItem(new Request(), ['id' => 'item-1'])->body);

        $this->assertNull($body['item']['user_data']);
        // The flat `actors` landmine + other keys must remain untouched.
        $this->assertArrayHasKey('streams', $body['item']);
    }

    public function testAddFavoriteReturns503WhenControllerNotWired(): void
    {
        $router = $this->makeRouter($this->itemRepoWithItem());

        $req = new Request();
        $req->userId = 'user-1';
        $response = $router->addFavorite($req, ['id' => 'item-1']);

        $this->assertSame(503, $response->statusCode);
    }

    public function testAddFavoriteDelegatesToControllerWhenWired(): void
    {
        $controller = $this->createMock(MediaUserDataController::class);
        $controller->expects($this->once())
            ->method('addFavorite')
            ->willReturn((new \Phlix\Server\Http\Response())->json(['message' => 'ok']));

        $router = $this->makeRouter($this->itemRepoWithItem(), null, $controller);

        $req = new Request();
        $req->userId = 'user-1';
        $response = $router->addFavorite($req, ['id' => 'item-1']);

        $this->assertSame(200, $response->statusCode);
    }

    public function testFavoriteRouteRequiresAuthViaDispatch(): void
    {
        // No userId → AuthMiddleware 401 and the controller is never invoked.
        $controller = $this->createMock(MediaUserDataController::class);
        $controller->expects($this->never())->method('addFavorite');

        $router = $this->makeRouter($this->itemRepoWithItem(), null, $controller);

        $req = new Request();
        $req->method = 'POST';
        $req->path = '/api/v1/media/item-1/favorite';

        $response = $router->dispatch($req);

        $this->assertSame(401, $response->statusCode);
    }

    public function testRatingRouteDispatchesToHandlerForAuthedUser(): void
    {
        $controller = $this->createMock(MediaUserDataController::class);
        $controller->expects($this->once())
            ->method('setRating')
            ->willReturn((new \Phlix\Server\Http\Response())->json(['message' => 'Rating saved']));

        $router = $this->makeRouter($this->itemRepoWithItem(), null, $controller);

        $req = new Request();
        $req->method = 'PUT';
        $req->path = '/api/v1/media/item-1/rating';
        $req->userId = 'user-1';
        $req->body = ['rating' => 5];

        $response = $router->dispatch($req);

        $this->assertSame(200, $response->statusCode);
    }

    public function testSetLikeLevelReturns503WhenControllerNotWired(): void
    {
        $router = $this->makeRouter($this->itemRepoWithItem());

        $req = new Request();
        $req->userId = 'user-1';
        $req->body = ['level' => 2];
        $response = $router->setLikeLevel($req, ['id' => 'item-1']);

        $this->assertSame(503, $response->statusCode);
    }

    public function testSetLikeLevelDelegatesToControllerWhenWired(): void
    {
        $controller = $this->createMock(MediaUserDataController::class);
        $controller->expects($this->once())
            ->method('setLikeLevel')
            ->willReturn((new \Phlix\Server\Http\Response())->json(['message' => 'Love level saved']));

        $router = $this->makeRouter($this->itemRepoWithItem(), null, $controller);

        $req = new Request();
        $req->userId = 'user-1';
        $req->body = ['level' => 2];
        $response = $router->setLikeLevel($req, ['id' => 'item-1']);

        $this->assertSame(200, $response->statusCode);
    }

    public function testLikeRouteRequiresAuthViaDispatch(): void
    {
        // No userId → AuthMiddleware 401 and the controller is never invoked.
        $controller = $this->createMock(MediaUserDataController::class);
        $controller->expects($this->never())->method('setLikeLevel');

        $router = $this->makeRouter($this->itemRepoWithItem(), null, $controller);

        $req = new Request();
        $req->method = 'PUT';
        $req->path = '/api/v1/media/item-1/like';

        $response = $router->dispatch($req);

        $this->assertSame(401, $response->statusCode);
    }

    /**
     * Proves the `PUT /api/v1/media/{id}/like` route is registered on the
     * single {@see WebPortalRouter} that BOTH HTTP entry points
     * (public/index.php and the Workerman HttpHandler) dispatch `/api/*` to —
     * so the route works in both modes, not just one.
     */
    public function testLikeRouteDispatchesToHandlerForAuthedUser(): void
    {
        $controller = $this->createMock(MediaUserDataController::class);
        $controller->expects($this->once())
            ->method('setLikeLevel')
            ->willReturn((new \Phlix\Server\Http\Response())->json(['message' => 'Love level saved']));

        $router = $this->makeRouter($this->itemRepoWithItem(), null, $controller);

        $req = new Request();
        $req->method = 'PUT';
        $req->path = '/api/v1/media/item-1/like';
        $req->userId = 'user-1';
        $req->body = ['level' => 3];

        $response = $router->dispatch($req);

        $this->assertSame(200, $response->statusCode);
    }
}
