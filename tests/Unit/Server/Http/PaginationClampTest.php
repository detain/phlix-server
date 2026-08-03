<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http;

use Phlix\Auth\AuthManager;
use Phlix\Common\Http\PageLimit;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\MarkerService as ChapterMarkerService;
use Phlix\Media\Markers\Detection\MarkerCandidateRepository;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Media\Playback\GaplessPlaybackManager;
use Phlix\Media\Streaming\Trickplay\TrickplayController;
use Phlix\Server\Http\Controllers\MediaItemController;
use Phlix\Server\Http\Request;
use Phlix\Server\WebPortal\WebPortalRouter;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * SECURITY regression tests: an over-large `?limit=` must never reach a
 * `LIMIT ?` binding.
 *
 * `Request::queryInt()` does no bounds checking at all, and an unclamped page
 * size flowed from nine list endpoints straight into `LIMIT ?`. Under
 * Workerman the worker process is **resident and shared**, so
 * `?limit=100000000` is not a big page — it is a memory-exhaustion vector that
 * can OOM the process serving every other user.
 *
 * Coverage spans BOTH dispatch paths (§7): `MediaItemController` (reached via
 * `public/index.php` / `Application`) and `WebPortalRouter` (reached via
 * `start.php` and the relay dispatcher).
 */
final class PaginationClampTest extends TestCase
{
    // -----------------------------------------------------------------
    // The policy itself.
    // -----------------------------------------------------------------

    /**
     * @return list<array{0: mixed, 1: int}>
     */
    public static function clampCases(): array
    {
        return [
            'huge int'          => [100000000, PageLimit::MAX],
            'huge numeric str'  => ['100000000', PageLimit::MAX],
            'just over the cap' => [PageLimit::MAX + 1, PageLimit::MAX],
            'at the cap'        => [PageLimit::MAX, PageLimit::MAX],
            'zero'              => [0, PageLimit::MIN],
            'negative'          => [-500, PageLimit::MIN],
            'float string'      => ['250.9', PageLimit::MAX],
            'ordinary'          => [24, 24],
        ];
    }

    /**
     * @dataProvider clampCases
     */
    public function testClampBoundsEveryRequestedPageSize(mixed $raw, int $expected): void
    {
        self::assertSame($expected, PageLimit::clamp($raw));
    }

    public function testClampFallsBackToTheDefaultForNonNumericInput(): void
    {
        self::assertSame(20, PageLimit::clamp(null, 20));
        self::assertSame(20, PageLimit::clamp('abc', 20));
        self::assertSame(20, PageLimit::clamp([], 20));
    }

    /**
     * The ceiling is HARD: a controller cannot opt out of it by declaring an
     * oversized default, which is the loophole "make it a configurable default"
     * would leave open.
     */
    public function testAnOversizedControllerDefaultIsItselfClamped(): void
    {
        self::assertSame(PageLimit::MAX, PageLimit::clamp(null, 100000));
    }

    public function testOffsetIsClampedToNonNegative(): void
    {
        self::assertSame(0, PageLimit::clampOffset(-1));
        self::assertSame(0, PageLimit::clampOffset('abc'));
        self::assertSame(5000, PageLimit::clampOffset('5000'));
    }

    public function testRequestHelpersApplyThePolicy(): void
    {
        $request = new Request();
        $request->query = ['limit' => '100000000', 'offset' => '-9'];

        self::assertSame(PageLimit::MAX, $request->queryPageSize());
        self::assertSame(0, $request->queryOffset());

        // queryInt() is deliberately left unbounded — it serves non-pagination
        // params — which is exactly why pagination must not use it.
        self::assertSame(100000000, $request->queryInt('limit'));
    }

    // -----------------------------------------------------------------
    // Dispatch path 1: MediaItemController (public/index.php + Application).
    // -----------------------------------------------------------------

    public function testLibraryItemsEndpointClampsLimit(): void
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->expects($this->once())
            ->method('getByLibrary')
            ->with('lib-1', PageLimit::MAX, 0)
            ->willReturn([]);

        $controller = $this->mediaItemController($repo);
        $response   = $controller->index(
            $this->request(['limit' => '100000000', 'offset' => '-3']),
            ['library_id' => 'lib-1'],
        );

        self::assertSame(200, $response->statusCode);
    }

    public function testRecentlyAddedEndpointClampsLimit(): void
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->expects($this->once())
            ->method('getRecentlyAdded')
            ->with('lib-1', PageLimit::MAX)
            ->willReturn([]);

        $controller = $this->mediaItemController($repo);
        $response   = $controller->recentlyAdded(
            $this->request(['limit' => '999999']),
            ['library_id' => 'lib-1'],
        );

        self::assertSame(200, $response->statusCode);
    }

    // -----------------------------------------------------------------
    // Dispatch path 2: WebPortalRouter (start.php + relay dispatcher).
    // -----------------------------------------------------------------

    public function testWebPortalLibraryItemsEndpointClampsLimit(): void
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->expects($this->once())
            ->method('getByLibrary')
            ->with('lib-1', PageLimit::MAX, 0)
            ->willReturn([]);

        $router   = $this->webPortalRouter($repo);
        $response = $router->getLibraryItems(
            $this->request(['limit' => '100000000', 'offset' => '-1']),
            ['id' => 'lib-1'],
        );

        self::assertSame(200, $response->statusCode);
    }

    public function testWebPortalSearchEndpointClampsLimit(): void
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->expects($this->once())
            ->method('search')
            ->with('dune', PageLimit::MAX)
            ->willReturn([]);

        $router   = $this->webPortalRouter($repo);
        $response = $router->searchMedia($this->request(['q' => 'dune', 'limit' => '5000']), []);

        self::assertSame(200, $response->statusCode);
    }

    // -----------------------------------------------------------------
    // Helpers.
    // -----------------------------------------------------------------

    /**
     * @param array<string, string> $query
     */
    private function request(array $query): Request
    {
        $request = new Request();
        $request->query = $query;

        return $request;
    }

    private function mediaItemController(ItemRepository $repo): MediaItemController
    {
        // MarkerService / ChapterMarkerService / TrickplayController are final,
        // so build the real (inert here) collaborators as the existing
        // MediaItemController tests do.
        return new MediaItemController(
            $repo,
            new MarkerService($repo, new MarkerCandidateRepository($repo)),
            $this->createMock(GaplessPlaybackManager::class),
            new TrickplayController('/tmp/phlix-trickplay-test', ''),
            new ChapterMarkerService($this->createMock(Connection::class)),
        );
    }

    private function webPortalRouter(ItemRepository $repo): WebPortalRouter
    {
        return new WebPortalRouter(
            $this->createMock(LibraryManager::class),
            $repo,
            $this->createMock(SessionManager::class),
            $this->createMock(PlaybackController::class),
            $this->createMock(AuthManager::class),
            $this->createMock(PlaybackMarkerService::class),
            $this->createMock(MarkerService::class),
        );
    }
}
