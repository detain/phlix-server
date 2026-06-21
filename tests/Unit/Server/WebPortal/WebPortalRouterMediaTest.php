<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\AuthManager;
use Phlix\Auth\UserRepository;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Server\Http\Request;
use Phlix\Server\WebPortal\WebPortalRouter;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;

class WebPortalRouterMediaTest extends TestCase
{
    private function makeRouter(ItemRepository $itemRepository): WebPortalRouter
    {
        return new WebPortalRouter(
            $this->createMock(LibraryManager::class),
            $itemRepository,
            $this->createMock(SessionManager::class),
            $this->createMock(PlaybackController::class),
            $this->createMock(AuthManager::class),
            $this->createMock(PlaybackMarkerService::class)
        );
    }

    public function testGetMediaReturnsItemsWithPagination(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->expects($this->once())
            ->method('query')
            ->with($this->callback(function (array $params): bool {
                return $params === [];
            }), $this->isNull())
            ->willReturn([
                'items' => [
                    [
                        'id' => 'movie-1',
                        'name' => 'Test Movie',
                        'type' => 'movie',
                        'library_id' => 'lib-1',
                        'path' => '/movies/test.mkv',
                        'metadata' => [
                            'poster_url' => 'http://example.com/poster.jpg',
                            'genres' => ['Action', 'Drama'],
                            'year' => 2020,
                            'rating' => 'PG-13',
                            'runtime' => 7200,
                            'overview' => 'A great movie',
                            'actors' => ['Actor One', 'Actor Two'],
                            'director' => 'Director Name',
                        ],
                    ],
                ],
                'total' => 1,
                'limit' => 50,
                'offset' => 0,
            ]);

        $router = $this->makeRouter($itemRepo);

        $request = new Request();
        $request->query = [];

        $response = $router->getMedia($request, []);

        $this->assertEquals(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('items', $body);
        $this->assertArrayHasKey('total', $body);
        $this->assertArrayHasKey('limit', $body);
        $this->assertArrayHasKey('offset', $body);
        $this->assertCount(1, $body['items']);
        $this->assertEquals('Test Movie', $body['items'][0]['name']);
        $this->assertEquals('http://example.com/poster.jpg', $body['items'][0]['poster_url']);
        $this->assertEquals(['Action', 'Drama'], $body['items'][0]['genres']);
        $this->assertEquals(2020, $body['items'][0]['year']);
        // non-TMDB poster → no responsive srcset (card falls back to poster_url)
        $this->assertNull($body['items'][0]['poster_srcset']);
    }

    public function testGetMediaEmitsResponsivePosterSrcsetForTmdbPostersOnly(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('query')->willReturn([
            'items' => [
                [
                    'id' => 'tmdb-1',
                    'name' => 'Tmdb Movie',
                    'type' => 'movie',
                    'path' => '/movies/a.mkv',
                    'metadata' => ['poster_url' => 'https://image.tmdb.org/t/p/w500/abc.jpg'],
                ],
                [
                    'id' => 'local-1',
                    'name' => 'Local Movie',
                    'type' => 'movie',
                    'path' => '/movies/b.mkv',
                    'metadata' => ['poster_url' => 'http://example.com/poster.jpg'],
                ],
            ],
            'total' => 2,
            'limit' => 50,
            'offset' => 0,
        ]);

        $request = new Request();
        $request->query = [];
        $body = json_decode($this->makeRouter($itemRepo)->getMedia($request, [])->body, true);

        // TMDB poster → a width-descriptor srcset the browser can choose from
        $srcset = $body['items'][0]['poster_srcset'];
        $this->assertIsString($srcset);
        $this->assertStringContainsString('https://image.tmdb.org/t/p/w185/abc.jpg 185w', $srcset);
        $this->assertStringContainsString('https://image.tmdb.org/t/p/w780/abc.jpg 780w', $srcset);
        // non-TMDB poster → null, so that card keeps its single poster_url
        $this->assertNull($body['items'][1]['poster_srcset']);
    }

    public function testGetMediaWithSearchParam(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->expects($this->once())
            ->method('query')
            ->with($this->callback(function (array $params): bool {
                return ($params['search'] ?? null) === 'batman';
            }), $this->isNull())
            ->willReturn(['items' => [], 'total' => 0, 'limit' => 50, 'offset' => 0]);

        $router = $this->makeRouter($itemRepo);

        $request = new Request();
        $request->query = ['search' => 'batman'];

        $response = $router->getMedia($request, []);

        $this->assertEquals(200, $response->statusCode);
    }

    public function testGetMediaWithFilters(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->expects($this->once())
            ->method('query')
            ->with($this->callback(function (array $params): bool {
                return ($params['genres'] ?? null) === ['Action', 'Drama']
                    && ($params['yearFrom'] ?? null) === 2010
                    && ($params['yearTo'] ?? null) === 2020
                    && ($params['ratings'] ?? null) === ['PG', 'PG-13']
                    && ($params['sort'] ?? null) === 'rating'
                    && ($params['order'] ?? null) === 'desc'
                    && ($params['limit'] ?? null) === 25
                    && ($params['offset'] ?? null) === 50;
            }), $this->isNull())
            ->willReturn(['items' => [], 'total' => 0, 'limit' => 25, 'offset' => 50]);

        $router = $this->makeRouter($itemRepo);

        $request = new Request();
        $request->query = [
            'genres' => ['Action', 'Drama'],
            'yearFrom' => '2010',
            'yearTo' => '2020',
            'ratings' => ['PG', 'PG-13'],
            'sort' => 'rating',
            'order' => 'desc',
            'limit' => '25',
            'offset' => '50',
        ];

        $response = $router->getMedia($request, []);

        $this->assertEquals(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertEquals(25, $body['limit']);
        $this->assertEquals(50, $body['offset']);
    }

    public function testGetMediaScopesToLibraryWhenLibraryIdProvided(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->expects($this->once())
            ->method('query')
            ->with($this->callback(function (array $params): bool {
                // libraryId is NOT a query-param key — it is passed as the
                // dedicated 2nd argument, so it must not leak into $params.
                return !array_key_exists('libraryId', $params);
            }), $this->equalTo('lib-42'))
            ->willReturn(['items' => [], 'total' => 0, 'limit' => 50, 'offset' => 0]);

        $router = $this->makeRouter($itemRepo);

        $request = new Request();
        $request->query = ['libraryId' => 'lib-42'];

        $response = $router->getMedia($request, []);

        $this->assertEquals(200, $response->statusCode);
    }

    public function testGetMediaTreatsBlankLibraryIdAsUnscoped(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->expects($this->once())
            ->method('query')
            ->with($this->anything(), $this->isNull())
            ->willReturn(['items' => [], 'total' => 0, 'limit' => 50, 'offset' => 0]);

        $router = $this->makeRouter($itemRepo);

        $request = new Request();
        $request->query = ['libraryId' => ''];

        $response = $router->getMedia($request, []);

        $this->assertEquals(200, $response->statusCode);
    }

    public function testGetMediaShapesMetadataFieldsCorrectly(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('query')->willReturn([
            'items' => [
                [
                    'id' => 'movie-1',
                    'name' => 'Test Movie',
                    'type' => 'movie',
                    'path' => '/movies/test.mkv',
                    'metadata' => [
                        'poster_url' => 'http://example.com/poster.jpg',
                        'genres' => ['Action'],
                        'year' => 2020,
                        'rating' => 'R',
                        'runtime' => 7200,
                        'overview' => 'Test overview',
                        'actors' => ['Actor One'],
                        'director' => 'Director Name',
                        'created_at' => '2024-01-01T00:00:00+00:00',
                        'updated_at' => '2024-01-02T00:00:00+00:00',
                    ],
                ],
            ],
            'total' => 1,
            'limit' => 50,
            'offset' => 0,
        ]);

        $router = $this->makeRouter($itemRepo);

        $request = new Request();
        $response = $router->getMedia($request, []);

        $body = json_decode($response->body, true);
        $item = $body['items'][0];
        $this->assertEquals('movie-1', $item['id']);
        $this->assertEquals('Test Movie', $item['name']);
        $this->assertEquals('movie', $item['type']);
        $this->assertEquals('http://example.com/poster.jpg', $item['poster_url']);
        $this->assertEquals(['Action'], $item['genres']);
        $this->assertEquals(2020, $item['year']);
        $this->assertEquals('R', $item['rating']);
        $this->assertEquals(7200, $item['runtime']);
        $this->assertEquals('Test overview', $item['overview']);
        $this->assertEquals(['Actor One'], $item['actors']);
        $this->assertEquals('Director Name', $item['director']);
    }

    public function testGetMediaExposesSeriesHierarchyFields(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('query')->willReturn([
            'items' => [
                [
                    'id' => 'ep-1',
                    'name' => 'Pilot',
                    'type' => 'episode',
                    'parent_id' => 'season-1',
                    'path' => '/tv/show/s01e01.mkv',
                    'metadata' => [
                        'season' => 1,
                        'episode' => 2,
                        'episode_title' => 'Pilot',
                    ],
                ],
            ],
            'total' => 1,
            'limit' => 50,
            'offset' => 0,
        ]);

        $router = $this->makeRouter($itemRepo);
        $body = json_decode($router->getMedia(new Request(), [])->body, true);
        $item = $body['items'][0];

        $this->assertSame('episode', $item['type']);
        $this->assertSame('season-1', $item['parent_id']);
        $this->assertSame(1, $item['season_number']);
        $this->assertSame(2, $item['episode_number']);
        $this->assertSame('Pilot', $item['episode_title']);
    }

    public function testGetMediaPassesThroughSeasonTypeAndNullHierarchyForTopLevel(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('query')->willReturn([
            'items' => [
                // A season row keeps its `season` type (not coerced to movie).
                [
                    'id' => 'season-1',
                    'name' => 'Season 1',
                    'type' => 'season',
                    'parent_id' => 'series-1',
                    'path' => '/tv/show/s01',
                    'metadata' => ['season' => 1],
                ],
                // A top-level series has a null parent + null season/episode numbers.
                [
                    'id' => 'series-1',
                    'name' => 'The Show',
                    'type' => 'series',
                    'parent_id' => null,
                    'path' => '/tv/show',
                    'metadata' => [],
                ],
            ],
            'total' => 2,
            'limit' => 50,
            'offset' => 0,
        ]);

        $router = $this->makeRouter($itemRepo);
        $body = json_decode($router->getMedia(new Request(), [])->body, true);

        $this->assertSame('season', $body['items'][0]['type']);
        $this->assertSame('series-1', $body['items'][0]['parent_id']);
        $this->assertSame(1, $body['items'][0]['season_number']);

        $this->assertSame('series', $body['items'][1]['type']);
        $this->assertNull($body['items'][1]['parent_id']);
        $this->assertNull($body['items'][1]['season_number']);
        $this->assertNull($body['items'][1]['episode_number']);
        $this->assertNull($body['items'][1]['episode_title']);
    }

    public function testGetMediaItemEnrichesSingleItemWithPosterAndStreams(): void
    {
        // The detail/player endpoint must return the SAME enriched shape as the
        // list (poster_url, genres, overview, season/episode numbers) PLUS streams
        // — previously it returned the raw row and those pages rendered blank.
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('findById')->with('ep-1')->willReturn([
            'id' => 'ep-1',
            'name' => 'Pilot',
            'type' => 'episode',
            'parent_id' => 'season-1',
            'path' => '/tv/show/s01e01.mkv',
            'intro_start_seconds' => 12,
            'metadata' => [
                'poster_url' => 'https://image.tmdb.org/t/p/w500/ep.jpg',
                'overview' => 'The one where it begins.',
                'genres' => ['Drama'],
                'season' => 1,
                'episode' => 1,
                'episode_title' => 'Pilot',
            ],
        ]);
        $itemRepo->method('getItemStreams')->with('ep-1')->willReturn([
            ['stream_index' => 0, 'stream_type' => 'video', 'codec' => 'h264'],
        ]);

        $router = $this->makeRouter($itemRepo);
        $body = json_decode($router->getMediaItem(new Request(), ['id' => 'ep-1'])->body, true);
        $item = $body['item'];

        $this->assertSame('https://image.tmdb.org/t/p/w500/ep.jpg', $item['poster_url']);
        $this->assertNotNull($item['poster_srcset']); // TMDB poster → responsive srcset
        $this->assertSame('The one where it begins.', $item['overview']);
        $this->assertSame(['Drama'], $item['genres']);
        $this->assertSame(1, $item['season_number']);
        $this->assertSame(1, $item['episode_number']);
        $this->assertSame('Pilot', $item['episode_title']);
        // Single-item extras the list shape omits are preserved.
        $this->assertSame(12, $item['intro_start_seconds']);
        $this->assertCount(1, $item['streams']);
        $this->assertSame('video', $item['streams'][0]['stream_type']);

        // The detail endpoint mints a signed direct-play URL (the <video src>
        // can't attach a Bearer header and /media/{id}/stream is now gated).
        $this->assertArrayHasKey('stream_url', $item);
        parse_str((string) parse_url((string) $item['stream_url'], PHP_URL_QUERY), $q);
        $this->assertTrue(
            \Phlix\Auth\SignedUrl::fromEnv()->verify(
                '/media/ep-1/stream',
                (string) ($q['exp'] ?? ''),
                (string) ($q['sig'] ?? ''),
            ),
            'stream_url must be a verifiable signed URL for /media/ep-1/stream',
        );
    }

    public function testGetMediaItemReturns404WhenMissing(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('findById')->willReturn(null);

        $response = $this->makeRouter($itemRepo)->getMediaItem(new Request(), ['id' => 'nope']);

        $this->assertSame(404, $response->statusCode);
    }

    public function testGetMediaForwardsParentIdScope(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->expects($this->once())
            ->method('query')
            ->with($this->callback(function (array $params): bool {
                return ($params['parentId'] ?? null) === 'series-7'
                    && !array_key_exists('topLevel', $params);
            }), $this->isNull())
            ->willReturn(['items' => [], 'total' => 0, 'limit' => 50, 'offset' => 0]);

        $request = new Request();
        $request->query = ['parentId' => 'series-7'];

        $this->assertEquals(200, $this->makeRouter($itemRepo)->getMedia($request, [])->statusCode);
    }

    public function testGetMediaForwardsTopLevelFlag(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->expects($this->once())
            ->method('query')
            ->with($this->callback(function (array $params): bool {
                return ($params['topLevel'] ?? null) === true;
            }), $this->isNull())
            ->willReturn(['items' => [], 'total' => 0, 'limit' => 50, 'offset' => 0]);

        $request = new Request();
        $request->query = ['topLevel' => '1'];

        $this->assertEquals(200, $this->makeRouter($itemRepo)->getMedia($request, [])->statusCode);
    }

    public function testGetMediaIgnoresBlankParentIdAndUnsetTopLevel(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->expects($this->once())
            ->method('query')
            ->with($this->callback(function (array $params): bool {
                return !array_key_exists('parentId', $params)
                    && !array_key_exists('topLevel', $params);
            }), $this->isNull())
            ->willReturn(['items' => [], 'total' => 0, 'limit' => 50, 'offset' => 0]);

        $request = new Request();
        $request->query = ['parentId' => '', 'topLevel' => '0'];

        $this->assertEquals(200, $this->makeRouter($itemRepo)->getMedia($request, [])->statusCode);
    }

    public function testGetMediaHandlesMissingMetadata(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('query')->willReturn([
            'items' => [
                [
                    'id' => 'movie-1',
                    'name' => 'Test Movie',
                    'type' => 'movie',
                    'path' => '/movies/test.mkv',
                    'metadata' => null,
                ],
            ],
            'total' => 1,
            'limit' => 50,
            'offset' => 0,
        ]);

        $router = $this->makeRouter($itemRepo);

        $request = new Request();
        $response = $router->getMedia($request, []);

        $body = json_decode($response->body, true);
        $item = $body['items'][0];
        $this->assertNull($item['poster_url']);
        $this->assertEquals([], $item['genres']);
        $this->assertNull($item['year']);
        $this->assertNull($item['rating']);
        $this->assertNull($item['runtime']);
    }

    public function testDispatchRequiresAuthForMediaListing(): void
    {
        // Going through dispatch() (not the handler directly) exercises the
        // AuthMiddleware the routes are now grouped behind: no userId → 401 and
        // the repository is never touched, so the library can't be enumerated.
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->expects($this->never())->method('query');

        $request = new Request();
        $request->method = 'GET';
        $request->path = '/api/v1/media';

        $response = $this->makeRouter($itemRepo)->dispatch($request);

        $this->assertSame(401, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('auth.required', $body['code']);
    }

    public function testDispatchAllowsMediaListingForAuthenticatedUser(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->expects($this->once())
            ->method('query')
            ->willReturn(['items' => [], 'total' => 0, 'limit' => 50, 'offset' => 0]);

        $request = new Request();
        $request->method = 'GET';
        $request->path = '/api/v1/media';
        $request->userId = 'user-1'; // populated by the entry point from the token

        $response = $this->makeRouter($itemRepo)->dispatch($request);

        $this->assertSame(200, $response->statusCode);
    }

    public function testDispatchRequiresAuthForLibrariesAndLetterIndex(): void
    {
        foreach (['/api/v1/libraries', '/api/v1/media/letter-index'] as $path) {
            $request = new Request();
            $request->method = 'GET';
            $request->path = $path;

            $response = $this->makeRouter($this->createMock(ItemRepository::class))->dispatch($request);
            $this->assertSame(401, $response->statusCode, "{$path} must require auth");
        }
    }
}
