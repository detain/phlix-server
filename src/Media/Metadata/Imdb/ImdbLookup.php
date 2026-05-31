<?php

declare(strict_types=1);

namespace Phlix\Media\Metadata\Imdb;

use Workerman\MySQL\Connection;

/**
 * Read-side of the IMDb data layer: matches a title (and optional year) against
 * the locally-imported `imdb_titles` table, and fetches a single title by its
 * IMDb id (`tconst`).
 *
 * Matching rules for {@see self::lookup()}:
 *   1. The query title is normalized with the same {@see self::normalizeTitle()}
 *      logic the importer used to populate `normalized_title`.
 *   2. When a year is supplied, candidates are restricted to a +/- 1 year window
 *      (release-year drift between sources is common).
 *   3. The best candidate is chosen by: exact-year match first, then highest
 *      `num_votes` (the most popular title wins ties / fuzzy-year matches).
 *
 * Database access is exclusively through the async {@see Connection} client with
 * parameterised queries.
 *
 * @package Phlix\Media\Metadata\Imdb
 * @since   0.21.0
 */
class ImdbLookup
{
    /** @var Connection Async MySQL connection used for all queries. */
    private Connection $db;

    /**
     * @param Connection $db Workerman MySQL connection.
     *
     * @since 0.21.0
     */
    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Look up the best-matching movie for a title (and optional year).
     *
     * @param string   $title Raw (un-normalized) movie title.
     * @param int|null $year  Optional release year; matched +/- 1 year.
     *
     * @return array{
     *     imdb_id: string,
     *     title: string,
     *     year: int|null,
     *     genres: list<string>,
     *     average_rating: float|null,
     *     num_votes: int|null,
     *     runtime_minutes: int|null
     * }|null The best candidate, or null when nothing matches.
     *
     * @since 0.21.0
     */
    public function lookup(string $title, ?int $year): ?array
    {
        $normalized = self::normalizeTitle($title);
        if ($normalized === '') {
            return null;
        }

        if ($year !== null) {
            $rows = $this->db->query(
                'SELECT tconst, primary_title, start_year, genres, average_rating, num_votes, runtime_minutes '
                . 'FROM imdb_titles '
                . 'WHERE normalized_title = ? AND start_year BETWEEN ? AND ? '
                . 'ORDER BY (start_year = ?) DESC, num_votes DESC '
                . 'LIMIT 1',
                [$normalized, $year - 1, $year + 1, $year]
            );
        } else {
            $rows = $this->db->query(
                'SELECT tconst, primary_title, start_year, genres, average_rating, num_votes, runtime_minutes '
                . 'FROM imdb_titles '
                . 'WHERE normalized_title = ? '
                . 'ORDER BY num_votes DESC '
                . 'LIMIT 1',
                [$normalized]
            );
        }

        if (!is_array($rows) || $rows === []) {
            return null;
        }

        $row = $rows[0];
        if (!is_array($row)) {
            return null;
        }

        return $this->mapRow($row);
    }

    /**
     * Fetch a single title by IMDb id (`tconst`).
     *
     * @param string $tconst IMDb id, e.g. `tt0133093`.
     *
     * @return array{
     *     imdb_id: string,
     *     title: string,
     *     year: int|null,
     *     genres: list<string>,
     *     average_rating: float|null,
     *     num_votes: int|null,
     *     runtime_minutes: int|null
     * }|null The title, or null when not found.
     *
     * @since 0.21.0
     */
    public function getByImdbId(string $tconst): ?array
    {
        if ($tconst === '') {
            return null;
        }

        $rows = $this->db->query(
            'SELECT tconst, primary_title, start_year, genres, average_rating, num_votes, runtime_minutes '
            . 'FROM imdb_titles WHERE tconst = ? LIMIT 1',
            [$tconst]
        );

        if (!is_array($rows) || $rows === []) {
            return null;
        }

        $row = $rows[0];
        if (!is_array($row)) {
            return null;
        }

        return $this->mapRow($row);
    }

    /**
     * Normalize a movie title for matching (lowercase, strip punctuation,
     * collapse whitespace, drop a leading article).
     *
     * Kept byte-faithful with {@see ImdbDatasetImporter::normalizeTitle()}.
     *
     * @param string $title Raw title.
     *
     * @return string Normalized title.
     *
     * @since 0.21.0
     */
    public static function normalizeTitle(string $title): string
    {
        $normalized = mb_strtolower($title, 'UTF-8');
        $normalized = preg_replace('/[:\-\'\"!?\.]/u', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized);

        foreach (['the ', 'a ', 'an '] as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                $normalized = substr($normalized, strlen($prefix));
            }
        }

        return trim($normalized);
    }

    /**
     * Map a raw `imdb_titles` row into the public return shape.
     *
     * @param array<string|int, mixed> $row Raw DB row.
     *
     * @return array{
     *     imdb_id: string,
     *     title: string,
     *     year: int|null,
     *     genres: list<string>,
     *     average_rating: float|null,
     *     num_votes: int|null,
     *     runtime_minutes: int|null
     * }
     */
    private function mapRow(array $row): array
    {
        $genresRaw = $row['genres'] ?? null;
        $genres = [];
        if (is_string($genresRaw) && $genresRaw !== '') {
            foreach (explode(',', $genresRaw) as $genre) {
                $genre = trim($genre);
                if ($genre !== '') {
                    $genres[] = $genre;
                }
            }
        }

        $year = $row['start_year'] ?? null;
        $rating = $row['average_rating'] ?? null;
        $votes = $row['num_votes'] ?? null;
        $runtime = $row['runtime_minutes'] ?? null;

        return [
            'imdb_id' => is_scalar($row['tconst'] ?? null) ? (string) $row['tconst'] : '',
            'title' => is_scalar($row['primary_title'] ?? null) ? (string) $row['primary_title'] : '',
            'year' => is_numeric($year) ? (int) $year : null,
            'genres' => $genres,
            'average_rating' => is_numeric($rating) ? (float) $rating : null,
            'num_votes' => is_numeric($votes) ? (int) $votes : null,
            'runtime_minutes' => is_numeric($runtime) ? (int) $runtime : null,
        ];
    }
}
