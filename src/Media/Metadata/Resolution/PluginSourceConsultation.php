<?php

/**
 * Phlix media server component: Resolution.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata\Resolution;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Metadata\Dto\MetadataValue;
use Phlix\Shared\Metadata\MetadataSourceInterface;
use Throwable;

/**
 * Consults the plugin-provided {@see MetadataSourceInterface} sources in
 * {@see SourceRegistry} for one media item and returns their canonical records,
 * provenance names and any surfaced ratings.
 *
 * ## Why this exists (F2)
 *
 * {@see SourceRegistry::forMediaType()} indexes the enabled metadata-source
 * plugins (`omdb`, `anidb`, `myanimelist`, …) but had **zero consumers** — the
 * live movie/series resolvers hard-wired TMDB+IMDb. This service is that
 * consumer. It runs the interface triad (`search` → `getDetails`) for each
 * matching source and normalises the result through
 * {@see FieldMappers::fromGeneric()} so a resolver can append the records to its
 * `$records[]` list and merge them via {@see PriorityFieldResolver} — always
 * UNDER the built-in providers (the caller appends the plugin source names to
 * the END of the merge order, so TMDB/IMDb win every shared field and plugin
 * data is pure gap-fill).
 *
 * ## Safety / isolation
 *
 * Each source is consulted in its OWN try/catch: a throwing or misbehaving
 * plugin source is logged and skipped, never breaking resolution — the caller
 * still gets whatever the built-ins (and the other plugin sources) produced.
 * This class performs NO persistence and holds NO mutable static/global state;
 * it is safe to construct per call under Workerman's resident-memory model.
 *
 * @package Phlix\Media\Metadata\Resolution
 * @since   0.15.0
 */
final class PluginSourceConsultation
{
    /**
     * @param SourceRegistry   $registry Process-scoped registry of enabled plugin sources.
     * @param StructuredLogger $logger   Structured logger (skipped-source warnings).
     */
    public function __construct(
        private readonly SourceRegistry $registry,
        private readonly StructuredLogger $logger,
    ) {
    }

    /**
     * Consult every registered plugin source that supports `$mediaType`.
     *
     * Sources are consulted in this ORDER: first the names from `$priorityOrder`
     * that are actually registered for `$mediaType`, THEN any remaining
     * registered sources not yet consulted (registration order). The returned
     * `records`/`sources` preserve that consult order, so an earlier (priority-
     * listed) plugin source out-ranks a later one when the caller merges.
     *
     * @param string       $mediaType     Host media-type slug (`movie`|`series`|…).
     * @param string       $title         Free-text title fed to each source's `search()`.
     * @param int|null     $year          Optional release/first-air year hint.
     * @param list<string> $priorityOrder Effective source order (e.g. from
     *     {@see PriorityConfig::orderFor()}); non-plugin names are ignored.
     *
     * @return array{
     *     records: list<SourceRecord>,
     *     sources: list<string>,
     *     ratings: list<array{source: string, score: float, votes?: int}>
     * } Canonical records to merge, the contributing source names (consult
     *   order), and any ratings the sources surfaced (for the caller to persist).
     */
    public function consult(string $mediaType, string $title, ?int $year, array $priorityOrder): array
    {
        $records = [];
        $sources = [];
        $ratings = [];

        foreach ($this->orderedSources($mediaType, $priorityOrder) as $name => $source) {
            try {
                $hits = $source->search($title, ['year' => $year]);
                $first = is_array($hits[0] ?? null) ? $hits[0] : null;
                if ($first === null) {
                    continue;
                }
                $externalId = MetadataValue::asNullableString($first['id'] ?? null);
                if ($externalId === null) {
                    continue;
                }

                $details = $source->getDetails($externalId);
                if ($details === []) {
                    continue;
                }

                $records[] = FieldMappers::fromGeneric($name, $details);
                $sources[] = $name;
                foreach ($this->extractRatings($details) as $rating) {
                    $ratings[] = $rating;
                }
            } catch (Throwable $e) {
                // Per-source isolation: a slow/throwing source must never break
                // resolution — log and move on to the next source.
                $this->logger->warning('PluginSourceConsultation: source failed, skipped', [
                    'source' => $name,
                    'media_type' => $mediaType,
                    'title' => $title,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['records' => $records, 'sources' => $sources, 'ratings' => $ratings];
    }

    /**
     * Registered sources for `$mediaType`, ordered priority-listed-first then the
     * remaining registered sources in registration order.
     *
     * @param list<string> $priorityOrder Effective priority names.
     *
     * @return array<string, MetadataSourceInterface> Sources keyed by name, in consult order.
     */
    private function orderedSources(string $mediaType, array $priorityOrder): array
    {
        $available = $this->registry->forMediaType($mediaType);

        $ordered = [];
        foreach ($priorityOrder as $name) {
            if (isset($available[$name]) && !isset($ordered[$name])) {
                $ordered[$name] = $available[$name];
            }
        }
        foreach ($available as $name => $source) {
            if (!isset($ordered[$name])) {
                $ordered[$name] = $source;
            }
        }

        return $ordered;
    }

    /**
     * Extract a source's ratings (e.g. omdb's `ratings` list) into the
     * `plugin_ratings` shape the caller persists. Entries without a usable
     * source string or numeric score are dropped; `votes` is carried when
     * present. The resolver does NOT own a media_item_id, so it never writes
     * `metadata_ratings` here — it only collects.
     *
     * @param array<string, mixed> $details Raw source detail payload.
     *
     * @return list<array{source: string, score: float, votes?: int}>
     */
    private function extractRatings(array $details): array
    {
        $raw = $details['ratings'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $source = MetadataValue::asNullableString($entry['source'] ?? null);
            $score = MetadataValue::asNullableFloat($entry['score'] ?? null);
            if ($source === null || $score === null) {
                continue;
            }
            $rating = ['source' => $source, 'score' => $score];
            $votes = MetadataValue::asNullableInt($entry['votes'] ?? null);
            if ($votes !== null) {
                $rating['votes'] = $votes;
            }
            $out[] = $rating;
        }

        return $out;
    }
}
