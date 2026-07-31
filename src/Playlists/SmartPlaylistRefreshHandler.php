<?php

/**
 * Phlix media server component: Playlists.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Playlists;

use Phlix\Collections\CollectionManager;
use Phlix\Collections\CollectionRepository;
use Phlix\Common\Events\ListenerRegistry;

/**
 * Refreshes the smart COLLECTIONS of a library when its content changes.
 *
 * For every smart playlist in the affected library this refreshes the
 * collections linked to that playlist, so their stored membership matches the
 * library again.
 *
 * ⚠ A smart PLAYLIST itself has nothing to refresh, so this class does not
 * touch one. `smart_playlists` (`migrations/004_smart_playlists.sql`) stores
 * rules only — there is no materialised-results table — and the rules are
 * evaluated ON REQUEST by
 * {@see SmartPlaylistController::preview()} (`POST
 * /api/v1/smart-playlists/{id}/preview`), the only place a smart playlist's
 * items are ever produced. A smart COLLECTION is different: its membership IS
 * persisted, in `collection_items`, so it goes stale after a scan until
 * something re-evaluates it. That is the whole job of this class.
 *
 * @since 0.14.0
 */
final class SmartPlaylistRefreshHandler
{
    /**
     * ⚠ Takes NO {@see SmartPlaylistEngine}, deliberately. Evaluating the rules
     * here would be a discarded full-library walk per playlist — see
     * {@see self::refreshLibrary()}; the evaluation that matters happens inside
     * {@see \Phlix\Collections\CollectionManager::refreshSmartCollection()}.
     *
     * @param SmartPlaylistRepository  $repo              Reads the library's smart playlists.
     * @param ListenerRegistry         $listeners         Registry used by {@see self::register()}.
     * @param CollectionManager|null   $collectionManager Performs the collection refresh; when null,
     *     the refresh is a silent no-op, so the container binds it explicitly.
     * @param CollectionRepository|null $collectionRepo   Finds the collections linked to a playlist.
     */
    public function __construct(
        private readonly SmartPlaylistRepository $repo,
        private readonly ListenerRegistry $listeners,
        private readonly ?CollectionManager $collectionManager = null,
        private readonly ?CollectionRepository $collectionRepo = null,
    ) {
    }

    /**
     * Handle library updated event.
     *
     * @param LibraryUpdated $event Event with library_id
     * @return void
     *
     * @since 0.14.0
     */
    public function onLibraryUpdated(LibraryUpdated $event): void
    {
        $this->refreshLibrary($event->libraryId);
    }

    /**
     * Refresh the collections linked to a library's smart playlists.
     *
     * ⚠ **Expensive and fully blocking — per LINKED COLLECTION.** Each
     * {@see \Phlix\Collections\CollectionManager::refreshSmartCollection()}
     * re-evaluates its playlist's rules by walking the WHOLE library in 500-row
     * batches ({@see SmartPlaylistEngine::evaluateOnScan()} →
     * `iterateItemsForLibrary()`) and then writes the membership diff into
     * `collection_items`. Cost is O(library size × linked collections) +
     * O(playlists) blocking DB round-trips: the heavy per-collection walk above,
     * plus one cheap `SELECT` for the library's playlists and one MORE per
     * playlist, because {@see self::refreshCollectionsForPlaylist()} has to query
     * `collections` before it can discover that a playlist has no link at all.
     * So the floor for a library whose smart playlists have no linked collection
     * is `1 + N` cheap `SELECT`s for N playlists, NOT one — measured, 5
     * playlists with zero links issue 6 queries (and one playlist with one link
     * issues 2, the count `tests/Integration/Playlists/SmartPlaylistRefreshTest`
     * pins). Never call this on a PSR-14 dispatch stack or from an HTTP handler
     * — it is driven off a timer by {@see SmartPlaylistRefreshSubscriber}, in
     * the library-scan worker only.
     *
     * @param string $libraryId Library UUID whose smart collections are stale.
     * @return void
     *
     * @since 0.14.0
     */
    public function refreshLibrary(string $libraryId): void
    {
        $playlists = $this->repo->findByLibraryId($libraryId);

        foreach ($playlists as $playlist) {
            // The playlist itself is deliberately NOT re-evaluated here. It has
            // no stored results to refresh (see the class docblock), and
            // refreshSmartCollection() re-evaluates the same rules itself
            // (CollectionManager:218) before writing the membership diff — so an
            // evaluation here would be a discarded full-library walk per
            // playlist, on the library-scan worker's event loop.
            $this->refreshCollectionsForPlaylist($playlist->id);
        }
    }

    /**
     * Refresh all collections that reference a smart playlist.
     *
     * @param string $smartPlaylistId Smart playlist UUID
     * @return void
     *
     * @since 0.14.0
     */
    private function refreshCollectionsForPlaylist(string $smartPlaylistId): void
    {
        if ($this->collectionManager === null || $this->collectionRepo === null) {
            return;
        }

        $collections = $this->collectionRepo->findBySmartPlaylistId($smartPlaylistId);
        foreach ($collections as $collection) {
            $this->collectionManager->refreshSmartCollection($collection->id);
        }
    }

    /**
     * Register this handler to listen for LibraryUpdated events.
     *
     * ⚠ Subscribes {@see self::onLibraryUpdated()} DIRECTLY, so the whole
     * collection refresh (expensive, blocking — see {@see self::refreshLibrary()})
     * runs INLINE on the dispatcher's call stack. Production wiring must not use
     * this: {@see SmartPlaylistRefreshSubscriber} is the registered subscriber,
     * and it only enqueues on the event and drains off a timer. Kept because it
     * is this class's documented API and is exercised by its tests.
     *
     * @return string Listener ID from the registry
     *
     * @since 0.14.0
     */
    public function register(): string
    {
        return $this->listeners->subscribe(
            LibraryUpdated::class,
            [$this, 'onLibraryUpdated']
        );
    }
}
