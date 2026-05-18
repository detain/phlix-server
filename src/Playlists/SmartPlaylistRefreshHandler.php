<?php

declare(strict_types=1);

namespace Phlex\Playlists;

use Phlex\Common\Events\ListenerRegistry;

/**
 * Handles smart playlist re-evaluation when library content changes.
 *
 * Listens to LibraryUpdated events and re-evaluates all smart playlists
 * for the affected library. This keeps smart playlists in sync with
 * the media library.
 *
 * @since 0.14.0
 */
final class SmartPlaylistRefreshHandler
{
    public function __construct(
        private readonly SmartPlaylistEngine $engine,
        private readonly SmartPlaylistRepository $repo,
        private readonly ListenerRegistry $listeners,
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
            // In a future phase (H.2), this could emit SmartPlaylistChanged events
            $this->engine->evaluateOnScan(
                $playlist->getRules(),
                $playlist->libraryId,
                $playlist->limit,
                $playlist->sortBy,
                $playlist->sortDesc
            );
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
