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
}
