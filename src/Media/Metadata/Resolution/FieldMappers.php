<?php

/**
 * Phlix media server component: Resolution.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata\Resolution;

use Phlix\Media\Metadata\Dto\MetadataValue;

/**
 * Per-provider factories that normalize a single provider's raw payload into a
 * canonical {@see SourceRecord}.
 *
 * Each provider exposes DIVERGENT field keys (e.g. TMDB `name` vs IMDb/TVDB
 * `title`; TMDB `runtime_ticks` vs IMDb `runtime_minutes` vs TVDB/NFO `runtime`;
 * IMDb `average_rating` vs `rating`; TMDB `vote_average`). These static factories
 * collapse those divergences onto the fixed canonical vocabulary of
 * {@see SourceRecord::CANONICAL_FIELDS} so {@see PriorityFieldResolver} (Step 3.2)
 * can merge several records purely by field name.
 *
 * ## Missing stays missing
 *
 * A raw key that the provider did NOT supply is left ABSENT in the resulting
 * record — never null-filled. The {@see Builder} helper only records a field
 * when a non-null/usable value is derived, so the resolver can tell "the source
 * never had this field" apart from "the source had it but it was empty".
 *
 * Pure functions, no I/O, no state — safe under Workerman resident memory.
 *
 * @package Phlix\Media\Metadata\Resolution
 * @since   Feature 3 (metadata source priority)
 */
final class FieldMappers
{
    /** TMDB image CDN base (image PATHs are converted to w500/original URLs). */
    private const TMDB_IMAGE_BASE = 'https://image.tmdb.org/t/p';

    /** One TMDB runtime tick-per-minute factor (minutes * 600000000 = ticks). */
    private const TICKS_PER_MINUTE = 600000000;

    private function __construct()
    {
    }

    /**
     * Map a TMDB movie- or TV-details payload (as produced by
     * {@see \Phlix\Media\Metadata\TmdbProvider::getDetails()} /
     * `getTvDetails()`) into a canonical record.
     *
     * Key normalizations:
     *  - `name`            -> title
     *  - `poster_path`     -> poster_url (prefixed `/w500`), or pass-through `poster_url`
     *  - `backdrop_path`   -> backdrop_url, or pass-through `backdrop_url`
     *  - `runtime_ticks`   -> runtime (minutes); falls back to `runtime` (already minutes)
     *  - `official_rating` -> official_rating (TV; absent/null for movies)
     *  - `actors`          -> actors (flattened to names)
     *  - `tmdb_id`/`imdb_id` (+ any `external_ids`) -> external_ids
     *
     * @param array<string, mixed> $raw Raw TMDB details payload.
     */
    public static function fromTmdb(array $raw): SourceRecord
    {
        $b = new Builder('tmdb');

        $b->string('title', $raw['name'] ?? null);
        $b->string('overview', $raw['overview'] ?? null);
        $b->tmdbImage('poster_url', $raw['poster_url'] ?? null, $raw['poster_path'] ?? null);
        $b->tmdbImage('backdrop_url', $raw['backdrop_url'] ?? null, $raw['backdrop_path'] ?? null);
        $b->stringList('genres', $raw['genres'] ?? null);
        $b->stringList('tags', $raw['tags'] ?? null);
        $b->int('year', $raw['year'] ?? null);

        $runtime = self::ticksToMinutes($raw['runtime_ticks'] ?? null);
        if ($runtime === null) {
            $runtime = MetadataValue::asNullableInt($raw['runtime'] ?? null);
        }
        $b->setInt('runtime', $runtime);

        $b->string('official_rating', $raw['official_rating'] ?? null);
        $b->actorNames('actors', $raw['actors'] ?? null);
        $b->string('director', $raw['director'] ?? null);
        $b->objectList('cast', $raw['cast'] ?? null);
        $b->objectList('crew', $raw['crew'] ?? null);
        $b->objectList('production_companies', $raw['production_companies'] ?? null);
        $b->string('studio', $raw['studio'] ?? null);

        // Primary trailer (from TMDB `append_to_response=videos`). Absent keys stay
        // absent so an item with no usable trailer carries no trailer_* field.
        $b->string('trailer_url', $raw['trailer_url'] ?? null);
        $b->string('trailer_key', $raw['trailer_key'] ?? null);
        $b->string('trailer_site', $raw['trailer_site'] ?? null);

        // Title-logo URL (from TMDB `append_to_response=images`). Absent when the
        // item has no usable logo so the field stays absent rather than empty.
        $b->string('logo_url', $raw['logo_url'] ?? null);

        $b->externalIds(self::mergeIds($raw, ['tmdb' => $raw['tmdb_id'] ?? null, 'imdb' => $raw['imdb_id'] ?? null]));

        return $b->build();
    }

    /**
     * Map an offline IMDb row (as produced by
     * {@see \Phlix\Media\Metadata\Imdb\ImdbLookup::lookup()} /
     * `getByImdbId()`) OR the {@see \Phlix\Media\Metadata\ImdbProvider::getDetails()}
     * shape into a canonical record.
     *
     * Key normalizations (tolerates both row shapes):
     *  - `title`                              -> title
     *  - `average_rating` | `rating`          -> imdb_rating (float)
     *  - `num_votes`                          -> imdb_votes (int)
     *  - `runtime_minutes` | `runtime`        -> runtime (minutes)
     *  - `genres`                             -> genres
     *  - `imdb_id`                            -> external_ids.imdb
     *
     * @param array<string, mixed> $raw Raw IMDb row / details payload.
     */
    public static function fromImdb(array $raw): SourceRecord
    {
        $b = new Builder('imdb');

        $b->string('title', $raw['title'] ?? null);
        $b->stringList('genres', $raw['genres'] ?? null);
        $b->int('year', $raw['year'] ?? null);

        // runtime: prefer the dataset's `runtime_minutes`, else a plain `runtime`.
        $runtime = MetadataValue::asNullableInt($raw['runtime_minutes'] ?? null);
        if ($runtime === null) {
            $runtime = MetadataValue::asNullableInt($raw['runtime'] ?? null);
        }
        $b->setInt('runtime', $runtime);

        // imdb_rating: `average_rating` (dataset) or `rating` (provider getDetails).
        $rating = MetadataValue::asNullableFloat($raw['average_rating'] ?? null);
        if ($rating === null) {
            $rating = MetadataValue::asNullableFloat($raw['rating'] ?? null);
        }
        $b->setFloat('imdb_rating', $rating);
        $b->int('imdb_votes', $raw['num_votes'] ?? null);

        $imdbId = MetadataValue::asNullableString($raw['imdb_id'] ?? null);
        $b->externalIds(self::mergeIds($raw, ['imdb' => $imdbId]));

        return $b->build();
    }

    /**
     * Map a TVDB series-details payload (as produced by
     * {@see \Phlix\Media\Metadata\TvdbProvider::getDetails()}) into a canonical
     * record.
     *
     * Key normalizations:
     *  - `name`              -> title
     *  - `genre`             -> genres (TVDB uses singular `genre`, already a list)
     *  - `runtime`           -> runtime (minutes)
     *  - `rating`            -> imdb_rating (TVDB site rating; the only numeric rating it exposes)
     *  - `network`           -> studio
     *  - `actors`            -> actors (flattened to names)
     *  - `imdb_id`/`tvdb_id` -> external_ids
     *
     * @param array<string, mixed> $raw Raw TVDB details payload.
     */
    public static function fromTvdb(array $raw): SourceRecord
    {
        $b = new Builder('tvdb');

        $b->string('title', $raw['name'] ?? null);
        $b->string('overview', $raw['overview'] ?? null);
        $b->stringList('genres', $raw['genre'] ?? null);
        $b->int('year', $raw['year'] ?? null);
        $b->int('runtime', $raw['runtime'] ?? null);
        $b->setFloat('imdb_rating', MetadataValue::asNullableFloat($raw['rating'] ?? null));
        $b->string('studio', $raw['network'] ?? null);
        $b->actorNames('actors', $raw['actors'] ?? null);

        $b->externalIds(self::mergeIds($raw, ['tvdb' => $raw['tvdb_id'] ?? null, 'imdb' => $raw['imdb_id'] ?? null]));

        return $b->build();
    }

    /**
     * Map a Fanart.tv payload into a canonical record.
     *
     * Fanart.tv is an ARTWORK source — it carries no descriptive text/ratings,
     * so almost every canonical field stays absent. It exposes a `name` (used as
     * a title fallback) and grouped image lists; the first poster/backdrop URL is
     * surfaced when present so artwork can act as a lower-priority image source.
     *
     * @param array<string, mixed> $raw Raw Fanart details/images payload
     *                                   (the `formatDetails`/`formatImages` shape or the raw API body).
     */
    public static function fromFanart(array $raw): SourceRecord
    {
        $b = new Builder('fanart');

        $b->string('title', $raw['name'] ?? null);
        $b->string('poster_url', self::firstImageUrl($raw, ['posters', 'tv_posters']));
        $b->string('backdrop_url', self::firstImageUrl($raw, ['backdrops', 'show_backdrops']));

        return $b->build();
    }

    /**
     * Map a local NFO payload (as produced by
     * {@see \Phlix\Media\Metadata\LocalNfoProvider}) into a canonical record.
     *
     * Key normalizations:
     *  - `name`                 -> title
     *  - `rating`               -> imdb_rating
     *  - `votes`                -> imdb_votes
     *  - `runtime`              -> runtime (minutes)
     *  - `mpaa`                 -> official_rating
     *  - `studios[0]`           -> studio
     *  - `directors[0]`         -> director
     *  - `actors`               -> actors (flattened to names)
     *  - `external_ids` map     -> external_ids
     *
     * @param array<string, mixed> $raw Raw NFO payload.
     */
    public static function fromLocalNfo(array $raw): SourceRecord
    {
        $b = new Builder('local');

        $b->string('title', $raw['name'] ?? null);
        $b->string('overview', $raw['overview'] ?? null);
        $b->stringList('genres', $raw['genres'] ?? null);
        $b->int('year', $raw['year'] ?? null);
        $b->int('runtime', $raw['runtime'] ?? null);
        $b->string('official_rating', $raw['mpaa'] ?? null);
        $b->setFloat('imdb_rating', MetadataValue::asNullableFloat($raw['rating'] ?? null));
        $b->int('imdb_votes', $raw['votes'] ?? null);
        $b->actorNames('actors', $raw['actors'] ?? null);
        $b->firstOf('studio', $raw['studios'] ?? null);
        $b->firstOf('director', $raw['directors'] ?? null);

        $b->externalIds(self::stringMap($raw['external_ids'] ?? null));

        return $b->build();
    }

    /**
     * Best-effort passthrough for a source that already speaks (mostly) canonical
     * keys — e.g. metadata-source plugins or pre-shaped payloads. Reads each
     * canonical field by its own name; image URLs are taken verbatim (no
     * `*_path` conversion). Missing keys stay absent.
     *
     * @param string               $source The plugin/source name.
     * @param array<string, mixed> $raw    Raw, already-canonical-ish payload.
     */
    public static function fromGeneric(string $source, array $raw): SourceRecord
    {
        $b = new Builder($source);

        // Title tolerates a `name` alias for convenience.
        $b->string('title', $raw['title'] ?? ($raw['name'] ?? null));
        $b->string('overview', $raw['overview'] ?? null);
        $b->string('poster_url', $raw['poster_url'] ?? null);
        $b->string('backdrop_url', $raw['backdrop_url'] ?? null);
        $b->stringList('genres', $raw['genres'] ?? null);
        $b->int('year', $raw['year'] ?? null);
        $b->int('runtime', $raw['runtime'] ?? null);
        $b->string('official_rating', $raw['official_rating'] ?? null);
        $b->setFloat('imdb_rating', MetadataValue::asNullableFloat($raw['imdb_rating'] ?? null));
        $b->int('imdb_votes', $raw['imdb_votes'] ?? null);
        $b->actorNames('actors', $raw['actors'] ?? null);
        $b->string('director', $raw['director'] ?? null);
        $b->objectList('cast', $raw['cast'] ?? null);
        $b->objectList('crew', $raw['crew'] ?? null);
        $b->objectList('production_companies', $raw['production_companies'] ?? null);
        $b->string('studio', $raw['studio'] ?? null);
        // Trailer fields come from untrusted plugin/pre-shaped input and are
        // interpolated into a client "Play Trailer" control, so validate before
        // recording: the URL must be http/https (drops `javascript:` and other
        // schemes) and the key must match the safe YouTube-id charset.
        $b->string('trailer_url', self::safeHttpUrl($raw['trailer_url'] ?? null));
        $b->string('trailer_key', self::safeYoutubeKey($raw['trailer_key'] ?? null));
        $b->string('trailer_site', $raw['trailer_site'] ?? null);
        // Title-logo URL from untrusted plugin/pre-shaped input is rendered as an
        // <img src>/CSS background on the client, so require an http(s) scheme
        // (drops `javascript:` and other schemes) — mirrors the trailer_url guard.
        $b->string('logo_url', self::safeHttpUrl($raw['logo_url'] ?? null));
        $b->externalIds(self::stringMap($raw['external_ids'] ?? null));

        return $b->build();
    }

    /**
     * Return the value only when it narrows to an `http`/`https` URL, else null.
     *
     * Guards the generic (plugin/pre-shaped) trailer passthrough: a value with
     * any other scheme (e.g. `javascript:`) or no scheme is dropped so a
     * malicious `trailer_url` never reaches the client render path.
     */
    private static function safeHttpUrl(mixed $raw): ?string
    {
        $value = MetadataValue::asNullableString($raw);
        if ($value === null) {
            return null;
        }
        $scheme = parse_url($value, PHP_URL_SCHEME);
        if (!is_string($scheme)) {
            return null;
        }
        $scheme = strtolower($scheme);
        return ($scheme === 'http' || $scheme === 'https') ? $value : null;
    }

    /**
     * Return the value only when it matches the safe YouTube-id charset
     * (`[A-Za-z0-9_-]{1,20}`), else null — so an out-of-charset `trailer_key`
     * from the generic path is dropped rather than interpolated into a URL.
     */
    private static function safeYoutubeKey(mixed $raw): ?string
    {
        $value = MetadataValue::asNullableString($raw);
        if ($value === null) {
            return null;
        }
        return preg_match('/^[A-Za-z0-9_-]{1,20}$/', $value) === 1 ? $value : null;
    }

    /**
     * Narrow a raw `external_ids` value to a clean `array<string,string>`,
     * dropping non-string keys and blank/non-string values.
     *
     * @return array<string, string>
     */
    private static function stringMap(mixed $raw): array
    {
        $out = [];
        foreach (MetadataValue::asAssoc($raw) as $key => $value) {
            $clean = MetadataValue::asNullableString($value);
            if ($clean !== null) {
                $out[$key] = $clean;
            }
        }
        return $out;
    }

    /**
     * Convert TMDB runtime ticks to whole minutes, or null when absent/zero.
     */
    private static function ticksToMinutes(mixed $ticks): ?int
    {
        $value = MetadataValue::asNullableInt($ticks);
        if ($value === null || $value <= 0) {
            return null;
        }
        return (int) ($value / self::TICKS_PER_MINUTE);
    }

    /**
     * Merge an explicit `external_ids` map (if any) with discovered top-level ids.
     * Blank/empty ids are dropped so the field only carries usable ids.
     *
     * @param array<string, mixed>       $raw       Raw payload (read for an `external_ids` block).
     * @param array<string, mixed>       $discovered Top-level id candidates keyed by provider.
     * @return array<string, string>
     */
    private static function mergeIds(array $raw, array $discovered): array
    {
        $out = [];
        foreach (MetadataValue::asAssoc($raw['external_ids'] ?? null) as $key => $value) {
            $clean = MetadataValue::asNullableString($value);
            if ($clean !== null) {
                $out[$key] = $clean;
            }
        }
        foreach ($discovered as $key => $value) {
            $clean = MetadataValue::asNullableString($value);
            if ($clean !== null) {
                $out[$key] = $clean;
            }
        }
        return $out;
    }

    /**
     * Pull the first usable image URL from one of several Fanart image buckets.
     *
     * @param array<string, mixed> $raw     Raw fanart payload.
     * @param list<string>         $buckets Bucket keys to try in order.
     */
    private static function firstImageUrl(array $raw, array $buckets): ?string
    {
        foreach ($buckets as $bucket) {
            foreach (MetadataValue::asAssocList($raw[$bucket] ?? null) as $entry) {
                $url = MetadataValue::asNullableString($entry['url'] ?? null);
                if ($url !== null) {
                    return $url;
                }
            }
        }
        return null;
    }

    /** TMDB image base accessor (used by the Builder). */
    public static function tmdbImageBase(): string
    {
        return self::TMDB_IMAGE_BASE;
    }
}
