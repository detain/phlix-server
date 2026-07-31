<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Playlists;

use PHPUnit\Framework\TestCase;
use Phlix\Collections\CollectionManager;
use Phlix\Collections\CollectionRepository;
use Phlix\Playlists\LibraryUpdated;
use Phlix\Playlists\SmartPlaylistRefreshHandler;
use Phlix\Playlists\SmartPlaylistRepository;
use Phlix\Common\Events\ListenerRegistry;
use Workerman\MySQL\Connection;

class SmartPlaylistRefreshTest extends TestCase
{
    private ListenerRegistry $listeners;

    protected function setUp(): void
    {
        $this->listeners = new ListenerRegistry();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function playlistRows(): array
    {
        return [
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
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectionRows(): array
    {
        return [
            [
                'id' => 'col-1',
                'name' => 'Drama Collection',
                'library_id' => 'lib-123',
                'smart_playlist_id' => 'pl-1',
                'parent_id' => null,
                'sort_order' => 0,
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-01 00:00:00',
            ],
        ];
    }

    /**
     * The event must refresh the smart COLLECTIONS linked to the library's smart
     * playlists — that membership write is the only persisted effect of the
     * whole path.
     *
     * This used to assert that the handler called
     * `SmartPlaylistEngine::evaluateOnScan()` once per playlist. It did, and
     * threw the result away: the engine persists nothing, `smart_playlists` has
     * no materialised-results table, the rules are evaluated on request by
     * `SmartPlaylistController::preview()`, and
     * `CollectionManager::refreshSmartCollection()` re-evaluates them itself.
     * The call was a discarded full-library walk per playlist, so it was removed
     * and this test now pins the behaviour that survives it.
     */
    public function test_on_library_updated_refreshes_the_linked_smart_collections(): void
    {
        /** @var array<int, string> $seen */
        $seen = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            /**
             * @param array<int, mixed>|null $params
             * @return array<int, array<string, mixed>>
             */
            function (string $sql, ?array $params = null) use (&$seen): array {
                $seen[] = $sql;
                if (str_contains($sql, 'FROM smart_playlists')) {
                    $this->assertSame(['lib-123'], $params);

                    return $this->playlistRows();
                }
                if (str_contains($sql, 'FROM collections')) {
                    $this->assertSame(['pl-1'], $params);

                    return $this->collectionRows();
                }

                $this->fail('unexpected query during a refresh: ' . $sql);
            }
        );

        $collectionManager = $this->createMock(CollectionManager::class);
        $collectionManager->expects($this->once())
            ->method('refreshSmartCollection')
            ->with('col-1');

        $handler = new SmartPlaylistRefreshHandler(
            new SmartPlaylistRepository($db),
            $this->listeners,
            $collectionManager,
            new CollectionRepository($db)
        );

        // Fire the event
        $event = new LibraryUpdated('lib-123', '/some/path');
        $handler->onLibraryUpdated($event);

        $this->assertCount(
            2,
            $seen,
            'One playlist with one linked collection is exactly two queries here: the '
            . "library's smart playlists, then the collections linked to that playlist. A "
            . 'third would mean the discarded per-playlist media_items walk is back — the '
            . 'membership diff itself belongs to CollectionManager::refreshSmartCollection(), '
            . 'which is mocked out above.'
        );
    }

    /**
     * A playlist with no linked collection must cost nothing beyond the lookup:
     * there is no playlist state to refresh.
     */
    public function test_a_playlist_without_a_linked_collection_refreshes_nothing(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            /**
             * @param array<int, mixed>|null $params
             * @return array<int, array<string, mixed>>
             */
            function (string $sql, ?array $params = null): array {
                if (str_contains($sql, 'FROM smart_playlists')) {
                    return $this->playlistRows();
                }
                if (str_contains($sql, 'FROM collections')) {
                    return [];
                }

                $this->fail('unexpected query during a refresh: ' . $sql);
            }
        );

        $collectionManager = $this->createMock(CollectionManager::class);
        $collectionManager->expects($this->never())
            ->method('refreshSmartCollection');

        $handler = new SmartPlaylistRefreshHandler(
            new SmartPlaylistRepository($db),
            $this->listeners,
            $collectionManager,
            new CollectionRepository($db)
        );

        $handler->onLibraryUpdated(new LibraryUpdated('lib-123', '/some/path'));
    }

    /**
     * `register()` is the handler's own documented API and still works, but it
     * subscribes the refresh INLINE on the dispatch stack, so production does not
     * use it: `SmartPlaylistRefreshSubscriber` is what `start.php` registers, and
     * it enqueues on the event and drains off a timer. See
     * `tests/Unit/Playlists/SmartPlaylistRefreshWiringTest.php` for that wiring.
     */
    public function test_handler_registers_for_library_updated_event(): void
    {
        $db = $this->createMock(Connection::class);
        $realRepo = new SmartPlaylistRepository($db);

        $handler = new SmartPlaylistRefreshHandler($realRepo, $this->listeners);

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
