<?php

declare(strict_types=1);

namespace Phlix\Media\Library;

use Phlix\Media\Metadata\Dto\MetadataValue;
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
            // Article-stripped key the client can group/sort by ("The Plot" → "Plot")
            // while still DISPLAYING `name`. The server already orders listings by
            // this (see ItemRepository); exposed so any client-side sort agrees.
            'sort_title' => SortTitle::from($name),
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
            // Precise media length in SECONDS, probed at transcode time
            // (distinct from `runtime`, which is TMDB minutes). Lets the player
            // show the true total instead of the value an in-progress transcode
            // manifest would otherwise grow toward.
            'duration' => isset($metadata['duration_seconds']) && is_numeric($metadata['duration_seconds'])
                ? (int) $metadata['duration_seconds']
                : null,
            'overview' => $metadata['overview'] ?? null,
            // Normalise to a flat list of names regardless of how the row was
            // stored (TMDB objects vs an already-flattened list) so the SPA
            // cast chips never render "[object Object]".
            'actors' => MetadataValue::actorNames($metadata['actors'] ?? null),
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
            'air_date' => self::extractAirDate($metadata),
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
     * @param bool                      $isAdmin Whether the requesting user is an admin.
     *                                            When true, the `files` block (containing
     *                                            full paths and file sizes) is included.
     *
     * @return array<string, mixed> The merged, enriched single-item response.
     */
    public static function shapeDetail(array $item, array $streams, bool $isAdmin = false): array
    {
        $merged = array_merge($item, self::shape($item));
        $merged['streams'] = $streams;

        /** @var array<string, mixed> $metadata */
        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];

        // Rich cast/crew/company blocks — exposed ONLY on the detail endpoint
        // (the list shape stays lean). Defensively normalized so a malformed or
        // legacy row can't break the contract. `actors` (flat names) +
        // `director` (string), set by shape(), are left exactly as they are.
        $merged['cast'] = self::normalizeCast($metadata);
        $merged['crew'] = self::normalizePeople($metadata['crew'] ?? null, 'job');
        $merged['production_companies'] = self::normalizeCompanies($metadata['production_companies'] ?? null);
        $merged['studio'] = is_string($metadata['studio'] ?? null) && $metadata['studio'] !== ''
            ? $metadata['studio']
            : null;
        $merged['backdrop_url'] = is_string($metadata['backdrop_url'] ?? null) && $metadata['backdrop_url'] !== ''
            ? $metadata['backdrop_url']
            : null;
        $merged['theme_audio_url'] = is_string($metadata['theme_audio_url'] ?? null) && $metadata['theme_audio_url'] !== ''
            ? $metadata['theme_audio_url']
            : null;

        // Curated external provider-id map ({tmdb, imdb, tvdb, anidb, …}) so the
        // SPA can render "view on TMDB/IMDb/…" links. Detail-only. Assembled from
        // `metadata_json.external_ids` merged with any top-level `*_id` keys; only
        // non-empty values survive and every value is stringified. Never exposes
        // the whole metadata_json — only these curated ids.
        $merged['external_ids'] = self::normalizeExternalIds($metadata);

        // Admin-gated `files` block — only surfaced when the requesting user is
        // an admin. Contains per-file path, size_bytes, container, codec, and
        // resolution drawn from `metadata_json.files` and the item's streams.
        if ($isAdmin) {
            $merged['files'] = self::buildFilesBlock($metadata, $streams);
        }

        return $merged;
    }

    /**
     * Build the admin-gated `files` block from metadata and streams.
     *
     * @param array<string, mixed>      $metadata Parsed metadata_json.
     * @param array<int, array<mixed>> $streams  Stream rows for the item.
     *
     * @return list<array<string, mixed>>
     */
    private static function buildFilesBlock(array $metadata, array $streams): array
    {
        $filesMeta = $metadata['files'] ?? null;
        if (!is_array($filesMeta)) {
            return [];
        }

        // Index streams by their numeric index for O(1) lookup.
        $streamsByIndex = [];
        foreach ($streams as $stream) {
            $idx = isset($stream['stream_index']) && is_numeric($stream['stream_index'])
                ? (int) $stream['stream_index']
                : -1;
            $streamsByIndex[$idx] = $stream;
        }

        $out = [];
        foreach ($filesMeta as $file) {
            if (!is_array($file)) {
                continue;
            }

            $path = is_string($file['path'] ?? null) ? $file['path'] : '';
            if ($path === '') {
                continue;
            }

            // Container derived from file extension.
            $extension = pathinfo($path, PATHINFO_EXTENSION);
            $container = $extension !== '' ? strtolower($extension) : null;

            // Look up the stream that corresponds to this file (matched by index).
            // If no matching stream is found, codec/resolution remain null.
            $streamIndex = isset($file['stream_index']) && is_numeric($file['stream_index'])
                ? (int) $file['stream_index']
                : null;
            $stream = $streamIndex !== null ? ($streamsByIndex[$streamIndex] ?? null) : null;

            $codec = isset($stream['codec']) && is_string($stream['codec']) ? $stream['codec'] : null;
            $resolution = isset($stream['width']) && isset($stream['height'])
                && is_numeric($stream['width']) && is_numeric($stream['height'])
                ? $stream['width'] . 'x' . $stream['height']
                : null;

            $out[] = [
                'path' => $path,
                'size_bytes' => isset($file['size']) && is_numeric($file['size']) ? (int) $file['size'] : null,
                'container' => $container,
                'codec' => $codec,
                'resolution' => $resolution,
            ];
        }

        return $out;
    }

    /**
     * Curated external provider-id map for the detail response.
     *
     * Providers store ids under `metadata_json.external_ids` (a
     * `{tmdb, imdb, tvdb, anidb, …}` map, see {@see \Phlix\Media\Metadata\Resolution\FieldMappers})
     * AND sometimes as top-level `metadata_json.{tmdb,imdb,tvdb,anidb}_id` scalars.
     * This merges both sources (the nested `external_ids` map wins on key
     * collision), stringifies every value, and drops empty/blank entries — so the
     * SPA gets a stable `{provider: id}` map it can turn into provider links
     * without ever seeing the raw metadata_json. Returns an empty map when no id
     * is present (the key stays present for a stable response shape).
     *
     * @param array<string, mixed> $metadata Parsed metadata_json.
     * @return array<string, string> Provider-keyed id map (possibly empty).
     */
    private static function normalizeExternalIds(array $metadata): array
    {
        $out = [];

        // Top-level `<provider>_id` scalars (lowest precedence). Only the known
        // provider keys are considered so unrelated `*_id` fields never leak.
        foreach (['tmdb', 'imdb', 'tvdb', 'anidb'] as $provider) {
            $value = self::stringOrNull($metadata[$provider . '_id'] ?? null);
            if ($value !== null) {
                $out[$provider] = $value;
            }
        }

        // Nested `external_ids` map (wins over the top-level scalars). Keys are
        // provider names (tmdb/imdb/tvdb/anidb/…); every string key is kept so a
        // future provider id surfaces without a code change.
        $nested = $metadata['external_ids'] ?? null;
        if (is_array($nested)) {
            foreach ($nested as $key => $value) {
                if (!is_string($key) || $key === '') {
                    continue;
                }
                $clean = self::stringOrNull($value);
                if ($clean !== null) {
                    $out[$key] = $clean;
                }
            }
        }

        return $out;
    }

    /**
     * Coerce a raw external-id value to a non-empty string, or null.
     *
     * Accepts strings and numeric scalars (ids are often stored as ints), drops
     * blanks and non-scalars so only usable ids reach the response.
     *
     * @param mixed $value Raw id value.
     * @return string|null Trimmed non-empty string, or null.
     */
    /**
     * Extract the original air/release date (YYYY-MM-DD) an item was matched to.
     * Checks the common top-level metadata keys first, then per-provider blocks
     * under `metadata_json.details.*` (TVDB `first_aired`, NFO `aired`, …).
     * Returns null when nothing datelike is present.
     *
     * @param array<string, mixed> $metadata Parsed metadata_json.
     */
    private static function extractAirDate(array $metadata): ?string
    {
        foreach (['air_date', 'first_aired', 'aired', 'premiered', 'release_date'] as $key) {
            $v = self::stringOrNull($metadata[$key] ?? null);
            if ($v !== null) {
                return $v;
            }
        }
        $details = $metadata['details'] ?? null;
        if (is_array($details)) {
            foreach (['tvdb', 'local', 'fanart', 'tmdb'] as $provider) {
                $block = $details[$provider] ?? null;
                if (!is_array($block)) {
                    continue;
                }
                foreach (['first_aired', 'aired', 'air_date', 'premiered', 'release_date'] as $key) {
                    $v = self::stringOrNull($block[$key] ?? null);
                    if ($v !== null) {
                        return $v;
                    }
                }
            }
        }
        return null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed === '' ? null : $trimmed;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return null;
    }

    /**
     * Normalize the `cast` block for the detail response.
     *
     * Prefers the rich `metadata.cast` objects ({name, role, profile_url}).
     * Falls back to object-form `actors` ([{name, role/character, …}, …]) when
     * no `cast` is present; a purely flat actor-name list yields cast entries
     * with empty role + null profile_url. Entries without a name are dropped.
     *
     * @param array<string, mixed> $metadata Parsed metadata_json.
     * @return list<array<string, mixed>>
     */
    private static function normalizeCast(array $metadata): array
    {
        $cast = $metadata['cast'] ?? null;
        if (is_array($cast) && $cast !== []) {
            return self::normalizePeople($cast, 'role');
        }

        // Fallback to the `actors` key (object form or flat names).
        $actors = $metadata['actors'] ?? null;
        if (!is_array($actors)) {
            return [];
        }
        $out = [];
        foreach ($actors as $entry) {
            if (is_string($entry)) {
                $name = trim($entry);
                $role = '';
                $profile = null;
            } elseif (is_array($entry)) {
                $name = is_scalar($entry['name'] ?? null) ? trim((string) $entry['name']) : '';
                $roleRaw = $entry['role'] ?? ($entry['character'] ?? null);
                $role = is_scalar($roleRaw) ? (string) $roleRaw : '';
                $profile = is_string($entry['profile_url'] ?? null) && $entry['profile_url'] !== ''
                    ? $entry['profile_url']
                    : null;
            } else {
                continue;
            }
            if ($name === '') {
                continue;
            }
            $out[] = ['name' => $name, 'role' => $role, 'profile_url' => $profile];
        }
        return $out;
    }

    /**
     * Normalize a people list ({name, <$roleKey>, profile_url}) — shared by
     * cast (role key `role`) and crew (role key `job`). Coerces scalar types,
     * drops entries without a name.
     *
     * @param mixed  $value   Raw cast/crew value from metadata_json.
     * @param string $roleKey Secondary string field: `role` (cast) or `job` (crew).
     * @return list<array<string, mixed>>
     */
    private static function normalizePeople(mixed $value, string $roleKey): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = is_scalar($entry['name'] ?? null) ? trim((string) $entry['name']) : '';
            if ($name === '') {
                continue;
            }
            $roleRaw = $entry[$roleKey] ?? null;
            $out[] = [
                'name' => $name,
                $roleKey => is_scalar($roleRaw) ? (string) $roleRaw : '',
                'profile_url' => is_string($entry['profile_url'] ?? null) && $entry['profile_url'] !== ''
                    ? $entry['profile_url']
                    : null,
            ];
        }
        return $out;
    }

    /**
     * Normalize the `production_companies` block for the detail response.
     *
     * @param mixed $value Raw production_companies value from metadata_json.
     * @return list<array{name: string, logo_url: string|null, origin_country: string|null}>
     */
    private static function normalizeCompanies(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = is_scalar($entry['name'] ?? null) ? trim((string) $entry['name']) : '';
            if ($name === '') {
                continue;
            }
            $out[] = [
                'name' => $name,
                'logo_url' => is_string($entry['logo_url'] ?? null) && $entry['logo_url'] !== ''
                    ? $entry['logo_url']
                    : null,
                'origin_country' => is_string($entry['origin_country'] ?? null) && $entry['origin_country'] !== ''
                    ? $entry['origin_country']
                    : null,
            ];
        }
        return $out;
    }
}
