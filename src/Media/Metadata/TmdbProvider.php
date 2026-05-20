<?php

declare(strict_types=1);

namespace Phlix\Media\Metadata;

use Phlix\Media\Metadata\Dto\MetadataValue;

/**
 * TmdbProvider fetches movie metadata from The Movie Database (TMDB) API.
 *
 * This provider supports searching movies, fetching detailed information
 * including credits and genres, and retrieving images (posters, backdrops, logos).
 *
 * @author Phlix Development Team
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
     *     id: string,
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
        $language = MetadataValue::asString($options['language'] ?? null, 'en-US');
        $includeAdult = (bool) ($options['include_adult'] ?? false);

        $params = [
            'query' => $query,
            'language' => $language,
            'include_adult' => $includeAdult,
        ];

        $response = $this->http->get('/search/movie', $params);

        if ($response === null || !isset($response['results'])) {
            return [];
        }

        $results = MetadataValue::asAssocList($response['results']);

        $output = [];
        foreach ($results as $result) {
            $output[] = [
                'id' => MetadataValue::asString($result['id'] ?? null),
                'title' => MetadataValue::asString(
                    $result['title'] ?? ($result['name'] ?? null)
                ),
                'original_title' => MetadataValue::asString($result['original_title'] ?? null),
                'overview' => MetadataValue::asString($result['overview'] ?? null),
                'poster_path' => MetadataValue::asNullableString($result['poster_path'] ?? null),
                'backdrop_path' => MetadataValue::asNullableString($result['backdrop_path'] ?? null),
                'release_date' => MetadataValue::asString($result['release_date'] ?? null),
                'vote_average' => MetadataValue::asFloat($result['vote_average'] ?? null),
                'vote_count' => MetadataValue::asInt($result['vote_count'] ?? null),
            ];
        }

        return $output;
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
        $language = MetadataValue::asString($options['language'] ?? null, 'en-US');

        $response = $this->http->get("/movie/{$externalId}", [
            'language' => $language,
            'append_to_response' => 'credits,genres,production_companies',
        ]);

        if ($response === null) {
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

        if ($response === null) {
            return [];
        }

        return [
            'posters' => $this->formatImages(MetadataValue::asAssocList($response['posters'] ?? null)),
            'backdrops' => $this->formatImages(MetadataValue::asAssocList($response['backdrops'] ?? null)),
            'logos' => $this->formatImages(MetadataValue::asAssocList($response['logos'] ?? null)),
        ];
    }

    /**
     * Get provider name aliases.
     *
     * @return array<int, string> Provider names ['tmdb']
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
        $releaseDate = MetadataValue::asString($data['release_date'] ?? null);
        $runtime = MetadataValue::asInt($data['runtime'] ?? null);

        $year = null;
        if ($releaseDate !== '') {
            $timestamp = strtotime($releaseDate);
            if ($timestamp !== false) {
                $year = date('Y', $timestamp);
            }
        }

        $genres = MetadataValue::asAssocList($data['genres'] ?? null);
        $genreNames = [];
        foreach ($genres as $genre) {
            $genreNames[] = MetadataValue::asString($genre['name'] ?? null);
        }

        $studios = MetadataValue::asAssocList($data['production_companies'] ?? null);
        $studio = isset($studios[0]['name'])
            ? MetadataValue::asNullableString($studios[0]['name'])
            : null;

        $credits = MetadataValue::asAssoc($data['credits'] ?? null);
        $cast = MetadataValue::asAssocList($credits['cast'] ?? null);
        $crew = MetadataValue::asAssocList($credits['crew'] ?? null);

        $actors = [];
        foreach (array_slice($cast, 0, 20) as $member) {
            $actors[] = [
                'name' => MetadataValue::asString($member['name'] ?? null),
                'role' => MetadataValue::asString($member['character'] ?? null),
                'order' => MetadataValue::asInt($member['order'] ?? null),
            ];
        }

        return [
            'name' => MetadataValue::asString(
                $data['title'] ?? ($data['name'] ?? null)
            ),
            'original_name' => MetadataValue::asString(
                $data['original_title'] ?? ($data['original_name'] ?? null)
            ),
            'overview' => MetadataValue::asString($data['overview'] ?? null),
            'official_rating' => null,
            'vote_average' => MetadataValue::asFloat($data['vote_average'] ?? null),
            'vote_count' => MetadataValue::asInt($data['vote_count'] ?? null),
            'year' => $year,
            'runtime_ticks' => $runtime * 600000000, // Convert minutes to ticks
            'genres' => $genreNames,
            'studio' => $studio,
            'tagline' => MetadataValue::asString($data['tagline'] ?? null),
            'budget' => MetadataValue::asInt($data['budget'] ?? null),
            'revenue' => MetadataValue::asInt($data['revenue'] ?? null),
            'imdb_id' => MetadataValue::asNullableString($data['imdb_id'] ?? null),
            'tmdb_id' => MetadataValue::asNullableString($data['id'] ?? null),
            'actors' => $actors,
            'director' => $this->findDirector($crew),
        ];
    }

    /**
     * Find the director from a list of crew members.
     *
     * @param list<array<string, mixed>> $crew Crew members from TMDB API
     * @return string|null Director name or null if not found
     */
    private function findDirector(array $crew): ?string
    {
        foreach ($crew as $member) {
            $job = MetadataValue::asString($member['job'] ?? null);
            if ($job === 'Director') {
                return MetadataValue::asNullableString($member['name'] ?? null);
            }
        }
        return null;
    }

    /**
     * Format image list with full URLs.
     *
     * @param list<array<string, mixed>> $images Raw image data from TMDB
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
        $output = [];
        foreach ($images as $image) {
            $filePath = MetadataValue::asString($image['file_path'] ?? null);
            $output[] = [
                'url' => $this->imageBaseUrl . '/w500' . $filePath,
                'url_original' => $this->imageBaseUrl . '/original' . $filePath,
                'width' => MetadataValue::asInt($image['width'] ?? null),
                'height' => MetadataValue::asInt($image['height'] ?? null),
                'language' => MetadataValue::asNullableString($image['iso_639_1'] ?? null),
            ];
        }
        return $output;
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

        if ($response === null || !isset($response['results']) || !is_array($response['results'])) {
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
