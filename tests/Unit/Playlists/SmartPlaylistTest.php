<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Playlists;

use PHPUnit\Framework\TestCase;
use Phlix\Playlists\SmartPlaylist;

class SmartPlaylistTest extends TestCase
{
    public function test_from_row_creates_instance(): void
    {
        $row = [
            'id' => 'test-uuid',
            'name' => 'Test Playlist',
            'library_id' => 'lib-123',
            'rules_json' => '{"logic":"and","rules":[]}',
            'limit' => 10,
            'sort_by' => 'year',
            'sort_desc' => 1,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-02 00:00:00',
        ];

        $playlist = SmartPlaylist::fromRow($row);

        $this->assertSame('test-uuid', $playlist->id);
        $this->assertSame('Test Playlist', $playlist->name);
        $this->assertSame('lib-123', $playlist->libraryId);
        $this->assertSame(10, $playlist->limit);
        $this->assertSame('year', $playlist->sortBy);
        $this->assertTrue($playlist->sortDesc);
    }

    public function test_get_rules_decodes_json(): void
    {
        $playlist = new SmartPlaylist(
            id: 'test-uuid',
            name: 'Test',
            libraryId: 'lib-123',
            rulesJson: '{"logic":"and","rules":[{"field":"genre","op":"contains","value":"Drama"}]}',
        );

        $rules = $playlist->getRules();

        $this->assertIsArray($rules);
        $this->assertSame('and', $rules['logic']);
        $this->assertCount(1, $rules['rules']);
    }

    public function test_to_array_returns_complete_representation(): void
    {
        $playlist = new SmartPlaylist(
            id: 'test-uuid',
            name: 'Test Playlist',
            libraryId: 'lib-123',
            rulesJson: '{"logic":"and","rules":[]}',
            limit: 5,
            sortBy: 'random',
            sortDesc: false,
        );

        $arr = $playlist->toArray();

        $this->assertSame('test-uuid', $arr['id']);
        $this->assertSame('Test Playlist', $arr['name']);
        $this->assertSame('lib-123', $arr['library_id']);
        $this->assertSame('{"logic":"and","rules":[]}', $arr['rules_json']);
        $this->assertSame(5, $arr['limit']);
        $this->assertSame('random', $arr['sort_by']);
        $this->assertFalse($arr['sort_desc']);
        $this->assertArrayHasKey('rules', $arr);
        $this->assertArrayHasKey('created_at', $arr);
        $this->assertArrayHasKey('updated_at', $arr);
    }
}
