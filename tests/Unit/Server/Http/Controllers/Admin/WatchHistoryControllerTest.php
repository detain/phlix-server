<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Admin;

use Phlix\Admin\WatchHistoryService;
use Phlix\Server\Http\Controllers\Admin\WatchHistoryController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for {@see WatchHistoryController::index()}.
 *
 * Auth (401/403) is enforced by {@see \Phlix\Server\Http\Middleware\AdminMiddleware}
 * upstream of this controller; here we assert the controller's own behaviour
 * given an already-authenticated admin request — limit clamping, the
 * userId/libraryId passthrough (present vs empty/absent → null), and the
 * `{success, data, count}` envelope with a 200 status.
 */
final class WatchHistoryControllerTest extends TestCase
{
    /**
     * @return \PHPUnit\Framework\MockObject\MockObject&WatchHistoryService
     */
    private function mockService(): WatchHistoryService
    {
        // The service's only ctor arg is a DB connection; disable the ctor so we
        // don't need a real one for these controller-level assertions.
        return $this->getMockBuilder(WatchHistoryService::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    /**
     * @param array<string, mixed> $query
     */
    private function req(array $query): Request
    {
        $r = new Request();
        $r->userId = 'admin-1';
        $r->query = $query;
        return $r;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function testDefaultsWhenNoQueryParams(): void
    {
        $sampleRows = [
            [
                'id' => 'wh-1',
                'media_item_id' => 'media-9',
                'media_name' => 'Test Movie',
                'media_type' => 'movie',
                'library_id' => 'lib-3',
                'user_id' => 'user-7',
                'username' => 'alice',
                'display_name' => '',
                'profile_name' => 'Kids',
                'last_watched_at' => '2026-06-01 12:00:00',
                'completed_at' => '',
                'progress_percent' => 42.5,
                'playback_status' => 'paused',
            ],
        ];

        $service = $this->mockService();
        $service->expects($this->once())
            ->method('getRecentWatchHistory')
            ->with(50, null, null)
            ->willReturn($sampleRows);

        $controller = new WatchHistoryController($service);
        $response = $controller->index($this->req([]), []);

        $this->assertSame(200, $response->statusCode);
        $body = $this->decode($response->body);
        $this->assertTrue($body['success']);
        $this->assertSame($sampleRows, $body['data']);
        $this->assertSame(1, $body['count']);
    }

    public function testLimitClampedToUpperBound(): void
    {
        $service = $this->mockService();
        $service->expects($this->once())
            ->method('getRecentWatchHistory')
            ->with(200, null, null)
            ->willReturn([]);

        $controller = new WatchHistoryController($service);
        $response = $controller->index($this->req(['limit' => '500']), []);

        $this->assertSame(200, $response->statusCode);
        $body = $this->decode($response->body);
        $this->assertSame(0, $body['count']);
    }

    public function testLimitClampedToLowerBound(): void
    {
        $service = $this->mockService();
        $service->expects($this->once())
            ->method('getRecentWatchHistory')
            ->with(1, null, null)
            ->willReturn([]);

        $controller = new WatchHistoryController($service);
        $controller->index($this->req(['limit' => '0']), []);
    }

    public function testNonNumericLimitFallsBackToDefault(): void
    {
        $service = $this->mockService();
        $service->expects($this->once())
            ->method('getRecentWatchHistory')
            ->with(50, null, null)
            ->willReturn([]);

        $controller = new WatchHistoryController($service);
        $controller->index($this->req(['limit' => 'abc']), []);
    }

    public function testUserIdAndLibraryIdPassedThroughWhenPresent(): void
    {
        $service = $this->mockService();
        $service->expects($this->once())
            ->method('getRecentWatchHistory')
            ->with(50, 'user-7', 'lib-3')
            ->willReturn([]);

        $controller = new WatchHistoryController($service);
        $controller->index($this->req(['userId' => 'user-7', 'libraryId' => 'lib-3']), []);
    }

    public function testEmptyFilterStringsBecomeNull(): void
    {
        $service = $this->mockService();
        $service->expects($this->once())
            ->method('getRecentWatchHistory')
            ->with(50, null, null)
            ->willReturn([]);

        $controller = new WatchHistoryController($service);
        $controller->index($this->req(['userId' => '', 'libraryId' => '']), []);
    }
}
