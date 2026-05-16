<?php

declare(strict_types=1);

namespace Phlex\Common\Events\Library;

use Phlex\Common\Events\AbstractEvent;

/**
 * Fired when a media item is removed from a library.
 *
 * Fired by: cleanup passes inside `\Phlex\Media\Library\ItemRepository`
 * and `MediaScanner` when the backing file is gone (wired in a later
 * phase; the event class ships in A.2 so plugins can pre-subscribe).
 * Typical listener: search-index cleaner, "your file is gone" notifier,
 * watch-history archiver, recommendation cache invalidator.
 *
 * Manifest alias (Phase A.4 loader): `phlex.library.item.removed`.
 *
 * @package Phlex\Common\Events\Library
 * @since 0.10.0
 */
final class MediaItemRemoved extends AbstractEvent
{
    /**
     * @param string $mediaItemId UUID of the removed item.
     * @param string $libraryId   UUID of the library it was removed from.
     */
    public function __construct(
        public readonly string $mediaItemId,
        public readonly string $libraryId,
    ) {
        parent::__construct();
    }
}
