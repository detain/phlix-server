<?php

declare(strict_types=1);

namespace Phlix\Media\Library;

use Phlix\Media\Metadata\PosterSrcset;

/**
 * Shapes a raw hydrated media-item DB row into the public `media-item.schema.json`
 * response format (poster URLs, genres, overview, year, the season/episode
 * hierarchy fields, …).
 *
 * Extracted so EVERY endpoint that returns a media item produces the SAME shape.
 * The list endpoint (`GET /api/v1/media`) already enriched its rows, but the
 * single-item endpoint (`GET /api/v1/media/{id}`) used by the detail and player
 * pages returned the raw row — so posters, overview, genres and the season/
 * episode numbers were all absent and those pages rendered blank. Both paths now
 * call this shaper.
 */
final class MediaItemShaper
{
    /** Media-item `type` enum (schema-constrained). */
    private const VALID_TYPES = ['movie', 'series', 'season', 'episode', 'audio', 'image'];

    /** Content-rating enum (schema-constrained). */
    private const VALID_RATINGS = ['G', 'PG', 'PG-13', 'R', 'NC-17', 'X', 'UNRATED'];

    /**
     * Shapes a raw media item row into the media-item schema format.
     *
     * @param array<string, mixed> $item Raw hydrated media item (with parsed `metadata`).
     *
     * @return array<string, mixed> Media-item shaped response.
     */
    public static function shape(array $item): array
    {
        /** @var array<string, mixed> $metadata */
        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];

        // id/name/type are required (non-null) and type/rating are enum-constrained
        // — coerce malformed rows so one bad row can't break the contract.
        $idRaw = $item['id'] ?? null;
        $id = is_scalar($idRaw) ? (string) $idRaw : '';
        $nameRaw = $item['name'] ?? null;
        $name = is_scalar($nameRaw) ? (string) $nameRaw : '';
        if ($name === '') {
            $name = $id !== '' ? $id : 'Untitled';
        }
        $type = is_string($item['type'] ?? null) && in_array($item['type'], self::VALID_TYPES, true)
            ? $item['type']
            : 'movie';
        $rating = is_string($metadata['rating'] ?? null) && in_array($metadata['rating'], self::VALID_RATINGS, true)
            ? $metadata['rating']
            : null;

        $posterUrl = $metadata['poster_url'] ?? null;

        return [
            'id' => $id,
            'name' => $name,
            'type' => $type,
            'path' => $item['path'] ?? null,
            'poster_url' => $posterUrl,
            // Responsive poster variants (TMDB width swap) for the client's
            // `srcset`; null for non-TMDB posters → the card uses `poster_url`.
            'poster_srcset' => PosterSrcset::forPosterUrl(
                is_string($posterUrl) ? $posterUrl : null,
            ),
            'genres' => $metadata['genres'] ?? [],
            'year' => isset($metadata['year']) && is_numeric($metadata['year']) ? (int) $metadata['year'] : null,
            'rating' => $rating,
            'runtime' => isset($metadata['runtime']) && is_numeric($metadata['runtime'])
                ? (int) $metadata['runtime']
                : null,
            'overview' => $metadata['overview'] ?? null,
            'actors' => $metadata['actors'] ?? [],
            'director' => $metadata['director'] ?? null,
            // Series→season→episode hierarchy. `parent_id` is a top-level column;
            // season/episode numbers + the per-episode title live in metadata_json
            // (the scanner parses `S01E02` into metadata.season/episode/episode_title).
            // Top-level items (movies, series) carry a null parent + null numbers.
            'parent_id' => is_scalar($item['parent_id'] ?? null) && ($item['parent_id'] ?? null) !== ''
                ? (string) $item['parent_id']
                : null,
            'season_number' => isset($metadata['season']) && is_numeric($metadata['season'])
                ? (int) $metadata['season']
                : null,
            'episode_number' => isset($metadata['episode']) && is_numeric($metadata['episode'])
                ? (int) $metadata['episode']
                : null,
            'episode_title' => is_string($metadata['episode_title'] ?? null)
                ? $metadata['episode_title']
                : null,
            'created_at' => $item['created_at'] ?? null,
            'updated_at' => $item['updated_at'] ?? null,
        ];
    }

    /**
     * Shapes a single media item for the detail/player endpoint: the full schema
     * shape PLUS the raw fields those views still need that the list shape omits
     * (intro/outro markers, chapters, the parsed `metadata`, `library_id`, and the
     * `streams` array). Shaped (enriched) keys win over the raw row on collision.
     *
     * @param array<string, mixed>      $item    Raw hydrated media item.
     * @param array<int, array<mixed>>  $streams Stream rows for the item.
     *
     * @return array<string, mixed> The merged, enriched single-item response.
     */
    public static function shapeDetail(array $item, array $streams): array
    {
        $merged = array_merge($item, self::shape($item));
        $merged['streams'] = $streams;

        return $merged;
    }
}
