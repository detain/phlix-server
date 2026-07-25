<?php

/**
 * Phlix media server component: Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Library;

use Phlix\Auth\SignedUrl;
use Phlix\Media\Metadata\BackdropSrcset;
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
    /**
     * Media-item `type` enum — the EXACT members of the `media_items.type`
     * column ENUM as built up by migrations 001 → 011 (`track`/`music`/`album`/
     * `artist`/`video`/`audio`/`book`/`photo`) → 034 (`audiobook`). Keep this in
     * lockstep with that column: {@see shape()} coerces any value NOT listed
     * here to `'movie'`, so a member missing from this list silently mislabels
     * real rows (a `photo` reported to clients as a movie).
     *
     * @var list<string>
     */
    private const VALID_TYPES = [
        'movie',
        'series',
        'season',
        'episode',
        'track',
        'music',
        'album',
        'artist',
        'video',
        'audio',
        'book',
        'photo',
        'audiobook',
    ];

    /**
     * Content-rating enum (schema-constrained). The MPAA movie ratings PLUS the
     * US TV ratings, interleaved on the shared restrictiveness scale (see
     * {@see ContentRating}). `NR` is normalized to `UNRATED` and so is absent.
     *
     * @var list<string>
     */
    private const VALID_RATINGS = [
        'G',
        'TV-Y',
        'TV-G',
        'TV-Y7',
        'PG',
        'TV-PG',
        'PG-13',
        'TV-14',
        'R',
        'TV-MA',
        'NC-17',
        'X',
        'UNRATED',
    ];

    /**
     * Characters that can break out of an HTML attribute (or smuggle markup) and
     * so may never appear in an image URL this server hands to a client:
     * double/single quote, `<`, `>`, backtick, backslash. Enforced by
     * {@see self::safeImageUrl()}.
     */
    private const UNSAFE_URL_CHARS = "\"'<>`\\";

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
        // Content rating: the resolver stores it under `official_rating` (movie
        // release-date certs + TV content_ratings), while older/pre-resolver rows
        // carried it under `rating`. Prefer `official_rating`, fall back to
        // `rating`, and normalize (folds `NR`→`UNRATED`, drops unknowns) so a
        // resolved cert actually surfaces. Kept behind VALID_RATINGS so the
        // response stays enum-constrained.
        $ratingRaw = $metadata['official_rating'] ?? ($metadata['rating'] ?? null);
        $rating = ContentRating::normalize($ratingRaw);
        if ($rating !== null && !in_array($rating, self::VALID_RATINGS, true)) {
            $rating = null;
        }

        $posterUrl = self::nonemptyString($metadata['poster_url'] ?? null)
            ?? self::nonemptyString($metadata['cover_image_large'] ?? null)
            ?? self::nonemptyString($metadata['cover_image_extralarge'] ?? null)
            ?? null;

        // Use stored poster_srcset if available (from ArtworkStorage cache, SV-3.4),
        // otherwise generate from poster_url (TMDB srcset or null for non-TMDB posters).
        $posterSrcset = $metadata['poster_srcset'] ?? null;
        if ($posterSrcset === null) {
            $posterSrcset = PosterSrcset::forPosterUrl(
                is_string($posterUrl) ? $posterUrl : null,
            );
        }

        // Re-mint any stale/expired signature on INTERNAL artwork URLs at RESPONSE
        // time. poster_url/poster_srcset are signed at scan/enrich time with a
        // bounded TTL and stored verbatim, so hours later every stored signature is
        // expired. Clients that fetch artwork WITHOUT a session (the console TUI's
        // <img>/PosterLoader) authorise by signature — an expired one 401s and the
        // poster renders blank. This is the single universal shaping point every
        // media response funnels through (list, detail via shapeDetail(), continue-
        // watching, user-data, chapter search), so re-signing here fixes them all.
        // External (TMDB/AniList) covers and null pass through untouched.
        $posterUrl = SignedUrl::refreshArtworkUrl(is_string($posterUrl) ? $posterUrl : null);
        if (is_string($posterSrcset)) {
            $posterSrcset = SignedUrl::refreshArtworkSrcset($posterSrcset);
        }

        // Row-sized backdrop for a wide-backdrop / hero-strip LIST view. Only
        // items that actually carry `metadata_json.backdrop_url` get one — types
        // with no landscape art (track/music/album/artist/photo/book) simply have
        // no such key and degrade to null rather than a broken URL. There is NO
        // type allowlist on purpose: fanart.tv genuinely supplies artist/album
        // backgrounds, so gating on `type` would throw away real artwork.
        //
        // Deliberately NOT the detail treatment: shapeDetail() prefers TMDB
        // `/original` (`backdrop_url_large`) because it paints ONE full-bleed hero
        // per page. This is up to PageLimit::MAX rows per response, so the row
        // ladder tops out at w1280 and `backdrop_url_large` is NOT emitted here.
        //
        // Both keys go through self::safeImageUrl(), a scheme allowlist: `http`,
        // `https` and app-relative paths (`/api/v1/artwork/…`) only. `metadata_json`
        // is provider- OR `.nfo`/plugin-supplied, so it is partly controllable by
        // whoever writes files into a library, and this step multiplies the exposure
        // from one URL per hero response to three strings on up to PageLimit::MAX
        // rows. `javascript:`/`data:` URIs and attribute-breakout payloads therefore
        // become null instead of being echoed (and, worse, width-swapped INTO the
        // srcset).
        $storedBackdrop = self::safeImageUrl($metadata['backdrop_url'] ?? null);
        // TMDB URLs step up from the stored /w500 to /w780; non-TMDB (fanart.tv,
        // locally-cached) URLs have no width ladder and pass through as stored.
        $backdropUrl = BackdropSrcset::rowUrl($storedBackdrop) ?? $storedBackdrop;
        // Prefer a STORED backdrop_srcset over deriving one — the exact pattern
        // poster_srcset uses above. This matters the moment S72 caches backdrops
        // as `/api/v1/artwork/{id}?size=…`: a local artwork URL is not a TMDB URL,
        // so BackdropSrcset::rowSrcset() cannot build a ladder for it and the
        // responsive candidates this step exists to add would silently become null
        // for EVERY cached backdrop. Whatever ArtworkStorage wrote wins, validated
        // candidate-by-candidate by the same allowlist.
        $backdropSrcset = self::safeImageSrcset($metadata['backdrop_srcset'] ?? null);
        if ($backdropSrcset === null) {
            $backdropSrcset = BackdropSrcset::rowSrcset($storedBackdrop);
        }

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
            // When ArtworkStorage has cached the poster (SV-3.4), this carries
            // the local srcset pointing to /api/v1/artwork/{id}?size=...
            'poster_srcset' => $posterSrcset,
            // Landscape backdrop for a wide-backdrop/hero-strip row renderer,
            // sized for a row (TMDB /w780) — NOT the detail page's /original.
            // Null when the item has no backdrop art. Re-minted at RESPONSE time
            // (see the poster_url note above): once backdrops are cached locally
            // this is a signed `/api/v1/artwork/{id}?size=…` URL whose stored
            // scan-time signature is long expired, and an authless <img> would
            // 401 on it. External TMDB/fanart URLs pass through untouched.
            'backdrop_url' => SignedUrl::refreshArtworkUrl($backdropUrl),
            // A stored (ArtworkStorage) srcset when there is one, else exactly TWO
            // derived responsive candidates (w780, w1280) so a row strip is crisp
            // from a 2×-DPR phone up to a ~1400 CSS px desktop row without ever
            // advertising `/original`. Null when neither exists (a non-TMDB
            // backdrop with no cached variants) → the client uses `backdrop_url`.
            'backdrop_srcset' => SignedUrl::refreshArtworkSrcset($backdropSrcset),
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
            // Music metadata (null for non-audio items) so the client can group /
            // label tracks by artist + album.
            'artist' => is_string($metadata['artist'] ?? null) ? $metadata['artist'] : null,
            'album' => is_string($metadata['album'] ?? null) ? $metadata['album'] : null,
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
        // Tags/keywords — detail-only (the list shape stays lean, like cast/crew).
        // Series carry their own; episodes inherit the series tags at match time.
        $merged['tags'] = self::normalizeStringList($metadata['tags'] ?? null);
        $merged['production_companies'] = self::normalizeCompanies($metadata['production_companies'] ?? null);
        $merged['studio'] = is_string($metadata['studio'] ?? null) && $metadata['studio'] !== ''
            ? $metadata['studio']
            : null;
        $backdropUrl = is_string($metadata['backdrop_url'] ?? null) && $metadata['backdrop_url'] !== ''
            ? $metadata['backdrop_url']
            : null;
        // Re-mint the signature on the way out (same expiry fix as poster_url in
        // shape()): a locally-cached backdrop is a signed `/api/v1/artwork/{id}?size=…`
        // URL stored at scan time whose token is expired hours later — authless
        // clients (console `<img>`) then 401. External TMDB backdrops pass through
        // untouched.
        //
        // NOTE: shape() already emitted a ROW-sized `backdrop_url`/`backdrop_srcset`
        // (TMDB /w780 + a w780/w1280 pair) for the list renderers. These three lines
        // deliberately OVERWRITE them with the hero budget — the stored URL as-is
        // plus `/original` — because the detail page paints ONE full-bleed
        // background where `/original` is worth its bytes. Do not "de-duplicate"
        // this into a single shared value: the whole point is that a 100-row list
        // page must never advertise `/original`.
        $merged['backdrop_url'] = SignedUrl::refreshArtworkUrl($backdropUrl);
        // Full-bleed background variants (TMDB width swap). `backdrop_url_large`
        // is the `/original` full-resolution asset for the page background;
        // `backdrop_srcset` advertises w780/w1280/original so the client can pick
        // by viewport. Both null for non-TMDB backdrops → the client uses
        // `backdrop_url` unchanged. Each srcset URL is re-signed too (no-op for the
        // TMDB-derived variants, correct once backdrops are cached locally).
        $merged['backdrop_url_large'] = BackdropSrcset::largeUrl($backdropUrl);
        $merged['backdrop_srcset'] = SignedUrl::refreshArtworkSrcset(BackdropSrcset::forBackdropUrl($backdropUrl));
        $merged['theme_audio_url'] = is_string($metadata['theme_audio_url'] ?? null) &&
            $metadata['theme_audio_url'] !== ''
            ? $metadata['theme_audio_url']
            : null;

        // Primary trailer — detail-only, so a client can render a "Play Trailer"
        // button. Captured at scan time from TMDB `videos` (movie via the canonical
        // pipeline, series via SeriesMetadataResolver). Absent/empty → null (never a
        // broken URL); trailer_key/trailer_site are surfaced when present.
        $merged['trailer_url'] = self::nonemptyString($metadata['trailer_url'] ?? null);
        $merged['trailer_key'] = self::nonemptyString($metadata['trailer_key'] ?? null);
        $merged['trailer_site'] = self::nonemptyString($metadata['trailer_site'] ?? null);

        // Title logo — detail-only, so a client can overlay the transparent title
        // treatment on the hero backdrop. Captured at scan time from TMDB `images`
        // and cached locally as a transparency-safe PNG (served at `?size=logo`).
        // Absent/empty → null (never a broken URL). Localized TMDB PNG logos are
        // served as a signed `/api/v1/artwork/{id}?size=logo` URL stored at scan
        // time, so re-mint the signature on the way out (same expiry fix as
        // poster_url); external/SVG logos pass through untouched.
        $merged['logo_url'] = SignedUrl::refreshArtworkUrl(
            self::nonemptyString($metadata['logo_url'] ?? null)
        );

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
     * Filter a value to a non-empty, TRIMMED string, or null.
     *
     * Unlike the null-coalescing operator (??), this also rejects empty strings,
     * so AniList cover_image_large: "" falls through to the next fallback.
     *
     * The value is returned TRIMMED, not verbatim: a stored URL padded with
     * whitespace (`" https://image.tmdb.org/t/p/w500/bg.jpg"`, or one with a
     * trailing newline) otherwise reached the client as-is AND silently lost its
     * responsive ladder, because the `^`-anchored TMDB regexes in
     * {@see \Phlix\Media\Metadata\BackdropSrcset} / {@see PosterSrcset} reject the
     * leading space and return null. Browsers trim whitespace in `src`, so nothing
     * visibly broke — the srcset just disappeared.
     *
     * @param mixed $value Raw value.
     * @return string|null Trimmed non-empty string, or null.
     */
    private static function nonemptyString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Filter a raw metadata value to an image URL that is SAFE to emit, or null.
     *
     * A scheme allowlist, because `metadata_json` image URLs are provider-, `.nfo`-
     * or plugin-supplied — i.e. partly controllable by whoever writes files into a
     * library — and are emitted on up to {@see \Phlix\Common\Http\PageLimit::MAX}
     * rows per listing response. Accepted:
     *
     *  - absolute `http://` / `https://` (TMDB, fanart.tv, artworks.thetvdb.com, …);
     *  - app-relative paths beginning with a single `/` (`/api/v1/artwork/{id}?size=…`
     *    — the locally-cached shape S72 introduces — and any other server-relative
     *    artwork path).
     *
     * Rejected to null: every other scheme (`javascript:`, `data:`, `vbscript:`,
     * `file:`), protocol-relative `//host/…`, anything carrying an
     * attribute-breakout character ({@see self::UNSAFE_URL_CHARS}) — which is what
     * kills the `…/w500/bg.jpg"><script>…` shape that would otherwise be
     * width-swapped and embedded into `backdrop_srcset` — and anything carrying a
     * control byte (raw newlines/tabs are the classic `jav&#x0a;ascript:`
     * obfuscation, and browsers strip them before parsing the scheme).
     *
     * @param mixed $value Raw metadata image-URL value.
     * @return string|null The trimmed URL when it passes, else null.
     */
    private static function safeImageUrl(mixed $value): ?string
    {
        $url = self::nonemptyString($value);
        if ($url === null) {
            return null;
        }
        if (strpbrk($url, self::UNSAFE_URL_CHARS) !== false) {
            return null;
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $url) === 1) {
            return null;
        }
        if (str_starts_with($url, '/')) {
            // Server-relative artwork path — but never protocol-relative `//host`.
            return str_starts_with($url, '//') ? null : $url;
        }

        return preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }

    /**
     * Filter a stored `srcset` value to one whose every candidate URL passes
     * {@see self::safeImageUrl()}, or null.
     *
     * A stored srcset is emitted verbatim on every row, so it gets the same
     * allowlist as the single URL. ONE unsafe candidate rejects the WHOLE value —
     * the caller then derives a ladder or emits null — rather than shipping a
     * half-sanitised srcset.
     *
     * @param mixed $value Raw stored srcset value.
     * @return string|null The trimmed srcset when every candidate is safe, else null.
     */
    private static function safeImageSrcset(mixed $value): ?string
    {
        $srcset = self::nonemptyString($value);
        if ($srcset === null) {
            return null;
        }

        foreach (explode(',', $srcset) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                return null;
            }
            // `"<url> <descriptor>"` — a srcset URL carries no space, so the
            // descriptor is the trailing token (the same split
            // {@see SignedUrl::refreshArtworkSrcset()} uses). A bare URL is fine.
            $space = strrpos($candidate, ' ');
            $url = $space === false ? $candidate : substr($candidate, 0, $space);
            if (self::safeImageUrl($url) === null) {
                return null;
            }
        }

        return $srcset;
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
     * Normalize a raw value to a de-duplicated list of non-empty strings (tags).
     * Non-array inputs and blank/non-scalar entries are dropped.
     *
     * @param mixed $value Raw tags value from metadata_json.
     * @return list<string>
     */
    private static function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            $name = self::stringOrNull($entry);
            if ($name !== null && !in_array($name, $out, true)) {
                $out[] = $name;
            }
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
