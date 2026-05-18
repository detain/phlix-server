<?php

declare(strict_types=1);

namespace Phlex\Media\Metadata;

/**
 * TmdbProvider fetches movie metadata from The Movie Database (TMDB) API.
 *
 * This provider supports searching movies, fetching detailed information
 * including credits and genres, and retrieving images (posters, backdrops, logos).
 *
 * @author Phlex Development Team
 * @version 1.0.0
 * @description TMDB API provider for movie metadata
 * @see https://api.themoviedb.org/3/
 * @see MetadataProviderInterface For provider contract
 */
class TmdbProvider implements MetadataProviderInterface
{
    /** @var MetadataHttpClient HTTP client for TMDB API requests */
    private MetadataHttpClient $http;

    /** @var string Base URL for TMDB image CDN */
    private string $imageBaseUrl;

    /** @var array<string, mixed> Response cache for API calls */
    private array $cache = [];

    /**
     * Constructor for TmdbProvider.
     *
     * @param string $apiKey TMDB API v3 authentication key
     */
    public function __construct(string $apiKey)
    {
        $this->http = new MetadataHttpClient(
            'https://api.themoviedb.org/3',
            $apiKey
        );
        $this->imageBaseUrl = 'https://image.tmdb.org/t/p';
    }

    /**
     * Search for movies by title.
     *
     * @param string $query Movie title search query
     * @param array<string, mixed> $options Search options (language, include_adult)
     * @return array<int, array{
     *     id: int,
     *     title: string,
     *     original_title: string,
     *     overview: string,
     *     poster_path: string|null,
     *     backdrop_path: string|null,
     *     release_date: string,
     *     vote_average: float,
     *     vote_count: int
     * }> Search results
     */
    public function search(string $query, array $options = []): array
    {
        $language = $options['language'] ?? 'en-US';
        $includeAdult = $options['include_adult'] ?? false;

        $params = [
            'query' => $query,
            'language' => $language,
            'include_adult' => $includeAdult,
        ];

        $response = $this->http->get('/search/movie', $params);

        if (!$response || !isset($response['results'])) {
            return [];
        }

        return array_map(function ($result) {
            return [
                'id' => $result['id'],
                'title' => $result['title'] ?? $result['name'] ?? '',
                'original_title' => $result['original_title'] ?? '',
                'overview' => $result['overview'] ?? '',
                'poster_path' => $result['poster_path'] ?? null,
                'backdrop_path' => $result['backdrop_path'] ?? null,
                'release_date' => $result['release_date'] ?? '',
                'vote_average' => $result['vote_average'] ?? 0,
                'vote_count' => $result['vote_count'] ?? 0,
            ];
        }, $response['results']);
    }

    /**
     * Get detailed movie information from TMDB.
     *
     * @param string $externalId TMDB movie ID
     * @param array<string, mixed> $options Options (language)
     * @return array<string, mixed> Movie details including name, overview, year, genres, actors, director
     */
    public function getDetails(string $externalId, array $options = []): array
    {
        $language = $options['language'] ?? 'en-US';

        $response = $this->http->get("/movie/{$externalId}", [
            'language' => $language,
            'append_to_response' => 'credits,genres,production_companies',
        ]);

        if (!$response) {
            return [];
        }

        return $this->formatMovieDetails($response);
    }

    /**
     * Get movie images (posters, backdrops, logos) from TMDB.
     *
     * @param string $externalId TMDB movie ID
     * @return array<string, array<int, array{
     *     url: string,
     *     url_original: string,
     *     width: int,
     *     height: int,
     *     language: string|null
     * }>> Images by type
     */
    public function getImages(string $externalId): array
    {
        $response = $this->http->get("/movie/{$externalId}/images");

        if (!$response) {
            return [];
        }

        return [
            'posters' => $this->formatImages($response['posters'] ?? []),
            'backdrops' => $this->formatImages($response['backdrops'] ?? []),
            'logos' => $this->formatImages($response['logos'] ?? []),
        ];
    }

    /**
     * Get provider name aliases.
     *
     * @return array<string> Provider names ['tmdb']
     */
    public function getProviders(): array
    {
        return ['tmdb'];
    }

    /**
     * {@inheritdoc}
     */
    public function getSourceName(): string
    {
        return 'tmdb';
    }

    /**
     * Format TMDB API response into standard movie details structure.
     *
     * @param array<string, mixed> $data Raw TMDB API response
     * @return array<string, mixed> Formatted movie details
     */
    private function formatMovieDetails(array $data): array
    {
        return [
            'name' => $data['title'] ?? $data['name'] ?? '',
            'original_name' => $data['original_title'] ?? $data['original_name'] ?? '',
            'overview' => $data['overview'] ?? '',
            'official_rating' => null,
            'vote_average' => $data['vote_average'] ?? 0,
            'vote_count' => $data['vote_count'] ?? 0,
            'year' => isset($data['release_date']) ? date('Y', strtotime($data['release_date'])) : null,
            'runtime_ticks' => ($data['runtime'] ?? 0) * 600000000, // Convert minutes to ticks
            'genres' => array_map(fn($g) => $g['name'], $data['genres'] ?? []),
            'studio' => $data['production_companies'][0]['name'] ?? null,
            'tagline' => $data['tagline'] ?? '',
            'budget' => $data['budget'] ?? 0,
            'revenue' => $data['revenue'] ?? 0,
            'imdb_id' => $data['imdb_id'] ?? null,
            'tmdb_id' => $data['id'] ?? null,
            'actors' => array_map(fn($c) => [
                'name' => $c['name'] ?? '',
                'role' => $c['character'] ?? '',
                'order' => $c['order'] ?? 0,
            ], array_slice($data['credits']['cast'] ?? [], 0, 20)),
            'director' => $this->findDirector($data['credits']['crew'] ?? []),
        ];
    }

    /**
     * Find the director from a list of crew members.
     *
     * @param array<int, array<string, mixed>> $crew Crew members from TMDB API
     * @return string|null Director name or null if not found
     */
    private function findDirector(array $crew): ?string
    {
        foreach ($crew as $member) {
            if (($member['job'] ?? '') === 'Director') {
                return $member['name'] ?? null;
            }
        }
        return null;
    }

    /**
     * Format image list with full URLs.
     *
     * @param array<int, array<string, mixed>> $images Raw image data from TMDB
     * @return array<int, array{
     *     url: string,
     *     url_original: string,
     *     width: int,
     *     height: int,
     *     language: string|null
     * }> Formatted images
     */
    private function formatImages(array $images): array
    {
        return array_map(function ($image) {
            return [
                'url' => $this->imageBaseUrl . '/w500' . $image['file_path'],
                'url_original' => $this->imageBaseUrl . '/original' . $image['file_path'],
                'width' => $image['width'] ?? 0,
                'height' => $image['height'] ?? 0,
                'language' => $image['iso_639_1'] ?? null,
            ];
        }, $images);
    }

    /**
     * Get trailers for a movie from TMDB.
     *
     * Fetches the /movie/{id}/videos endpoint to retrieve trailer URLs.
     *
     * @param string $externalId TMDB movie ID
     * @return array<int, array{
     *     title: string,
     *     url: string,
     *     duration: int,
     *     quality: int
     * }> Array of trailer data
     *
     * @since 0.14.0
     */
    public function getTrailers(string $externalId): array
    {
        $response = $this->http->get("/movie/{$externalId}/videos");

        if (!is_array($response) || !isset($response['results']) || !is_array($response['results'])) {
            return [];
        }

        $trailers = [];
        foreach ($response['results'] as $video) {
            if (!is_array($video)) {
                continue;
            }

            // Only include trailers (type=Trailer) and teasers (type=Teaser)
            $typeRaw = $video['type'] ?? '';
            if (!is_string($typeRaw)) {
                continue;
            }
            $type = strtolower($typeRaw);
            if ($type !== 'trailer' && $type !== 'teaser') {
                continue;
            }

            // Build YouTube URL from site and key
            $siteRaw = $video['site'] ?? '';
            $site = is_string($siteRaw) ? strtolower($siteRaw) : '';
            $videoKeyRaw = $video['key'] ?? '';
            $videoKey = is_string($videoKeyRaw) ? $videoKeyRaw : '';

            if ($site !== 'youtube' || $videoKey === '') {
                continue; // Skip non-YouTube trailers
            }

            $url = 'https://www.youtube.com/watch?v=' . $videoKey;

            $nameRaw = $video['name'] ?? $type;
            $name = is_string($nameRaw) ? $nameRaw : $type;

            $trailers[] = [
                'title' => ucfirst($type) . ' (' . $name . ')',
                'url' => $url,
                'duration' => 0, // TMDB doesn't provide duration
                'quality' => 0, // Unknown until played
            ];
        }

        return $trailers;
    }
}
