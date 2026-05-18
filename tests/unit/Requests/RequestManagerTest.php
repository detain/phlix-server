<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Requests;

use PHPUnit\Framework\TestCase;
use Phlex\Arr\ArrClientFactory;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Requests\RequestManager;
use Workerman\MySQL\Connection;

class RequestManagerTest extends TestCase
{
    private RequestManager $manager;
    private Connection $db;

    protected function setUp(): void
    {
        LoggerFactory::init(__DIR__ . '/../../../config/logger.php');
        $this->db = $this->createMock(Connection::class);
        $arrClientFactory = new ArrClientFactory([]);
        $this->manager = new RequestManager($this->db, $arrClientFactory);
    }

    public function testCreateRequestStoresPending(): void
    {
        $this->db->expects($this->exactly(2))
            ->method('query')
            ->willReturnCallback(function ($sql, $params = []) {
                if (strpos($sql, 'INSERT INTO requests') !== false) {
                    return [];
                }
                return [[
                    'id' => 'test-uuid',
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
                ]];
            });

        $result = $this->manager->createRequest(
            'user-123',
            'movie',
            12345,
            'Test Movie',
            'https://poster.url'
        );

        $this->assertIsArray($result);
        $this->assertEquals('user-123', $result['user_id']);
        $this->assertEquals('movie', $result['type']);
        $this->assertEquals(12345, $result['tmdb_id']);
        $this->assertEquals('Test Movie', $result['title']);
        $this->assertEquals('pending', $result['status']);
        $this->assertEquals('user-123', $result['user_id']);
        $this->assertEquals('movie', $result['type']);
        $this->assertEquals(12345, $result['tmdb_id']);
        $this->assertEquals('Test Movie', $result['title']);
        $this->assertEquals('pending', $result['status']);
    }

    public function testCreateSeriesRequestWithSeasonAndEpisode(): void
    {
        $this->db->expects($this->exactly(2))
            ->method('query')
            ->willReturnCallback(function ($sql, $params = []) {
                if (strpos($sql, 'INSERT INTO requests') !== false) {
                    return [];
                }
                return [[
                    'id' => 'test-uuid',
                    'user_id' => 'user-456',
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
                ]];
            });

        $result = $this->manager->createRequest(
            'user-456',
            'series',
            54321,
            'Test Series',
            null,
            2,
            5
        );

        $this->assertIsArray($result);
        $this->assertEquals('series', $result['type']);
        $this->assertEquals(2, $result['season']);
        $this->assertEquals(5, $result['episode']);
        $this->assertEquals(5, $result['episode']);
    }

    public function testGetRequestByIdReturnsNullWhenNotFound(): void
    {
        $this->db->method('query')
            ->with($this->stringContains('SELECT * FROM requests WHERE id'))
            ->willReturn([]);

        $result = $this->manager->getRequestById('non-existent-id');

        $this->assertNull($result);
    }

    public function testGetRequestByIdReturnsHydratedRequest(): void
    {
        $this->db->method('query')
            ->with($this->stringContains('SELECT * FROM requests WHERE id'))
            ->willReturn([[
                'id' => 'test-id',
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
            ]]);

        $result = $this->manager->getRequestById('test-id');

        $this->assertIsArray($result);
        $this->assertEquals('test-id', $result['id']);
        $this->assertEquals(12345, $result['tmdb_id']);
        $this->assertIsInt($result['tmdb_id']);
    }

    public function testRejectRequestSetsStatusToRejected(): void
    {
        $this->db->expects($this->exactly(2))
            ->method('query')
            ->willReturnCallback(function ($sql, $params = []) {
                if (strpos($sql, 'SELECT * FROM requests WHERE id') !== false) {
                    return [[
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
                    ]];
                }
                return [];
            });

        $result = $this->manager->rejectRequest('test-id', 'Too controversial');

        $this->assertTrue($result);
    }

    public function testRejectRequestReturnsFalseForNonPending(): void
    {
        $this->db->method('query')
            ->with($this->stringContains('SELECT * FROM requests WHERE id'))
            ->willReturn([[
                'id' => 'test-id',
                'user_id' => 'user-123',
                'type' => 'movie',
                'tmdb_id' => 12345,
                'title' => 'Test Movie',
                'poster_url' => null,
                'season' => null,
                'episode' => null,
                'status' => 'approved',
                'rejection_reason' => null,
                'created_at' => '2024-01-01 00:00:00',
                'updated_at' => '2024-01-01 00:00:00',
            ]]);

        $result = $this->manager->rejectRequest('test-id', 'Some reason');

        $this->assertFalse($result);
    }

    public function testRejectRequestReturnsFalseForNonExistent(): void
    {
        $this->db->method('query')
            ->with($this->stringContains('SELECT * FROM requests WHERE id'))
            ->willReturn([]);

        $result = $this->manager->rejectRequest('non-existent', 'Some reason');

        $this->assertFalse($result);
    }

    public function testGetRequestStatusReturnsCorrectStatus(): void
    {
        $this->db->method('query')
            ->with($this->stringContains('SELECT * FROM requests WHERE id'))
            ->willReturn([[
                'id' => 'test-id',
                'user_id' => 'user-123',
                'type' => 'movie',
                'tmdb_id' => 12345,
                'title' => 'Test Movie',
                'poster_url' => null,
                'season' => null,
                'episode' => null,
                'status' => 'approved',
                'rejection_reason' => null,
                'created_at' => '2024-01-01 00:00:00',
                'updated_at' => '2024-01-01 00:00:00',
            ]]);

        $status = $this->manager->getRequestStatus('test-id');

        $this->assertEquals('approved', $status);
    }

    public function testGetRequestStatusReturnsUnknownForNonExistent(): void
    {
        $this->db->method('query')
            ->with($this->stringContains('SELECT * FROM requests WHERE id'))
            ->willReturn([]);

        $status = $this->manager->getRequestStatus('non-existent');

        $this->assertEquals('unknown', $status);
    }

    public function testListPendingRequestsForUser(): void
    {
        $this->db->method('query')
            ->with(
                $this->stringContains("WHERE user_id = ? AND status = 'pending'"),
                ['user-123']
            )
            ->willReturn([
                [
                    'id' => 'req-1',
                    'user_id' => 'user-123',
                    'type' => 'movie',
                    'tmdb_id' => 111,
                    'title' => 'Movie 1',
                    'poster_url' => null,
                    'season' => null,
                    'episode' => null,
                    'status' => 'pending',
                    'rejection_reason' => null,
                    'created_at' => '2024-01-01 00:00:00',
                    'updated_at' => '2024-01-01 00:00:00',
                ],
                [
                    'id' => 'req-2',
                    'user_id' => 'user-123',
                    'type' => 'series',
                    'tmdb_id' => 222,
                    'title' => 'Series 1',
                    'poster_url' => null,
                    'season' => 1,
                    'episode' => null,
                    'status' => 'pending',
                    'rejection_reason' => null,
                    'created_at' => '2024-01-02 00:00:00',
                    'updated_at' => '2024-01-02 00:00:00',
                ],
            ]);

        $result = $this->manager->listPendingRequests('user-123');

        $this->assertCount(2, $result);
        $this->assertEquals('Movie 1', $result[0]['title']);
        $this->assertEquals('Series 1', $result[1]['title']);
    }

    public function testListPendingRequestsAllUsers(): void
    {
        $this->db->method('query')
            ->with($this->stringContains("WHERE status = 'pending'"))
            ->willReturn([
                [
                    'id' => 'req-1',
                    'user_id' => 'user-123',
                    'type' => 'movie',
                    'tmdb_id' => 111,
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

        $result = $this->manager->listPendingRequests();

        $this->assertCount(1, $result);
    }

    public function testListAvailableRequests(): void
    {
        $this->db->method('query')
            ->with($this->stringContains("WHERE status = 'available'"))
            ->willReturn([
                [
                    'id' => 'req-1',
                    'user_id' => 'user-123',
                    'type' => 'movie',
                    'tmdb_id' => 111,
                    'title' => 'Available Movie',
                    'poster_url' => null,
                    'season' => null,
                    'episode' => null,
                    'status' => 'available',
                    'rejection_reason' => null,
                    'created_at' => '2024-01-01 00:00:00',
                    'updated_at' => '2024-01-03 00:00:00',
                ],
            ]);

        $result = $this->manager->listAvailableRequests();

        $this->assertCount(1, $result);
        $this->assertEquals('available', $result[0]['status']);
    }

    public function testDeleteRequest(): void
    {
        $this->db->expects($this->exactly(2))
            ->method('query')
            ->willReturnCallback(function ($sql, $params = []) {
                if (strpos($sql, 'SELECT * FROM requests WHERE id') !== false) {
                    return [[
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
                    ]];
                }
                return [];
            });

        $result = $this->manager->deleteRequest('test-id');

        $this->assertTrue($result);
    }

    public function testDeleteRequestReturnsFalseForNonExistent(): void
    {
        $this->db->method('query')
            ->with($this->stringContains('SELECT * FROM requests WHERE id'))
            ->willReturn([]);

        $result = $this->manager->deleteRequest('non-existent');

        $this->assertFalse($result);
    }

    public function testApproveRequestReturnsFalseWhenRadarrNotConfigured(): void
    {
        $arrClientFactory = new ArrClientFactory([]);
        $manager = new RequestManager($this->db, $arrClientFactory);

        $this->db->method('query')
            ->with($this->stringContains('SELECT * FROM requests WHERE id'))
            ->willReturn([[
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
            ]]);

        $result = $manager->approveRequest('test-id');

        $this->assertFalse($result);
    }

    public function testApproveRequestReturnsFalseForNonPending(): void
    {
        $this->db->method('query')
            ->with($this->stringContains('SELECT * FROM requests WHERE id'))
            ->willReturn([[
                'id' => 'test-id',
                'user_id' => 'user-123',
                'type' => 'movie',
                'tmdb_id' => 12345,
                'title' => 'Test Movie',
                'poster_url' => null,
                'season' => null,
                'episode' => null,
                'status' => 'approved',
                'rejection_reason' => null,
                'created_at' => '2024-01-01 00:00:00',
                'updated_at' => '2024-01-01 00:00:00',
            ]]);

        $result = $this->manager->approveRequest('test-id');

        $this->assertFalse($result);
    }

    public function testApproveSeriesRequestReturnsFalseWhenSonarrNotConfigured(): void
    {
        $arrClientFactory = new ArrClientFactory([]);
        $manager = new RequestManager($this->db, $arrClientFactory);

        $this->db->method('query')
            ->with($this->stringContains('SELECT * FROM requests WHERE id'))
            ->willReturn([[
                'id' => 'test-id',
                'user_id' => 'user-123',
                'type' => 'series',
                'tmdb_id' => 12345,
                'title' => 'Test Series',
                'poster_url' => null,
                'season' => 1,
                'episode' => null,
                'status' => 'pending',
                'rejection_reason' => null,
                'created_at' => '2024-01-01 00:00:00',
                'updated_at' => '2024-01-01 00:00:00',
            ]]);

        $result = $manager->approveRequest('test-id');

        $this->assertFalse($result);
    }

    public function testMarkAvailable(): void
    {
        $this->db->expects($this->exactly(2))
            ->method('query')
            ->willReturnCallback(function ($sql, $params = []) {
                if (strpos($sql, 'SELECT * FROM requests WHERE id') !== false) {
                    return [[
                        'id' => 'test-id',
                        'user_id' => 'user-123',
                        'type' => 'movie',
                        'tmdb_id' => 12345,
                        'title' => 'Test Movie',
                        'poster_url' => null,
                        'season' => null,
                        'episode' => null,
                        'status' => 'approved',
                        'rejection_reason' => null,
                        'created_at' => '2024-01-01 00:00:00',
                        'updated_at' => '2024-01-01 00:00:00',
                    ]];
                }
                return [];
            });

        $result = $this->manager->markAvailable('test-id');

        $this->assertTrue($result);
    }

    public function testMarkAvailableReturnsFalseForNonApproved(): void
    {
        $this->db->method('query')
            ->with($this->stringContains('SELECT * FROM requests WHERE id'))
            ->willReturn([[
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
            ]]);

        $result = $this->manager->markAvailable('test-id');

        $this->assertFalse($result);
    }
}
