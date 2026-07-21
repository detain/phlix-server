<?php

/**
 * Local artwork cache (SV-3.4) configuration.
 *
 * Downloaded TMDB/provider posters are stored on disk as sized variants
 * (w185/w342/w500/w780/original) under one sub-directory per media item, so
 * offline/LAN installs can serve artwork without reaching TMDB.
 *
 * @since 0.36.0
 */

declare(strict_types=1);

return [
    /*
     * Root directory for the local artwork cache. Each media item gets a
     * sub-directory (named by its UUID) containing the JPEG size variants.
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
     * exactly one place per asset kind — the two choke points in
     * `LibraryMetadataMatcher` that are the sole callers of
     * `ArtworkStorage::downloadAndStore()` (posters) and
     * `::downloadAndStoreLogo()` (title logos), both funnelled through
     * `persistMetadata()` on the worker AND HTTP paths. See
     * {@see \Phlix\Media\Storage\ArtworkDownloadPolicy}.
     *
     * WHAT TURNING THIS OFF STOPS
     *   - Downloading TMDB posters and generating the local w185/w342/w500/
     *     w780/original JPEG variants.
     *   - Downloading TMDB title logos and storing the local PNG.
     *   That is all the outbound image traffic and all the new disk writes
     *   under `storage_path`.
     *
     * WHAT KEEPS WORKING WHEN IT IS OFF
     *   - Every artwork file ALREADY cached on disk is untouched and still
     *     served — nothing is deleted, invalidated or unlinked.
     *   - Items already carrying local `poster_url` / `poster_srcset` /
     *     `logo_url` keep them; those values live in `metadata_json` and are
     *     not rewritten.
     *   - Metadata matching itself continues in full: titles, overviews,
     *     genres, cast, ratings and every other field are still fetched and
     *     persisted. An item matched while this is off simply keeps the
     *     REMOTE provider URL in `poster_url` / `logo_url` instead of a local
     *     one, so the UI still shows artwork wherever it can reach the
     *     provider directly.
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
