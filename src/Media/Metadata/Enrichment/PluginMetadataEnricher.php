<?php

/**
 * Phlix media server component: Enrichment.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata\Enrichment;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\Dto\MetadataValue;
use Phlix\Media\Metadata\RatingService;
use Phlix\Media\Metadata\RatingSource;
use Phlix\Media\Metadata\RatingType;
use Phlix\Media\Metadata\Resolution\PluginSourceConsultation;
use Phlix\Media\Metadata\Resolution\PriorityConfig;
use Phlix\Media\Metadata\Resolution\PriorityFieldResolver;
use Phlix\Media\Metadata\Resolution\SourceRegistry;
use Throwable;

/**
 * Drains ONE queued media item and gap-fills it from the enabled plugin
 * metadata sources (omdb / anidb / myanimelist), off the scan path.
 *
 * ## What it does (F2b)
 *
 * F2a taught the resolver to consult {@see SourceRegistry} sources, but left it
 * off by default so a bulk scan never hits their quotas. This class is the
 * automatic, throttled, background trigger: for a single already-scanned item
 * it consults ONLY the sources that are (a) registered for the item's type,
 * (b) not yet recorded against the item, and (c) currently DUE per
 * {@see SourceRateLimiter}. It then GAP-FILLS the plugin data OVER the item's
 * existing metadata — a plugin value is written only into a field the item
 * currently lacks, so the scan-resolved TMDB/IMDb values are NEVER clobbered
 * (plugin data lives strictly UNDER them). It does NOT re-run the full
 * {@see \Phlix\Media\Metadata\MovieMetadataResolver}, so it never re-hits TMDB.
 *
 * ## Idempotency / no re-spend
 *
 * Every consulted source is stamped into `metadata_json.plugin_enriched`
 * (`{ "omdb": <unix_ts>, … }`) — marked on ATTEMPT, whether or not it matched.
 * A later rescan / re-`MediaItemAdded` therefore never re-enqueues or
 * re-consults an already-attempted source, so a source's quota is spent at most
 * ONCE per item.
 *
 * ## Safety / isolation
 *
 * Per-source failures are already isolated inside {@see PluginSourceConsultation}
 * (a throwing source is logged and skipped, the rest still run). This class
 * performs NO concurrent fan-out — one item, its due sources consulted in
 * order, one persist. It holds no mutable static/global state.
 *
 * @package Phlix\Media\Metadata\Enrichment
 * @since   0.15.0
 */
final class PluginMetadataEnricher
{
    /** Media-item types eligible for plugin-source enrichment. */
    private const ENRICHABLE_TYPES = ['movie', 'series'];

    private StructuredLogger $logger;

    private PriorityFieldResolver $fieldResolver;

    /**
     * @param SourceRegistry             $registry      Process-scoped registry of enabled plugin sources.
     * @param ItemRepository             $items         Media-item persistence (findById / update).
     * @param RatingService              $ratingService Persists any surfaced plugin ratings.
     * @param PriorityConfig             $priorityConfig Per-type source priority (consult order + genres mode).
     * @param SourceRateLimiter          $rateLimiter   Per-source min-spacing quota guard.
     * @param StructuredLogger|null      $logger        Optional logger; defaults to the MEDIA channel.
     * @param PriorityFieldResolver|null $fieldResolver Optional merge engine; a fresh pure instance by default.
     */
    public function __construct(
        private readonly SourceRegistry $registry,
        private readonly ItemRepository $items,
        private readonly RatingService $ratingService,
        private readonly PriorityConfig $priorityConfig,
        private readonly SourceRateLimiter $rateLimiter,
        ?StructuredLogger $logger = null,
        ?PriorityFieldResolver $fieldResolver = null,
    ) {
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::MEDIA);
        $this->fieldResolver = $fieldResolver ?? new PriorityFieldResolver();
    }

    /**
     * Enrich a single media item from its due plugin sources.
     *
     * @param string $itemId Media-item UUID.
     * @return EnrichmentOutcome Whether work was done and whether the item must be re-queued.
     */
    public function enrichOne(string $itemId): EnrichmentOutcome
    {
        if ($itemId === '') {
            return EnrichmentOutcome::Nothing;
        }

        $item = $this->items->findById($itemId);
        if ($item === null) {
            return EnrichmentOutcome::Nothing;
        }

        $type = MetadataValue::asNullableString($item['type'] ?? null);
        if ($type === null || !in_array($type, self::ENRICHABLE_TYPES, true)) {
            return EnrichmentOutcome::Nothing;
        }

        $registered = $this->registry->forMediaType($type);
        if ($registered === []) {
            // RULE 7: no source plugins enabled for this type — a pure no-op.
            return EnrichmentOutcome::Nothing;
        }

        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
        /** @var array<string, mixed> $marker */
        $marker = is_array($metadata['plugin_enriched'] ?? null) ? $metadata['plugin_enriched'] : [];

        // Sources registered for this type that have NOT yet been attempted.
        $unmarked = [];
        foreach ($registered as $name => $source) {
            if (!array_key_exists($name, $marker)) {
                $unmarked[$name] = $source;
            }
        }
        if ($unmarked === []) {
            // Every registered source already attempted — never re-spend.
            return EnrichmentOutcome::Nothing;
        }

        // Of the unmarked sources, consult ONLY those currently due.
        $due = [];
        foreach ($unmarked as $name => $source) {
            if ($this->rateLimiter->due($name)) {
                $due[$name] = $source;
            }
        }
        if ($due === []) {
            // Sources pending but all throttled — retry on a later tick.
            return EnrichmentOutcome::Deferred;
        }

        // Consult only the due sources via a throwaway registry, reusing the
        // F2a consultation (search → getDetails → fromGeneric, per-source
        // try/catch, priority-first order).
        $filtered = new SourceRegistry();
        foreach ($due as $source) {
            $filtered->register($source);
        }

        $title = $this->deriveTitle($item, $metadata);
        $year = MetadataValue::asNullableInt($metadata['year'] ?? null);
        $order = $this->priorityConfig->orderFor($type);

        $consult = (new PluginSourceConsultation($filtered, $this->logger))
            ->consult($type, $title, $year, $order);

        // Mark every due source as ATTEMPTED (quota guard + idempotency),
        // regardless of whether it matched — so we never re-spend on it.
        $now = time();
        foreach (array_keys($due) as $name) {
            $this->rateLimiter->mark($name);
            $marker[$name] = $now;
        }

        $merged = $this->gapFill($metadata, $consult['records'], $consult['sources']);
        $merged['plugin_enriched'] = $marker;

        $this->items->update($itemId, ['metadata_json' => $merged]);

        $this->persistRatings($itemId, $consult['ratings']);

        $this->logger->info('PluginMetadataEnricher: enriched', [
            'item_id' => $itemId,
            'type' => $type,
            'sources_consulted' => array_keys($due),
            'contributed' => $consult['sources'],
        ]);

        // If some registered sources were still pending but throttled this
        // cycle, ask the drain loop to re-queue so they finish later.
        $stillPending = count($unmarked) > count($due);
        return $stillPending ? EnrichmentOutcome::EnrichedDeferred : EnrichmentOutcome::Enriched;
    }

    /**
     * Gap-fill the item's existing metadata with the plugin records — writing a
     * plugin value ONLY where the item currently has no non-empty value, so
     * TMDB/IMDb (already present from the scan) always wins a shared field.
     *
     * @param array<string, mixed>                                $existing Existing decoded metadata.
     * @param list<\Phlix\Media\Metadata\Resolution\SourceRecord> $records  Plugin canonical records.
     * @param list<string>                                        $sources  Contributing source names (consult order).
     * @return array<string, mixed> Merged metadata (existing values preserved).
     */
    private function gapFill(array $existing, array $records, array $sources): array
    {
        if ($records === [] || $sources === []) {
            return $existing;
        }

        $resolved = $this->fieldResolver->resolve($records, $sources, $this->priorityConfig->genresMode());
        unset($resolved['sources']);
        /** @var array<string, string> $pluginIds */
        $pluginIds = is_array($resolved['external_ids'] ?? null) ? $resolved['external_ids'] : [];
        unset($resolved['external_ids']);

        $merged = $existing;
        foreach ($resolved as $key => $value) {
            if (!$this->isNonEmpty($merged[$key] ?? null)) {
                $merged[$key] = $value;
            }
        }

        // external_ids: union with the EXISTING ids winning any collision so a
        // plugin can never overwrite the scan-resolved tmdb/imdb id.
        $existingIds = is_array($merged['external_ids'] ?? null) ? $merged['external_ids'] : [];
        if ($pluginIds !== [] || $existingIds !== []) {
            $merged['external_ids'] = array_merge($pluginIds, $existingIds);
        }

        return $merged;
    }

    /**
     * Persist ratings surfaced by plugin sources, mirroring
     * {@see \Phlix\Media\Metadata\LibraryMetadataMatcher::persistPluginRatings()}:
     * each entry's source is mapped through {@see RatingSource::tryFrom()} so a
     * value outside the DB ENUM is dropped rather than throwing.
     *
     * @param string                                                     $itemId  Media-item UUID.
     * @param list<array{source: string, score: float, votes?: int}>     $ratings Surfaced ratings.
     */
    private function persistRatings(string $itemId, array $ratings): void
    {
        if ($ratings === []) {
            return;
        }

        $persisted = false;
        foreach ($ratings as $rating) {
            $sourceName = MetadataValue::asNullableString($rating['source'] ?? null);
            $score = MetadataValue::asNullableFloat($rating['score'] ?? null);
            if ($sourceName === null || $score === null) {
                continue;
            }
            $source = RatingSource::tryFrom($sourceName);
            if ($source === null) {
                $this->logger->debug('PluginMetadataEnricher: skipping non-enum rating source', [
                    'item_id' => $itemId,
                    'source' => $sourceName,
                ]);
                continue;
            }
            $votes = MetadataValue::asNullableInt($rating['votes'] ?? null);
            try {
                $this->ratingService->upsert($itemId, $source, RatingType::User, $score, $votes);
                $persisted = true;
            } catch (Throwable $e) {
                $this->logger->warning('PluginMetadataEnricher: rating upsert failed', [
                    'item_id' => $itemId,
                    'source' => $sourceName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($persisted) {
            try {
                $this->ratingService->aggregate($itemId);
            } catch (Throwable $e) {
                $this->logger->warning('PluginMetadataEnricher: rating aggregate failed', [
                    'item_id' => $itemId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Best title for the plugin `search()` — the resolved metadata title, else
     * the item's on-disk name.
     *
     * @param array<string, mixed> $item     Hydrated item row.
     * @param array<string, mixed> $metadata Decoded metadata.
     */
    private function deriveTitle(array $item, array $metadata): string
    {
        $title = MetadataValue::asNullableString($metadata['title'] ?? null);
        if ($title !== null) {
            return $title;
        }
        return MetadataValue::asString($item['name'] ?? null);
    }

    /**
     * A value counts as present for gap-fill purposes when it is a non-empty
     * string, a non-empty array, or any scalar. null / '' / [] mean "missing"
     * and are eligible to be gap-filled.
     */
    private function isNonEmpty(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_string($value)) {
            return $value !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }
        return true;
    }
}
