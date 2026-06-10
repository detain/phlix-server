<?php

declare(strict_types=1);

namespace Phlix\Media\Metadata;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Metadata\Dto\MetadataValue;
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

    public function __construct(
        private readonly TmdbProvider $tmdb,
        private readonly ?StructuredLogger $loggerOverride = null,
    ) {
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
        $result = [
            'external_ids' => array_filter([
                'tmdb' => $tmdbId,
                'imdb' => MetadataValue::asNullableString($details['imdb_id'] ?? null),
            ], static fn(?string $v): bool => $v !== null && $v !== ''),
            'tmdb_id' => $tmdbId,
            'sources' => ['tmdb'],
        ];

        $name = MetadataValue::asNullableString($details['name'] ?? null);
        if ($name !== null) {
            $result['title'] = $name;
        }
        $overview = MetadataValue::asNullableString($details['overview'] ?? null);
        if ($overview !== null) {
            $result['overview'] = $overview;
        }
        $poster = $this->imageUrl($details['poster_path'] ?? null);
        if ($poster !== null) {
            $result['poster_url'] = $poster;
        }
        $backdrop = $this->imageUrl($details['backdrop_path'] ?? null);
        if ($backdrop !== null) {
            $result['backdrop_url'] = $backdrop;
        }
        $genres = MetadataValue::asList($details['genres'] ?? null);
        $genreNames = array_values(array_filter(
            array_map(static fn(mixed $g): string => MetadataValue::asString($g), $genres),
            static fn(string $g): bool => $g !== '',
        ));
        if ($genreNames !== []) {
            $result['genres'] = $genreNames;
        }
        $year = MetadataValue::asNullableInt($details['year'] ?? null);
        if ($year !== null) {
            $result['year'] = $year;
        }
        $rating = MetadataValue::asNullableString($details['official_rating'] ?? null);
        if ($rating !== null) {
            $result['official_rating'] = $rating;
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
