<?php

/**
 * Phlix media server component: Enrichment.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata\Enrichment;

use Phlix\Common\Events\ListenerRegistry;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\Resolution\SourceRegistry;
use Phlix\Shared\Events\Library\MediaItemAdded;
use Throwable;
use Workerman\Timer;

/**
 * Host-side subscriber that drives background, throttled plugin-source
 * enrichment off the library scan path (F2b).
 *
 * ## Flow
 *
 * 1. Subscribes to {@see MediaItemAdded} in the worker that dispatches it (the
 *    `library-scan` managed worker — see `start.php`). The handler filters to
 *    `movie`/`series`, checks the item is not already fully plugin-enriched,
 *    and ENQUEUES its id. It does NO HTTP — the scan path stays fast (that is
 *    the whole point of F2b).
 * 2. A re-arming {@see Timer}, armed LAZILY on the first enqueue, drains ONE
 *    item per tick via {@see PluginMetadataEnricher}. The timer is boot-safe:
 *    guarded by `class_exists(Timer)` + try/catch so it is a pure no-op outside
 *    a Workerman runtime (and in unit tests).
 *
 * ## RULE 7 (behaviour equals before, when no source plugins are enabled)
 *
 * With no plugin metadata sources registered, {@see SourceRegistry::forMediaType()}
 * returns empty, so {@see onMediaItemAdded()} returns immediately WITHOUT even a
 * DB read, nothing is ever enqueued, the drain timer never arms, and no item
 * metadata changes — identical to pre-F2b behaviour.
 *
 * ## Resident-memory
 *
 * Instance-scoped queue + rate limiter (bounded, de-duped, freed as they drain).
 * No mutable static/global state. One re-arming timer per worker; armed once.
 *
 * @package Phlix\Media\Metadata\Enrichment
 * @since   0.15.0
 */
final class BackgroundEnrichmentSubscriber
{
    /** Media-item types eligible for plugin-source enrichment. */
    private const ENRICHABLE_TYPES = ['movie', 'series'];

    private StructuredLogger $logger;

    /** Whether the re-arming drain timer has been armed in this worker. */
    private bool $timerArmed = false;

    /**
     * @param PluginEnrichmentQueue   $queue          Bounded FIFO of pending item ids.
     * @param PluginMetadataEnricher  $enricher       Drains + gap-fills one item.
     * @param SourceRegistry          $registry       Enabled plugin metadata sources.
     * @param ItemRepository          $items          Media-item lookup (idempotency check).
     * @param StructuredLogger|null   $logger         Optional logger; defaults to the MEDIA channel.
     * @param float                   $drainTickSeconds Timer tick spacing; the queue's own
     *     min-interval + the per-source limiter are the real throttles, so a modest
     *     1s tick just paces the drain loop against the event loop.
     */
    public function __construct(
        private readonly PluginEnrichmentQueue $queue,
        private readonly PluginMetadataEnricher $enricher,
        private readonly SourceRegistry $registry,
        private readonly ItemRepository $items,
        ?StructuredLogger $logger = null,
        private readonly float $drainTickSeconds = 1.0,
    ) {
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::MEDIA);
    }

    /**
     * Subscribe this subscriber's {@see MediaItemAdded} handler to the given
     * registry. Call once per worker that dispatches the event.
     *
     * @param ListenerRegistry $listeners The worker's listener registry.
     * @return string The opaque listener id.
     */
    public function register(ListenerRegistry $listeners): string
    {
        return $listeners->subscribe(MediaItemAdded::class, [$this, 'onMediaItemAdded']);
    }

    /**
     * Handle a newly-added media item: filter, idempotency-check, enqueue.
     * Performs NO HTTP — only a bounded enqueue plus a single indexed DB read.
     */
    public function onMediaItemAdded(MediaItemAdded $event): void
    {
        if (!in_array($event->type, self::ENRICHABLE_TYPES, true)) {
            return;
        }

        // RULE 7: no source plugins for this type → zero work, not even a read.
        if ($this->registry->forMediaType($event->type) === []) {
            return;
        }

        if (!$this->needsEnrichment($event->mediaItemId, $event->type)) {
            return;
        }

        if ($this->queue->enqueue($event->mediaItemId)) {
            $this->armDrainTimer();
        }
    }

    /**
     * Drain at most one due item and enrich it. Re-queues the item when its
     * enrichment was only partially completed (some sources throttled).
     *
     * Public so the drain timer callback (and tests) can invoke it directly.
     */
    public function drainTick(): void
    {
        $itemId = $this->queue->dequeueDue();
        if ($itemId === null) {
            return;
        }

        try {
            $outcome = $this->enricher->enrichOne($itemId);
            if ($outcome->requeue()) {
                $this->queue->enqueue($itemId);
            }
        } catch (Throwable $e) {
            $this->logger->warning('BackgroundEnrichmentSubscriber: drain failed', [
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Whether the item still has at least one registered source for its type
     * with no recorded contribution. A single indexed primary-key read; no I/O
     * beyond the DB.
     */
    private function needsEnrichment(string $itemId, string $type): bool
    {
        $item = $this->items->findById($itemId);
        if ($item === null) {
            return false;
        }
        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
        $marker = is_array($metadata['plugin_enriched'] ?? null) ? $metadata['plugin_enriched'] : [];
        foreach (array_keys($this->registry->forMediaType($type)) as $name) {
            if (!array_key_exists($name, $marker)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Arm the re-arming drain timer once. Boot-safe: a no-op outside a
     * Workerman runtime (class missing) or when the timer cannot be created
     * (no running event loop — e.g. unit tests), so an enqueue never fails
     * because a timer could not be scheduled.
     */
    private function armDrainTimer(): void
    {
        if ($this->timerArmed) {
            return;
        }
        // Flag first so a throwing Timer::add (no runtime) does not retry-spam
        // on every subsequent enqueue.
        $this->timerArmed = true;

        if (!class_exists(Timer::class)) {
            return;
        }
        try {
            // Repeating timer (Workerman repeats by default) — one drain tick
            // per interval, re-arming until the worker stops.
            Timer::add($this->drainTickSeconds, [$this, 'drainTick']);
        } catch (Throwable $e) {
            // No Workerman event loop (tests / non-daemon entrypoint): the drain
            // simply won't auto-run here; drainTick() can still be called
            // explicitly. Never let this bubble into the scan path.
            $this->logger->debug('BackgroundEnrichmentSubscriber: drain timer not armed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
