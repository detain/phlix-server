<?php

declare(strict_types=1);

namespace Phlex\Media\Metadata;

use SimpleXMLElement;

/**
 * TVDB API provider for TV series metadata.
 *
 * This provider implements the MetadataProviderInterface to fetch TV series metadata
 * from TheTVDB.com API v3. It supports searching for series, retrieving detailed information
 * including actors and episodes, and fetching images (posters, banners, season posters).
 *
 * The provider handles language localization, caches responses in-memory, and formats
 * TVDB responses into a standardized metadata structure compatible with the media library.
 *
 * ## API Documentation
 * @see https://api.thetvdb.com/swagger
 *
 * ## Supported Media Types
 * - TV Series (complete metadata with seasons and episodes)
 * - TV Episodes (individual episode details)
 *
 * ## Response Format
 * All methods return arrays. Search results include id, title, overview, poster_path.
 * Details include name, year, overview, genres, actors, episodes, episode_count, season_count.
 * Images are organized by type: posters, banners, season_posters, season_thumbs.
 *
 * ## Caching
 * Responses are cached in-memory for the duration of the instance lifecycle.
 * Use MetadataHttpClient for persistent caching across requests.
 *
 * @author Phlex Development Team
 * @version 1.0.0
 * @description TVDB API provider for TV series metadata with episode support
 * @see MetadataProviderInterface For provider contract
 * @see MetadataHttpClient For HTTP client with persistent caching
 */
class TvdbProvider implements MetadataProviderInterface
{
    /** @var MetadataHttpClient HTTP client for TVDB API requests */
    private MetadataHttpClient $http;

    /** @var string Default language code for API requests (ISO 639-1) */
    private string $language;

    /** @var array<string, mixed> In-memory response cache keyed by cache key */
    private array $cache = [];

    /**
     * Constructor for TvdbProvider.
     *
     * @param string $apiKey TVDB API v3 authentication key
     *                       Get your key at https://www.thetvdb.com/?tab=apiregister
     * @param string $language Default language code (ISO 639-1, default: 'eng')
     *                         Supported: 'eng', ' spa', 'ger', 'fre', 'ita', 'jpn', etc.
     */
    public function __construct(string $apiKey, string $language = 'eng')
    {
        $this->http = new MetadataHttpClient(
            'https://api.thetvdb.com',
            $apiKey
        );
        $this->language = $language;
    }

    /**
     * Search for TV series by name.
     *
     * @param string $query Search query (series name, show title)
     * @param array<string, mixed> $options Search options:
     *                                    - language (string): Override default language
     *                                    - year (int): Filter by release year
     *                                    - include_adult (bool): Include adult content
     * @return array<int, array{
     *                id: string,
     *                title: string,
     *                original_title: string,
     *                overview: string,
     *                poster_path: string|null,
     *                banner_path: string|null,
     *                first_aired: string,
     *                network: string|null,
     *                status: string|null,
     *                rating: float|null
     *            }> Search results sorted by relevance
     */
    public function search(string $query, array $options = []): array
    {
        $language = $options['language'] ?? $this->language;

        $params = [
            'name' => $query,
            'language' => $language,
        ];

        $response = $this->http->get('/search/series', $params);

        if (!$response || !isset($response['data'])) {
            return [];
        }

        return array_map(function ($result) {
            return [
                'id' => (string) ($result['id'] ?? ''),
                'title' => $result['seriesName'] ?? $result['alias'] ?? '',
                'original_title' => $result['seriesName'] ?? '',
                'overview' => $result['overview'] ?? '',
                'poster_path' => $result['poster'] ?? null,
                'banner_path' => $result['banner'] ?? null,
                'first_aired' => $result['firstAired'] ?? '',
                'network' => $result['network'] ?? null,
                'status' => $result['status'] ?? null,
                'rating' => $result['siteRating'] ?? null,
            ];
        }, $response['data']);
    }

    /**
     * Get detailed metadata for a TV series.
     *
     * @param string $externalId TVDB series ID (e.g., '81179' for Breaking Bad)
     * @param array<string, mixed> $options Options:
     *                                    - language (string): Override default language
     * @return array<string, mixed> Series details including:
     *                           - name, original_name, overview, year
     *                           - first_aired, network, genre, rating, runtime, status
     *                           - imdb_id, tvdb_id
     *                           - actors (array), episodes (array), episode_count, season_count
     */
    public function getDetails(string $externalId, array $options = []): array
    {
        $language = $options['language'] ?? $this->language;

        $params = [
            'language' => $language,
        ];

        // Fetch series details
        $seriesResponse = $this->http->get("/series/{$externalId}", $params);

        if (!$seriesResponse || !isset($seriesResponse['data'])) {
            return [];
        }

        $series = $seriesResponse['data'];

        // Fetch actors
        $actorsResponse = $this->http->get("/series/{$externalId}/actors", $params);
        $actors = $this->formatActors($actorsResponse['data'] ?? []);

        // Fetch episodes
        $episodesResponse = $this->http->get("/series/{$externalId}/episodes", $params);
        $episodes = $this->formatEpisodes($episodesResponse['data'] ?? []);

        return [
            'name' => $series['seriesName'] ?? '',
            'original_name' => $series['seriesName'] ?? '',
            'overview' => $series['overview'] ?? '',
            'year' => isset($series['firstAired']) ? date('Y', strtotime($series['firstAired'])) : null,
            'first_aired' => $series['firstAired'] ?? null,
            'network' => $series['network'] ?? null,
            'genre' => $this->parseGenres($series['genre'] ?? ''),
            'rating' => $series['siteRating'] ?? null,
            'runtime' => $series['runtime'] ?? null,
            'status' => $series['status'] ?? null,
            'imdb_id' => $series['imdbId'] ?? null,
            'tvdb_id' => $series['id'] ?? null,
            'actors' => $actors,
            'episodes' => $episodes,
            'episode_count' => count($episodes),
            'season_count' => $this->countSeasons($episodes),
        ];
    }

    /**
     * Get images (posters, banners, season posters) for a TV series.
     *
     * @param string $externalId TVDB series ID
     * @return array<string, array<int, array{
     *                url: string,
     *                type: string,
     *                width: int,
     *                rating: float|null,
     *                language: string|null
     *            }>> Images grouped by type:
     *            - posters: Series poster images
     *            - banners: Wide banner images
     *            - season_posters: Per-season poster images
     *            - season_thumbs: Wide season thumbnails
     */
    public function getImages(string $externalId): array
    {
        $params = ['keyType' => 'poster'];

        // Get posters
        $posterResponse = $this->http->get("/series/{$externalId}/images/query", array_merge($params, ['keyType' => 'poster']));
        $posters = $this->formatImages($posterResponse['data'] ?? [], 'poster');

        // Get banners
        $bannerResponse = $this->http->get("/series/{$externalId}/images/query", array_merge($params, ['keyType' => 'series']));
        $banners = $this->formatImages($bannerResponse['data'] ?? [], 'banner');

        // Get season posters
        $seasonPosterResponse = $this->http->get("/series/{$externalId}/images/query", array_merge($params, ['keyType' => 'season']));
        $seasonPosters = $this->formatImages($seasonPosterResponse['data'] ?? [], 'season_poster');

        // Get season thumbs
        $seasonThumbResponse = $this->http->get("/series/{$externalId}/images/query", array_merge($params, ['keyType' => 'seasonwide']));
        $seasonThumbs = $this->formatImages($seasonThumbResponse['data'] ?? [], 'season_thumb');

        return [
            'posters' => $posters,
            'banners' => $banners,
            'season_posters' => $seasonPosters,
            'season_thumbs' => $seasonThumbs,
        ];
    }

    /**
     * Get provider name aliases.
     *
     * @return array<string> Provider names: ['tvdb', 'thetvdb']
     */
    public function getProviders(): array
    {
        return ['tvdb', 'thetvdb'];
    }

    /**
     * {@inheritdoc}
     */
    public function getSourceName(): string
    {
        return 'tvdb';
    }

    /**
     * Get episode details by series ID and season/episode number.
     *
     * @param string $seriesId TVDB series ID
     * @param int $season Season number (1-indexed)
     * @param int $episode Episode number (1-indexed)
     * @param array<string, mixed> $options Options:
     *                                    - language (string): Override default language
     * @return array<string, mixed> Episode details including:
     *                           - id, series_id, name, overview
     *                           - season_number, episode_number
     *                           - first_aired, runtime, rating, thumbnail
     */
    public function getEpisode(string $seriesId, int $season, int $episode, array $options = []): array
    {
        $language = $options['language'] ?? $this->language;

        $params = ['language' => $language];

        // First get all episodes to find the right one
        $response = $this->http->get("/series/{$seriesId}/episodes/query", array_merge($params, [
            'airedSeason' => $season,
            'airedEpisode' => $episode,
        ]));

        if (!$response || !isset($response['data']) || empty($response['data'])) {
            return [];
        }

        $episodeData = $response['data'][0];

        return [
            'id' => (string) ($episodeData['id'] ?? ''),
            'series_id' => $seriesId,
            'name' => $episodeData['episodeName'] ?? '',
            'overview' => $episodeData['overview'] ?? '',
            'season_number' => $episodeData['airedSeason'] ?? $season,
            'episode_number' => $episodeData['airedEpisodeNumber'] ?? $episode,
            'first_aired' => $episodeData['firstAired'] ?? null,
            'runtime' => $episodeData['runtime'] ?? null,
            'rating' => $episodeData['siteRating'] ?? null,
            'thumbnail' => $episodeData['filename'] ?? null,
        ];
    }

    /**
     * Get all episodes for a specific season.
     *
     * @param string $seriesId TVDB series ID
     * @param int $season Season number (1-indexed)
     * @param array<string, mixed> $options Options:
     *                                    - language (string): Override default language
     * @return array<int, array{
     *                id: string,
     *                name: string,
     *                overview: string,
     *                season_number: int,
     *                episode_number: int,
     *                first_aired: string|null,
     *                runtime: int|null,
     *                rating: float|null,
     *                thumbnail: string|null
     *            }> Array of episode details for the season
     */
    public function getSeasonEpisodes(string $seriesId, int $season, array $options = []): array
    {
        $language = $options['language'] ?? $this->language;

        $response = $this->http->get("/series/{$seriesId}/episodes/query", [
            'language' => $language,
            'airedSeason' => $season,
        ]);

        if (!$response || !isset($response['data'])) {
            return [];
        }

        return $this->formatEpisodes($response['data']);
    }

    /**
     * Format actor list from TVDB API response.
     *
     * @param array<int, array<string, mixed>> $actors Raw actor data from TVDB
     * @return array<int, array{
     *                name: string,
     *                role: string,
     *                image_url: string|null,
     *                sort_order: int
     *            }> Formatted actor list
     */
    private function formatActors(array $actors): array
    {
        return array_map(function ($actor) {
            return [
                'name' => $actor['personName'] ?? $actor['actorName'] ?? '',
                'role' => $actor['role'] ?? '',
                'image_url' => $actor['image'] ?? null,
                'sort_order' => $actor['sortOrder'] ?? 0,
            ];
        }, $actors);
    }

    /**
     * Format episode list from TVDB API response.
     *
     * @param array<int, array<string, mixed>> $episodes Raw episode data from TVDB
     * @return array<int, array{
     *                id: string,
     *                name: string,
     *                overview: string,
     *                season_number: int,
     *                episode_number: int,
     *                first_aired: string|null,
     *                runtime: int|null,
     *                rating: float|null,
     *                thumbnail: string|null
     *            }> Formatted episode list
     */
    private function formatEpisodes(array $episodes): array
    {
        return array_map(function ($episode) {
            return [
                'id' => (string) ($episode['id'] ?? ''),
                'name' => $episode['episodeName'] ?? '',
                'overview' => $episode['overview'] ?? '',
                'season_number' => $episode['airedSeason'] ?? 1,
                'episode_number' => $episode['airedEpisodeNumber'] ?? 0,
                'first_aired' => $episode['firstAired'] ?? null,
                'runtime' => $episode['runtime'] ?? null,
                'rating' => $episode['siteRating'] ?? null,
                'thumbnail' => $episode['filename'] ?? null,
            ];
        }, $episodes);
    }

    /**
     * Format image list from TVDB API response.
     *
     * @param array<int, array<string, mixed>> $images Raw image data from TVDB
     * @param string $type Image type classification (poster, banner, season_poster, season_thumb)
     * @return array<int, array{
     *                url: string,
     *                type: string,
     *                width: int,
     *                rating: float|null,
     *                language: string|null
     *            }> Formatted image list
     */
    private function formatImages(array $images, string $type): array
    {
        return array_map(function ($image) use ($type) {
            return [
                'url' => $image['fileName'] ?? '',
                'type' => $type,
                'width' => $image['resolution'] ?? 0,
                'rating' => $image['ratingsInfo']['average'] ?? null,
                'language' => $image['language'] ?? null,
            ];
        }, $images);
    }

    /**
     * Parse pipe-separated genre string into array.
     *
     * @param string $genres Pipe-separated genre string (e.g., "Action|Drama|Sci-Fi|")
     * @return array<int, string> Array of unique, trimmed genre names
     */
    private function parseGenres(string $genres): array
    {
        if (empty($genres)) {
            return [];
        }
        return array_filter(array_map('trim', explode('|', $genres)));
    }

    /**
     * Count unique seasons from episode list.
     *
     * @param array<int, array<string, mixed>> $episodes Episode list with season_number keys
     * @return int Number of unique seasons
     */
    private function countSeasons(array $episodes): int
    {
        $seasons = [];
        foreach ($episodes as $episode) {
            $season = $episode['season_number'] ?? 1;
            $seasons[$season] = true;
        }
        return count($seasons);
    }
}
