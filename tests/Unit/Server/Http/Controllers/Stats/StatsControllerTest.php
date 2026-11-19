<?php

/**
 * Phlix media server component: tests for the Stats admin controller.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Stats;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\Server\Http\Controllers\Stats\StatsController;
use Phlix\Server\Http\Request;
use Phlix\Stats\StatsCollector;

/**
 * SRV-3.1 (bug #7) — pagination-clamp pins for
 * {@see StatsController::topUsers()} / {@see StatsController::topMedia()}.
 *
 * The ceiling law lives at {@see \Phlix\Common\Http\PageLimit}: under
 * Workerman (resident memory) an unclamped `limit` is not "a big page", it is
 * a memory-exhaustion / unbounded-scan vector straight into the collector's
 * `LIMIT ?` binding. Valid in-range client behavior is pinned byte-identical
 * here as well — a clamp that changes answers for legal callers is not a fix.
 *
 * Channel note pinned by test_query_channel_*: `Request::input()` reads the
 * decoded BODY only, so the documented `?limit=` surface previously reached
 * nothing; the controller now honors query-first, body-second, then clamps.
 */
final class StatsControllerTest extends TestCase
{
    /**
     * @return StatsCollector&MockObject
     */
    private function mockCollector(): StatsCollector
    {
        return $this->createMock(StatsCollector::class);
    }

    public function test_top_media_clamps_oversized_body_limit(): void
    {
        $collector = $this->mockCollector();
        $collector->expects($this->once())
            ->method('getTopMedia')
            ->with(100, null)
            ->willReturn([]);

        $request = new Request();
        $request->body = ['limit' => '1000000'];

        $response = (new StatsController($collector))->topMedia($request, []);
        $this->assertSame(200, $response->statusCode);
    }

    public function test_top_users_clamps_oversized_body_limit(): void
    {
        $collector = $this->mockCollector();
        $collector->expects($this->once())
            ->method('getTopUsers')
            ->with(100, null)
            ->willReturn([]);

        $request = new Request();
        $request->body = ['limit' => '1000000'];

        $response = (new StatsController($collector))->topUsers($request, []);
        $this->assertSame(200, $response->statusCode);
    }

    public function test_top_media_clamps_oversized_query_limit(): void
    {
        $collector = $this->mockCollector();
        $collector->expects($this->once())
            ->method('getTopMedia')
            ->with(100, null)
            ->willReturn([]);

        $request = new Request();
        $request->query = ['limit' => '99999999'];

        $response = (new StatsController($collector))->topMedia($request, []);
        $this->assertSame(200, $response->statusCode);
    }

    public function test_top_media_honors_a_query_channel_limit(): void
    {
        $collector = $this->mockCollector();
        $collector->expects($this->once())
            ->method('getTopMedia')
            ->with(7, null)
            ->willReturn([]);

        $request = new Request();
        $request->query = ['limit' => '7'];

        $response = (new StatsController($collector))->topMedia($request, []);
        $this->assertSame(200, $response->statusCode);
    }

    public function test_top_media_passes_a_valid_body_limit_unchanged(): void
    {
        $collector = $this->mockCollector();
        $collector->expects($this->once())
            ->method('getTopMedia')
            ->with(25, null)
            ->willReturn([]);

        $request = new Request();
        $request->body = ['limit' => '25'];

        $response = (new StatsController($collector))->topMedia($request, []);
        $this->assertSame(200, $response->statusCode);
    }

    public function test_top_users_passes_a_valid_body_limit_unchanged(): void
    {
        $collector = $this->mockCollector();
        $collector->expects($this->once())
            ->method('getTopUsers')
            ->with(5, null)
            ->willReturn([]);

        $request = new Request();
        $request->body = ['limit' => '5'];

        $response = (new StatsController($collector))->topUsers($request, []);
        $this->assertSame(200, $response->statusCode);
    }

    public function test_top_media_defaults_to_ten_when_nothing_is_supplied(): void
    {
        $collector = $this->mockCollector();
        $collector->expects($this->once())
            ->method('getTopMedia')
            ->with(10, null)
            ->willReturn([]);

        $request = new Request();

        $response = (new StatsController($collector))->topMedia($request, []);
        $this->assertSame(200, $response->statusCode);
    }

    public function test_top_media_falls_back_to_ten_on_non_numeric_input(): void
    {
        $collector = $this->mockCollector();
        $collector->expects($this->once())
            ->method('getTopMedia')
            ->with(10, null)
            ->willReturn([]);

        $request = new Request();
        $request->body = ['limit' => 'not-a-number'];

        $response = (new StatsController($collector))->topMedia($request, []);
        $this->assertSame(200, $response->statusCode);
    }

    public function test_top_media_clamps_zero_limit_to_the_minimum(): void
    {
        $collector = $this->mockCollector();
        $collector->expects($this->once())
            ->method('getTopMedia')
            ->with(1, null)
            ->willReturn([]);

        $request = new Request();
        $request->body = ['limit' => '0'];

        $response = (new StatsController($collector))->topMedia($request, []);
        $this->assertSame(200, $response->statusCode);
    }

    public function test_top_users_clamps_negative_limit_to_the_minimum(): void
    {
        $collector = $this->mockCollector();
        $collector->expects($this->once())
            ->method('getTopUsers')
            ->with(1, null)
            ->willReturn([]);

        $request = new Request();
        $request->body = ['limit' => '-5'];

        $response = (new StatsController($collector))->topUsers($request, []);
        $this->assertSame(200, $response->statusCode);
    }
}
