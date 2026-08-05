<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\RatingGate;
use Phlix\Server\Http\Controllers\MostWatchedController;
use Phlix\Server\Http\Middleware\AuthMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Router;
use Phlix\Stats\StatsCollector;
use PHPUnit\Framework\TestCase;

/**
 * S31: public "Most Watched" rail — GLOBAL trending fed by
 * StatsCollector::getTopMedia(), shaped through MediaItemShaper like the other
 * media-list rails, and gated by AuthMiddleware to match the home-rail audience.
 *
 * S213 adds the parental half: the aggregate is server-wide, so the rail must
 * post-filter through {@see RatingGate} or a rating-capped child profile sees
 * the server's most-watched R-rated titles on the first screen it loads.
 */
final class MostWatchedControllerTest extends TestCase
{
    /**
     * A REAL {@see RatingGate} (it is final — not mockable) over test doubles,
     * resolving to `$filter` for any signed-in account.
     *
     * `$effective` seeds {@see ItemRepository::effectiveContentRatingsForIds()}
     * for rows that carry no `content_rating` of their own.
     *
     * @param array{allowedRatings: list<string>, allowUnrated: bool}|null $filter
     * @param array<string, string|null>                                   $effective
     */
    private function gateResolving(?array $filter, array $effective = []): RatingGate
    {
        $gateItems = $this->createMock(ItemRepository::class);
        $gateItems->method('effectiveContentRatingsForIds')->willReturn($effective);

        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->method('getActiveRatingFilter')->willReturn($filter);

        // Non-admin account, so the owner shortcut does NOT short-circuit the cap.
        $users = $this->createMock(UserRepository::class);
        $users->method('findById')->willReturn(null);

        return new RatingGate($gateItems, $profiles, $users);
    }

    /**
     * The owner / un-capped-profile gate: a strict no-op, which is the posture
     * every pre-S213 assertion in this file was written against.
     */
    private function unCappedGate(): RatingGate
    {
        return $this->gateResolving(null);
    }

    /**
     * The caller this handler actually has: a signed-in account. The route lives
     * inside an `AuthMiddleware` group, so an unidentified request is 401'd
     * before the handler runs — and since S235 the gate resolves a DENY-ALL cap
     * for one, which would empty the rail. Fixtures here therefore name a user
     * rather than defaulting to an anonymous `new Request()`.
     */
    private function signedInRequest(): Request
    {
        $request = new Request();
        $request->userId = 'user-1';

        return $request;
    }

    /**
     * 🚨 S213 — THE DEFECT. A PG-capped active profile must receive ZERO
     * over-cap rows from a fixture that contains both, and the envelope's
     * `total` must count only what it can actually see (so the count does not
     * leak how many titles were hidden).
     *
     * Asserted on the RETURNED ROWS, deliberately not on the route table or the
     * middleware list: the route was always correctly registered and
     * AuthMiddleware-gated, and the hole lived entirely inside the handler.
     */
    public function testACappedProfileGetsZeroOverCapRowsFromTheRail(): void
    {
        $stats = $this->createMock(StatsCollector::class);
        $stats->method('getTopMedia')->willReturn([
            ['media_item_id' => 'r-1',  'play_count' => 99],
            ['media_item_id' => 'pg-1', 'play_count' => 50],
            ['media_item_id' => 'nc-1', 'play_count' => 20],
        ]);

        $items = $this->createMock(ItemRepository::class);
        $items->method('findByIds')->willReturn([
            ['id' => 'r-1',  'name' => 'Very Adult Blockbuster', 'type' => 'movie',
                'content_rating' => 'R', 'metadata' => []],
            ['id' => 'pg-1', 'name' => 'Family Film', 'type' => 'movie',
                'content_rating' => 'PG', 'metadata' => []],
            ['id' => 'nc-1', 'name' => 'Adults Only', 'type' => 'movie',
                'content_rating' => 'NC-17', 'metadata' => []],
        ]);

        $controller = new MostWatchedController(
            $stats,
            $items,
            $this->gateResolving(['allowedRatings' => ['G', 'PG'], 'allowUnrated' => false])
        );

        $request = new Request();
        $request->userId = 'kid-account';

        $response = $controller->mostWatched($request, []);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);

        $this->assertSame(
            ['pg-1'],
            array_column($body['items'], 'id'),
            'An over-cap title reached a PG-capped profile on the home screen.'
        );
        $this->assertSame(1, $body['total'], 'total must count the POST-cap list.');
    }

    /**
     * The inherited half: an episode row with a NULL own rating takes its
     * SERIES rating, so a capped profile cannot reach an over-cap series'
     * episodes through the rail either. Also pins that a genuinely-unrated row
     * is refused when the cap forbids unrated content.
     */
    public function testTheCapUsesTheEFFECTIVERatingNotJustTheOwnColumn(): void
    {
        $stats = $this->createMock(StatsCollector::class);
        $stats->method('getTopMedia')->willReturn([
            ['media_item_id' => 'ep-of-r-series',  'play_count' => 9],
            ['media_item_id' => 'ep-of-pg-series', 'play_count' => 8],
            ['media_item_id' => 'orphan',          'play_count' => 7],
        ]);

        $items = $this->createMock(ItemRepository::class);
        $items->method('findByIds')->willReturn([
            // Episodes carry NULL content_rating; the series above them decides.
            ['id' => 'ep-of-r-series', 'name' => 'E1', 'type' => 'episode',
                'content_rating' => null, 'parent_id' => 'series-r', 'metadata' => []],
            ['id' => 'ep-of-pg-series', 'name' => 'E1', 'type' => 'episode',
                'content_rating' => null, 'parent_id' => 'series-pg', 'metadata' => []],
            // No parent and no rating: genuinely unrated.
            ['id' => 'orphan', 'name' => 'Mystery', 'type' => 'movie',
                'content_rating' => null, 'metadata' => []],
        ]);

        $controller = new MostWatchedController(
            $stats,
            $items,
            $this->gateResolving(
                ['allowedRatings' => ['TV-G', 'TV-PG'], 'allowUnrated' => false],
                ['series-r' => 'TV-MA', 'series-pg' => 'TV-PG']
            )
        );

        $request = new Request();
        $request->userId = 'kid-account';

        $response = $controller->mostWatched($request, []);

        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame(['ep-of-pg-series'], array_column($body['items'], 'id'));
    }

    /**
     * THE NOISE CONTROL, and the reason the gate is safe to apply
     * unconditionally: the account OWNER / an un-capped profile resolves a null
     * filter, and the rail is then byte-for-byte what it was before S213 — the
     * same rows, in the same order, with the same total.
     */
    public function testAnUnCappedProfileSeesTheRailUnchanged(): void
    {
        $stats = $this->createMock(StatsCollector::class);
        $stats->method('getTopMedia')->willReturn([
            ['media_item_id' => 'r-1',  'play_count' => 99],
            ['media_item_id' => 'pg-1', 'play_count' => 50],
        ]);

        $items = $this->createMock(ItemRepository::class);
        $items->method('findByIds')->willReturn([
            ['id' => 'r-1',  'name' => 'Adult', 'type' => 'movie',
                'content_rating' => 'R', 'metadata' => []],
            ['id' => 'pg-1', 'name' => 'Family', 'type' => 'movie',
                'content_rating' => 'PG', 'metadata' => []],
        ]);

        $controller = new MostWatchedController($stats, $items, $this->unCappedGate());

        $request = new Request();
        $request->userId = 'owner-account';

        $response = $controller->mostWatched($request, []);

        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame(['r-1', 'pg-1'], array_column($body['items'], 'id'));
        $this->assertSame(2, $body['total']);
    }

    /**
     * The endpoint returns the top media, in play-count order, shaped and
     * TYPE-CORRECT (episode stays 'episode', track stays 'track' — never
     * mislabelled), in the same `{items,total,limit,offset}` envelope the sibling
     * `GET /api/v1/media` uses.
     */
    public function testReturnsTypeCorrectShapedTopMedia(): void
    {
        $stats = $this->createMock(StatsCollector::class);
        $stats->expects($this->once())
            ->method('getTopMedia')
            ->with(20) // default rail size
            ->willReturn([
                ['media_item_id' => 'm1', 'play_count' => 10, 'total_duration' => 100],
                ['media_item_id' => 'm2', 'play_count' => 7,  'total_duration' => 70],
            ]);

        $items = $this->createMock(ItemRepository::class);
        $items->expects($this->once())
            ->method('findByIds')
            ->with(['m1', 'm2'])
            ->willReturn([
                ['id' => 'm1', 'name' => 'Pilot', 'type' => 'episode', 'metadata' => []],
                ['id' => 'm2', 'name' => 'Track One', 'type' => 'track', 'metadata' => []],
            ]);

        $controller = new MostWatchedController($stats, $items, $this->unCappedGate());

        // S235: a signed-in caller, because that is the only audience the route
        // admits (see testAuthMiddlewareGuardsTheRail — an anonymous request is
        // 401'd by AuthMiddleware and never reaches this handler). Since S235 an
        // unidentified request resolves a DENY-ALL cap, so a `new Request()`
        // fixture here would be claiming a caller the route rejects.
        $response = $controller->mostWatched($this->signedInRequest(), []);

        $this->assertSame(200, $response->statusCode);

        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertIsArray($body['items']);
        $this->assertCount(2, $body['items']);
        $this->assertSame(2, $body['total']);
        $this->assertSame(20, $body['limit']);
        $this->assertSame(0, $body['offset']);

        // Type-correctness: the shaper preserves the real ENUM member.
        $this->assertSame('m1', $body['items'][0]['id']);
        $this->assertSame('episode', $body['items'][0]['type']);
        $this->assertSame('m2', $body['items'][1]['id']);
        $this->assertSame('track', $body['items'][1]['type']);
    }

    /**
     * A small `?limit=` is validated + honoured (below the ceiling) and passed
     * through getTopMedia()'s existing signature.
     */
    public function testHonoursLimitQueryParam(): void
    {
        $stats = $this->createMock(StatsCollector::class);
        $stats->expects($this->once())
            ->method('getTopMedia')
            ->with(5)
            ->willReturn([]);

        $items = $this->createMock(ItemRepository::class);
        $items->method('findByIds')->willReturn([]);

        $controller = new MostWatchedController($stats, $items, $this->unCappedGate());

        $request = new Request();
        $request->query = ['limit' => '5'];

        $response = $controller->mostWatched($request, []);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame(5, $body['limit']);
    }

    /**
     * An oversized `?limit=` is CLAMPED to the hard server-side ceiling
     * (PageLimit::MAX = 100) before it ever reaches getTopMedia(), so an
     * unbounded request cannot exhaust a resident worker. The clamped value is
     * both what getTopMedia() is asked for and what the envelope reports.
     */
    public function testClampsOverMaxLimitToCeiling(): void
    {
        $stats = $this->createMock(StatsCollector::class);
        $stats->expects($this->once())
            ->method('getTopMedia')
            ->with(100) // 999 clamped down to PageLimit::MAX
            ->willReturn([]);

        $items = $this->createMock(ItemRepository::class);
        $items->method('findByIds')->willReturn([]);

        $controller = new MostWatchedController($stats, $items, $this->unCappedGate());

        $request = new Request();
        $request->query = ['limit' => '999'];

        $response = $controller->mostWatched($request, []);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame(100, $body['limit']);
    }

    /**
     * When the server has no playback stats yet, getTopMedia() returns nothing:
     * the rail responds 200 with an EMPTY item list and total 0 (never a null or
     * a partially-shaped payload), so the SPA renders an empty rail cleanly.
     */
    public function testReturnsEmptyRailWhenNoStats(): void
    {
        $stats = $this->createMock(StatsCollector::class);
        $stats->expects($this->once())
            ->method('getTopMedia')
            ->with(20)
            ->willReturn([]);

        // With no IDs to hydrate, findByIds is called with an empty list.
        $items = $this->createMock(ItemRepository::class);
        $items->expects($this->once())
            ->method('findByIds')
            ->with([])
            ->willReturn([]);

        $controller = new MostWatchedController($stats, $items, $this->unCappedGate());

        // S235: a signed-in caller, because that is the only audience the route
        // admits (see testAuthMiddlewareGuardsTheRail — an anonymous request is
        // 401'd by AuthMiddleware and never reaches this handler). Since S235 an
        // unidentified request resolves a DENY-ALL cap, so a `new Request()`
        // fixture here would be claiming a caller the route rejects.
        $response = $controller->mostWatched($this->signedInRequest(), []);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame([], $body['items']);
        $this->assertSame(0, $body['total']);
        $this->assertSame(20, $body['limit']);
        $this->assertSame(0, $body['offset']);
    }

    /**
     * The play-count-descending order returned by getTopMedia() is preserved end
     * to end: the IDs are extracted in that exact order, handed to findByIds() in
     * that order, and the shaped rail comes back in that order (never re-sorted by
     * id or hydration order). This is the invariant that makes it a *ranked* rail.
     */
    public function testPreservesPlayCountOrderIntoFindByIds(): void
    {
        $stats = $this->createMock(StatsCollector::class);
        $stats->expects($this->once())
            ->method('getTopMedia')
            ->with(20)
            ->willReturn([
                ['media_item_id' => 'zeta',  'play_count' => 50],
                ['media_item_id' => 'alpha', 'play_count' => 40],
                ['media_item_id' => 'beta',  'play_count' => 30],
            ]);

        $items = $this->createMock(ItemRepository::class);
        $items->expects($this->once())
            ->method('findByIds')
            // Exact ranked order, NOT alphabetical — proves order is preserved.
            ->with(['zeta', 'alpha', 'beta'])
            ->willReturn([
                ['id' => 'zeta',  'name' => 'Z', 'type' => 'movie', 'metadata' => []],
                ['id' => 'alpha', 'name' => 'A', 'type' => 'movie', 'metadata' => []],
                ['id' => 'beta',  'name' => 'B', 'type' => 'movie', 'metadata' => []],
            ]);

        $controller = new MostWatchedController($stats, $items, $this->unCappedGate());

        // S235: a signed-in caller, because that is the only audience the route
        // admits (see testAuthMiddlewareGuardsTheRail — an anonymous request is
        // 401'd by AuthMiddleware and never reaches this handler). Since S235 an
        // unidentified request resolves a DENY-ALL cap, so a `new Request()`
        // fixture here would be claiming a caller the route rejects.
        $response = $controller->mostWatched($this->signedInRequest(), []);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame(
            ['zeta', 'alpha', 'beta'],
            array_column($body['items'], 'id')
        );
    }

    /**
     * Defensive ID extraction: a getTopMedia() row with a missing, empty, or
     * non-string media_item_id is skipped, so findByIds() is only ever asked for
     * the well-formed IDs and the rail never references a bogus row.
     */
    public function testSkipsRowsWithMissingOrEmptyMediaItemId(): void
    {
        $stats = $this->createMock(StatsCollector::class);
        $stats->method('getTopMedia')->willReturn([
            ['media_item_id' => 'good-1', 'play_count' => 9],
            ['media_item_id' => '',       'play_count' => 8], // empty → skipped
            ['play_count' => 7],                              // missing → skipped
            ['media_item_id' => 'good-2', 'play_count' => 6],
        ]);

        $items = $this->createMock(ItemRepository::class);
        $items->expects($this->once())
            ->method('findByIds')
            ->with(['good-1', 'good-2']) // only the valid IDs survive
            ->willReturn([
                ['id' => 'good-1', 'name' => 'One', 'type' => 'movie', 'metadata' => []],
                ['id' => 'good-2', 'name' => 'Two', 'type' => 'movie', 'metadata' => []],
            ]);

        $controller = new MostWatchedController($stats, $items, $this->unCappedGate());

        // S235: a signed-in caller, because that is the only audience the route
        // admits (see testAuthMiddlewareGuardsTheRail — an anonymous request is
        // 401'd by AuthMiddleware and never reaches this handler). Since S235 an
        // unidentified request resolves a DENY-ALL cap, so a `new Request()`
        // fixture here would be claiming a caller the route rejects.
        $response = $controller->mostWatched($this->signedInRequest(), []);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame(['good-1', 'good-2'], array_column($body['items'], 'id'));
    }

    /**
     * Auth posture: the rail is reachable by exactly the audience of the other
     * home rails — a signed-in user. Mirrors the Application wiring (the route in
     * an AuthMiddleware group), asserting an unauthenticated request is rejected
     * with 401 before the handler runs, while a signed-in one reaches it.
     */
    public function testAuthMiddlewareGuardsTheRail(): void
    {
        $stats = $this->createMock(StatsCollector::class);
        $stats->method('getTopMedia')->willReturn([]);
        $items = $this->createMock(ItemRepository::class);
        $items->method('findByIds')->willReturn([]);
        $controller = new MostWatchedController($stats, $items, $this->unCappedGate());

        $router = new Router();
        $router->group(
            '',
            static function (Router $r) use ($controller): void {
                $r->get('/api/v1/media/most-watched', [$controller, 'mostWatched']);
            },
            [new AuthMiddleware()]
        );

        // Unauthenticated → 401 (never reaches the controller).
        $anon = new Request();
        $anon->method = 'GET';
        $anon->path = '/api/v1/media/most-watched';
        $this->assertSame(401, $router->dispatch($anon)->statusCode);

        // Signed-in → the handler runs and returns the rail.
        $authed = new Request();
        $authed->method = 'GET';
        $authed->path = '/api/v1/media/most-watched';
        $authed->userId = 'user-1';
        $this->assertSame(200, $router->dispatch($authed)->statusCode);
    }
}
