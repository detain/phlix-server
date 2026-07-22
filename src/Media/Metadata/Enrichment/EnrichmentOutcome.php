<?php

/**
 * Phlix media server component: Enrichment.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata\Enrichment;

/**
 * The result of a single {@see PluginMetadataEnricher::enrichOne()} drain.
 *
 * Tells the drain loop whether the item still needs to be re-queued (because
 * some of its registered sources were rate-limited this cycle and have not yet
 * been consulted).
 *
 * @package Phlix\Media\Metadata\Enrichment
 * @since   0.15.0
 */
enum EnrichmentOutcome
{
    /**
     * Nothing to do: item missing, not an enrichable type, no sources
     * registered for the type, or already fully enriched. Do NOT re-queue.
     */
    case Nothing;

    /**
     * The item has registered sources still missing their contribution, but
     * EVERY one is inside its per-source cool-down right now. No source was
     * consulted and no quota was spent — re-queue so a later tick can try once
     * a source becomes due.
     */
    case Deferred;

    /**
     * At least one due source was consulted and the item was persisted. No
     * further sources remain pending — do NOT re-queue.
     */
    case Enriched;

    /**
     * At least one due source was consulted and persisted, but OTHER
     * registered sources are still missing their contribution and were
     * throttled this cycle — re-queue to finish them later.
     */
    case EnrichedDeferred;

    /**
     * Whether the drain loop should re-enqueue this item to finish sources
     * that were throttled this cycle.
     */
    public function requeue(): bool
    {
        return $this === self::Deferred || $this === self::EnrichedDeferred;
    }
}
