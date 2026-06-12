<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\Exception\TmdbUnconfiguredException;
use Phlix\Media\Metadata\LibraryMetadataMatcher;
use Phlix\Media\Metadata\MovieMetadataResolver;
use Phlix\Media\Metadata\SeriesMetadataResolver;
use Phlix\Media\Metadata\TmdbProvider;
use Phlix\Server\Http\Controllers\MediaMatchController;
use Phlix\Server\Http\Request;

/**
 * Unit tests for {@see MediaMatchController} — the S5 interactive per-item
 * metadata match endpoints. Admin auth is asserted via the userId gate; the
 * controller's AdminMiddleware is left unset (matching the existing controller
 * test convention that assumes an authenticated admin).
 *
 * @covers \Phlix\Server\Http\Controllers\MediaMatchController
 */
class MediaMatchControllerTest extends TestCase
{
    /** Build an authenticated request with optional query + body. */
    private function authedRequest(array $query = [], array $body = []): Request
    {
        $request = new Request();
        $request->userId = 'admin-1';
        $request->query = $query;
        $request->body = $body;
        return $request;
    }

    public function testSearchRequiresAuth(): void
    {
        $controller = new MediaMatchController(
            $this->createMock(ItemRepository::class),
            $this->createMock(LibraryMetadataMatcher::class),
        );

        $response = $controller->search(new Request(), ['id' => 'm1']);
        $this->assertSame(401, $response->statusCode);
        $this->assertSame('auth.required', json_decode($response->body, true)['code']);
    }

    public function testSearch404WhenItemMissing(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn(null);

        $controller = new MediaMatchController($items, $this->createMock(LibraryMetadataMatcher::class));
        $response = $controller->search($this->authedRequest(), ['id' => 'missing']);

        $this->assertSame(404, $response->statusCode);
    }

    public function testSearchAutoDerivesQueryYearAndTypeFromItem(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn([
            'id' => 's1',
            'type' => 'series',
            'name' => 'Some Show',
            'metadata' => ['year' => 2011],
        ]);

        $matcher = $this->createMock(LibraryMetadataMatcher::class);
        $matcher->expects($this->once())
            ->method('searchCandidates')
            ->with('Some Show', 'tv', 2011, 20)
            ->willReturn([['tmdb_id' => '1', 'type' => 'tv', 'title' => 'Some Show', 'year' => 2011]]);

        $controller = new MediaMatchController($items, $matcher);
        $response = $controller->search($this->authedRequest(), ['id' => 's1']);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Some Show', $body['query']);
        $this->assertSame('tv', $body['type']);
        $this->assertCount(1, $body['results']);
    }

    public function testSearchHonorsManualQueryYearType(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn(['id' => 'm1', 'type' => 'movie', 'name' => 'Wrong', 'metadata' => []]);

        $matcher = $this->createMock(LibraryMetadataMatcher::class);
        $matcher->expects($this->once())
            ->method('searchCandidates')
            ->with('The Matrix', 'movie', 1999, 20)
            ->willReturn([]);

        $controller = new MediaMatchController($items, $matcher);
        $response = $controller->search(
            $this->authedRequest(['query' => 'The Matrix', 'year' => '1999', 'type' => 'movie']),
            ['id' => 'm1'],
        );

        $this->assertSame(200, $response->statusCode);
    }

    public function testSearch400WhenNoQueryAndNoTitle(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn(['id' => 'm1', 'type' => 'movie', 'name' => '', 'metadata' => []]);

        $controller = new MediaMatchController($items, $this->createMock(LibraryMetadataMatcher::class));
        $response = $controller->search($this->authedRequest(), ['id' => 'm1']);

        $this->assertSame(400, $response->statusCode);
        $this->assertSame('metadata.no_query', json_decode($response->body, true)['code']);
    }

    public function testSearch422WhenTmdbUnconfigured(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn(['id' => 'm1', 'type' => 'movie', 'name' => 'X', 'metadata' => []]);

        $matcher = $this->createMock(LibraryMetadataMatcher::class);
        $matcher->method('searchCandidates')->willThrowException(new TmdbUnconfiguredException());

        $controller = new MediaMatchController($items, $matcher);
        $response = $controller->search($this->authedRequest(), ['id' => 'm1']);

        $this->assertSame(422, $response->statusCode);
        $this->assertSame('metadata.tmdb_unconfigured', json_decode($response->body, true)['code']);
    }

    public function testSearch502WhenTmdbUnreachable(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn(['id' => 'm1', 'type' => 'movie', 'name' => 'X', 'metadata' => []]);

        $matcher = $this->createMock(LibraryMetadataMatcher::class);
        $matcher->method('searchCandidates')->willThrowException(new \RuntimeException('boom'));

        $controller = new MediaMatchController($items, $matcher);
        $response = $controller->search($this->authedRequest(), ['id' => 'm1']);

        $this->assertSame(502, $response->statusCode);
        $this->assertSame('metadata.tmdb_unreachable', json_decode($response->body, true)['code']);
    }

    public function testApply400WhenNoTmdbId(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn(['id' => 'm1', 'type' => 'movie', 'metadata' => []]);

        $controller = new MediaMatchController($items, $this->createMock(LibraryMetadataMatcher::class));
        $response = $controller->apply($this->authedRequest([], []), ['id' => 'm1']);

        $this->assertSame(400, $response->statusCode);
        $this->assertSame('metadata.bad_tmdb_id', json_decode($response->body, true)['code']);
    }

    public function testApply404WhenItemMissing(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn(null);

        $controller = new MediaMatchController($items, $this->createMock(LibraryMetadataMatcher::class));
        $response = $controller->apply($this->authedRequest([], ['tmdb_id' => '603']), ['id' => 'x']);

        $this->assertSame(404, $response->statusCode);
    }

    public function testApplySuccessReturnsShapedItem(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn([
            'id' => 'm1',
            'type' => 'movie',
            'name' => 'The Matrix',
            'metadata' => ['poster_url' => 'https://image.tmdb.org/t/p/w500/p.jpg', 'year' => 1999],
        ]);
        $items->method('getItemStreams')->willReturn([]);

        $matcher = $this->createMock(LibraryMetadataMatcher::class);
        $matcher->expects($this->once())
            ->method('applyMatch')
            ->with('m1', '603', 'movie')
            ->willReturn([
                'item_id' => 'm1', 'mode' => 'movie', 'tmdb_id' => '603',
                'matched' => true, 'children_enriched' => 0,
            ]);

        $controller = new MediaMatchController($items, $matcher);
        $response = $controller->apply($this->authedRequest([], ['tmdb_id' => '603']), ['id' => 'm1']);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('m1', $body['item']['id']);
        $this->assertSame('The Matrix', $body['item']['name']);
        $this->assertTrue($body['applied']['matched']);
    }

    public function testApplyDerivesTvTypeForSeriesItem(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn(['id' => 's1', 'type' => 'series', 'name' => 'Show', 'metadata' => []]);
        $items->method('getItemStreams')->willReturn([]);

        $matcher = $this->createMock(LibraryMetadataMatcher::class);
        $matcher->expects($this->once())
            ->method('applyMatch')
            ->with('s1', '1399', 'tv')
            ->willReturn([
                'item_id' => 's1', 'mode' => 'tv', 'tmdb_id' => '1399',
                'matched' => true, 'children_enriched' => 5,
            ]);

        $controller = new MediaMatchController($items, $matcher);
        $response = $controller->apply($this->authedRequest([], ['tmdb_id' => '1399']), ['id' => 's1']);

        $this->assertSame(200, $response->statusCode);
    }

    public function testApply422WhenNoMatch(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn(['id' => 'm1', 'type' => 'movie', 'metadata' => []]);

        $matcher = $this->createMock(LibraryMetadataMatcher::class);
        $matcher->method('applyMatch')->willReturn([
            'item_id' => 'm1', 'mode' => 'movie', 'tmdb_id' => '0',
            'matched' => false, 'children_enriched' => 0,
        ]);

        $controller = new MediaMatchController($items, $matcher);
        $response = $controller->apply($this->authedRequest([], ['tmdb_id' => '0']), ['id' => 'm1']);

        $this->assertSame(422, $response->statusCode);
        $this->assertSame('metadata.no_match', json_decode($response->body, true)['code']);
    }

    public function testApply422WhenTmdbUnconfigured(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn(['id' => 'm1', 'type' => 'movie', 'metadata' => []]);

        $matcher = $this->createMock(LibraryMetadataMatcher::class);
        $matcher->method('applyMatch')->willThrowException(new TmdbUnconfiguredException());

        $controller = new MediaMatchController($items, $matcher);
        $response = $controller->apply($this->authedRequest([], ['tmdb_id' => '603']), ['id' => 'm1']);

        $this->assertSame(422, $response->statusCode);
        $this->assertSame('metadata.tmdb_unconfigured', json_decode($response->body, true)['code']);
    }

    /**
     * End-to-end through a REAL matcher + a REAL TmdbProvider built with an
     * EMPTY API key (as the container wires it when no key is configured):
     * search must return `422 metadata.tmdb_unconfigured`, never an empty 200,
     * and never make an outbound TMDB call (no network in this unit test).
     */
    public function testSearch422EndToEndWhenApiKeyEmpty(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn(['id' => 'm1', 'type' => 'movie', 'name' => 'X', 'metadata' => []]);

        $controller = new MediaMatchController($items, $this->matcherWithEmptyKey($items));
        $response = $controller->search($this->authedRequest(), ['id' => 'm1']);

        $this->assertSame(422, $response->statusCode);
        $this->assertSame('metadata.tmdb_unconfigured', json_decode($response->body, true)['code']);
    }

    /**
     * End-to-end through a REAL matcher + a REAL empty-key TmdbProvider: apply
     * must return `422 metadata.tmdb_unconfigured`, not `metadata.no_match`.
     */
    public function testApply422EndToEndWhenApiKeyEmpty(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn(['id' => 'm1', 'type' => 'movie', 'metadata' => []]);
        $items->expects($this->never())->method('update');

        $controller = new MediaMatchController($items, $this->matcherWithEmptyKey($items));
        $response = $controller->apply($this->authedRequest([], ['tmdb_id' => '603']), ['id' => 'm1']);

        $this->assertSame(422, $response->statusCode);
        $this->assertSame('metadata.tmdb_unconfigured', json_decode($response->body, true)['code']);
    }

    /**
     * Regression: with a configured API key the same real-matcher wiring
     * proceeds past the configured-key gate (no 422 tmdb_unconfigured) — a
     * stubbed HTTP client returns one TV result, yielding a normal `200`.
     */
    public function testSearch200EndToEndWhenApiKeyPresent(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn([
            'id' => 's1', 'type' => 'series', 'name' => 'Some Show', 'metadata' => ['year' => 2011],
        ]);

        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('hasApiKey')->willReturn(true);
        $tmdb->method('searchTv')->willReturn([
            [
                'id' => '1399', 'name' => 'Some Show', 'overview' => '',
                'poster_path' => null, 'backdrop_path' => null,
                'first_air_date' => '2011-04-17', 'vote_average' => 9.0,
            ],
        ]);

        $matcher = new LibraryMetadataMatcher(
            $items,
            $this->createMock(MovieMetadataResolver::class),
            $this->createMock(SeriesMetadataResolver::class),
            $this->createMock(StructuredLogger::class),
            $tmdb,
        );

        $controller = new MediaMatchController($items, $matcher);
        $response = $controller->search($this->authedRequest(), ['id' => 's1']);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertCount(1, $body['results']);
        $this->assertSame('1399', $body['results'][0]['tmdb_id']);
    }

    /**
     * Build a real {@see LibraryMetadataMatcher} wired with a real
     * {@see TmdbProvider} constructed with an empty key — `hasApiKey()` is
     * false, so search/apply throw {@see TmdbUnconfiguredException} before any
     * HTTP call is made.
     */
    private function matcherWithEmptyKey(ItemRepository $items): LibraryMetadataMatcher
    {
        return new LibraryMetadataMatcher(
            $items,
            $this->createMock(MovieMetadataResolver::class),
            $this->createMock(SeriesMetadataResolver::class),
            $this->createMock(StructuredLogger::class),
            new TmdbProvider(''),
        );
    }
}
