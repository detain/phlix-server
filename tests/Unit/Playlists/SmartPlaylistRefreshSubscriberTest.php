<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Playlists;

use Crell\Tukio\Dispatcher;
use Phlix\Collections\CollectionManager;
use Phlix\Collections\CollectionRepository;
use Phlix\Common\Events\ListenerRegistry;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Playlists\LibraryUpdated;
use Phlix\Playlists\SmartPlaylistEngine;
use Phlix\Playlists\SmartPlaylistRefreshHandler;
use Phlix\Playlists\SmartPlaylistRefreshSubscriber;
use Phlix\Playlists\SmartPlaylistRepository;
use Phlix\Shared\Events\Library\LibraryScanCompleted;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use Workerman\MySQL\Connection;

/**
 * The subscriber that makes {@see SmartPlaylistRefreshHandler} live.
 *
 * The defect this suite guards is "the handler exists but nothing ever calls
 * it": `SmartPlaylistRefreshHandler::register()` had NO caller in the repo, so
 * the collections linked to a smart playlist never re-evaluated their stored
 * membership after a scan. (Smart PLAYLISTS have no stored membership — they are
 * evaluated on request — so smart-COLLECTION refresh is the whole of the
 * production behaviour at stake here.)
 *
 * The second half of the guard is just as important — the refresh is heavy and
 * blocking, so the event handler must NOT perform it inline on the dispatch
 * stack.
 *
 * @covers \Phlix\Playlists\SmartPlaylistRefreshSubscriber
 */
final class SmartPlaylistRefreshSubscriberTest extends TestCase
{
    /**
     * One smart-playlist row as `smart_playlists` returns it.
     *
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
     * One smart collection linked to `pl-1`, as `collections` returns it.
     *
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

    private function logger(): StructuredLogger
    {
        return $this->createMock(StructuredLogger::class);
    }

    /**
     * Build a subscriber over a real repository backed by a mock connection, so
     * "did the refresh run?" is observable as "was the DB touched?".
     *
     * @param Connection                $db                Mock connection.
     * @param CollectionManager|null    $collectionManager Optional collection refresher.
     * @param CollectionRepository|null $collectionRepo    Optional link lookup.
     * @return SmartPlaylistRefreshSubscriber
     */
    private function subscriber(
        Connection $db,
        ?CollectionManager $collectionManager = null,
        ?CollectionRepository $collectionRepo = null
    ): SmartPlaylistRefreshSubscriber {
        $handler = new SmartPlaylistRefreshHandler(
            new SmartPlaylistRepository($db),
            new ListenerRegistry(),
            $collectionManager,
            $collectionRepo
        );

        return new SmartPlaylistRefreshSubscriber($handler, $this->logger());
    }

    /**
     * The whole point: a real LibraryScanCompleted, dispatched through a real
     * Tukio dispatcher, must reach this subscriber. If `register()` is not
     * called — or subscribes the wrong event — nothing is pending.
     */
    public function test_scan_completed_dispatched_through_the_registry_reaches_the_subscriber(): void
    {
        $db = $this->createMock(Connection::class);
        $sub = $this->subscriber($db);

        $listeners = new ListenerRegistry();
        $ids = $sub->register($listeners);
        $this->assertNotEmpty($ids, 'register() must return the listener ids it created');

        $dispatcher = new Dispatcher($listeners->provider());
        $dispatcher->dispatch(new LibraryScanCompleted('lib-123', 7, 0, 0, 1234));

        $this->assertTrue(
            $sub->isPending('lib-123'),
            'LibraryScanCompleted must reach the subscriber — it is the only event the '
            . 'scanner actually dispatches, so subscribing to it is what makes smart '
            . 'collection refresh live at all.'
        );
    }

    /**
     * LibraryUpdated — the event the handler was originally written for — must
     * also be subscribed. Its emitter (`FolderWatcher`) is now driven by
     * `FolderWatchScheduler` in this same worker, but only when folder watching
     * is enabled, and `config/folder_watch.php` ships it disabled. So this stays
     * the guard that the folder-watch path works when an operator turns it on.
     */
    public function test_library_updated_dispatched_through_the_registry_reaches_the_subscriber(): void
    {
        $db = $this->createMock(Connection::class);
        $sub = $this->subscriber($db);

        $listeners = new ListenerRegistry();
        $sub->register($listeners);

        $dispatcher = new Dispatcher($listeners->provider());
        $dispatcher->dispatch(new LibraryUpdated('lib-777', '/media/movies'));

        $this->assertTrue($sub->isPending('lib-777'));
    }

    /**
     * The blocking-work guard: handling the event must do NO I/O.
     *
     * Refreshing one linked smart collection walks the whole library in 500-row
     * batches and then writes the membership diff. Running that on the dispatch
     * stack would stall the single-threaded worker's event loop from inside
     * `MediaScanner::scan()`, so the handler is only allowed to record the
     * library id.
     */
    public function test_the_event_handler_performs_no_database_work(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())
            ->method('query');

        $manager = $this->createMock(CollectionManager::class);
        $manager->expects($this->never())
            ->method('refreshSmartCollection');

        $sub = $this->subscriber($db, $manager, new CollectionRepository($db));

        $listeners = new ListenerRegistry();
        $sub->register($listeners);
        (new Dispatcher($listeners->provider()))->dispatch(
            new LibraryScanCompleted('lib-123', 7, 0, 0, 1234)
        );

        $this->assertSame(1, $sub->pendingCount(), 'the event may only enqueue');
    }

    /**
     * …and the deferred drain must actually refresh the smart COLLECTIONS linked
     * to the library's smart playlists, or the wiring would be an elaborate
     * no-op. That membership write is the only persisted effect of the whole
     * refresh path.
     */
    public function test_drain_tick_refreshes_the_linked_smart_collections(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            /**
             * @param array<int, mixed>|null $params
             * @return array<int, array<string, mixed>>
             */
            function (string $sql, ?array $params = null): array {
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

        $manager = $this->createMock(CollectionManager::class);
        $manager->expects($this->once())
            ->method('refreshSmartCollection')
            ->with('col-1');

        $sub = $this->subscriber($db, $manager, new CollectionRepository($db));
        $sub->onLibraryScanCompleted(new LibraryScanCompleted('lib-123', 7, 0, 0, 1234));

        $sub->drainTick();

        $this->assertSame(0, $sub->pendingCount(), 'the drained library must leave the pending set');
    }

    /**
     * REGRESSION GUARD: the refresh must not re-evaluate the playlist itself.
     *
     * `refreshLibrary()` used to call `SmartPlaylistEngine::evaluateOnScan()`
     * once per smart playlist and DISCARD the result. That was pure cost: the
     * engine persists nothing (no `query()`, no property mutation), there is no
     * materialised-results table in `migrations/004_smart_playlists.sql`, the
     * rules are evaluated on request by `SmartPlaylistController::preview()`,
     * and `CollectionManager::refreshSmartCollection()` re-evaluates them itself
     * anyway. Each discarded call was a full-library walk
     * (`SELECT * FROM media_items … LIMIT 500 OFFSET ?` per batch, rule-eval per
     * row) on the single-threaded library-scan worker's event loop.
     *
     * Guarded two ways, because after the dependency was dropped the defect has
     * no other runtime signature:
     * 1. the handler must not be constructible WITH a {@see SmartPlaylistEngine}
     *    — an engine here can only be used for that discarded evaluation;
     * 2. a drain must touch `smart_playlists` and `collections` only, never
     *    `media_items`.
     */
    public function test_the_refresh_never_re_evaluates_the_playlist_itself(): void
    {
        foreach ((new ReflectionClass(SmartPlaylistRefreshHandler::class))->getConstructor()?->getParameters() ?? [] as $param) {
            $type = $param->getType();
            $this->assertFalse(
                $type instanceof ReflectionNamedType && $type->getName() === SmartPlaylistEngine::class,
                'SmartPlaylistRefreshHandler must not take a SmartPlaylistEngine: evaluating a '
                . 'playlist here can only produce a DISCARDED full-library walk per playlist, on '
                . 'the library-scan worker\'s event loop. The evaluation that matters happens '
                . 'inside CollectionManager::refreshSmartCollection().'
            );
        }

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
                    return $this->playlistRows();
                }
                if (str_contains($sql, 'FROM collections')) {
                    return $this->collectionRows();
                }

                return [];
            }
        );

        $sub = $this->subscriber(
            $db,
            $this->createMock(CollectionManager::class),
            new CollectionRepository($db)
        );
        $sub->onLibraryScanCompleted(new LibraryScanCompleted('lib-123', 7, 0, 0, 1234));
        $sub->drainTick();

        foreach ($seen as $sql) {
            $this->assertStringNotContainsString(
                'media_items',
                $sql,
                'The handler must not walk media_items: that walk belongs to '
                . 'CollectionManager::refreshSmartCollection(), which does it once per LINKED '
                . 'collection and actually uses the result.'
            );
        }
        $this->assertCount(
            2,
            $seen,
            'a refresh of one playlist with one linked collection is exactly two queries: '
            . 'the playlists of the library, and the collections linked to the playlist'
        );
    }

    /**
     * `LibraryManager::scanLibrary()` calls `MediaScanner::scan()` once per
     * library PATH and each call dispatches its own LibraryScanCompleted, so a
     * 3-path library must still refresh ONCE, not three times.
     */
    public function test_repeated_events_for_one_library_coalesce_into_a_single_refresh(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->willReturn([]);

        $sub = $this->subscriber($db);

        $sub->onLibraryScanCompleted(new LibraryScanCompleted('lib-123', 1, 0, 0, 10));
        $sub->onLibraryScanCompleted(new LibraryScanCompleted('lib-123', 2, 0, 0, 20));
        $sub->onLibraryUpdated(new LibraryUpdated('lib-123', '/media/b'));

        $this->assertSame(1, $sub->pendingCount(), 'three events for one library are one refresh');

        $sub->drainTick();
        $sub->drainTick();

        $this->assertSame(0, $sub->pendingCount());
    }

    /**
     * Distinct libraries are drained one per tick, so a single heavy refresh
     * never chains into another within the same event-loop turn.
     */
    public function test_drain_tick_refreshes_at_most_one_library_per_tick(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(2))
            ->method('query')
            ->willReturn([]);

        $sub = $this->subscriber($db);

        $sub->onLibraryScanCompleted(new LibraryScanCompleted('lib-a', 1, 0, 0, 10));
        $sub->onLibraryScanCompleted(new LibraryScanCompleted('lib-b', 1, 0, 0, 10));
        $this->assertSame(2, $sub->pendingCount());

        $sub->drainTick();
        $this->assertSame(1, $sub->pendingCount(), 'exactly one library per tick');

        $sub->drainTick();
        $this->assertSame(0, $sub->pendingCount());
    }

    /**
     * Resident-memory safety: the pending set is a bounded set, not an
     * unbounded array that grows for the life of the worker.
     */
    public function test_pending_set_is_bounded(): void
    {
        $db = $this->createMock(Connection::class);
        $sub = $this->subscriber($db);

        for ($i = 0; $i < 400; $i++) {
            $sub->onLibraryScanCompleted(new LibraryScanCompleted('lib-' . $i, 1, 0, 0, 10));
        }

        $this->assertSame(
            256,
            $sub->pendingCount(),
            'the pending set must be capped — an unbounded array in a resident '
            . 'Workerman worker is a memory leak'
        );
    }

    /**
     * A failing refresh must not escape the timer callback: an uncaught throw
     * there kills the drain for the rest of the worker's life.
     */
    public function test_a_failing_refresh_does_not_escape_the_drain(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willThrowException(new \RuntimeException('db down'));

        $sub = $this->subscriber($db);

        $sub->onLibraryScanCompleted(new LibraryScanCompleted('lib-123', 1, 0, 0, 10));
        $sub->drainTick();

        $this->assertSame(
            0,
            $sub->pendingCount(),
            'a failed library must be dropped, not retried in a tight loop'
        );
    }

    /**
     * An empty pending set must be a cheap no-op — the timer ticks forever.
     */
    public function test_drain_tick_with_nothing_pending_is_a_no_op(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        $sub = $this->subscriber($db);

        $sub->drainTick();

        $this->assertSame(0, $sub->pendingCount());
    }
}
