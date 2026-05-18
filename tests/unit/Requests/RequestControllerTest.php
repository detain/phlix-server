<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Requests;

use PHPUnit\Framework\TestCase;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Requests\RequestManager;
use Phlex\Requests\RequestNotification;
use Phlex\Server\Http\Controllers\Requests\RequestController;
use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;
use Workerman\MySQL\Connection;

class RequestControllerTest extends TestCase
{
    private RequestController $controller;
    private RequestManager $manager;
    private RequestNotification $notification;

    protected function setUp(): void
    {
        LoggerFactory::init(__DIR__ . '/../../../config/logger.php');
        $this->manager = $this->createMock(RequestManager::class);
        $this->notification = new RequestNotification();
        $this->controller = new RequestController($this->manager, $this->notification);
    }

    public function testListRequestsReturnsUnauthorizedWhenNoUser(): void
    {
        $request = new Request();
        $request->userId = null;

        $response = $this->controller->listRequests($request, []);

        $this->assertEquals(401, $response->statusCode);
    }

    public function testListRequestsReturnsUserRequests(): void
    {
        $request = new Request();
        $request->userId = 'user-123';

        $this->manager->method('listUserRequests')
            ->with('user-123')
            ->willReturn([
                [
                    'id' => 'req-1',
                    'user_id' => 'user-123',
                    'type' => 'movie',
                    'tmdb_id' => 12345,
                    'title' => 'Test Movie',
                    'poster_url' => null,
                    'season' => null,
                    'episode' => null,
                    'status' => 'pending',
                    'rejection_reason' => null,
                    'created_at' => '2024-01-01 00:00:00',
                    'updated_at' => '2024-01-01 00:00:00',
                ],
            ]);

        $response = $this->controller->listRequests($request, []);

        $this->assertEquals(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('requests', $body);
        $this->assertCount(1, $body['requests']);
    }

    public function testListRequestsWithPendingStatus(): void
    {
        $request = new Request();
        $request->userId = 'user-123';
        $request->query['status'] = 'pending';

        $this->manager->method('listPendingRequests')
            ->with('user-123')
            ->willReturn([]);

        $response = $this->controller->listRequests($request, []);

        $this->assertEquals(200, $response->statusCode);
    }

    public function testListRequestsWithAvailableStatus(): void
    {
        $request = new Request();
        $request->userId = 'user-123';
        $request->query['status'] = 'available';

        $this->manager->method('listAvailableRequests')
            ->willReturn([]);

        $response = $this->controller->listRequests($request, []);

        $this->assertEquals(200, $response->statusCode);
    }

    public function testCreateRequestReturnsUnauthorizedWhenNoUser(): void
    {
        $request = new Request();
        $request->userId = null;

        $response = $this->controller->createRequest($request, []);

        $this->assertEquals(401, $response->statusCode);
    }

    public function testCreateRequestReturnsBadRequestForInvalidType(): void
    {
        $request = new Request();
        $request->userId = 'user-123';
        $request->body = [
            'type' => 'invalid',
            'tmdb_id' => 12345,
            'title' => 'Test Movie',
        ];

        $response = $this->controller->createRequest($request, []);

        $this->assertEquals(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertStringContainsString('Invalid request type', $body['error']);
    }

    public function testCreateRequestReturnsBadRequestForMissingTmdbId(): void
    {
        $request = new Request();
        $request->userId = 'user-123';
        $request->body = [
            'type' => 'movie',
            'title' => 'Test Movie',
        ];

        $response = $this->controller->createRequest($request, []);

        $this->assertEquals(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertStringContainsString('tmdb_id', $body['error']);
    }

    public function testCreateRequestReturnsBadRequestForMissingTitle(): void
    {
        $request = new Request();
        $request->userId = 'user-123';
        $request->body = [
            'type' => 'movie',
            'tmdb_id' => 12345,
        ];

        $response = $this->controller->createRequest($request, []);

        $this->assertEquals(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertStringContainsString('title', $body['error']);
    }

    public function testCreateRequestReturns201OnSuccess(): void
    {
        $request = new Request();
        $request->userId = 'user-123';
        $request->body = [
            'type' => 'movie',
            'tmdb_id' => 12345,
            'title' => 'Test Movie',
            'poster_url' => 'https://poster.url',
        ];

        $this->manager->method('createRequest')
            ->willReturn([
                'id' => 'new-req-id',
                'user_id' => 'user-123',
                'type' => 'movie',
                'tmdb_id' => 12345,
                'title' => 'Test Movie',
                'poster_url' => 'https://poster.url',
                'season' => null,
                'episode' => null,
                'status' => 'pending',
                'rejection_reason' => null,
                'created_at' => '2024-01-01 00:00:00',
                'updated_at' => '2024-01-01 00:00:00',
            ]);

        $response = $this->controller->createRequest($request, []);

        $this->assertEquals(201, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('request', $body);
        $this->assertEquals('Test Movie', $body['request']['title']);
    }

    public function testCreateSeriesRequestWithSeasonAndEpisode(): void
    {
        $request = new Request();
        $request->userId = 'user-123';
        $request->body = [
            'type' => 'series',
            'tmdb_id' => 54321,
            'title' => 'Test Series',
            'season' => 2,
            'episode' => 5,
        ];

        $this->manager->method('createRequest')
            ->willReturn([
                'id' => 'new-req-id',
                'user_id' => 'user-123',
                'type' => 'series',
                'tmdb_id' => 54321,
                'title' => 'Test Series',
                'poster_url' => null,
                'season' => 2,
                'episode' => 5,
                'status' => 'pending',
                'rejection_reason' => null,
                'created_at' => '2024-01-01 00:00:00',
                'updated_at' => '2024-01-01 00:00:00',
            ]);

        $response = $this->controller->createRequest($request, []);

        $this->assertEquals(201, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertEquals(2, $body['request']['season']);
        $this->assertEquals(5, $body['request']['episode']);
    }

    public function testApproveRequestReturnsBadRequestWhenNoId(): void
    {
        $request = new Request();

        $response = $this->controller->approveRequest($request, []);

        $this->assertEquals(400, $response->statusCode);
    }

    public function testApproveRequestReturns404WhenNotFound(): void
    {
        $request = new Request();

        $this->manager->method('getRequestById')
            ->with('non-existent')
            ->willReturn(null);

        $response = $this->controller->approveRequest($request, ['id' => 'non-existent']);

        $this->assertEquals(404, $response->statusCode);
    }

    public function testApproveRequestReturns500OnFailure(): void
    {
        $request = new Request();

        $this->manager->method('getRequestById')
            ->with('test-id')
            ->willReturn([
                'id' => 'test-id',
                'user_id' => 'user-123',
                'type' => 'movie',
                'tmdb_id' => 12345,
                'title' => 'Test Movie',
                'poster_url' => null,
                'season' => null,
                'episode' => null,
                'status' => 'pending',
                'rejection_reason' => null,
                'created_at' => '2024-01-01 00:00:00',
                'updated_at' => '2024-01-01 00:00:00',
            ]);

        $this->manager->method('approveRequest')
            ->with('test-id')
            ->willReturn(false);

        $response = $this->controller->approveRequest($request, ['id' => 'test-id']);

        $this->assertEquals(500, $response->statusCode);
    }

    public function testRejectRequestReturnsBadRequestWhenNoId(): void
    {
        $request = new Request();

        $response = $this->controller->rejectRequest($request, []);

        $this->assertEquals(400, $response->statusCode);
    }

    public function testRejectRequestReturns404WhenNotFound(): void
    {
        $request = new Request();

        $this->manager->method('getRequestById')
            ->with('non-existent')
            ->willReturn(null);

        $response = $this->controller->rejectRequest($request, ['id' => 'non-existent']);

        $this->assertEquals(404, $response->statusCode);
    }

    public function testRejectRequestSuccess(): void
    {
        $request = new Request();
        $request->body = ['reason' => 'Not appropriate'];

        $this->manager->method('getRequestById')
            ->with('test-id')
            ->willReturn([
                'id' => 'test-id',
                'user_id' => 'user-123',
                'type' => 'movie',
                'tmdb_id' => 12345,
                'title' => 'Test Movie',
                'poster_url' => null,
                'season' => null,
                'episode' => null,
                'status' => 'pending',
                'rejection_reason' => null,
                'created_at' => '2024-01-01 00:00:00',
                'updated_at' => '2024-01-01 00:00:00',
            ]);

        $this->manager->method('rejectRequest')
            ->with('test-id', 'Not appropriate')
            ->willReturn(true);

        $response = $this->controller->rejectRequest($request, ['id' => 'test-id']);

        $this->assertEquals(200, $response->statusCode);
    }

    public function testDeleteRequestReturnsUnauthorizedWhenNoUser(): void
    {
        $request = new Request();
        $request->userId = null;

        $response = $this->controller->deleteRequest($request, ['id' => 'test-id']);

        $this->assertEquals(401, $response->statusCode);
    }

    public function testDeleteRequestReturnsBadRequestWhenNoId(): void
    {
        $request = new Request();
        $request->userId = 'user-123';

        $response = $this->controller->deleteRequest($request, []);

        $this->assertEquals(400, $response->statusCode);
    }

    public function testDeleteRequestReturns404WhenNotFound(): void
    {
        $request = new Request();
        $request->userId = 'user-123';

        $this->manager->method('getRequestById')
            ->with('non-existent')
            ->willReturn(null);

        $response = $this->controller->deleteRequest($request, ['id' => 'non-existent']);

        $this->assertEquals(404, $response->statusCode);
    }

    public function testDeleteRequestReturns403WhenNotOwner(): void
    {
        $request = new Request();
        $request->userId = 'user-456';

        $this->manager->method('getRequestById')
            ->with('test-id')
            ->willReturn([
                'id' => 'test-id',
                'user_id' => 'user-123',
                'type' => 'movie',
                'tmdb_id' => 12345,
                'title' => 'Test Movie',
                'poster_url' => null,
                'season' => null,
                'episode' => null,
                'status' => 'pending',
                'rejection_reason' => null,
                'created_at' => '2024-01-01 00:00:00',
                'updated_at' => '2024-01-01 00:00:00',
            ]);

        $response = $this->controller->deleteRequest($request, ['id' => 'test-id']);

        $this->assertEquals(403, $response->statusCode);
    }

    public function testDeleteRequestSuccess(): void
    {
        $request = new Request();
        $request->userId = 'user-123';

        $this->manager->method('getRequestById')
            ->with('test-id')
            ->willReturn([
                'id' => 'test-id',
                'user_id' => 'user-123',
                'type' => 'movie',
                'tmdb_id' => 12345,
                'title' => 'Test Movie',
                'poster_url' => null,
                'season' => null,
                'episode' => null,
                'status' => 'pending',
                'rejection_reason' => null,
                'created_at' => '2024-01-01 00:00:00',
                'updated_at' => '2024-01-01 00:00:00',
            ]);

        $this->manager->method('deleteRequest')
            ->with('test-id')
            ->willReturn(true);

        $response = $this->controller->deleteRequest($request, ['id' => 'test-id']);

        $this->assertEquals(200, $response->statusCode);
    }

    public function testGetRequestReturnsUnauthorizedWhenNoUser(): void
    {
        $request = new Request();
        $request->userId = null;

        $response = $this->controller->getRequest($request, ['id' => 'test-id']);

        $this->assertEquals(401, $response->statusCode);
    }

    public function testGetRequestReturns404WhenNotFound(): void
    {
        $request = new Request();
        $request->userId = 'user-123';

        $this->manager->method('getRequestById')
            ->with('non-existent')
            ->willReturn(null);

        $response = $this->controller->getRequest($request, ['id' => 'non-existent']);

        $this->assertEquals(404, $response->statusCode);
    }

    public function testGetRequestSuccess(): void
    {
        $request = new Request();
        $request->userId = 'user-123';

        $this->manager->method('getRequestById')
            ->with('test-id')
            ->willReturn([
                'id' => 'test-id',
                'user_id' => 'user-123',
                'type' => 'movie',
                'tmdb_id' => 12345,
                'title' => 'Test Movie',
                'poster_url' => null,
                'season' => null,
                'episode' => null,
                'status' => 'pending',
                'rejection_reason' => null,
                'created_at' => '2024-01-01 00:00:00',
                'updated_at' => '2024-01-01 00:00:00',
            ]);

        $response = $this->controller->getRequest($request, ['id' => 'test-id']);

        $this->assertEquals(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('request', $body);
        $this->assertEquals('Test Movie', $body['request']['title']);
    }

    public function testListPendingRequestsReturnsAllPending(): void
    {
        $request = new Request();

        $this->manager->method('listPendingRequests')
            ->with(null)
            ->willReturn([
                [
                    'id' => 'req-1',
                    'user_id' => 'user-123',
                    'type' => 'movie',
                    'tmdb_id' => 12345,
                    'title' => 'Movie 1',
                    'poster_url' => null,
                    'season' => null,
                    'episode' => null,
                    'status' => 'pending',
                    'rejection_reason' => null,
                    'created_at' => '2024-01-01 00:00:00',
                    'updated_at' => '2024-01-01 00:00:00',
                ],
            ]);

        $response = $this->controller->listPendingRequests($request, []);

        $this->assertEquals(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertCount(1, $body['requests']);
    }
}
