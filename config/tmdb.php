<?php

declare(strict_types=1);

/**
 * TMDB (The Movie Database) provider configuration.
 *
 * Used by {@see \Phlix\Media\Metadata\TmdbProvider} for movie/TV metadata
 * lookups and by {@see \Phlix\Media\Extras\TrailerResolver} for fetching
 * trailers exposed via the `/api/v1/media/{id}/trailers` family of
 * endpoints (Section 1.6c).
 *
 * Set `TMDB_API_KEY` in the environment to enable upstream lookups.
 * When empty, local extras (under the media folder's `Trailers/`
 * subdirectory and the cached `media_extras` table) still work, but no
 * new trailers will be discovered from TMDB.
 *
 * @since 0.20.0
 */

return [
    /**
     * TMDB API v3 key. Obtain from https://www.themoviedb.org/settings/api.
     */
    'api_key' => getenv('TMDB_API_KEY') ?: '',
];
