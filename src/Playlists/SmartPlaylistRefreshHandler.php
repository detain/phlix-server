<?php

declare(strict_types=1);

namespace Phlix\Playlists;

use Phlix\Collections\CollectionManager;
use Phlix\Collections\CollectionRepository;
use Phlix\Common\Events\ListenerRegistry;

/**
 * Handles smart playlist re-evaluation when library content changes.
 *
 * Listens to LibraryUpdated events and re-evaluates all smart playlists
 * for the affected library. This keeps smart playlists in sync with
 * the media library. Also refreshes any collections linked to the
 * affected smart playlists.
 *
 * @since 0.14.0
 */
final class SmartPlaylistRefreshHandler
{
    public function __construct(
        private readonly SmartPlaylistEngine $engine,
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
        $playlists = $this->repo->findByLibraryId($event->libraryId);

        foreach ($playlists as $playlist) {
            // Re-evaluate the playlist to update any cached results
            /** @var array<array<string, mixed>|RuleNode> $rules */
            $rules = $playlist->getRules();
            $this->engine->evaluateOnScan(
                $rules,
                $playlist->libraryId,
                $playlist->limit,
                $playlist->sortBy,
                $playlist->sortDesc
            );

            // Refresh any collections linked to this smart playlist (H.2)
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
