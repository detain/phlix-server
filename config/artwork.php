<?php

/**
 * Local artwork cache (SV-3.4) configuration.
 *
 * Downloaded TMDB/provider artwork is stored on disk as sized variants
 * (w185/w342/w500/w780/original) under ONE sub-directory per cache key, so
 * offline/LAN installs can serve artwork without reaching TMDB. Two flat key
 * shapes share the single root (S72): the media-item UUID directory (posters)
 * and the `people-{tmdbPersonId}` directory (cast/crew profile photos, shared
 * across every item that references the person — a person is a shared asset,
 * not item property). Keys are flat `[a-zA-Z0-9-]` by construction (the
 * ImageResizer target-directory gate); nothing nests.
 *
 * @since 0.36.0
 */

declare(strict_types=1);

return [
    /*
     * Root directory for the local artwork cache. Each cache key gets a flat
     * sub-directory containing the JPEG size variants — named by the media-item
     * UUID for posters, by `people-{tmdbPersonId}` for shared cast/crew profile
     * photos (S72).
     *
     * Operator-overridable via ARTWORK_STORAGE_PATH so self-hosted installs
     * can point the cache at a larger/faster volume. When unset the historic
     * default (/var/artwork) is used. The directory is created on demand.
     *
     * Example env: ARTWORK_STORAGE_PATH=/data/phlix/artwork
     */
    'storage_path' => getenv('ARTWORK_STORAGE_PATH') ?: '/var/artwork',

    /*
     * Master switch for FETCHING new artwork from the metadata provider.
     *
     * Backs the `artwork.download_enabled` admin setting and is enforced in
     * exactly one place per asset kind — the three choke points in
     * `LibraryMetadataMatcher` that are the sole callers of
     * `ArtworkStorage::downloadAndStore()` (posters and, since S72, shared
     * cast/crew profile photos) and `::downloadAndStoreLogo()` (title logos),
     * all funnelled through `persistMetadata()` on the worker AND HTTP paths.
     * See {@see \Phlix\Media\Storage\ArtworkDownloadPolicy}.
     *
     * WHAT TURNING THIS OFF STOPS
     *   - Downloading TMDB posters and generating the local w185/w342/w500/
     *     w780/original JPEG variants.
     *   - Downloading TMDB title logos and storing the local PNG.
     *   - Downloading TMDB cast/crew profile photos into the shared
     *     `people-{tmdbPersonId}` directories (S72).
     *   That is all the outbound image traffic and all the new disk writes
     *   under `storage_path`.
     *
     * WHAT KEEPS WORKING WHEN IT IS OFF
     *   - Every artwork file ALREADY cached on disk is untouched and still
     *     served — nothing is deleted, invalidated or unlinked.
     *   - Items already carrying local `poster_url` / `poster_srcset` /
     *     `logo_url` keep them; those values live in `metadata_json` and are
     *     not rewritten. So do people entries already carrying a local
     *     `profile_url` (S72).
     *   - Metadata matching itself continues in full: titles, overviews,
     *     genres, cast, ratings and every other field are still fetched and
     *     persisted. An item matched while this is off simply keeps the
     *     REMOTE provider URL in `poster_url` / `logo_url` (and in each cast/
     *     crew member's `profile_url`) instead of a local one, so the UI still
     *     shows artwork wherever it can reach the provider directly.
     *   - Re-enabling it is enough to make the next match (or a metadata
     *     refresh) cache the artwork locally; no repair step is needed.
     *
     * The operator cases this exists for are "I am being rate-limited by the
     * provider" and "I am out of disk", both of which want the downloads to
     * stop IMMEDIATELY. It is therefore read live per persist via
     * SettingsRepository::getEffective() rather than from the boot config, so
     * the schema entry must be `"restart": false`.
     */
    'download_enabled' => true,
];
