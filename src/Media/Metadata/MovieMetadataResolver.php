<?php

declare(strict_types=1);

namespace Phlix\Media\Metadata;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Metadata\Dto\MetadataValue;
use Phlix\Media\Metadata\Imdb\ImdbLookup;
use Throwable;

/**
 * Cross-source movie metadata resolver — the "matching brain".
 *
 * Given a title (and optional year / known external ids), this service gathers
 * metadata from BOTH the online TMDB provider and the offline IMDb dataset,
 * cross-populates ids between the two sources, and merges everything into a
 * single details array. Either provider being unavailable (no API key, network
 * failure, no local IMDb table) degrades gracefully: whatever the other source
 * produced is still returned, and only a total miss yields null.
 *
 * This class deliberately performs NO persistence and is NOT yet wired into
 * {@see MetadataManager}; it is a pure matching/merge unit.
 *
 * @package Phlix\Media\Metadata
 * @since   0.21.0
 */
class MovieMetadataResolver
{
    /** @var TmdbProvider Online TMDB provider (search / find / details). */
    private TmdbProvider $tmdb;

    /** @var ImdbLookup Offline IMDb dataset lookup (ratings / genres / runtime). */
    private ImdbLookup $imdb;

    /** @var StructuredLogger Structured logger instance. */
    private StructuredLogger $logger;

    /** @var string Base URL for TMDB image CDN. */
    private string $imageBaseUrl = 'https://image.tmdb.org/t/p';

    /**
     * @param TmdbProvider          $tmdb   Online TMDB provider.
     * @param ImdbLookup            $imdb   Offline IMDb dataset lookup.
     * @param StructuredLogger|null $logger Optional logger; defaults to the MEDIA channel.
     *
     * @since 0.21.0
     */
    public function __construct(TmdbProvider $tmdb, ImdbLookup $imdb, ?StructuredLogger $logger = null)
    {
        $this->tmdb = $tmdb;
        $this->imdb = $imdb;
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::MEDIA);
    }

    /**
     * Resolve and merge movie metadata across TMDB and IMDb.
     *
     * @param string                $title              Raw movie title.
     * @param int|null              $year               Optional release year.
     * @param array<string, string> $existingExternalIds Already-known external ids, e.g. `['imdb' => 'tt…']`.
     *
     * @return array<string, mixed>|null Merged details, or null when neither source matched.
     *     Shape (keys present only when data is available):
     *     ```
     *     [
     *         'external_ids' => array<string, string>, // ['tmdb' => '603', 'imdb' => 'tt0133093']
     *         'title'        => string,
     *         'overview'     => string,
     *         'poster_url'   => string,
     *         'backdrop_url' => string,
     *         'genres'       => list<string>,
     *         'year'         => int,
     *         'runtime'      => int,   // minutes
     *         'imdb_rating'  => float,
     *         'imdb_votes'   => int,
     *         'sources'      => list<string>, // which providers contributed, e.g. ['tmdb','imdb']
     *     ]
     *     ```
     *
     * @since 0.21.0
     */
    public function resolve(string $title, ?int $year, array $existingExternalIds = []): ?array
    {
        // 1. Seed the IMDb id from caller-provided ids, else attempt an offline
        //    title lookup to discover one.
        $imdbId = $this->extractImdbId($existingExternalIds);

        $imdbLookupData = null;
        if ($imdbId === null) {
            $imdbLookupData = $this->safeImdbLookup($title, $year);
            if ($imdbLookupData !== null) {
                $candidate = MetadataValue::asNullableString($imdbLookupData['imdb_id'] ?? null);
                if ($candidate !== null) {
                    $imdbId = $candidate;
                }
            }
        }

        // 2. Resolve the TMDB id: by IMDb id when known (surer), else title search.
        $tmdbId = $this->resolveTmdbId($title, $year, $imdbId);

        // 3. Fetch TMDB details; cross-populate the IMDb id from them if needed.
        $tmdbDetails = null;
        if ($tmdbId !== null) {
            $tmdbDetails = $this->safeTmdbDetails($tmdbId);
            if ($tmdbDetails !== null && $imdbId === null) {
                $fromTmdb = MetadataValue::asNullableString($tmdbDetails['imdb_id'] ?? null);
                if ($fromTmdb !== null) {
                    $imdbId = $fromTmdb;
                }
            }
        }

        // 4. Fetch offline IMDb data for the (possibly cross-populated) id.
        $imdbData = $imdbLookupData;
        if ($imdbId !== null && ($imdbData === null || ($imdbData['imdb_id'] ?? null) !== $imdbId)) {
            $byId = $this->safeImdbGetById($imdbId);
            if ($byId !== null) {
                $imdbData = $byId;
            }
        }

        // 5. Merge — bail out only when NEITHER source produced anything.
        if ($tmdbDetails === null && $imdbData === null) {
            return null;
        }

        return $this->merge($existingExternalIds, $tmdbId, $imdbId, $tmdbDetails, $imdbData);
    }

    /**
     * Merge TMDB details and IMDb data into one details array.
     *
     * @param array<string, string>     $existingExternalIds Caller-supplied ids (lowest priority for ids).
     * @param string|null               $tmdbId              Resolved TMDB id.
     * @param string|null               $imdbId              Resolved IMDb id.
     * @param array<string, mixed>|null $tmdbDetails         Formatted TMDB details.
     * @param array<string, mixed>|null $imdbData            Offline IMDb row.
     *
     * @return array<string, mixed> Merged details.
     */
    private function merge(
        array $existingExternalIds,
        ?string $tmdbId,
        ?string $imdbId,
        ?array $tmdbDetails,
        ?array $imdbData
    ): array {
        $tmdb = $tmdbDetails ?? [];
        $imdb = $imdbData ?? [];

        // external_ids: discovered ids merged OVER caller-supplied ones.
        $discovered = array_filter([
            'tmdb' => $tmdbId,
            'imdb' => $imdbId,
        ], static fn(?string $v): bool => $v !== null && $v !== '');
        /** @var array<string, string> $externalIds */
        $externalIds = array_merge($existingExternalIds, $discovered);

        $result = [];
        $result['external_ids'] = $externalIds;

        // Descriptive fields: TMDB preferred, IMDb fallback.
        $title = MetadataValue::asNullableString($tmdb['name'] ?? null)
            ?? MetadataValue::asNullableString($imdb['title'] ?? null);
        if ($title !== null) {
            $result['title'] = $title;
        }

        $overview = MetadataValue::asNullableString($tmdb['overview'] ?? null);
        if ($overview !== null) {
            $result['overview'] = $overview;
        }

        $posterUrl = $this->imageUrl($tmdb['poster_path'] ?? null);
        if ($posterUrl !== null) {
            $result['poster_url'] = $posterUrl;
        }

        $backdropUrl = $this->imageUrl($tmdb['backdrop_path'] ?? null);
        if ($backdropUrl !== null) {
            $result['backdrop_url'] = $backdropUrl;
        }

        $genres = $this->mergeGenres($tmdb['genres'] ?? null, $imdb['genres'] ?? null);
        if ($genres !== []) {
            $result['genres'] = $genres;
        }

        $year = MetadataValue::asNullableInt($tmdb['year'] ?? null)
            ?? MetadataValue::asNullableInt($imdb['year'] ?? null);
        if ($year !== null) {
            $result['year'] = $year;
        }

        $runtime = $this->resolveRuntime($tmdb, $imdb);
        if ($runtime !== null) {
            $result['runtime'] = $runtime;
        }

        // Cast & crew — TMDB-sourced. TMDB yields actor objects
        // ({name, role, order}); the shaper, the `$.actors[*]` filter and the
        // SPA cast chips all consume a flat list of names, so reduce to names
        // here. Previously omitted entirely, so bulk-matched movies had no cast.
        $actors = MetadataValue::actorNames($tmdb['actors'] ?? null);
        if ($actors !== []) {
            $result['actors'] = $actors;
        }
        $director = MetadataValue::asNullableString($tmdb['director'] ?? null);
        if ($director !== null) {
            $result['director'] = $director;
        }

        // Ratings — IMDb-sourced only.
        $imdbRating = MetadataValue::asNullableFloat($imdb['average_rating'] ?? null);
        if ($imdbRating !== null) {
            $result['imdb_rating'] = $imdbRating;
        }
        $imdbVotes = MetadataValue::asNullableInt($imdb['num_votes'] ?? null);
        if ($imdbVotes !== null) {
            $result['imdb_votes'] = $imdbVotes;
        }

        // Which providers contributed.
        $sources = [];
        if ($tmdbDetails !== null) {
            $sources[] = 'tmdb';
        }
        if ($imdbData !== null) {
            $sources[] = 'imdb';
        }
        $result['sources'] = $sources;

        return $result;
    }

    /**
     * Determine runtime in minutes, preferring TMDB (stored as ticks) then IMDb.
     *
     * @param array<string, mixed> $tmdb Formatted TMDB details.
     * @param array<string, mixed> $imdb Offline IMDb row.
     *
     * @return int|null Runtime in minutes, or null when unknown.
     */
    private function resolveRuntime(array $tmdb, array $imdb): ?int
    {
        $ticks = MetadataValue::asNullableInt($tmdb['runtime_ticks'] ?? null);
        if ($ticks !== null && $ticks > 0) {
            return (int) ($ticks / 600000000);
        }

        return MetadataValue::asNullableInt($imdb['runtime_minutes'] ?? null);
    }

    /**
     * Merge genre lists, preferring TMDB and de-duplicating.
     *
     * @param mixed $tmdbGenres Raw TMDB genres.
     * @param mixed $imdbGenres Raw IMDb genres.
     *
     * @return list<string> Merged, de-duplicated genre names.
     */
    private function mergeGenres(mixed $tmdbGenres, mixed $imdbGenres): array
    {
        $primary = $this->stringList($tmdbGenres);
        if ($primary !== []) {
            return $primary;
        }

        return $this->stringList($imdbGenres);
    }

    /**
     * Narrow a mixed value to a de-duplicated list of non-empty strings.
     *
     * @param mixed $value Raw value.
     *
     * @return list<string> Clean genre list.
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
     * Build a full TMDB image URL from a `/path.jpg` fragment.
     *
     * @param mixed $path Raw TMDB image path.
     *
     * @return string|null Full URL, or null when no path is present.
     */
    private function imageUrl(mixed $path): ?string
    {
        $clean = MetadataValue::asNullableString($path);
        if ($clean === null) {
            return null;
        }
        return $this->imageBaseUrl . '/w500' . $clean;
    }

    /**
     * Resolve the TMDB movie id: by IMDb id when known, else by title search.
     *
     * @param string      $title  Movie title.
     * @param int|null    $year   Optional year.
     * @param string|null $imdbId Known IMDb id, if any.
     *
     * @return string|null TMDB id, or null when no TMDB match.
     */
    private function resolveTmdbId(string $title, ?int $year, ?string $imdbId): ?string
    {
        try {
            if ($imdbId !== null) {
                $found = $this->tmdb->findByImdbId($imdbId);
                $id = MetadataValue::asNullableString($found['id'] ?? null);
                if ($id !== null) {
                    return $id;
                }
                return null;
            }

            $results = $this->tmdb->search($title, ['year' => $year]);
            $first = $results[0] ?? null;
            if (is_array($first)) {
                return MetadataValue::asNullableString($first['id'] ?? null);
            }
            return null;
        } catch (Throwable $e) {
            $this->logger->warning('TMDB id resolution failed', [
                'title' => $title,
                'imdb_id' => $imdbId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Fetch TMDB details, tolerating provider errors.
     *
     * @param string $tmdbId TMDB id.
     *
     * @return array<string, mixed>|null Formatted details, or null on error/empty.
     */
    private function safeTmdbDetails(string $tmdbId): ?array
    {
        try {
            $details = $this->tmdb->getDetails($tmdbId);
            return $details === [] ? null : $details;
        } catch (Throwable $e) {
            $this->logger->warning('TMDB getDetails failed', [
                'tmdb_id' => $tmdbId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Offline IMDb title lookup, tolerating errors (e.g. missing local table).
     *
     * @param string   $title Movie title.
     * @param int|null $year  Optional year.
     *
     * @return array<string, mixed>|null IMDb row, or null when no match/error.
     */
    private function safeImdbLookup(string $title, ?int $year): ?array
    {
        try {
            return $this->imdb->lookup($title, $year);
        } catch (Throwable $e) {
            $this->logger->warning('IMDb lookup failed', [
                'title' => $title,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Offline IMDb fetch by id, tolerating errors.
     *
     * @param string $imdbId IMDb id.
     *
     * @return array<string, mixed>|null IMDb row, or null when no match/error.
     */
    private function safeImdbGetById(string $imdbId): ?array
    {
        try {
            return $this->imdb->getByImdbId($imdbId);
        } catch (Throwable $e) {
            $this->logger->warning('IMDb getByImdbId failed', [
                'imdb_id' => $imdbId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Extract a non-empty IMDb id from caller-supplied external ids.
     *
     * @param array<string, string> $existingExternalIds External ids.
     *
     * @return string|null IMDb id, or null when absent/empty.
     */
    private function extractImdbId(array $existingExternalIds): ?string
    {
        return MetadataValue::asNullableString($existingExternalIds['imdb'] ?? null);
    }
}
