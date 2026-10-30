<?php

/**
 * Phlix media server component: Common.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Http;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Http\PageLimit;
use Phlix\Media\Library\AudiobookLibraryManager;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Metadata\OpdsFeedBuilder;
use Phlix\Server\Http\Controllers\AudiobookController;
use Phlix\Server\Http\Controllers\BookController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * minors M-SERVER-84 (W-min100): the three book/audiobook listing endpoints
 * parse `?limit`/`?offset` through the ONE `PageLimit` policy instead of
 * hand-inlined `min(100, max(1, …))` ladders.
 *
 * The endpoints' HTTP behavior is the assertion surface: every case here was
 * written against the PRE-cure inline code's answers and must return them
 * byte-identically from the policy (that identity is the entire point of the
 * consolidation — a "refactor" that moved a bound would be a regression).
 * The new `PageLimit::coerce()` seam is pinned too: it is the parse-half that
 * `QuickConnectController::adminClients()` needs (the ceiling on that surface
 * is owned downstream by `ClientHeartbeatStore::recent()`'s deliberate
 * 1..1000 clamp, so the handler must coerce without clamping).
 */
final class PageLimitSitesTest extends TestCase
{
    // ------------------------------------------------------------------
    // PageLimit::coerce — the clamp's parse-half, no bounds
    // ------------------------------------------------------------------

    /**
     * @return array<array{mixed, int, int}>
     */
    public static function coerceTable(): array
    {
        return [
            'numeric string becomes its integer value' => ['42', 200, 42],
            'negative survives (ceiling is downstream)' => ['-5', 200, -5],
            'decimal string truncates like the old (int) cast' => ['12.9', 200, 12],
            'zero is a value, not absence' => ['0', 200, 0],
            'int passes through' => [777777, 200, 777777],
            'non-numeric falls back to the caller default' => ['abc', 200, 200],
            'empty string falls back' => ['', 200, 200],
            'null falls back' => [null, 200, 200],
            'array falls back' => [['1'], 200, 200],
        ];
    }

    /**
     * @dataProvider coerceTable
     */
    public function testCoerceIsExactlyTheParseHalfOfClamp(mixed $raw, int $default, int $want): void
    {
        $this->assertSame($want, PageLimit::coerce($raw, $default));
        if ($raw !== null && is_numeric($raw)) {
            // Where the inline ladder agreed, clamp() agrees too — same parse,
            // then bounds. This pins coerce() and clamp() on ONE parse rule.
            $this->assertSame(PageLimit::clamp($raw, $default), max(1, min(100, PageLimit::coerce($raw, $default))));
        }
    }

    // ------------------------------------------------------------------
    // BookController::listBooks — /books
    // ------------------------------------------------------------------

    /**
     * @return array<array{string|null, string|null, int, int}>
     */
    public static function limitOffsetTable(): array
    {
        return [
            'oversized limit clamps to 100' => ['999999', null, 100, 0],
            'negative limit floors at 1' => ['-5', null, 1, 0],
            'junk limit falls back to 50' => ['abc', null, 50, 0],
            'absent pair defaults' => [null, null, 50, 0],
            'negative offset floors at 0' => ['10', '-7', 10, 0],
            'in-range pair rides through' => ['25', '30', 25, 30],
        ];
    }

    /**
     * @dataProvider limitOffsetTable
     */
    public function testBookListParsesPagingThroughThePolicy(
        ?string $limit,
        ?string $offset,
        int $wantLimit,
        int $wantOffset,
    ): void {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->expects($this->once())->method('getByLibrary')
            ->with('lib-1', $wantLimit, $wantOffset)->willReturn([]);
        $controller = new BookController($itemRepo, $this->createMock(LibraryManager::class), $this->opds());

        $request = $this->queryRequest(['library_id' => 'lib-1', 'limit' => $limit, 'offset' => $offset]);
        $body = $this->json($controller->listBooks($request));

        $this->assertSame($wantLimit, $body['limit'], 'the echoed limit is the bound that reached SQL');
        $this->assertSame($wantOffset, $body['offset']);
        $this->assertSame([], $body['books']);
    }

    // ------------------------------------------------------------------
    // BookController::opdsLibraryBooks — the OPDS acquisition feed
    // ------------------------------------------------------------------

    /**
     * @dataProvider limitOffsetTable
     */
    public function testOpdsAcquisitionFeedPagesThroughThePolicy(
        ?string $limit,
        ?string $offset,
        int $wantLimit,
        int $wantOffset,
    ): void {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('getByLibrary')->with('lib-book-1', $wantLimit, $wantOffset)->willReturn([]);
        $itemRepo->method('countByType')->willReturn(0);
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->method('getLibrary')->willReturn(
            ['id' => 'lib-book-1', 'name' => 'My Books', 'type' => 'book']
        );
        $controller = new BookController($itemRepo, $libraryManager, $this->opds($itemRepo));

        $response = $controller->opdsLibraryBooks(
            $this->queryRequest(['limit' => $limit, 'offset' => $offset]),
            ['id' => 'lib-book-1'],
        );

        $this->assertSame(200, $response->statusCode);
    }

    // ------------------------------------------------------------------
    // AudiobookController::listAudiobooks — /audiobooks
    // ------------------------------------------------------------------

    /**
     * @dataProvider limitOffsetTable
     */
    public function testAudiobookListParsesPagingThroughThePolicy(
        ?string $limit,
        ?string $offset,
        int $wantLimit,
        int $wantOffset,
    ): void {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->expects($this->once())->method('getByLibrary')
            ->with('lib-1', $wantLimit, $wantOffset)->willReturn([]);
        $controller = new AudiobookController($itemRepo, $this->createMock(AudiobookLibraryManager::class));

        $request = $this->queryRequest(['library_id' => 'lib-1', 'limit' => $limit, 'offset' => $offset]);
        $body = $this->json($controller->listAudiobooks($request));

        $this->assertSame($wantLimit, $body['limit']);
        $this->assertSame($wantOffset, $body['offset']);
        $this->assertSame([], $body['audiobooks']);
    }

    // ------------------------------------------------------------------
    // harness
    // ------------------------------------------------------------------

    private function opds(?ItemRepository $repo = null): OpdsFeedBuilder
    {
        return new OpdsFeedBuilder($repo ?? $this->createMock(ItemRepository::class), 'http://localhost:8080');
    }

    /**
     * @param array<string, string|null> $query
     */
    private function queryRequest(array $query): Request
    {
        $request = new Request();
        $request->query = array_filter($query, static fn(?string $v): bool => $v !== null);

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $decoded = json_decode((string) $response->body, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
