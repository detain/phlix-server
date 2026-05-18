<?php

declare(strict_types=1);

namespace Phlex\Media\Metadata;

/**
 * Fanart.tv API provider for artwork (banners, thumbnails, logos).
 *
 * This provider implements the MetadataProviderInterface to fetch high-quality artwork
 * from Fanart.tv API v3. It specializes in artwork retrieval for movies, TV shows,
 * and music, providing HD logos, clear arts, banners, thumbs, and backgrounds.
 *
 * Note: Fanart.tv does not support direct search. This provider requires an external
 * ID (TVDB, IMDB, TMDB, or MusicBrainz) obtained from another provider like TvdbProvider
 * or TmdbProvider.
 *
 * ## API Documentation
 * @see https://fanart.tv/api/
 *
 * ## Supported ID Types
 * - tvdb: For TV shows (use TVDB series ID)
 * - imdb: For movies (use IMDB movie ID)
 * - tmdb: For movies (use TMDB movie ID)
 * - musicbrainz: For music (use MusicBrainz release/group ID)
 *
 * ## Artwork Types Retrieved
 * - hd_logos, hd_tv_logos: HD translucent logo overlays
 * - logos, tv_logos: Standard logo overlays
 * - posters, tv_posters, season_posters: Poster images
 * - backdrops, show_backdrops, season_backdrops: Background images
 * - banners, thumbs, season_thumbs, tv_thumbs: Banner and thumbnail images
 * - clear_arts, tv_clouds: Transparent PNG overlays
 * - movie_thumbs: Movie-specific thumbnails
 *
 * ## Caching
 * Responses are cached in-memory using key "fanart_{idType}_{id}" for the
 * duration of the instance lifecycle.
 *
 * @author Phlex Development Team
 * @version 1.0.0
 * @description Fanart.tv API provider for high-quality artwork retrieval
 * @see MetadataProviderInterface For provider contract
 * @see MetadataHttpClient For HTTP client with persistent caching
 * @see TvdbProvider For obtaining TVDB IDs
 * @see TmdbProvider For obtaining TMDB/IMDB IDs
 */
class FanartProvider implements MetadataProviderInterface
{
    /** @var MetadataHttpClient HTTP client for Fanart.tv API requests */
    private MetadataHttpClient $http;

    /** @var string Fanart.tv API client key for authentication */
    private string $apiKey;

    /** @var array<string, mixed> In-memory response cache keyed by "fanart_{idType}_{id}" */
    private array $cache = [];

    /**
     * Constructor for FanartProvider.
     *
     * @param string $apiKey Fanart.tv API client key
     *                       Get your key at https://fanart.tv/api/
     */
    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
        $this->http = new MetadataHttpClient(
            'https://webservice.fanart.tv/v3',
            $apiKey
        );
    }

    /**
     * Search is not supported by Fanart.tv.
     *
     * Fanart.tv requires an external ID from another provider (TVDB, IMDB, TMDB, etc.)
     * to fetch artwork. This method always returns an empty array.
     *
     * @param string $query Ignored - Fanart.tv does not support search
     * @param array<string, mixed> $options Ignored
     * @return array<int, mixed> Always empty - use getDetails() or getImages() with an external ID
     */
    public function search(string $query, array $options = []): array
    {
        // Fanart.tv doesn't support search directly, return empty
        // Use TVDB or TMDB to get the IMDB/TVDB ID first
        return [];
    }

    /**
     * Get artwork details for a media item using external ID.
     *
     * @param string $externalId External provider ID (TVDB, IMDB, TMDB, or MusicBrainz ID)
     * @param array<string, mixed> $options Options:
     *                                    - id_type (string): ID type - 'tvdb', 'imdb', 'tmdb', 'musicbrainz'
     *                                    - Default: 'tvdb'
     * @return array<string, mixed> Artwork details including:
     *                           - name, has_all_images
     *                           - image_counts (hd_logos, logos, posters, backdrops, banners, thumbs, etc.)
     */
    public function getDetails(string $externalId, array $options = []): array
    {
        // Fanart.tv uses external IDs - try to fetch by type
        $idType = $options['id_type'] ?? 'tvdb'; // 'tvdb', 'imdb', 'tmdb', 'musicbrainz'

        $response = $this->fetchArtwork($idType, $externalId);

        if (!$response) {
            return [];
        }

        return $this->formatDetails($response);
    }

    /**
     * Get all artwork images for a media item.
     *
     * @param string $externalId External provider ID
     * @return array<string, array<int, array{
     *                url: string,
     *                type: string,
     *                width: int,
     *                height: int,
     *                language: string|null,
     *                rating: float|null,
     *                likes: int
     *            }>> Images grouped by type:
     *            - hd_logos, hd_tv_logos, logos, tv_logos
     *            - posters, tv_posters, season_posters
     *            - backdrops, show_backdrops, season_backdrops
     *            - banners, thumbs, season_thumbs, tv_thumbs
     *            - clear_arts, tv_clouds, movie_thumbs
     */
    public function getImages(string $externalId): array
    {
        $idType = 'tvdb'; // Default to TVDB
        $response = $this->fetchArtwork($idType, $externalId);

        if (!$response) {
            return [];
        }

        return $this->formatImages($response);
    }

    /**
     * Get provider name aliases.
     *
     * @return array<string> Provider names: ['fanart', 'fanarttv']
     */
    public function getProviders(): array
    {
        return ['fanart', 'fanarttv'];
    }

    /**
     * {@inheritdoc}
     */
    public function getSourceName(): string
    {
        return 'fanart';
    }

    /**
     * Get movie artwork from Fanart.tv using IMDB ID.
     *
     * @param string $imdbId IMDB movie ID (e.g., 'tt0133093' for The Matrix)
     * @return array<string, array<int, array{
     *                url: string,
     *                type: string,
     *                width: int,
     *                height: int,
     *                language: string|null,
     *                rating: float|null,
     *                likes: int
     *            }>> Movie artwork grouped by type
     */
    public function getMovieImages(string $imdbId): array
    {
        $response = $this->fetchArtwork('imdb', $imdbId);

        if (!$response) {
            return [];
        }

        return $this->formatImages($response);
    }

    /**
     * Get TV show artwork from Fanart.tv using TVDB ID.
     *
     * @param string $tvdbId TVDB series ID (e.g., '81179' for Breaking Bad)
     * @return array<string, array<int, array{
     *                url: string,
     *                type: string,
     *                width: int,
     *                height: int,
     *                language: string|null,
     *                rating: float|null,
     *                likes: int
     *            }>> TV show artwork grouped by type
     */
    public function getTvShowImages(string $tvdbId): array
    {
        $response = $this->fetchArtwork('tvdb', $tvdbId);

        if (!$response) {
            return [];
        }

        return $this->formatImages($response);
    }

    /**
     * Get music artwork from Fanart.tv using MusicBrainz ID.
     *
     * @param string $musicbrainzId MusicBrainz release group ID
     *                              (e.g., '66x5t7a5p1e8-1a2b3c4d5e6f')
     * @return array<string, array<int, array{
     *                url: string,
     *                type: string,
     *                width: int,
     *                height: int,
     *                language: string|null,
     *                rating: float|null,
     *                likes: int
     *            }>> Music artwork grouped by type
     */
    public function getMusicImages(string $musicbrainzId): array
    {
        $response = $this->fetchArtwork('musicbrainz', $musicbrainzId);

        if (!$response) {
            return [];
        }

        return $this->formatImages($response);
    }

    /**
     * Fetch artwork data from Fanart.tv API with caching.
     *
     * @param string $idType ID type ('tvdb', 'imdb', 'tmdb', 'musicbrainz')
     * @param string $id External provider ID
     * @return array<string, mixed>|null Decoded API response or null on failure
     */
    private function fetchArtwork(string $idType, string $id): ?array
    {
        $cacheKey = "fanart_{$idType}_{$id}";

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $endpoint = match ($idType) {
            'tvdb' => "/tv/{$id}",
            'imdb' => "/movies/{$id}",
            'tmdb' => "/movies/fanart{$id}", // TMDB requires different approach
            'musicbrainz' => "/music/{$id}",
            default => null,
        };

        if (!$endpoint) {
            return null;
        }

        // Fanart.tv requires X-Client-Key header
        $response = $this->http->get($endpoint);

        if (!$response) {
            return null;
        }

        $this->cache[$cacheKey] = $response;
        return $response;
    }

    /**
     * Format artwork details from Fanart.tv API response.
     *
     * @param array<string, mixed> $data Raw API response
     * @return array<string, mixed> Formatted details:
     *                           - name, has_all_images
     *                           - image_counts by artwork category
     */
    private function formatDetails(array $data): array
    {
        $images = $this->formatImages($data);

        return [
            'name' => $data['name'] ?? '',
            'has_all_images' => !empty($images),
            'image_counts' => [
                'hd_logos' => count($data['hddata']['hdmovielogo'] ?? []),
                'logos' => count($data['hddata']['movielogo'] ?? []),
                'posters' => count($data['movieposters']['movieposter'] ?? []),
                'backdrops' => count($data['moviebackgrounds']['moviebackground'] ?? []),
                'banners' => count($data['moviebanners']['moviebanner'] ?? []),
                'thumbs' => count($data['moviethumbs']['moviethumb'] ?? []),
                'logos' => count($data['movielogos']['movielogo'] ?? []),
                'tshots' => count($data['moviescreencaps']['moviescreencap'] ?? []),
            ],
        ];
    }

    /**
     * Format artwork images from Fanart.tv API response.
     *
     * @param array<string, mixed> $data Raw API response
     * @return array<string, array<int, array{
     *                url: string,
     *                type: string,
     *                width: int,
     *                height: int,
     *                language: string|null,
     *                rating: float|null,
     *                likes: int
     *            }>> Images grouped by type
     */
    private function formatImages(array $data): array
    {
        $images = [];

        // HD logos (for TV shows and movies)
        foreach ($data['hddata']['hdmovielogo'] ?? [] as $logo) {
            $images['hd_logos'][] = $this->formatImage($logo, 'hd_logo');
        }
        foreach ($data['hddata']['hdtvlogo'] ?? [] as $logo) {
            $images['hd_tv_logos'][] = $this->formatImage($logo, 'hd_tv_logo');
        }

        // Standard logos
        foreach ($data['hddata']['movielogo'] ?? [] as $logo) {
            $images['logos'][] = $this->formatImage($logo, 'logo');
        }
        foreach ($data['hddata']['tvlogo'] ?? [] as $logo) {
            $images['tv_logos'][] = $this->formatImage($logo, 'tv_logo');
        }

        // Posters
        foreach ($data['movieposters']['movieposter'] ?? [] as $poster) {
            $images['posters'][] = $this->formatImage($poster, 'poster');
        }
        foreach ($data['tvposters']['tvposter'] ?? [] as $poster) {
            $images['tv_posters'][] = $this->formatImage($poster, 'poster');
        }
        foreach ($data['seasonposters']['seasonposter'] ?? [] as $poster) {
            $images['season_posters'][] = $this->formatImage($poster, 'season_poster');
        }

        // Backdrops
        foreach ($data['moviebackgrounds']['moviebackground'] ?? [] as $bg) {
            $images['backdrops'][] = $this->formatImage($bg, 'backdrop');
        }
        foreach ($data['showbackgrounds']['showbackground'] ?? [] as $bg) {
            $images['show_backdrops'][] = $this->formatImage($bg, 'backdrop');
        }
        foreach ($data['seasonbackgrounds']['seasonbackground'] ?? [] as $bg) {
            $images['season_backdrops'][] = $this->formatImage($bg, 'season_backdrop');
        }

        // Banners
        foreach ($data['moviebanners']['moviebanner'] ?? [] as $banner) {
            $images['banners'][] = $this->formatImage($banner, 'banner');
        }
        foreach ($data['tvthumbs']['tvthumb'] ?? [] as $thumb) {
            $images['thumbs'][] = $this->formatImage($thumb, 'thumb');
        }

        // Season thumbs (wide banners)
        foreach ($data['seasonthumbs']['seasonthumb'] ?? [] as $thumb) {
            $images['season_thumbs'][] = $this->formatImage($thumb, 'season_thumb');
        }
        foreach ($data['tvthumb']['tvthumb'] ?? [] as $thumb) {
            $images['tv_thumbs'][] = $this->formatImage($thumb, 'tv_thumb');
        }

        // Clear arts (transparent PNG overlays)
        foreach ($data['hddata']['clearart'] ?? [] as $art) {
            $images['clear_arts'][] = $this->formatImage($art, 'clear_art');
        }
        foreach ($data['hddata']['tvcloud'] ?? [] as $art) {
            $images['tv_clouds'][] = $this->formatImage($art, 'tv_cloud');
        }

        // Thumbnails
        foreach ($data['moviethumbs']['moviethumb'] ?? [] as $thumb) {
            $images['movie_thumbs'][] = $this->formatImage($thumb, 'movie_thumb');
        }

        return $images;
    }

    /**
     * Format a single image entry.
     *
     * @param array<string, mixed> $image Raw image data
     * @param string $type Image type classification
     * @return array{
     *            url: string,
     *            type: string,
     *            width: int,
     *            height: int,
     *            language: string|null,
     *            rating: float|null,
     *            likes: int
     *        } Formatted image entry
     */
    private function formatImage(array $image, string $type): array
    {
        return [
            'url' => $image['url'] ?? '',
            'type' => $type,
            'width' => $image['width'] ?? 0,
            'height' => $image['height'] ?? 0,
            'language' => $image['lang'] ?? null,
            'rating' => $image['rating'] ?? null,
            'likes' => $image['likes'] ?? 0,
        ];
    }
}
