<?php

declare(strict_types=1);

namespace Phlex\Playlists;

/**
 * Event fired when library content is updated (files added/modified/deleted).
 *
 * Emitted by FolderWatcher on detecting changes during folder-watch cycles.
 *
 * @since 0.14.0
 */
final class LibraryUpdated
{
    /**
     * @param string $libraryId The library that was updated
     * @param string $path The path that triggered the update
     * @param \DateTimeImmutable $occurredAt When the update was detected
     */
    public function __construct(
        public readonly string $libraryId,
        public readonly string $path,
        public readonly \DateTimeImmutable $occurredAt = new \DateTimeImmutable(),
    ) {
    }
}
