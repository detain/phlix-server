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
];
