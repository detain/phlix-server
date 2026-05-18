<?php

declare(strict_types=1);

namespace Phlex\Tests\Integration\Playlists;

use PHPUnit\Framework\TestCase;
use Phlex\Playlists\LibraryUpdated;
use Phlex\Playlists\SmartPlaylist;
use Phlex\Playlists\SmartPlaylistEngine;
use Phlex\Playlists\SmartPlaylistRefreshHandler;
use Phlex\Playlists\SmartPlaylistRepository;
use Phlex\Common\Events\ListenerRegistry;
use Phlex\Media\Library\ItemRepository;
use Workerman\MySQL\Connection;

class SmartPlaylistRefreshTest extends TestCase
{
    private SmartPlaylistRepository $repo;
    private SmartPlaylistEngine $engine;
    private ListenerRegistry $listeners;

    protected function setUp(): void
    {
        $this->listeners = new ListenerRegistry();
    }

    public function test_on_library_updated_re_evaluates_smart_playlists(): void
    {
        // Create a mock database connection that returns expected data
        $db = $this->createMock(Connection::class);
        $this->repo = new SmartPlaylistRepository($db);
        $this->engine = $this->createMock(SmartPlaylistEngine::class);

        // Expect the repo's findByLibraryId to be called
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('SELECT * FROM smart_playlists WHERE library_id = ?'),
                ['lib-123']
            )
            ->willReturn([
                [
                    'id' => 'pl-1',
                    'name' => 'Drama Playlist',
                    'library_id' => 'lib-123',
                    'rules_json' => json_encode([
                        'logic' => 'and',
                        'rules' => [
                            ['field' => 'genre', 'op' => 'contains', 'value' => 'Drama'],
                        ],
                    ]),
                    'limit' => 0,
                    'sort_by' => 'addedAt',
                    'sort_desc' => 1,
                    'created_at' => '2026-01-01 00:00:00',
                    'updated_at' => '2026-01-01 00:00:00',
                ],
            ]);

        // Expect engine evaluation to be called
        $this->engine->expects($this->once())
            ->method('evaluateOnScan')
            ->with(
                $this->callback(function (array $rules): bool {
                    return isset($rules['rules'][0]['field'])
                        && $rules['rules'][0]['field'] === 'genre';
                }),
                'lib-123',
                0,
                'addedAt',
                true
            )
            ->willReturn([]);

        // Now use real SmartPlaylistRepository that uses the mock db
        $realRepo = new SmartPlaylistRepository($db);
        $handler = new SmartPlaylistRefreshHandler($this->engine, $realRepo, $this->listeners);

        // Fire the event
        $event = new LibraryUpdated('lib-123', '/some/path');
        $handler->onLibraryUpdated($event);
    }

    public function test_handler_registers_for_library_updated_event(): void
    {
        $db = $this->createMock(Connection::class);
        $engine = $this->createMock(SmartPlaylistEngine::class);
        $realRepo = new SmartPlaylistRepository($db);

        $handler = new SmartPlaylistRefreshHandler($engine, $realRepo, $this->listeners);

        $listenerId = $handler->register();

        $this->assertNotEmpty($listenerId);
    }

    public function test_event_contains_expected_data(): void
    {
        $event = new LibraryUpdated('lib-123', '/media/movies', new \DateTimeImmutable('2026-01-01'));

        $this->assertSame('lib-123', $event->libraryId);
        $this->assertSame('/media/movies', $event->path);
        $this->assertSame('2026-01-01', $event->occurredAt->format('Y-m-d'));
    }
}
