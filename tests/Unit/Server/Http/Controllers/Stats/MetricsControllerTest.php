<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Stats;

use Phlix\Server\Http\Controllers\Stats\MetricsController;
use Phlix\Server\Http\Request;
use Phlix\Stats\Metrics\MetricsRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see MetricsController} (S2).
 */
final class MetricsControllerTest extends TestCase
{
    /**
     * @return MetricsRepositoryInterface&MockObject
     */
    private function mockRepo(): MetricsRepositoryInterface
    {
        return $this->createMock(MetricsRepositoryInterface::class);
    }

    public function testSnapshotCallsRepoWithDefaultWindow(): void
    {
        $repo = $this->mockRepo();
        $repo->expects($this->once())
            ->method('snapshot')
            ->with(60)
            ->willReturn(['bytes_in_per_sec' => 0]);

        $controller = new MetricsController($repo);
        $request = new Request();
        $request->body = [];

        $response = $controller->snapshot($request, []);

        $this->assertEquals(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('data', $body);
        $this->assertEquals(['bytes_in_per_sec' => 0], $body['data']);
    }

    public function testSnapshotUsesWindowQueryParam(): void
    {
        $repo = $this->mockRepo();
        $repo->expects($this->once())
            ->method('snapshot')
            ->with(120)
            ->willReturn(['bytes_in_per_sec' => 100]);

        $controller = new MetricsController($repo);
        $request = new Request();
        $request->body = ['window' => '120'];

        $response = $controller->snapshot($request, []);

        $this->assertEquals(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('data', $body);
    }

    public function testResponsesUseSuccessDataEnvelope(): void
    {
        // The envelope is {success:true, data:...} — matching the spec and the hub
        // controller (the server had drifted to a bare {data}).
        $repo = $this->mockRepo();
        $repo->method('snapshot')->willReturn(['bytes_in_per_sec' => 1]);

        $controller = new MetricsController($repo);
        $request = new Request();
        $request->body = [];

        /** @var array<string, mixed> $body */
        $body = json_decode($controller->snapshot($request, [])->body, true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('success', $body);
        $this->assertTrue($body['success']);
        $this->assertSame(['bytes_in_per_sec' => 1], $body['data']);
    }

    public function testHistoryCallsRepoWithDefaultArgs(): void
    {
        $expectedRows = [
            [
                'bucket' => 1000,
                'bytes_in' => 500,
                'bytes_out' => 1000,
                'requests' => 10,
                'errors' => 0,
                'p50_ms' => 5,
                'p95_ms' => 20,
            ],
        ];
        $repo = $this->mockRepo();
        $repo->expects($this->once())
            ->method('history')
            ->with(60, 60)
            ->willReturn($expectedRows);

        $controller = new MetricsController($repo);
        $request = new Request();
        $request->body = [];

        $response = $controller->history($request, []);

        $this->assertEquals(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('data', $body);
        $this->assertEquals($expectedRows, $body['data']);
    }

    public function testHistoryUsesQueryParams(): void
    {
        $repo = $this->mockRepo();
        $repo->expects($this->once())
            ->method('history')
            ->with(30, 30)
            ->willReturn([]);

        $controller = new MetricsController($repo);
        $request = new Request();
        $request->body = ['minutes' => '30', 'resolution' => '30'];

        $response = $controller->history($request, []);

        $this->assertEquals(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('data', $body);
    }

    public function testConnectionsCallsRepoWithDefaultTtl(): void
    {
        $expectedConnections = [
            ['id' => 'ws-1', 'kind' => 'websocket', 'user_id' => 'u1', 'remote_ip' => '127.0.0.1'],
        ];
        $repo = $this->mockRepo();
        $repo->expects($this->once())
            ->method('liveConnections')
            ->with(15)
            ->willReturn($expectedConnections);

        $controller = new MetricsController($repo);
        $request = new Request();
        $request->body = [];

        $response = $controller->connections($request, []);

        $this->assertEquals(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('data', $body);
        $this->assertEquals($expectedConnections, $body['data']);
    }

    public function testConnectionsUsesTtlQueryParam(): void
    {
        $repo = $this->mockRepo();
        $repo->expects($this->once())
            ->method('liveConnections')
            ->with(30)
            ->willReturn([]);

        $controller = new MetricsController($repo);
        $request = new Request();
        $request->body = ['ttl' => '30'];

        $response = $controller->connections($request, []);

        $this->assertEquals(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('data', $body);
    }

    public function testRoutesCallsRepoWithDefaultArgs(): void
    {
        $expectedRoutes = [
            [
                'route' => '/api/v1/media',
                'method' => 'GET',
                'request_count' => 100,
                'error_count' => 2,
                'avg_ms' => 16,
                'max_ms' => 50,
            ],
        ];
        $repo = $this->mockRepo();
        $repo->expects($this->once())
            ->method('topRoutes')
            ->with(15, 20)
            ->willReturn($expectedRoutes);

        $controller = new MetricsController($repo);
        $request = new Request();
        $request->body = [];

        $response = $controller->routes($request, []);

        $this->assertEquals(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('data', $body);
        $this->assertEquals($expectedRoutes, $body['data']);
    }

    public function testRoutesUsesQueryParams(): void
    {
        $repo = $this->mockRepo();
        $repo->expects($this->once())
            ->method('topRoutes')
            ->with(30, 50)
            ->willReturn([]);

        $controller = new MetricsController($repo);
        $request = new Request();
        $request->body = ['minutes' => '30', 'limit' => '50'];

        $response = $controller->routes($request, []);

        $this->assertEquals(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('data', $body);
    }

    // -- SRV-3.1 (bug #7) bounded-parameter pins ---------------------------------
    //
    // Same Workerman-resident-memory law as the pagination ceiling at
    // \Phlix\Common\Http\PageLimit: every caller-supplied number here flows
    // into repository scans/loops with NO upper bound today, so one admin-page
    // refresh can pin a worker scanning years of buckets. The caps are the
    // named MAX_* constants on the controller; valid in-range values and the
    // documented body channel stay byte-identical (pinned above and below).

    public function testSnapshotCapsOversizedWindow(): void
    {
        $repo = $this->mockRepo();
        $repo->expects($this->once())
            ->method('snapshot')
            ->with(604800)
            ->willReturn([]);

        $controller = new MetricsController($repo);
        $request = new Request();
        $request->body = ['window' => '999999999'];

        $controller->snapshot($request, []);
    }

    public function testHistoryCapsOversizedMinutesAndResolution(): void
    {
        $repo = $this->mockRepo();
        $repo->expects($this->once())
            ->method('history')
            ->with(10080, 86400)
            ->willReturn([]);

        $controller = new MetricsController($repo);
        $request = new Request();
        $request->body = ['minutes' => '999999999', 'resolution' => '999999999'];

        $controller->history($request, []);
    }

    public function testConnectionsCapsOversizedTtl(): void
    {
        $repo = $this->mockRepo();
        $repo->expects($this->once())
            ->method('liveConnections')
            ->with(604800)
            ->willReturn([]);

        $controller = new MetricsController($repo);
        $request = new Request();
        $request->body = ['ttl' => '999999999'];

        $controller->connections($request, []);
    }

    public function testRoutesCapsOversizedMinutesAndLimit(): void
    {
        $repo = $this->mockRepo();
        $repo->expects($this->once())
            ->method('topRoutes')
            ->with(10080, 100)
            ->willReturn([]);

        $controller = new MetricsController($repo);
        $request = new Request();
        $request->body = ['minutes' => '999999999', 'limit' => '1000000'];

        $controller->routes($request, []);
    }

    public function testQueryChannelParametersAreHonored(): void
    {
        $repo = $this->mockRepo();
        $repo->expects($this->once())
            ->method('snapshot')
            ->with(120)
            ->willReturn([]);

        $controller = new MetricsController($repo);
        $request = new Request();
        $request->query = ['window' => '120'];

        $controller->snapshot($request, []);
    }

    public function testNonNumericParametersFallBackToDefaults(): void
    {
        $repo = $this->mockRepo();
        $repo->expects($this->once())
            ->method('history')
            ->with(60, 60)
            ->willReturn([]);

        $controller = new MetricsController($repo);
        $request = new Request();
        $request->body = ['minutes' => 'not-a-number', 'resolution' => ''];

        $controller->history($request, []);
    }

    public function testZeroAndNegativeParametersClampToOne(): void
    {
        $repo = $this->mockRepo();
        $repo->expects($this->once())
            ->method('topRoutes')
            ->with(1, 1)
            ->willReturn([]);

        $controller = new MetricsController($repo);
        $request = new Request();
        $request->body = ['minutes' => '-5', 'limit' => '0'];

        $controller->routes($request, []);
    }
}
