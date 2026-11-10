<?php

/**
 * SH-023: `GET /api/v1/media/search` must report the library-wide match
 * count in `total`, never the length of the page it happened to return.
 *
 * The pre-SH-023 handler echoed `count($shapedItems)` — a page-length lie
 * that killed the client-side "showing X of N" affordance by construction
 * (phlix-ui SearchPage.vue's cap notice compares `items.length` against the
 * server `total`, and the two were pinned equal by the old code). These
 * tests pin the new law (dedicated count leg flows through), the response
 * key-set law (shape unchanged — only the VALUE semantics move), the
 * validation arms that stay (empty q still 400s), and the SH-023 minor leg:
 * `search/by-marker` clamps an oversized `limit` through the shared
 * PageLimit policy instead of 400-ing where every other page in the API
 * clamps. The semantically-invalid `around` window still 400s by law.
 *
 * [SH-023 / server__webux#1-#2, w22L2 2026-11-10]
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal;

use Phlix\Auth\AuthManager;
use Phlix\Common\Http\PageLimit;
use Phlix\Media\ChapterSearchService;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Server\Http\Request;
use Phlix\Server\WebPortal\WebPortalRouter;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

final class WebPortalRouterSearchTotalTest extends TestCase
{
    private function router(ItemRepository $repo, ?ChapterSearchService $chapters = null): WebPortalRouter
    {
        return new WebPortalRouter(
            $this->createMock(LibraryManager::class),
            $repo,
            $this->createMock(SessionManager::class),
            $this->createMock(PlaybackController::class),
            $this->createMock(AuthManager::class),
            $this->createMock(PlaybackMarkerService::class),
            $this->createMock(MarkerService::class),
            $chapters,
        );
    }

    /**
     * @param array<string, string> $query
     */
    private function request(array $query): Request
    {
        $request = new Request();
        $request->query = $query;

        return $request;
    }

    /**
     * @param array<string, string> $query
     */
    private function authedRequest(array $query): Request
    {
        $request = $this->request($query);
        $request->userId = 'user-1';
        $request->profileId = 'profile-1';

        return $request;
    }

    /**
     * One hydrated row in the shape ItemRepository::search() hands back.
     *
     * @return array<string, mixed>
     */
    private function row(string $id, string $name): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'type' => 'movie',
            'library_id' => 'lib-1',
            'path' => '/mnt/media/' . $name . '.mkv',
            'metadata' => [],
        ];
    }

    public function testSearchMediaTotalIsTheLibraryMatchCount_NotThePageLength(): void
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->expects($this->once())
            ->method('search')
            ->with('dune', 50)
            ->willReturn([$this->row('m-1', 'Dune'), $this->row('m-2', 'Dune 2')]);
        $repo->expects($this->once())
            ->method('searchCount')
            ->with('dune')
            ->willReturn(400);

        $response = $this->router($repo)->searchMedia($this->request(['q' => 'dune']), []);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);

        self::assertSame(200, $response->statusCode);
        self::assertSame(400, $body['total'], 'total must be the library-wide match count');
        self::assertCount(2, $body['items'], 'the page still carries only the fetched rows');
        self::assertSame('dune', $body['query']);
    }

    public function testSearchMediaResponseKeySetIsByteIdenticalToTheOldContract(): void
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('search')->willReturn([$this->row('m-1', 'Dune')]);
        $repo->method('searchCount')->willReturn(1);

        $response = $this->router($repo)->searchMedia($this->request(['q' => 'dune']), []);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);

        // Only the total VALUE semantics change; the key set never moves.
        self::assertSame(['items', 'query', 'total'], array_keys($body));
    }

    public function testSearchMediaWithZeroMatchesStillReportsTheTrueMatchCount(): void
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('search')->willReturn([]);
        $repo->method('searchCount')->willReturn(3);

        $response = $this->router($repo)->searchMedia($this->request(['q' => 'ghost']), []);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);

        self::assertSame(200, $response->statusCode);
        self::assertSame(3, $body['total']);
        self::assertSame([], $body['items']);
    }

    public function testSearchMediaWhenTheLibraryMatchesFewerThanThePage(): void
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('search')->willReturn([$this->row('m-1', 'Dune')]);
        $repo->method('searchCount')->willReturn(1);

        $response = $this->router($repo)->searchMedia($this->request(['q' => 'dune']), []);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);

        self::assertSame(1, $body['total']);
    }

    public function testSearchMediaStillRequiresQueryText(): void
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->expects($this->never())->method('search');
        $repo->expects($this->never())->method('searchCount');

        $response = $this->router($repo)->searchMedia($this->request([]), []);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);

        self::assertSame(400, $response->statusCode);
        self::assertSame(['error'], array_keys($body));
        self::assertSame('Search text is required', $body['error']);
    }

    /**
     * ChapterSearchService is final, so the by-marker legs drive the REAL
     * service over a doubled Workerman connection; the query params are the
     * observation point (the handler's clamped limit must arrive intact).
     *
     * @param array<int, array<int|string, mixed>> $recorded
     */
    private function chapterService(array &$recorded): ChapterSearchService
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            /** @param array<int, mixed> $params */
            function (string $sql, ?array $params = null) use (&$recorded): array {
                if (is_array($params)) {
                    $recorded[] = $params;
                }
                return [];
            },
        );

        return new ChapterSearchService($db, $this->createMock(ItemRepository::class));
    }

    public function testSearchByMarkerClampsOversizedLimitThroughTheSharedPolicy(): void
    {
        /** @var array<int, array<int|string, mixed>> $recorded */
        $recorded = [];
        $repo = $this->createMock(ItemRepository::class);
        $response = $this->router($repo, $this->chapterService($recorded))->searchByMarker(
            $this->authedRequest(['type' => 'intro', 'position' => '1234', 'limit' => '500']),
            [],
        );
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);

        // Pre-SH-023 this 400'd with 'The limit value must be between 1 and 100'
        // while every other page endpoint clamped. Clamp-on-read is the house law.
        self::assertSame(200, $response->statusCode);
        self::assertCount(1, $recorded, 'the real service must have been queried once');
        // Params: [marker_type, minPosition, maxPosition, profile, LIMIT]
        self::assertSame('intro', $recorded[0][0]);
        self::assertSame(PageLimit::MAX, $recorded[0][4], 'limit=500 must arrive clamped to the shared PageLimit max');
        self::assertSame(['items', 'marker_type', 'around', 'position'], array_keys($body));
        self::assertSame('intro', $body['marker_type']);
        self::assertSame([], $body['items']);
    }

    public function testSearchByMarkerStillRejectsAnInvalidAroundWindow(): void
    {
        /** @var array<int, array<int|string, mixed>> $recorded */
        $recorded = [];
        $repo = $this->createMock(ItemRepository::class);
        $response = $this->router($repo, $this->chapterService($recorded))->searchByMarker(
            $this->authedRequest(['type' => 'intro', 'around' => '9999', 'limit' => '10']),
            [],
        );
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);

        self::assertSame(400, $response->statusCode);
        self::assertSame('The around value must be between 1 and 300', $body['error']);
        self::assertSame([], $recorded, 'a rejected window must never reach the service');
    }

    public function testSearchByMarkerStillRejectsAnUnknownMarkerType(): void
    {
        /** @var array<int, array<int|string, mixed>> $recorded */
        $recorded = [];
        $repo = $this->createMock(ItemRepository::class);
        $response = $this->router($repo, $this->chapterService($recorded))->searchByMarker(
            $this->authedRequest(['type' => 'not-a-marker', 'limit' => '10']),
            [],
        );

        self::assertSame(400, $response->statusCode);
        self::assertSame([], $recorded);
    }
}
