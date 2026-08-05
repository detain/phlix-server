<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\AuthManager;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\RatingGate;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Server\Http\Request;
use Phlix\Server\WebPortal\WebPortalRouter;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;

/**
 * Covers the parental content-rating filter wired into the browse/listing/detail
 * read path: a capped, non-admin profile is restricted to its allow-list, while
 * the account owner/admin, unauthenticated requests, an absent profile manager,
 * and profiles with no (or a most-permissive) cap all keep today's unfiltered
 * behaviour.
 */
class WebPortalRouterRatingFilterTest extends TestCase
{
    /**
     * @param UserRepository|null      $userRepository
     * @param UserProfileManager|null  $profileManager
     */
    private function makeRouter(
        ItemRepository $itemRepository,
        ?UserRepository $userRepository = null,
        ?UserProfileManager $profileManager = null
    ): WebPortalRouter {
        return new WebPortalRouter(
            $this->createMock(LibraryManager::class),
            $itemRepository,
            $this->createMock(SessionManager::class),
            $this->createMock(PlaybackController::class),
            $this->createMock(AuthManager::class),
            $this->createMock(PlaybackMarkerService::class),
            $this->createMock(MarkerService::class),
            null,
            $userRepository,
            null,
            $profileManager
        );
    }

    /**
     * @param bool $isAdmin
     * @return UserRepository&\PHPUnit\Framework\MockObject\MockObject
     */
    private function userRepo(bool $isAdmin)
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findById')->willReturn(['id' => 'user-1', 'is_admin' => $isAdmin ? 1 : 0]);
        return $repo;
    }

    /**
     * @param array{allowedRatings: list<string>, allowUnrated: bool}|null $filter
     * @return UserProfileManager&\PHPUnit\Framework\MockObject\MockObject
     */
    private function profileManagerWithFilter(?array $filter)
    {
        $pm = $this->createMock(UserProfileManager::class);
        $pm->method('getActiveRatingFilter')->willReturn($filter);
        $pm->method('getActiveProfile')->willReturn(null);
        return $pm;
    }

    private function cappedRequest(): Request
    {
        $req = new Request();
        $req->userId = 'user-1';
        return $req;
    }

    /**
     * Build a router wired with a PG-13 parental filter and a user whose admin
     * status is controlled by $isAdmin.
     */
    private function pg13Router(ItemRepository $itemRepo, bool $isAdmin): WebPortalRouter
    {
        return $this->makeRouter(
            $itemRepo,
            $this->userRepo($isAdmin),
            $this->profileManagerWithFilter($this->pg13Filter())
        );
    }

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

    public function testGetMediaThreadsCapForCappedNonAdminProfile(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->expects($this->once())
            ->method('query')
            ->with($this->callback(function (array $params): bool {
                return isset($params['allowedRatings'])
                    && in_array('PG-13', $params['allowedRatings'], true)
                    && !in_array('R', $params['allowedRatings'], true)
                    && ($params['allowUnrated'] ?? null) === true;
            }))
            ->willReturn(['items' => [], 'total' => 0, 'limit' => 50, 'offset' => 0]);

        $router = $this->pg13Router($itemRepo, false);
        $router->getMedia($this->cappedRequest(), []);
    }

    public function testGetMediaAppliesNoFilterForOwnerAdmin(): void
    {
        // Account owner/admin: query must receive params WITHOUT a cap.
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->expects($this->once())
            ->method('query')
            ->with($this->callback(function (array $params): bool {
                return !isset($params['allowedRatings']) && !isset($params['allowUnrated']);
            }))
            ->willReturn(['items' => [], 'total' => 0, 'limit' => 50, 'offset' => 0]);

        $router = $this->pg13Router($itemRepo, true);
        $router->getMedia($this->cappedRequest(), []);
    }

    public function testGetMediaAppliesNoFilterWhenNoProfileManager(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->expects($this->once())
            ->method('query')
            ->with($this->callback(fn (array $params): bool => !isset($params['allowedRatings'])))
            ->willReturn(['items' => [], 'total' => 0, 'limit' => 50, 'offset' => 0]);

        $router = $this->makeRouter($itemRepo, $this->userRepo(false), null);
        $router->getMedia($this->cappedRequest(), []);
    }

    public function testGetMediaAppliesNoFilterWhenProfileHasNoCap(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->expects($this->once())
            ->method('query')
            ->with($this->callback(fn (array $params): bool => !isset($params['allowedRatings'])))
            ->willReturn(['items' => [], 'total' => 0, 'limit' => 50, 'offset' => 0]);

        $router = $this->makeRouter($itemRepo, $this->userRepo(false), $this->profileManagerWithFilter(null));
        $router->getMedia($this->cappedRequest(), []);
    }

    /**
     * 🚨 S235 — this REPLACES `testGetMediaAppliesNoFilterForUnauthenticatedRequest`,
     * which asserted the defect: "no userId → permissive". That equivalence of
     * "no user" with "no cap" is exactly what let an anonymous caller reach
     * `/api/v1/media/{id}/download`'s signed URL, so it is no longer the rule
     * anywhere. An unidentified browse now carries a DENY-ALL cap into the
     * listing SQL.
     *
     * Every route reaching this helper is inside this router's `AuthMiddleware`
     * group, so the state is unreachable in production — this pins the safe
     * DEFAULT, which is the point of the step.
     *
     * The cap's allow-list must also be NON-EMPTY: `ItemRepository::ratingCapClause()`
     * treats an empty allow-list as "no filtering", so a `[]` cap would fail OPEN
     * right here, in the one place the cap becomes SQL.
     */
    public function testGetMediaAppliesADenyAllCapForAnUnauthenticatedRequest(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->expects($this->once())
            ->method('query')
            ->with($this->callback(function (array $params): bool {
                return isset($params['allowedRatings'])
                    && $params['allowedRatings'] === [RatingGate::NO_USER_RATING]
                    && ($params['allowUnrated'] ?? null) === false;
            }))
            ->willReturn(['items' => [], 'total' => 0, 'limit' => 50, 'offset' => 0]);

        $router = $this->pg13Router($itemRepo, false);
        $router->getMedia(new Request(), []);
    }

    public function testGetMediaItemBlocksTooMatureItemForCappedProfile(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('findById')->willReturn([
            'id' => 'item-1',
            'name' => 'Mature Movie',
            'type' => 'movie',
            'content_rating' => 'R',
        ]);

        $router = $this->pg13Router($itemRepo, false);
        $response = $router->getMediaItem($this->cappedRequest(), ['id' => 'item-1']);

        $this->assertSame(404, $response->statusCode);
    }

    public function testGetMediaItemAllowsWithinCapItemForCappedProfile(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('findById')->willReturn([
            'id' => 'item-1',
            'name' => 'Family Movie',
            'type' => 'movie',
            'content_rating' => 'PG',
            'metadata_json' => '{}',
        ]);
        $itemRepo->method('getItemStreams')->willReturn([]);

        $router = $this->pg13Router($itemRepo, false);
        $response = $router->getMediaItem($this->cappedRequest(), ['id' => 'item-1']);

        $this->assertSame(200, $response->statusCode);
    }

    public function testGetMediaItemAllowsNullRatedItemForCappedProfile(): void
    {
        // A NULL/absent content_rating (e.g. an episode inheriting series context)
        // stays reachable on the detail path so drill-downs are never broken.
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('findById')->willReturn([
            'id' => 'ep-1',
            'name' => 'Episode 1',
            'type' => 'episode',
            'content_rating' => null,
            'metadata_json' => '{}',
        ]);
        $itemRepo->method('getItemStreams')->willReturn([]);

        $router = $this->pg13Router($itemRepo, false);
        $response = $router->getMediaItem($this->cappedRequest(), ['id' => 'ep-1']);

        $this->assertSame(200, $response->statusCode);
    }

    public function testGetMediaItemAllowsTooMatureItemForOwnerAdmin(): void
    {
        // The owner/admin is never blocked, even on a mature title.
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('findById')->willReturn([
            'id' => 'item-1',
            'name' => 'Mature Movie',
            'type' => 'movie',
            'content_rating' => 'NC-17',
            'metadata_json' => '{}',
        ]);
        $itemRepo->method('getItemStreams')->willReturn([]);

        $router = $this->pg13Router($itemRepo, true);
        $response = $router->getMediaItem($this->cappedRequest(), ['id' => 'item-1']);

        $this->assertSame(200, $response->statusCode);
    }

    public function testGetLibraryItemsUsesAllowedRatingsForCappedProfile(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->expects($this->once())
            ->method('getByAllowedRatings')
            ->with('lib-1', $this->pg13Filter()['allowedRatings'], 50, 0, true)
            ->willReturn([]);
        $itemRepo->expects($this->never())->method('getByLibrary');

        $router = $this->pg13Router($itemRepo, false);
        $router->getLibraryItems($this->cappedRequest(), ['id' => 'lib-1']);
    }

    public function testGetLibraryItemsUsesPlainListingForOwner(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->expects($this->once())->method('getByLibrary')->willReturn([]);
        $itemRepo->expects($this->never())->method('getByAllowedRatings');

        $router = $this->pg13Router($itemRepo, true);
        $router->getLibraryItems($this->cappedRequest(), ['id' => 'lib-1']);
    }
}
