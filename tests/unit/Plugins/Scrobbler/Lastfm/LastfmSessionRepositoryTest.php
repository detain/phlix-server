<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Plugins\Scrobbler\Lastfm;

use PHPUnit\Framework\TestCase;
use Phlex\Plugins\Scrobbler\Lastfm\LastfmSessionRepository;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see LastfmSessionRepository}.
 *
 * @covers \Phlex\Plugins\Scrobbler\Lastfm\LastfmSessionRepository
 *
 * @package Phlex\Tests\Unit\Plugins\Scrobbler\Lastfm
 * @since 0.15.0
 */
final class LastfmSessionRepositoryTest extends TestCase
{
    /** @var Connection&\PHPUnit\Framework\MockObject\MockObject */
    private Connection $db;

    private LastfmSessionRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->createMock(Connection::class);
        $this->repo = new LastfmSessionRepository($this->db);
    }

    public function testFindByUserIdReturnsHydratedRow(): void
    {
        $this->db->expects(self::once())
            ->method('query')
            ->with(self::stringContains('SELECT user_id, session_key'), ['user-1'])
            ->willReturn([
                ['user_id' => 'user-1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00'],
            ]);

        $row = $this->repo->findByUserId('user-1');
        $this->assertNotNull($row);
        $this->assertSame('user-1', $row['user_id']);
        $this->assertSame('SK', $row['session_key']);
        $this->assertSame('2024-01-01 00:00:00', $row['connected_at']);
    }

    public function testFindByUserIdReturnsNullWhenMissing(): void
    {
        $this->db->method('query')->willReturn([]);
        $this->assertNull($this->repo->findByUserId('nope'));
    }

    public function testFindByUserIdReturnsNullWhenColumnsMistyped(): void
    {
        $this->db->method('query')->willReturn([['user_id' => 1, 'session_key' => null, 'connected_at' => null]]);
        $this->assertNull($this->repo->findByUserId('user-1'));
    }

    public function testSaveIssuesUpsert(): void
    {
        $this->db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('ON DUPLICATE KEY UPDATE'),
                ['user-1', 'SK'],
            )
            ->willReturn([]);
        $this->repo->save('user-1', 'SK');
    }

    public function testDeleteIssuesDelete(): void
    {
        $this->db->expects(self::once())
            ->method('query')
            ->with('DELETE FROM lastfm_sessions WHERE user_id = ?', ['user-1'])
            ->willReturn([]);
        $this->repo->delete('user-1');
    }
}
