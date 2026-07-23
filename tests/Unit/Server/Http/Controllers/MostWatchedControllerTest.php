<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Media\Library\ItemRepository;
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
 * @covers \Phlix\Server\Http\Controllers\MostWatchedController
 */
final class MostWatchedControllerTest extends TestCase
{
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

        $controller = new MostWatchedController($stats, $items);

        $response = $controller->mostWatched(new Request(), []);

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
     * A `?limit=` is validated + clamped and passed through getTopMedia()'s
     * existing signature.
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

        $controller = new MostWatchedController($stats, $items);

        $request = new Request();
        $request->query = ['limit' => '5'];

        $response = $controller->mostWatched($request, []);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame(5, $body['limit']);
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
        $controller = new MostWatchedController($stats, $items);

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
