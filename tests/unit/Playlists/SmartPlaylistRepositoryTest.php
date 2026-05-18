<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Playlists;

use PHPUnit\Framework\TestCase;
use Phlex\Playlists\SmartPlaylist;
use Phlex\Playlists\SmartPlaylistRepository;
use Workerman\MySQL\Connection;

class SmartPlaylistRepositoryTest extends TestCase
{
    private SmartPlaylistRepository $repo;
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
        $this->repo = new SmartPlaylistRepository($this->db);
    }

    public function test_insert_then_find_returns_same_row(): void
    {
        $playlist = new SmartPlaylist(
            id: 'test-uuid-123',
            name: 'My Playlist',
            libraryId: 'lib-456',
            rulesJson: '{"logic":"and","rules":[]}',
            limit: 10,
            sortBy: 'addedAt',
            sortDesc: true,
        );

        $callCount = 0;
        $this->db->method('query')
            ->willReturnCallback(function (string $sql) use (&$callCount, $playlist): array {
                $callCount++;
                if ($callCount === 1) {
                    // First call is INSERT - return empty
                    return [];
                }
                // Second call is SELECT - return playlist data
                return [[
                    'id' => $playlist->id,
                    'name' => $playlist->name,
                    'library_id' => $playlist->libraryId,
                    'rules_json' => $playlist->rulesJson,
                    'limit' => $playlist->limit,
                    'sort_by' => $playlist->sortBy,
                    'sort_desc' => 1,
                    'created_at' => '2026-01-01 00:00:00',
                    'updated_at' => '2026-01-01 00:00:00',
                ]];
            });

        $this->repo->insert($playlist);
        $found = $this->repo->findById('test-uuid-123');

        $this->assertInstanceOf(SmartPlaylist::class, $found);
        $this->assertSame('test-uuid-123', $found->id);
        $this->assertSame('My Playlist', $found->name);
        $this->assertSame(10, $found->limit);
    }

    public function test_update_modifies_row(): void
    {
        $playlist = new SmartPlaylist(
            id: 'test-uuid-123',
            name: 'Updated Playlist',
            libraryId: 'lib-456',
            rulesJson: '{"logic":"or","rules":[]}',
            limit: 20,
            sortBy: 'random',
            sortDesc: false,
        );

        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('UPDATE smart_playlists'),
                $this->callback(function (array $params): bool {
                    // params: name, library_id, rules_json, limit, sort_by, sort_desc, updated_at, id
                    return $params[0] === 'Updated Playlist'
                        && $params[1] === 'lib-456'
                        && $params[4] === 'random'
                        && $params[5] === 0; // sortDesc = false
                })
            );

        $this->repo->update($playlist);
    }

    public function test_delete_removes_row(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('DELETE FROM smart_playlists WHERE id = ?'),
                ['test-uuid-123']
            );

        $this->repo->delete('test-uuid-123');
    }

    public function test_find_by_library_id_returns_matching(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('SELECT * FROM smart_playlists WHERE library_id = ?'),
                ['lib-456']
            )
            ->willReturn([
                [
                    'id' => 'pl-1',
                    'name' => 'Playlist 1',
                    'library_id' => 'lib-456',
                    'rules_json' => '{}',
                    'limit' => 0,
                    'sort_by' => 'addedAt',
                    'sort_desc' => 1,
                    'created_at' => '2026-01-01 00:00:00',
                    'updated_at' => '2026-01-01 00:00:00',
                ],
                [
                    'id' => 'pl-2',
                    'name' => 'Playlist 2',
                    'library_id' => 'lib-456',
                    'rules_json' => '{}',
                    'limit' => 5,
                    'sort_by' => 'addedAt',
                    'sort_desc' => 1,
                    'created_at' => '2026-01-01 00:00:00',
                    'updated_at' => '2026-01-01 00:00:00',
                ],
            ]);

        $playlists = $this->repo->findByLibraryId('lib-456');

        $this->assertCount(2, $playlists);
        $this->assertSame('pl-1', $playlists[0]->id);
        $this->assertSame('pl-2', $playlists[1]->id);
    }

    public function test_find_all_returns_all_rows(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with($this->stringContains('SELECT * FROM smart_playlists'))
            ->willReturn([
                [
                    'id' => 'pl-1',
                    'name' => 'Playlist 1',
                    'library_id' => 'lib-1',
                    'rules_json' => '{}',
                    'limit' => 0,
                    'sort_by' => 'addedAt',
                    'sort_desc' => 1,
                    'created_at' => '2026-01-01 00:00:00',
                    'updated_at' => '2026-01-01 00:00:00',
                ],
            ]);

        $playlists = $this->repo->findAll();

        $this->assertCount(1, $playlists);
    }
}
