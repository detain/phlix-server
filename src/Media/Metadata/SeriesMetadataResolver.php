<?php

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
     * @param string   $title Series name (e.g. "24").
     * @param int|null $year  Optional first-air year to disambiguate.
     *
     * @return array<string, mixed>|null Metadata to merge (with `external_ids.tmdb`
     *     + `tmdb_id` so the caller can fetch seasons), or null on no match.
     */
    public function resolve(string $title, ?int $year): ?array
    {
        if (trim($title) === '') {
            return null;
        }

        try {
            $tmdbId = $this->searchSeriesId($title, $year);
            if ($tmdbId === null) {
                return null;
            }

            $details = $this->tmdb->getTvDetails($tmdbId);
            if ($details === []) {
                return null;
            }

            return $this->format($tmdbId, $details);
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
     *         runtime: int|null
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
            $episodes[$number] = [
                'episode_title' => MetadataValue::asNullableString($ep['name'] ?? null),
                'overview' => MetadataValue::asNullableString($ep['overview'] ?? null),
                'poster_url' => $this->imageUrl($ep['still_path'] ?? null),
                'air_date' => MetadataValue::asNullableString($ep['air_date'] ?? null),
                'runtime' => (($r = MetadataValue::asInt($ep['runtime'] ?? null)) > 0) ? $r : null,
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
            $results = $this->tmdb->searchTv($title); // retry without the year filter
        }
        if ($results === []) {
            return null;
        }

        $id = MetadataValue::asNullableString($results[0]['id'] ?? null);
        return ($id !== null && $id !== '') ? $id : null;
    }

    /**
     * Shape raw TMDB series details into a mergeable metadata array.
     *
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function format(string $tmdbId, array $details): array
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
        $resolved = $this->fieldResolver->resolve(
            [FieldMappers::fromTmdb($details)],
            ['tmdb'],
            $this->priorityConfig->genresMode(),
        );
        // Drop the resolver's provenance/id keys — rebuilt below to match the live
        // shape exactly (hard-coded sources=['tmdb'], an explicit tmdb_id, and the
        // tmdb+imdb external_ids derived from the resolved id and the details).
        unset($resolved['external_ids'], $resolved['sources']);

        $result = [
            'external_ids' => array_filter([
                'tmdb' => $tmdbId,
                'imdb' => MetadataValue::asNullableString($details['imdb_id'] ?? null),
            ], static fn(?string $v): bool => $v !== null && $v !== ''),
            'tmdb_id' => $tmdbId,
            'sources' => ['tmdb'],
        ];

        foreach ($resolved as $key => $value) {
            $result[$key] = $value;
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
}
