<?php

/**
 * Phlix media server component: Metadata.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Metadata\Dto\MetadataValue;
use Phlix\Media\Metadata\Resolution\FieldMappers;
use Phlix\Media\Metadata\Resolution\PriorityConfig;
use Phlix\Media\Metadata\Resolution\PriorityFieldResolver;
use Throwable;

/**
 * TV series metadata resolver — the series-side counterpart to
 * {@see MovieMetadataResolver}.
 *
 * Given a series title (and optional first-air year) it searches TMDB's TV
 * endpoints, fetches the series details, and returns a metadata array shaped
 * with the SAME keys the media-item shaper consumes (`poster_url`, `overview`,
 * `genres`, `year`, …) so a matched series renders a cover + synopsis exactly
 * like a movie. It also exposes {@see self::resolveSeasonEpisodes()} so the
 * matcher can enrich each episode (title/still/overview/air date) from one
 * `/tv/{id}/season/{n}` call per season.
 *
 * Performs NO persistence — it is a pure matching/format unit. TMDB being
 * unavailable (no API key, network error) degrades gracefully to `null`/empty.
 *
 * @package Phlix\Media\Metadata
 * @since   0.24.0
 */
class SeriesMetadataResolver
{
    /** @var string Base URL for the TMDB image CDN. */
    private string $imageBaseUrl = 'https://image.tmdb.org/t/p';

    /** @var PriorityConfig Effective per-media-type source priority. */
    private PriorityConfig $priorityConfig;

    /** @var PriorityFieldResolver Configurable per-field first-non-empty merge engine. */
    private PriorityFieldResolver $fieldResolver;

    /**
     * @param TmdbProvider               $tmdb           Online TMDB provider (TV endpoints).
     * @param StructuredLogger|null      $loggerOverride Optional logger; defaults to the MEDIA channel.
     * @param PriorityConfig|null        $priorityConfig Effective per-type source priority. When null,
     *     defaults to a series order of `['tmdb']` — i.e. today's TMDB-only behavior — so output is
     *     unchanged for callers that do not inject it. Note the series path only ever builds a TMDB
     *     record, so it stays free of any TVDB-sourced field (e.g. a TVDB site rating mapped into the
     *     imdb_rating slot) regardless of the configured order.
     * @param PriorityFieldResolver|null $fieldResolver  The merge engine; a fresh pure instance by default.
     */
    public function __construct(
        private readonly TmdbProvider $tmdb,
        private readonly ?StructuredLogger $loggerOverride = null,
        ?PriorityConfig $priorityConfig = null,
        ?PriorityFieldResolver $fieldResolver = null,
    ) {
        // Default config reproduces today's TMDB-only series behavior. Even if an
        // admin order names other sources, the series path below only constructs a
        // TMDB record, so no other source can contribute a field.
        $this->priorityConfig = $priorityConfig ?? new PriorityConfig(['series' => ['tmdb']]);
        $this->fieldResolver = $fieldResolver ?? new PriorityFieldResolver();
    }

    private function logger(): StructuredLogger
    {
        return $this->loggerOverride ?? LoggerFactory::get(LogChannels::MEDIA);
    }

    /**
     * Resolve series-level metadata for a title.
     *
     * @param string              $title            Series name (e.g. "24").
     * @param int|null            $year             Optional first-air year to disambiguate.
     * @param PriorityConfig|null $priorityOverride Optional per-library effective
     *     priority config (library override layered over the global default). When
     *     provided it drives the genres mode for THIS call instead of the injected
     *     global `$this->priorityConfig`; null (the default) preserves the existing
     *     global behaviour, so all existing callers are unaffected. The series path
     *     stays TMDB-only, so only the genres mode is affected by the override.
     *
     * @return array<string, mixed>|null Metadata to merge (with `external_ids.tmdb`
     *     + `tmdb_id` so the caller can fetch seasons), or null on no match.
     */
    public function resolve(string $title, ?int $year, ?PriorityConfig $priorityOverride = null): ?array
    {
        if (trim($title) === '') {
            return null;
        }

        try {
            $this->logger()->info('SeriesMetadataResolver: searching', [
                'title' => $title,
                'year' => $year,
            ]);

            $tmdbId = $this->searchSeriesId($title, $year);
            if ($tmdbId === null) {
                $this->logger()->info('SeriesMetadataResolver: search returned no id', [
                    'title' => $title,
                    'year' => $year,
                ]);
                return null;
            }

            $this->logger()->info('SeriesMetadataResolver: fetching details', [
                'title' => $title,
                'year' => $year,
                'tmdb_id' => $tmdbId,
            ]);

            $details = $this->tmdb->getTvDetails($tmdbId);
            if ($details === []) {
                $this->logger()->info('SeriesMetadataResolver: details returned empty', [
                    'title' => $title,
                    'tmdb_id' => $tmdbId,
                ]);
                return null;
            }

            $result = $this->format($tmdbId, $details, $priorityOverride);

            $resultExternalIds = is_array($result['external_ids'] ?? null) ? $result['external_ids'] : [];
            $this->logger()->info('SeriesMetadataResolver: resolved', [
                'title' => $title,
                'year' => $year,
                'tmdb_id' => $tmdbId,
                'imdb_id' => $resultExternalIds['imdb'] ?? null,
                'tvdb_id' => $resultExternalIds['tvdb'] ?? null,
            ]);

            return $result;
        } catch (Throwable $e) {
            $this->logger()->warning('SeriesMetadataResolver: resolve failed', [
                'title' => $title,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Resolve a season's poster/overview + a per-episode-number metadata map.
     *
     * @param string $tmdbId       TMDB series id.
     * @param int    $seasonNumber Season number (0 = Specials).
     *
     * @return array{
     *     poster_url: string|null,
     *     overview: string,
     *     episodes: array<int, array{
     *         episode_title: string|null,
     *         overview: string|null,
     *         poster_url: string|null,
     *         air_date: string|null,
     *         runtime: int|null,
     *         vote_average: float|null,
     *         cast: list<array{name: string, role: string, profile_url: string|null}>,
     *         crew: list<array{name: string, job: string, profile_url: string|null}>
     *     }>
     * } Empty `episodes` when the season is unknown.
     */
    public function resolveSeasonEpisodes(string $tmdbId, int $seasonNumber): array
    {
        $empty = ['poster_url' => null, 'overview' => '', 'episodes' => []];
        if ($tmdbId === '') {
            return $empty;
        }

        try {
            $season = $this->tmdb->getTvSeason($tmdbId, $seasonNumber);
        } catch (Throwable $e) {
            $this->logger()->warning('SeriesMetadataResolver: season fetch failed', [
                'tmdb_id' => $tmdbId,
                'season' => $seasonNumber,
                'error' => $e->getMessage(),
            ]);
            return $empty;
        }

        $episodes = [];
        foreach ($season['episodes'] ?? [] as $ep) {
            $number = MetadataValue::asInt($ep['episode_number'] ?? null);
            $vote = MetadataValue::asFloat($ep['vote_average'] ?? null);
            $episodes[$number] = [
                'episode_title' => MetadataValue::asNullableString($ep['name'] ?? null),
                'overview' => MetadataValue::asNullableString($ep['overview'] ?? null),
                'poster_url' => null,  // Episodes don't have posters; fall through to season/series poster
                'air_date' => MetadataValue::asNullableString($ep['air_date'] ?? null),
                'runtime' => (($r = MetadataValue::asInt($ep['runtime'] ?? null)) > 0) ? $r : null,
                'vote_average' => $vote > 0.0 ? $vote : null,
                'cast' => $this->castList($ep['cast'] ?? null),
                'crew' => $this->crewList($ep['crew'] ?? null),
            ];
        }

        return [
            'poster_url' => $this->imageUrl($season['poster_path'] ?? null),
            'overview' => MetadataValue::asString($season['overview'] ?? null),
            'episodes' => $episodes,
        ];
    }

    /**
     * Find the TMDB series id by title (+ optional year), falling back to a
     * year-less search when a year-scoped search finds nothing.
     */
    private function searchSeriesId(string $title, ?int $year): ?string
    {
        $options = $year !== null ? ['first_air_date_year' => $year] : [];
        $results = $this->tmdb->searchTv($title, $options);
        if ($results === [] && $year !== null) {
            $this->logger()->debug('SeriesMetadataResolver: year-scoped search empty, retrying without year', [
                'title' => $title,
                'year' => $year,
            ]);
            $results = $this->tmdb->searchTv($title); // retry without the year filter
        }
        if ($results === []) {
            $this->logger()->info('SeriesMetadataResolver: searchTv returned no results', [
                'title' => $title,
                'year' => $year,
            ]);
            return null;
        }

        $id = MetadataValue::asNullableString($results[0]['id'] ?? null);
        $this->logger()->info('SeriesMetadataResolver: searchTv result', [
            'title' => $title,
            'year' => $year,
            'first_result_tmdb_id' => $id,
            'total_results' => count($results),
        ]);
        return ($id !== null && $id !== '') ? $id : null;
    }

    /**
     * Shape raw TMDB series details into a mergeable metadata array.
     *
     * @param array<string, mixed> $details
     * @param PriorityConfig|null  $priorityOverride Per-library override; when null the
     *     injected global `$this->priorityConfig` drives the genres mode.
     * @return array<string, mixed>
     */
    private function format(string $tmdbId, array $details, ?PriorityConfig $priorityOverride = null): array
    {
        // Per-field selection is delegated to PriorityFieldResolver. The series
        // path builds ONLY a TMDB record, so it stays TMDB-only — no TVDB/IMDb
        // source can contribute a field (in particular no TVDB site rating can
        // surface in the imdb_rating slot), preserving today's output exactly.
        // FieldMappers::fromTmdb reproduces the live per-field shaping: `name`→
        // title, `*_path`→`*_url` (/w500), genres→cleaned string list, `year`,
        // `official_rating`, flat actor names, verbatim cast/crew/companies, studio.
        // A fixed `['tmdb']` order is used (not the configured series order) so the
        // series resolver remains robustly TMDB-driven regardless of admin config
        // until real series sources are registered (Step 3.5).
        $priority = $priorityOverride ?? $this->priorityConfig;
        $resolved = $this->fieldResolver->resolve(
            [FieldMappers::fromTmdb($details)],
            ['tmdb'],
            $priority->genresMode(),
        );
        // Drop the resolver's provenance/id keys — rebuilt below to match the live
        // shape exactly (hard-coded sources=['tmdb'], an explicit tmdb_id, and the
        // tmdb+imdb external_ids derived from the resolved id and the details).
        unset($resolved['external_ids'], $resolved['sources']);

        $result = [
            'external_ids' => array_filter([
                'tmdb' => $tmdbId,
                'imdb' => MetadataValue::asNullableString($details['imdb_id'] ?? null),
                // TheTVDB id (from TmdbProvider::formatTvDetails `external_ids`).
                // Threaded here so the theme-music resolver (M3) can build the
                // Plex-archive fallback URL keyed on the TVDB id.
                'tvdb' => MetadataValue::asNullableString($details['tvdb_id'] ?? null),
            ], static fn(?string $v): bool => $v !== null && $v !== ''),
            'tmdb_id' => $tmdbId,
            'sources' => ['tmdb'],
        ];

        foreach ($resolved as $key => $value) {
            $result[$key] = $value;
        }

        // Tags/keywords are not part of the canonical merge vocabulary
        // (SourceRecord::CANONICAL_FIELDS), so carry them straight from the raw
        // TMDB details. Series-level; episodes inherit them via the matcher.
        $tags = $this->stringList($details['tags'] ?? null);
        if ($tags !== []) {
            $result['tags'] = $tags;
        }

        return $result;
    }

    private function imageUrl(mixed $path): ?string
    {
        $clean = MetadataValue::asNullableString($path);
        if ($clean === null) {
            return null;
        }
        return $this->imageBaseUrl . '/w500' . $clean;
    }

    /**
     * Narrow a mixed value to a de-duplicated list of non-empty strings.
     *
     * @param mixed $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            $name = MetadataValue::asNullableString($entry);
            if ($name !== null && !in_array($name, $out, true)) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * Narrow a raw episode cast value to the canonical shape the media-item shaper
     * renders — `{name, role, profile_url}`. Entries without a name are dropped;
     * profile URLs are already full TMDB URLs from {@see \Phlix\Media\Metadata\TmdbProvider}.
     *
     * @param mixed $value Raw cast list from `getTvSeason()`.
     * @return list<array{name: string, role: string, profile_url: string|null}>
     */
    private function castList(mixed $value): array
    {
        $out = [];
        foreach ($this->namedEntries($value) as $entry) {
            $out[] = [
                'name' => MetadataValue::asString($entry['name'] ?? null),
                'role' => MetadataValue::asString($entry['role'] ?? null),
                'profile_url' => MetadataValue::asNullableString($entry['profile_url'] ?? null),
            ];
        }
        return $out;
    }

    /**
     * Narrow a raw episode crew value to the canonical shape the media-item shaper
     * renders — `{name, job, profile_url}`. Entries without a name are dropped.
     *
     * @param mixed $value Raw crew list from `getTvSeason()`.
     * @return list<array{name: string, job: string, profile_url: string|null}>
     */
    private function crewList(mixed $value): array
    {
        $out = [];
        foreach ($this->namedEntries($value) as $entry) {
            $out[] = [
                'name' => MetadataValue::asString($entry['name'] ?? null),
                'job' => MetadataValue::asString($entry['job'] ?? null),
                'profile_url' => MetadataValue::asNullableString($entry['profile_url'] ?? null),
            ];
        }
        return $out;
    }

    /**
     * Yield the array entries of a raw people list that carry a non-empty `name`.
     *
     * @param mixed $value Raw cast/crew list.
     * @return list<array<string, mixed>>
     */
    private function namedEntries(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (MetadataValue::asString($entry['name'] ?? null) === '') {
                continue;
            }
            $out[] = $entry;
        }
        return $out;
    }
}
