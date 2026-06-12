<?php

declare(strict_types=1);

namespace Phlix\Media\Metadata;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\ItemRepository;
use Throwable;
use Phlix\Media\Metadata\SceneFilenameNormalizer;

/**
 * Background metadata matcher for a whole library.
 *
 * Pages through a library's media items and, for each MOVIE-type item, asks the
 * {@see MovieMetadataResolver} (the cross-source TMDB + IMDb "matching brain")
 * to resolve fresh details from the item's stored name + year + any known
 * external ids. When the resolver returns a match, the result is MERGED into the
 * item's existing `metadata_json` (preserving unrelated keys) and persisted via
 * {@see ItemRepository::update()}, stamping `metadata_refreshed_at = NOW()`.
 *
 * This service is the unit the async {@see \Phlix\Media\Library\LibraryScanWorker}
 * runs for `metadata`-type jobs — reusing the existing `library_scan_jobs`
 * queue + status infrastructure so the admin UI's scan-status badge/polling
 * shows progress for a metadata match exactly as it does for a scan.
 *
 * **Resilience.** A single item failing (resolver error, malformed metadata,
 * persistence error) must NOT abort the whole library — each item is processed
 * in its own try/catch, the failure is logged on the MEDIA channel, and the run
 * continues. The method returns `{matched, processed}` counts.
 *
 * **Resident-memory (Workerman) safety.** The matcher holds no unbounded
 * `static`/`global` state — its only instance state is the injected
 * dependencies. It pages the library in fixed-size batches so a huge library
 * never loads every row into memory at once.
 *
 * @package Phlix\Media\Metadata
 * @since   0.21.0
 */
class LibraryMetadataMatcher
{
    /**
     * Media-item `type` values that represent a movie. The scanner stores
     * concrete `movie` for video-library films (see {@see \Phlix\Media\Library\MediaScanner}),
     * and `video` is accepted as the raw/library-level fallback some callers
     * persist before a metadata pass has refined the type.
     *
     * @var list<string>
     */
    private const MOVIE_TYPES = ['movie', 'video'];

    /** @var int Page size used to drain the library without loading it all at once. */
    private const PAGE_SIZE = 100;

    /** @var ItemRepository Media-item data access (paging + persistence). */
    private ItemRepository $items;

    /** @var MovieMetadataResolver Cross-source resolver (TMDB + IMDb). */
    private MovieMetadataResolver $resolver;

    /** @var SeriesMetadataResolver|null TV series resolver (TMDB TV); null disables TV matching. */
    private ?SeriesMetadataResolver $seriesResolver;

    /** @var StructuredLogger Logger for the MEDIA channel. */
    private StructuredLogger $logger;

    /**
     * @param ItemRepository             $items          Media-item data access.
     * @param MovieMetadataResolver      $resolver       Cross-source movie resolver.
     * @param SeriesMetadataResolver|null $seriesResolver TV series resolver; when
     *                                                   null, series/episode items
     *                                                   are skipped (movie-only).
     * @param StructuredLogger|null      $logger         Optional logger; defaults
     *                                                   to the MEDIA channel.
     *
     * @since 0.21.0
     */
    public function __construct(
        ItemRepository $items,
        MovieMetadataResolver $resolver,
        ?SeriesMetadataResolver $seriesResolver = null,
        ?StructuredLogger $logger = null
    ) {
        $this->items = $items;
        $this->resolver = $resolver;
        $this->seriesResolver = $seriesResolver;
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::MEDIA);
    }

    /**
     * Match metadata for every movie in a library.
     *
     * Pages through {@see ItemRepository::getByLibrary()} in fixed batches. For
     * each MOVIE-type item it resolves details from the stored name/year/external
     * ids and, on a hit, merges the result into the item's `metadata_json` and
     * persists it (stamping `metadata_refreshed_at`). Non-movie items are
     * skipped. A per-item exception is logged and swallowed so one bad item
     * cannot abort the run.
     *
     * @param string $libraryId Target library UUID.
     *
     * @return array{matched: int, processed: int} `processed` = movie items
     *         examined; `matched` = movie items whose metadata was updated.
     *
     * @since 0.21.0
     */
    public function matchLibrary(string $libraryId): array
    {
        $matched = 0;
        $processed = 0;
        $offset = 0;

        // A start marker so the log shows the run has begun, not just its end.
        $this->logger->info('LibraryMetadataMatcher: library match started', [
            'library_id' => $libraryId,
        ]);

        while (true) {
            $batch = $this->items->getByLibrary($libraryId, self::PAGE_SIZE, $offset);
            if ($batch === []) {
                break;
            }

            foreach ($batch as $item) {
                $type = $item['type'] ?? null;
                $isMovie = is_string($type) && in_array($type, self::MOVIE_TYPES, true);
                $isSeries = $type === 'series' && $this->seriesResolver !== null;
                // Seasons/episodes are enriched under their series (matchSeries),
                // so the flat pass only acts on movies and series roots.
                if (!$isMovie && !$isSeries) {
                    continue;
                }

                $processed++;
                $id = is_string($item['id'] ?? null) ? $item['id'] : '';
                $name = is_string($item['name'] ?? null) ? $item['name'] : '';

                try {
                    $hit = $isSeries ? $this->matchSeries($item) : $this->matchItem($item);
                    if ($hit) {
                        $matched++;
                        // Per-item line (DEBUG) so progress is visible as items
                        // are processed, written immediately rather than buffered
                        // until the run finishes.
                        $this->logger->debug('LibraryMetadataMatcher: item matched', [
                            'library_id' => $libraryId,
                            'item_id' => $id,
                            'name' => $name,
                            'processed' => $processed,
                            'matched' => $matched,
                        ]);
                    } else {
                        $this->logger->debug('LibraryMetadataMatcher: item not matched', [
                            'library_id' => $libraryId,
                            'item_id' => $id,
                            'name' => $name,
                            'processed' => $processed,
                        ]);
                    }
                } catch (Throwable $e) {
                    $this->logger->warning('LibraryMetadataMatcher: item match failed; skipping', [
                        'library_id' => $libraryId,
                        'item_id' => $id,
                        'name' => $name,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Per-batch summary at INFO so the run is visibly advancing even when
            // the per-item DEBUG lines are filtered out.
            $this->logger->info('LibraryMetadataMatcher: library match progress', [
                'library_id' => $libraryId,
                'processed' => $processed,
                'matched' => $matched,
            ]);

            // The driver may return a short final page; stop once it does.
            if (count($batch) < self::PAGE_SIZE) {
                break;
            }

            $offset += self::PAGE_SIZE;
        }

        $this->logger->info('LibraryMetadataMatcher: library match complete', [
            'library_id' => $libraryId,
            'processed' => $processed,
            'matched' => $matched,
        ]);

        return ['matched' => $matched, 'processed' => $processed];
    }

    /**
     * Resolve + persist metadata for a single (already movie-typed) item.
     *
     * @param array<string, mixed> $item Hydrated media-item row.
     *
     * @return bool `true` when the resolver matched and the item was persisted,
     *              `false` when there was no usable id/name or no match.
     */
    private function matchItem(array $item): bool
    {
        $id = $item['id'] ?? null;
        if (!is_string($id) || $id === '') {
            return false;
        }

        $existingMetadata = $this->extractMetadata($item);

        $name = $this->extractName($item, $existingMetadata);
        if ($name === null) {
            return false;
        }

        $normalized = SceneFilenameNormalizer::normalize($name);
        if ($normalized['title'] !== '') {
            $name = $normalized['title'];
        }

        $year = $this->extractYear($existingMetadata);
        if ($year === null && $normalized['year'] !== null) {
            $year = $normalized['year'];
        }

        $externalIds = $this->extractExternalIds($existingMetadata);

        $resolved = $this->resolver->resolve($name, $year, $externalIds);
        if ($resolved === null) {
            return false;
        }

        $merged = array_merge($existingMetadata, $resolved);

        $this->persistMetadata($id, $merged);

        return true;
    }

    /**
     * Resolve + persist a TV series and enrich its whole season/episode subtree.
     *
     * Matches the series against TMDB TV, persists its poster/overview/genres,
     * then walks its children: each season gets the series (or season) poster +
     * overview, and each episode gets its TMDB title/still/overview/air-date —
     * falling back to the series poster so nothing in the tree renders blank.
     *
     * @param array<string, mixed> $seriesItem Hydrated `series`-type row.
     *
     * @return bool True when the series matched (and its subtree was enriched).
     */
    private function matchSeries(array $seriesItem): bool
    {
        $resolver = $this->seriesResolver;
        if ($resolver === null) {
            return false;
        }

        $id = is_string($seriesItem['id'] ?? null) ? $seriesItem['id'] : '';
        if ($id === '') {
            return false;
        }

        $existing = $this->extractMetadata($seriesItem);

        // Prefer the folder-derived series title/year hint that the scanner
        // persists in series-per-directory mode (`series_title`/`year` on the
        // container's metadata). The folder name is far cleaner than the noisy
        // filename-derived title, so it drives the TMDB TV search directly with
        // no further scene-normalisation. Fall back to the legacy
        // name-+-normalise path when no hint is present.
        $hintTitle = $this->stringOrNull($existing['series_title'] ?? null);
        if ($hintTitle !== null) {
            $name = $hintTitle;
            $year = $this->extractYear($existing);
        } else {
            $name = $this->extractName($seriesItem, $existing);
            if ($name === null) {
                return false;
            }

            $normalized = SceneFilenameNormalizer::normalize($name);
            if ($normalized['title'] !== '') {
                $name = $normalized['title'];
            }
            $year = $this->extractYear($existing) ?? $normalized['year'];
        }

        $resolved = $resolver->resolve($name, $year);
        if ($resolved === null) {
            return false;
        }

        $this->persistMetadata($id, array_merge($existing, $resolved));

        $tmdbId = $this->resolvedTmdbId($resolved);
        if ($tmdbId !== '') {
            $this->enrichSeriesChildren(
                $id,
                $tmdbId,
                $this->stringOrNull($resolved['poster_url'] ?? null),
                $this->stringOrNull($resolved['backdrop_url'] ?? null),
                $this->stringOrNull($resolved['overview'] ?? null),
            );
        }

        return true;
    }

    /**
     * Enrich a series' seasons + episodes from TMDB, caching one season fetch per
     * season number.
     *
     * @param string      $seriesId       The series item id.
     * @param string      $tmdbId         Resolved TMDB series id.
     * @param string|null $seriesPoster   Series poster URL (episode/season fallback).
     * @param string|null $seriesBackdrop Series backdrop URL.
     * @param string|null $seriesOverview Series overview (episode/season fallback).
     */
    private function enrichSeriesChildren(
        string $seriesId,
        string $tmdbId,
        ?string $seriesPoster,
        ?string $seriesBackdrop,
        ?string $seriesOverview
    ): void {
        /** @var array<int, array<string, mixed>> $seasonCache */
        $seasonCache = [];

        foreach ($this->items->findByParent($seriesId) as $child) {
            $childType = $child['type'] ?? null;
            $childId = is_string($child['id'] ?? null) ? $child['id'] : '';
            if ($childId === '') {
                continue;
            }
            $childMeta = $this->extractMetadata($child);

            if ($childType === 'season') {
                $seasonData = $this->cachedSeason($tmdbId, $this->intMeta($childMeta, 'season'), $seasonCache);
                $this->persistMetadata(
                    $childId,
                    array_merge($childMeta, $this->seasonPatch($seasonData, $seriesPoster, $seriesBackdrop, $seriesOverview))
                );
                foreach ($this->items->findByParent($childId) as $episode) {
                    $this->enrichEpisode($episode, $seasonData, $seriesPoster, $seriesOverview);
                }
            } elseif ($childType === 'episode') {
                $seasonData = $this->cachedSeason($tmdbId, $this->intMeta($childMeta, 'season'), $seasonCache);
                $this->enrichEpisode($child, $seasonData, $seriesPoster, $seriesOverview);
            }
        }
    }

    /**
     * Persist an episode's TMDB title/still/overview/air-date, falling back to the
     * season/series poster + series overview so it never renders blank.
     *
     * @param array<string, mixed>      $episode        Hydrated episode row.
     * @param array<string, mixed>|null $seasonData     Resolved season data (or null).
     * @param string|null               $seriesPoster   Series poster fallback.
     * @param string|null               $seriesOverview Series overview fallback.
     */
    private function enrichEpisode(
        array $episode,
        ?array $seasonData,
        ?string $seriesPoster,
        ?string $seriesOverview
    ): void {
        $id = is_string($episode['id'] ?? null) ? $episode['id'] : '';
        if ($id === '') {
            return;
        }
        $meta = $this->extractMetadata($episode);
        $episodeNumber = $this->intMeta($meta, 'episode');

        /** @var array<string, mixed> $info */
        $info = [];
        if ($seasonData !== null && $episodeNumber !== null) {
            $episodes = $seasonData['episodes'] ?? [];
            if (is_array($episodes) && isset($episodes[$episodeNumber]) && is_array($episodes[$episodeNumber])) {
                $info = $episodes[$episodeNumber];
            }
        }

        $patch = [];
        $title = $this->stringOrNull($info['episode_title'] ?? null);
        if ($title !== null) {
            $patch['episode_title'] = $title;
        }
        $overview = $this->stringOrNull($info['overview'] ?? null) ?? $seriesOverview;
        if ($overview !== null) {
            $patch['overview'] = $overview;
        }
        $airDate = $this->stringOrNull($info['air_date'] ?? null);
        if ($airDate !== null) {
            $patch['air_date'] = $airDate;
        }
        if (is_int($info['runtime'] ?? null) && $info['runtime'] > 0) {
            $patch['runtime'] = $info['runtime'];
        }
        // Poster: episode still → season poster → series poster.
        $poster = $this->stringOrNull($info['poster_url'] ?? null)
            ?? ($seasonData !== null ? $this->stringOrNull($seasonData['poster_url'] ?? null) : null)
            ?? $seriesPoster;
        if ($poster !== null) {
            $patch['poster_url'] = $poster;
        }

        if ($patch !== []) {
            $this->persistMetadata($id, array_merge($meta, $patch));
        }
    }

    /**
     * Build the season-item metadata patch (poster + overview, series fallbacks).
     *
     * @param array<string, mixed>|null $seasonData
     * @return array<string, mixed>
     */
    private function seasonPatch(
        ?array $seasonData,
        ?string $seriesPoster,
        ?string $seriesBackdrop,
        ?string $seriesOverview
    ): array {
        $patch = [];
        $poster = ($seasonData !== null ? $this->stringOrNull($seasonData['poster_url'] ?? null) : null) ?? $seriesPoster;
        if ($poster !== null) {
            $patch['poster_url'] = $poster;
        }
        if ($seriesBackdrop !== null) {
            $patch['backdrop_url'] = $seriesBackdrop;
        }
        $overview = ($seasonData !== null ? $this->stringOrNull($seasonData['overview'] ?? null) : null) ?? $seriesOverview;
        if ($overview !== null) {
            $patch['overview'] = $overview;
        }
        return $patch;
    }

    /**
     * Return the cached season data for a season number, fetching once per number.
     *
     * @param array<int, array<string, mixed>> $cache Season cache (by number, mutated).
     *
     * @return array<string, mixed>|null Season data, or null when no number.
     */
    private function cachedSeason(string $tmdbId, ?int $seasonNumber, array &$cache): ?array
    {
        if ($seasonNumber === null || $this->seriesResolver === null) {
            return null;
        }
        if (!array_key_exists($seasonNumber, $cache)) {
            $cache[$seasonNumber] = $this->seriesResolver->resolveSeasonEpisodes($tmdbId, $seasonNumber);
        }
        return $cache[$seasonNumber];
    }

    /**
     * Pull the TMDB series id out of a resolved metadata array.
     *
     * @param array<string, mixed> $resolved
     */
    private function resolvedTmdbId(array $resolved): string
    {
        $ext = $resolved['external_ids'] ?? null;
        if (is_array($ext) && is_string($ext['tmdb'] ?? null) && $ext['tmdb'] !== '') {
            return $ext['tmdb'];
        }
        return $this->stringOrNull($resolved['tmdb_id'] ?? null) ?? '';
    }

    /**
     * Persist a merged metadata array onto an item, stamping the refresh time.
     *
     * @param array<string, mixed> $merged
     */
    private function persistMetadata(string $id, array $merged): void
    {
        $this->items->update($id, [
            'metadata_json' => $merged,
            'metadata_refreshed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** A non-empty string value, or null. */
    private function stringOrNull(mixed $value): ?string
    {
        return (is_string($value) && $value !== '') ? $value : null;
    }

    /**
     * Read an int from a metadata field (int or numeric string), else null.
     *
     * @param array<string, mixed> $meta
     */
    private function intMeta(array $meta, string $key): ?int
    {
        $value = $meta[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        return null;
    }

    /**
     * Extract the item's existing decoded metadata as a string-keyed array.
     *
     * Prefers the hydrated `metadata` key {@see ItemRepository} adds; falls back
     * to decoding `metadata_json` defensively when only the raw column is
     * present (e.g. a mocked row).
     *
     * @param array<string, mixed> $item Hydrated media-item row.
     *
     * @return array<string, mixed> Existing metadata (empty when none/invalid).
     */
    private function extractMetadata(array $item): array
    {
        $metadata = $item['metadata'] ?? null;
        if (is_array($metadata)) {
            return $this->stringKeyed($metadata);
        }

        $raw = $item['metadata_json'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $this->stringKeyed($decoded);
            }
        }
        if (is_array($raw)) {
            return $this->stringKeyed($raw);
        }

        return [];
    }

    /**
     * Resolve the movie title: the item's `name` column, else a `title`/`name`
     * already in its metadata.
     *
     * @param array<string, mixed> $item             Hydrated media-item row.
     * @param array<string, mixed> $existingMetadata Decoded metadata.
     *
     * @return string|null Non-empty title, or null when none is available.
     */
    private function extractName(array $item, array $existingMetadata): ?string
    {
        $candidates = [
            $item['name'] ?? null,
            $existingMetadata['title'] ?? null,
            $existingMetadata['name'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }
        return null;
    }

    /**
     * Resolve the release year from the item's metadata, if present and sane.
     *
     * @param array<string, mixed> $existingMetadata Decoded metadata.
     *
     * @return int|null Year, or null when absent/non-numeric.
     */
    private function extractYear(array $existingMetadata): ?int
    {
        $year = $existingMetadata['year'] ?? null;
        if (is_int($year)) {
            return $year;
        }
        if (is_string($year) && is_numeric($year)) {
            return (int) $year;
        }
        return null;
    }

    /**
     * Extract already-known external ids (e.g. `['imdb' => 'tt…']`) from the
     * item's metadata, narrowing to a `array<string, string>` for the resolver.
     *
     * @param array<string, mixed> $existingMetadata Decoded metadata.
     *
     * @return array<string, string> Known external ids (possibly empty).
     */
    private function extractExternalIds(array $existingMetadata): array
    {
        $raw = $existingMetadata['external_ids'] ?? null;
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $stringValue = (string) $value;
                if ($stringValue !== '') {
                    $out[$key] = $stringValue;
                }
            }
        }
        return $out;
    }

    /**
     * Narrow a mixed array to only its string-keyed entries.
     *
     * @param array<array-key, mixed> $value Raw array.
     *
     * @return array<string, mixed> String-keyed subset.
     */
    private function stringKeyed(array $value): array
    {
        $out = [];
        foreach ($value as $key => $entry) {
            if (is_string($key)) {
                $out[$key] = $entry;
            }
        }
        return $out;
    }
}
