<?php

/**
 * Phlix media server component: Stats.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Stats;

use Phlix\Server\Http\Controllers\Stats\MetricsController;
use Phlix\Server\Http\Request;
use Phlix\Stats\Metrics\MetricsRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Characterization pins for the server metrics-controller parameter surface
 * (finding A-13, wave-5).
 *
 * These tests pin WHAT THE SERVER ACCEPTS TODAY — they are not a ratification
 * of the behavior. The hub copy of MetricsController reads the same four
 * endpoints through a materially different parameter law: query-string only
 * (no body channel), NO upper ceilings, and `filter_var(FILTER_VALIDATE_INT)`
 * coercion instead of the shared `PageLimit::coerce` (`is_numeric ? (int) :
 * default`). See `phlix-hub/tests/Unit/Http/Controllers/Stats/`
 * `MetricsControllerParamSourceCharacterizationTest.php` for the mirrored
 * hub-side pins; the two files must be read together and any harmonization
 * commit must flip BOTH at once (register: SRV-METRICS-A13).
 *
 * The server side additionally documents the historical load-bearing body
 * fallback: pre-fix, these endpoints read `input()` (the decoded body) only,
 * which never saw the `?window=` surfaces the docs advertise — so the fix
 * layered query-over-body and the body channel stayed for compatibility.
 * Estate client census (2026-11-19): phlix-ui `ApiClient.get()` and the
 * console's `ApiClient::send('GET', …)` both send these parameters as the
 * query string exclusively — no first-party client sends them in a body.
 *
 * @package Phlix\Tests\Unit\Server\Http\Controllers\Stats
 */
final class MetricsControllerParamSourceCharacterizationTest extends TestCase
{
    /**
     * @return MetricsRepositoryInterface&MockObject
     */
    private function mockRepo(): MetricsRepositoryInterface
    {
        return $this->createMock(MetricsRepositoryInterface::class);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    private function request(array $query = [], array $body = []): Request
    {
        $request          = new Request();
        $request->query   = $query;
        $request->body    = $body;

        return $request;
    }

    // ---- defaults ----

    public function testDefaultsWhenNothingIsSupplied(): void
    {
        $repo = $this->mockRepo();
        $repo->expects($this->once())->method('snapshot')->with(60)->willReturn([]);
        $repo->expects($this->once())->method('history')->with(60, 60)->willReturn([]);
        $repo->expects($this->once())->method('liveConnections')->with(15)->willReturn([]);
        $repo->expects($this->once())->method('topRoutes')->with(15, 20)->willReturn([]);

        $controller = new MetricsController($repo);
        $controller->snapshot($this->request(), []);
        $controller->history($this->request(), []);
        $controller->connections($this->request(), []);
        $controller->routes($this->request(), []);
    }

    // ---- channels: query wins, body is the legacy fallback ----

    public function testQueryStringChannelIsRead(): void
    {
        $repo = $this->mockRepo();
        $repo->expects($this->once())->method('snapshot')->with(120)->willReturn([]);

        $controller = new MetricsController($repo);
        $controller->snapshot($this->request(['window' => '120']), []);
    }

    public function testBodyChannelIsReadWhenTheQueryIsAbsent(): void
    {
        $repo = $this->mockRepo();
        $repo->expects($this->once())->method('snapshot')->with(120)->willReturn([]);
        $repo->expects($this->once())->method('history')->with(90, 30)->willReturn([]);

        $controller = new MetricsController($repo);
        $controller->snapshot($this->request([], ['window' => '120']), []);
        $controller->history($this->request([], ['minutes' => '90', 'resolution' => '30']), []);
    }

    public function testQueryWinsOverBodyForTheSameKey(): void
    {
        $repo = $this->mockRepo();
        $repo->expects($this->once())->method('snapshot')->with(5)->willReturn([]);

        $controller = new MetricsController($repo);
        $controller->snapshot($this->request(['window' => '5'], ['window' => '999']), []);
    }

    // ---- ceilings (the law the hub side does not have) ----

    public function testDocumentedCeilingConstantsArePublicLaw(): void
    {
        $this->assertSame(10080, MetricsController::MAX_LOOKBACK_MINUTES);
        $this->assertSame(604800, MetricsController::MAX_WINDOW_SECONDS);
        $this->assertSame(604800, MetricsController::MAX_TTL_SECONDS);
        $this->assertSame(86400, MetricsController::MAX_RESOLUTION_SECONDS);
    }

    public function testOversizedValuesAreClampedToTheirCeilings(): void
    {
        $repo = $this->mockRepo();
        $repo->expects($this->once())->method('snapshot')->with(604800)->willReturn([]);
        $repo->expects($this->once())->method('history')->with(10080, 86400)->willReturn([]);
        $repo->expects($this->once())->method('liveConnections')->with(604800)->willReturn([]);
        // `limit` rides the shared pagination policy [PageLimit::MIN, PageLimit::MAX] = 1..100.
        $repo->expects($this->once())->method('topRoutes')->with(10080, 100)->willReturn([]);

        $controller = new MetricsController($repo);
        $controller->snapshot($this->request(['window' => '9999999']), []);
        $controller->history($this->request(['minutes' => '999999', 'resolution' => '999999']), []);
        $controller->connections($this->request(['ttl' => '9999999']), []);
        $controller->routes($this->request(['minutes' => '999999', 'limit' => '5000']), []);
    }

    public function testNegativeValuesClampToTheFloorOfOne(): void
    {
        $repo = $this->mockRepo();
        $repo->expects($this->once())->method('snapshot')->with(1)->willReturn([]);
        $repo->expects($this->once())->method('topRoutes')->with(1, 1)->willReturn([]);

        $controller = new MetricsController($repo);
        $controller->snapshot($this->request(['window' => '-5']), []);
        $controller->routes($this->request(['minutes' => '-5', 'limit' => '-5']), []);
    }

    // ---- coercion law: PageLimit::coerce is `is_numeric ? (int) : default` ----

    public function testCoercionLawForEdgeShapes(): void
    {
        // '12.5' / '1e3' / '12 ' are is_numeric() (PHP 8 allows trailing
        // whitespace in numeric strings) → cast toward zero; booleans are
        // NOT is_numeric() → the action's default. ('12 ' and ' 12' AGREE
        // with the hub's filter_var law; '12.5'/'1e3'/true DISAGREE — the
        // coercion split named in A-13.)
        $observed = [];
        $repo     = $this->mockRepo();
        $repo->expects($this->exactly(4))->method('snapshot')
            ->willReturnCallback(function (int $window) use (&$observed): array {
                $observed[] = $window;

                return [];
            });

        $controller = new MetricsController($repo);
        $controller->snapshot($this->request(['window' => '12.5']), []);
        $controller->snapshot($this->request(['window' => '1e3']), []);
        $controller->snapshot($this->request(['window' => '12 ']), []);
        $controller->snapshot($this->request(['window' => true]), []);

        $this->assertSame(
            [12, 1000, 12, 60],
            $observed,
            'PageLimit::coerce law (server); the hub filter_var law yields 60/60/12/1 '
            . 'for the same shapes — see the hub copy',
        );
    }

    public function testNonNumericGarbageFallsBackToTheDefault(): void
    {
        $repo = $this->mockRepo();
        $repo->expects($this->once())->method('history')->with(60, 60)->willReturn([]);

        $controller = new MetricsController($repo);
        $controller->history($this->request(['minutes' => '60x', 'resolution' => ['60']]), []);
    }
}
