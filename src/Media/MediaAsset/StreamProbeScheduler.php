<?php

/**
 * Phlix media server component: Media Asset.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Media\MediaAsset;

use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\LogChannels;
use Phlix\Media\Library\StreamProbeBackfill;
use Psr\Log\LoggerInterface;

/**
 * Enqueues the background full-track-set probe for a legacy media item.
 *
 * Shared by both playback-info dispatch paths
 * ({@see \Phlix\Server\Http\Controllers\MediaItemController::getPlaybackInfo()}
 * and {@see \Phlix\Server\WebPortal\WebPortalRouter::getPlaybackInfo()}) so they enqueue identically.
 * It only writes a job file. It never probes, never reads the media file, and never touches the
 * stream rows, so it is safe on the request path. The probe itself runs in
 * {@see MediaAssetWorker}.
 *
 * A failure to write the queue is logged and swallowed. A playback response must never fail
 * because a background repair could not be scheduled.
 *
 * @since 0.74.0
 */
class StreamProbeScheduler
{
    /** @var MediaAssetJobStore|null Queue; built on first use from the configured directory when not injected */
    private ?MediaAssetJobStore $store;

    /** @var LoggerInterface Media-channel logger */
    private LoggerInterface $logger;

    /**
     * @param MediaAssetJobStore|null $store  Optional queue (test seam). Null builds the
     *                                        configured store on first enqueue.
     * @param LoggerInterface|null    $logger Optional logger (defaults to the MEDIA channel).
     */
    public function __construct(?MediaAssetJobStore $store = null, ?LoggerInterface $logger = null)
    {
        $this->store = $store;
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::MEDIA);
    }

    /**
     * Enqueues a background full probe when the item matches the legacy trigger.
     *
     * Uses {@see StreamProbeBackfill::needsFullStreamProbe()} as the only predicate. A video-only
     * gap is not matched here (see that method). Enqueueing is idempotent per item: when a job is
     * already pending the store leaves it alone.
     *
     * @param array<string, mixed>             $item    Hydrated media_items row.
     * @param array<int, array<string, mixed>> $streams The item's stored `media_streams` rows.
     *
     * @return bool True when the item matched and a job is now pending (new or already queued);
     *              false when it did not match or the queue could not be written.
     */
    public function scheduleIfLegacy(array $item, array $streams): bool
    {
        if (!StreamProbeBackfill::needsFullStreamProbe($item, $streams)) {
            return false;
        }

        $itemId = is_string($item['id'] ?? null) ? $item['id'] : '';

        try {
            $this->store ??= new MediaAssetJobStore();
            $this->store->enqueueStreamProbe(new StreamProbeJob($itemId));
        } catch (\Throwable $e) {
            $this->logger->warning('Stream probe could not be enqueued; playback continues with stored rows', [
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        return true;
    }
}
