<?php

/**
 * Phlix media server component: Metadata.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Metadata\Dto\MetadataValue;

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
 * @author Phlix Development Team
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

    /** @var array<string, array<string, mixed>> In-memory response cache keyed by "fanart_{idType}_{id}" */
    private array $cache = [];

    /** @var StructuredLogger Structured logger instance */
    private StructuredLogger $logger;

    /**
     * Constructor for FanartProvider.
     *
     * @param string                  $apiKey Fanart.tv API client key
     *                                        Get your key at https://fanart.tv/api/
     * @param StructuredLogger|null   $logger Optional logger; defaults to MEDIA channel.
     * @param MetadataHttpClient|null $http   Optional HTTP client (injected in
     *                                        tests); defaults to a real Fanart.tv client.
     */
    public function __construct(
        string $apiKey,
        ?StructuredLogger $logger = null,
        ?MetadataHttpClient $http = null
    ) {
        $this->http = $http ?? new MetadataHttpClient(
            'https://webservice.fanart.tv/v3',
            $apiKey
        );
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::MEDIA);
    }

    /**
     * Search is not supported by Fanart.tv.
     *
     * Fanart.tv requires an external ID from another provider (TVDB, IMDB, TMDB, etc.)
     * to fetch artwork. This method always returns an empty array.
     *
     * @param string $query Ignored - Fanart.tv does not support search
     * @param array<string, mixed> $options Ignored
     * @return array<int, array{id: string, title: string, overview?: string, poster_path?: string}>
     *         Always empty - use getDetails() or getImages() with an external ID
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
        $idType = MetadataValue::asString($options['id_type'] ?? null, 'tvdb');

        $result = $this->fetchArtwork($idType, $externalId);
        $response = $result->body();

        if ($response === null) {
            ProviderOutcomeLog::record($this->logger, 'FanartProvider', 'getDetails', $result, [
                'id_type' => $idType,
                'external_id' => $externalId,
            ]);
            return [];
        }

        $details = $this->formatDetails($response);

        $this->logger->debug('FanartProvider: getDetails', [
            'id_type' => $idType,
            'external_id' => $externalId,
            'name' => $details['name'] ?? null,
        ]);

        return $details;
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
        $result = $this->fetchArtwork($idType, $externalId);
        $response = $result->body();

        if ($response === null) {
            ProviderOutcomeLog::record($this->logger, 'FanartProvider', 'getImages', $result, [
                'id_type' => $idType,
                'external_id' => $externalId,
            ]);
            return [];
        }

        $images = $this->formatImages($response);
        $totalImages = array_sum(array_map('count', $images));

        $this->logger->debug('FanartProvider: getImages', [
            'id_type' => $idType,
            'external_id' => $externalId,
            'image_count' => $totalImages,
        ]);

        return $images;
    }

    /**
     * Get provider name aliases.
     *
     * @return array<int, string> Provider names: ['fanart', 'fanarttv']
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
        $result = $this->fetchArtwork('imdb', $imdbId);
        $response = $result->body();

        if ($response === null) {
            ProviderOutcomeLog::record($this->logger, 'FanartProvider', 'getMovieImages', $result, [
                'imdb_id' => $imdbId,
            ]);
            return [];
        }

        $images = $this->formatImages($response);
        $totalImages = array_sum(array_map('count', $images));

        $this->logger->debug('FanartProvider: getMovieImages', [
            'imdb_id' => $imdbId,
            'image_count' => $totalImages,
        ]);

        return $images;
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
        $result = $this->fetchArtwork('tvdb', $tvdbId);
        $response = $result->body();

        if ($response === null) {
            ProviderOutcomeLog::record($this->logger, 'FanartProvider', 'getTvShowImages', $result, [
                'tvdb_id' => $tvdbId,
            ]);
            return [];
        }

        $images = $this->formatImages($response);
        $totalImages = array_sum(array_map('count', $images));

        $this->logger->debug('FanartProvider: getTvShowImages', [
            'tvdb_id' => $tvdbId,
            'image_count' => $totalImages,
        ]);

        return $images;
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
        $result = $this->fetchArtwork('musicbrainz', $musicbrainzId);
        $response = $result->body();

        if ($response === null) {
            ProviderOutcomeLog::record($this->logger, 'FanartProvider', 'getMusicImages', $result, [
                'musicbrainz_id' => $musicbrainzId,
            ]);
            return [];
        }

        $images = $this->formatImages($response);
        $totalImages = array_sum(array_map('count', $images));

        $this->logger->debug('FanartProvider: getMusicImages', [
            'musicbrainz_id' => $musicbrainzId,
            'image_count' => $totalImages,
        ]);

        return $images;
    }

    /**
     * Fetch artwork data from Fanart.tv API with caching.
     *
     * Returns the classified outcome rather than a bare `?array` so callers can
     * tell an empty artwork set apart from Fanart.tv refusing our API key —
     * every one of the five public entry points used to log both as the same
     * DEBUG "miss" line.
     *
     * @param string $idType ID type ('tvdb', 'imdb', 'tmdb', 'musicbrainz')
     * @param string $id External provider ID
     * @return MetadataHttpResult Classified outcome; never null.
     */
    private function fetchArtwork(string $idType, string $id): MetadataHttpResult
    {
        $cacheKey = "fanart_{$idType}_{$id}";

        if (isset($this->cache[$cacheKey])) {
            return MetadataHttpResult::success(200, $this->cache[$cacheKey]);
        }

        $endpoint = match ($idType) {
            'tvdb' => "/tv/{$id}",
            'imdb' => "/movies/{$id}",
            'tmdb' => "/movies/fanart{$id}", // TMDB requires different approach
            'musicbrainz' => "/music/{$id}",
            default => null,
        };

        if ($endpoint === null) {
            return MetadataHttpResult::unsupported("unknown id_type '{$idType}'");
        }

        // Fanart.tv requires X-Client-Key header
        $result = $this->http->getResult($endpoint);
        $response = $result->body();

        if ($response !== null) {
            $this->cache[$cacheKey] = $response;
        }

        return $result;
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

        $hdData = MetadataValue::asAssoc($data['hddata'] ?? null);

        return [
            'name' => MetadataValue::asString($data['name'] ?? null),
            'has_all_images' => !empty($images),
            'image_counts' => [
                'hd_logos' => count(MetadataValue::asList($hdData['hdmovielogo'] ?? null)),
                'logos' => count(MetadataValue::asList(
                    MetadataValue::asAssoc($data['movielogos'] ?? null)['movielogo'] ?? null
                )),
                'posters' => count(MetadataValue::asList(
                    MetadataValue::asAssoc($data['movieposters'] ?? null)['movieposter'] ?? null
                )),
                'backdrops' => count(MetadataValue::asList(
                    MetadataValue::asAssoc($data['moviebackgrounds'] ?? null)['moviebackground'] ?? null
                )),
                'banners' => count(MetadataValue::asList(
                    MetadataValue::asAssoc($data['moviebanners'] ?? null)['moviebanner'] ?? null
                )),
                'thumbs' => count(MetadataValue::asList(
                    MetadataValue::asAssoc($data['moviethumbs'] ?? null)['moviethumb'] ?? null
                )),
                'tshots' => count(MetadataValue::asList(
                    MetadataValue::asAssoc($data['moviescreencaps'] ?? null)['moviescreencap'] ?? null
                )),
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

        $hdData = MetadataValue::asAssoc($data['hddata'] ?? null);

        // HD logos (for TV shows and movies)
        $images = $this->collectInto(
            $images,
            'hd_logos',
            'hd_logo',
            MetadataValue::asAssocList($hdData['hdmovielogo'] ?? null)
        );
        $images = $this->collectInto(
            $images,
            'hd_tv_logos',
            'hd_tv_logo',
            MetadataValue::asAssocList($hdData['hdtvlogo'] ?? null)
        );

        // Standard logos
        $images = $this->collectInto(
            $images,
            'logos',
            'logo',
            MetadataValue::asAssocList($hdData['movielogo'] ?? null)
        );
        $images = $this->collectInto(
            $images,
            'tv_logos',
            'tv_logo',
            MetadataValue::asAssocList($hdData['tvlogo'] ?? null)
        );

        // Posters
        $images = $this->collectInto(
            $images,
            'posters',
            'poster',
            $this->nested($data, 'movieposters', 'movieposter')
        );
        $images = $this->collectInto(
            $images,
            'tv_posters',
            'poster',
            $this->nested($data, 'tvposters', 'tvposter')
        );
        $images = $this->collectInto(
            $images,
            'season_posters',
            'season_poster',
            $this->nested($data, 'seasonposters', 'seasonposter')
        );

        // Backdrops
        $images = $this->collectInto(
            $images,
            'backdrops',
            'backdrop',
            $this->nested($data, 'moviebackgrounds', 'moviebackground')
        );
        $images = $this->collectInto(
            $images,
            'show_backdrops',
            'backdrop',
            $this->nested($data, 'showbackgrounds', 'showbackground')
        );
        $images = $this->collectInto(
            $images,
            'season_backdrops',
            'season_backdrop',
            $this->nested($data, 'seasonbackgrounds', 'seasonbackground')
        );

        // Banners
        $images = $this->collectInto(
            $images,
            'banners',
            'banner',
            $this->nested($data, 'moviebanners', 'moviebanner')
        );
        $images = $this->collectInto(
            $images,
            'thumbs',
            'thumb',
            $this->nested($data, 'tvthumbs', 'tvthumb')
        );

        // Season thumbs (wide banners)
        $images = $this->collectInto(
            $images,
            'season_thumbs',
            'season_thumb',
            $this->nested($data, 'seasonthumbs', 'seasonthumb')
        );
        $images = $this->collectInto(
            $images,
            'tv_thumbs',
            'tv_thumb',
            $this->nested($data, 'tvthumb', 'tvthumb')
        );

        // Clear arts (transparent PNG overlays)
        $images = $this->collectInto(
            $images,
            'clear_arts',
            'clear_art',
            MetadataValue::asAssocList($hdData['clearart'] ?? null)
        );
        $images = $this->collectInto(
            $images,
            'tv_clouds',
            'tv_cloud',
            MetadataValue::asAssocList($hdData['tvcloud'] ?? null)
        );

        // Thumbnails
        $images = $this->collectInto(
            $images,
            'movie_thumbs',
            'movie_thumb',
            $this->nested($data, 'moviethumbs', 'moviethumb')
        );

        return $images;
    }

    /**
     * Extract a nested image list from a fanart response shape.
     *
     * Fanart.tv responses wrap each image category in an object whose single
     * key matches the singular form (e.g. `moviebanners` -> `moviebanner`).
     *
     * @param array<string, mixed> $data Raw API response
     * @return list<array<string, mixed>>
     */
    private function nested(array $data, string $outerKey, string $innerKey): array
    {
        $outer = MetadataValue::asAssoc($data[$outerKey] ?? null);
        return MetadataValue::asAssocList($outer[$innerKey] ?? null);
    }

    /**
     * Append formatted images under a bucket key.
     *
     * @param array<string, array<int, array{
     *     url: string,
     *     type: string,
     *     width: int,
     *     height: int,
     *     language: string|null,
     *     rating: float|null,
     *     likes: int
     * }>> $images Accumulator (preserves all previously-seen buckets)
     * @param string $bucket Output bucket key (e.g. 'posters')
     * @param string $type Image type label written to each entry
     * @param list<array<string, mixed>> $items Source list of raw image entries
     * @return array<string, array<int, array{
     *     url: string,
     *     type: string,
     *     width: int,
     *     height: int,
     *     language: string|null,
     *     rating: float|null,
     *     likes: int
     * }>>
     */
    private function collectInto(array $images, string $bucket, string $type, array $items): array
    {
        foreach ($items as $item) {
            $images[$bucket][] = $this->formatImage($item, $type);
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
            'url' => MetadataValue::asString($image['url'] ?? null),
            'type' => $type,
            'width' => MetadataValue::asInt($image['width'] ?? null),
            'height' => MetadataValue::asInt($image['height'] ?? null),
            'language' => MetadataValue::asNullableString($image['lang'] ?? null),
            'rating' => MetadataValue::asNullableFloat($image['rating'] ?? null),
            'likes' => MetadataValue::asInt($image['likes'] ?? null),
        ];
    }
}
