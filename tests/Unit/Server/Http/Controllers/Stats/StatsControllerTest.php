<?php

/**
 * Phlix media server component: tests for the Stats admin controller.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Stats;

use DateTime;
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

    /**
     * SRV-3 review (minor): `since` must follow the SAME channel law as
     * `limit` — the method docblocks advertise `?since=2024-01-01` and
     * phlix-console-client builds it into the query string, so a body-only
     * read made that param dead-on-arrival. Empty body + query since must
     * filter. RED while `since` is read through input() alone.
     */
    public function test_top_media_honors_a_query_channel_since(): void
    {
        $collector = $this->mockCollector();
        $collector->expects($this->once())
            ->method('getTopMedia')
            ->with(10, $this->callback(
                static fn (mixed $d): bool => $d instanceof DateTime && $d->format('Y-m-d') === '2024-01-01'
            ))
            ->willReturn([]);

        $request = new Request();
        $request->query = ['since' => '2024-01-01'];

        $response = (new StatsController($collector))->topMedia($request, []);
        $this->assertSame(200, $response->statusCode);
    }

    public function test_top_users_honors_a_query_channel_since(): void
    {
        $collector = $this->mockCollector();
        $collector->expects($this->once())
            ->method('getTopUsers')
            ->with(10, $this->callback(
                static fn (mixed $d): bool => $d instanceof DateTime && $d->format('Y-m-d') === '2024-01-01'
            ))
            ->willReturn([]);

        $request = new Request();
        $request->query = ['since' => '2024-01-01'];

        $response = (new StatsController($collector))->topUsers($request, []);
        $this->assertSame(200, $response->statusCode);
    }

    /**
     * Honest control (green before and after): the pre-existing BODY channel
     * keeps working byte-identically — query-first, body-second is an
     * addition, never a replacement, so existing senders are untouched.
     */
    public function test_top_media_still_honors_a_body_channel_since(): void
    {
        $collector = $this->mockCollector();
        $collector->expects($this->once())
            ->method('getTopMedia')
            ->with(10, $this->callback(
                static fn (mixed $d): bool => $d instanceof DateTime && $d->format('Y-m-d') === '2024-02-02'
            ))
            ->willReturn([]);

        $request = new Request();
        $request->body = ['since' => '2024-02-02'];

        $response = (new StatsController($collector))->topMedia($request, []);
        $this->assertSame(200, $response->statusCode);
    }

    /**
     * Honest control (green after the fix): BOTH channels carry the same law
     * the clamp side already follows — when both are present the query wins.
     */
    public function test_top_media_query_channel_since_wins_over_the_body(): void
    {
        $collector = $this->mockCollector();
        $collector->expects($this->once())
            ->method('getTopMedia')
            ->with(10, $this->callback(
                static fn (mixed $d): bool => $d instanceof DateTime && $d->format('Y-m-d') === '2024-01-01'
            ))
            ->willReturn([]);

        $request = new Request();
        $request->query = ['since' => '2024-01-01'];
        $request->body = ['since' => '2024-09-09'];

        $response = (new StatsController($collector))->topMedia($request, []);
        $this->assertSame(200, $response->statusCode);
    }

    /**
     * Honest control (green after the fix): routing through rawParam does NOT
     * alter parseDate's documented leniency — a malformed query value yields
     * "now" exactly like the same malformed value in the body always did
     * (the guard at the call site only skips the FILTER when the param is
     * entirely absent).
     */
    public function test_top_media_malformed_query_since_uses_the_existing_parse_date_leniency(): void
    {
        $collector = $this->mockCollector();
        $collector->expects($this->once())
            ->method('getTopMedia')
            ->with(10, $this->callback(
                static function (mixed $d): bool {
                    if (!$d instanceof DateTime) {
                        return false;
                    }
                    return abs($d->getTimestamp() - time()) < 60;
                }
            ))
            ->willReturn([]);

        $request = new Request();
        $request->query = ['since' => 'nonsense'];

        $response = (new StatsController($collector))->topMedia($request, []);
        $this->assertSame(200, $response->statusCode);
    }
}
