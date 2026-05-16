<?php

declare(strict_types=1);

namespace Phlex\Common\Events\Library;

use Phlex\Common\Events\AbstractEvent;

/**
 * Fired when an existing media item's metadata changes.
 *
 * Fired by: metadata-refresh writes inside
 * `\Phlex\Media\Library\ItemRepository` (wired in a later phase; the
 * event class ships in A.2 so plugins can pre-subscribe).
 * Typical listener: search-index re-indexer, recommendation cache
 * invalidator, "updated metadata" notifier, integrations that mirror
 * Phlex metadata into another tool (Notion, Airtable, etc.).
 *
 * Manifest alias (Phase A.4 loader): `phlex.library.item.updated`.
 *
 * @package Phlex\Common\Events\Library
 * @since 0.10.0
 */
final class MediaItemUpdated extends AbstractEvent
{
    /**
     * @param string                $mediaItemId   UUID of the updated item.
     * @param array<int, string>    $changedFields Ordered list of column /
     *                                             metadata-key names that
     *                                             changed in this update.
     *                                             Listeners can use this to
     *                                             skip work when the change
     *                                             is irrelevant to them.
     */
    public function __construct(
        public readonly string $mediaItemId,
        public readonly array $changedFields,
    ) {
        parent::__construct();
    }
}
