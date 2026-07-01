<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Stats;

use Phlix\Server\Http\Controllers\Stats\MetricsController;
use Phlix\Server\Http\Request;
use Phlix\Stats\Metrics\MetricsRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see MetricsController} (S2).
 *
 * @covers \Phlix\Server\Http\Controllers\Stats\MetricsController
 */
final class MetricsControllerTest extends TestCase
{
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
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('data', $body);
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
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('data', $body);
    }
}
