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
     * @param string                  $apiKey TMDB API v3 authentication key.
     * @param MetadataHttpClient|null $http   Optional HTTP client (injected in
     *                                        tests); defaults to a real TMDB client.
     */
    public function __construct(string $apiKey, ?MetadataHttpClient $http = null)
    {
        $this->http = $http ?? new MetadataHttpClient(
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
     * Resolve a movie by its IMDb id via TMDB's `/find` endpoint.
     *
     * Uses `GET /find/{imdbId}?external_source=imdb_id` and returns the first
     * `movie_results` entry mapped like a {@see self::search()} result. This is
     * faster and more reliable than a title search when an IMDb id is known.
     *
     * @param string $imdbId IMDb id, e.g. `tt0133093`.
     * @return array{id: string, title: string, overview: string, year: int|null}|null
     *     First matching movie, or null when there is no match.
     */
    public function findByImdbId(string $imdbId): ?array
    {
        if ($imdbId === '') {
            return null;
        }

        $response = $this->http->get("/find/{$imdbId}", [
            'external_source' => 'imdb_id',
        ]);

        if ($response === null) {
            return null;
        }

        $movieResults = MetadataValue::asAssocList($response['movie_results'] ?? null);
        if ($movieResults === []) {
            return null;
        }

        $first = $movieResults[0];

        $year = null;
        $releaseDate = MetadataValue::asString($first['release_date'] ?? null);
        if ($releaseDate !== '') {
            $timestamp = strtotime($releaseDate);
            if ($timestamp !== false) {
                $year = (int) date('Y', $timestamp);
            }
        }

        return [
            'id' => MetadataValue::asString($first['id'] ?? null),
            'title' => MetadataValue::asString(
                $first['title'] ?? ($first['name'] ?? null)
            ),
            'overview' => MetadataValue::asString($first['overview'] ?? null),
            'year' => $year,
        ];
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
            'append_to_response' => 'credits,genres,production_companies,external_ids',
        ]);

        if ($response === null) {
            return [];
        }

        return $this->formatMovieDetails($response);
    }

    /**
     * Search for TV series by name.
     *
     * @param string $query TV series name search query.
     * @param array<string, mixed> $options Search options (language, first_air_date_year).
     * @return array<int, array{
     *     id: string,
     *     name: string,
     *     overview: string,
     *     poster_path: string|null,
     *     backdrop_path: string|null,
     *     first_air_date: string,
     *     vote_average: float
     * }> Search results.
     */
    public function searchTv(string $query, array $options = []): array
    {
        $params = [
            'query' => $query,
            'language' => MetadataValue::asString($options['language'] ?? null, 'en-US'),
            'include_adult' => (bool) ($options['include_adult'] ?? false),
        ];
        $year = $options['first_air_date_year'] ?? null;
        if (is_int($year) || (is_string($year) && $year !== '')) {
            $params['first_air_date_year'] = (string) $year;
        }

        $response = $this->http->get('/search/tv', $params);
        if ($response === null || !isset($response['results'])) {
            return [];
        }

        $output = [];
        foreach (MetadataValue::asAssocList($response['results']) as $result) {
            $output[] = [
                'id' => MetadataValue::asString($result['id'] ?? null),
                'name' => MetadataValue::asString($result['name'] ?? ($result['original_name'] ?? null)),
                'overview' => MetadataValue::asString($result['overview'] ?? null),
                'poster_path' => MetadataValue::asNullableString($result['poster_path'] ?? null),
                'backdrop_path' => MetadataValue::asNullableString($result['backdrop_path'] ?? null),
                'first_air_date' => MetadataValue::asString($result['first_air_date'] ?? null),
                'vote_average' => MetadataValue::asFloat($result['vote_average'] ?? null),
            ];
        }

        return $output;
    }

    /**
     * Get detailed TV series information from TMDB.
     *
     * @param string $externalId TMDB TV series ID.
     * @param array<string, mixed> $options Options (language).
     * @return array<string, mixed> Series details (name, overview, year, genres,
     *     poster_path, backdrop_path, official_rating, tmdb_id, imdb_id).
     */
    public function getTvDetails(string $externalId, array $options = []): array
    {
        $response = $this->http->get("/tv/{$externalId}", [
            'language' => MetadataValue::asString($options['language'] ?? null, 'en-US'),
            'append_to_response' => 'genres,external_ids,content_ratings',
        ]);
        if ($response === null) {
            return [];
        }

        return $this->formatTvDetails($response);
    }

    /**
     * Get a TV season's details + episode list from TMDB.
     *
     * @param string $externalId   TMDB TV series ID.
     * @param int    $seasonNumber Season number (0 = Specials).
     * @param array<string, mixed> $options Options (language).
     * @return array{
     *     poster_path: string|null,
     *     overview: string,
     *     episodes: array<int, array{
     *         episode_number: int,
     *         name: string,
     *         overview: string,
     *         still_path: string|null,
     *         air_date: string,
     *         runtime: int
     *     }>
     * } Season details (empty `episodes` when the season is unknown).
     */
    public function getTvSeason(string $externalId, int $seasonNumber, array $options = []): array
    {
        $response = $this->http->get("/tv/{$externalId}/season/{$seasonNumber}", [
            'language' => MetadataValue::asString($options['language'] ?? null, 'en-US'),
        ]);
        if ($response === null) {
            return ['poster_path' => null, 'overview' => '', 'episodes' => []];
        }

        $episodes = [];
        foreach (MetadataValue::asAssocList($response['episodes'] ?? null) as $ep) {
            $episodes[] = [
                'episode_number' => MetadataValue::asInt($ep['episode_number'] ?? null),
                'name' => MetadataValue::asString($ep['name'] ?? null),
                'overview' => MetadataValue::asString($ep['overview'] ?? null),
                'still_path' => MetadataValue::asNullableString($ep['still_path'] ?? null),
                'air_date' => MetadataValue::asString($ep['air_date'] ?? null),
                'runtime' => MetadataValue::asInt($ep['runtime'] ?? null),
            ];
        }

        return [
            'poster_path' => MetadataValue::asNullableString($response['poster_path'] ?? null),
            'overview' => MetadataValue::asString($response['overview'] ?? null),
            'episodes' => $episodes,
        ];
    }

    /**
     * Format a TMDB `/tv/{id}` response into a standard series-details structure.
     *
     * @param array<string, mixed> $data Raw TMDB API response.
     * @return array<string, mixed> Formatted series details.
     */
    private function formatTvDetails(array $data): array
    {
        $firstAir = MetadataValue::asString($data['first_air_date'] ?? null);
        $year = null;
        if ($firstAir !== '') {
            $timestamp = strtotime($firstAir);
            if ($timestamp !== false) {
                $year = (int) date('Y', $timestamp);
            }
        }

        $genreNames = [];
        foreach (MetadataValue::asAssocList($data['genres'] ?? null) as $genre) {
            $genreNames[] = MetadataValue::asString($genre['name'] ?? null);
        }

        $externalIds = MetadataValue::asAssoc($data['external_ids'] ?? null);
        $imdbId = MetadataValue::asNullableString($externalIds['imdb_id'] ?? null);

        return [
            'name' => MetadataValue::asString($data['name'] ?? ($data['original_name'] ?? null)),
            'original_name' => MetadataValue::asString($data['original_name'] ?? null),
            'overview' => MetadataValue::asString($data['overview'] ?? null),
            'official_rating' => $this->extractUsContentRating($data['content_ratings'] ?? null),
            'vote_average' => MetadataValue::asFloat($data['vote_average'] ?? null),
            'year' => $year,
            'genres' => $genreNames,
            'tmdb_id' => MetadataValue::asNullableString($data['id'] ?? null),
            'imdb_id' => $imdbId,
            'poster_path' => MetadataValue::asNullableString($data['poster_path'] ?? null),
            'backdrop_path' => MetadataValue::asNullableString($data['backdrop_path'] ?? null),
            'number_of_seasons' => MetadataValue::asInt($data['number_of_seasons'] ?? null),
        ];
    }

    /**
     * Pull the US content rating (e.g. `TV-14`) from a `content_ratings` block.
     *
     * @param mixed $contentRatings Raw `content_ratings` payload.
     * @return string|null The US rating, or null when absent.
     */
    private function extractUsContentRating(mixed $contentRatings): ?string
    {
        $block = MetadataValue::asAssoc($contentRatings);
        foreach (MetadataValue::asAssocList($block['results'] ?? null) as $row) {
            if (MetadataValue::asString($row['iso_3166_1'] ?? null) === 'US') {
                $rating = MetadataValue::asString($row['rating'] ?? null);
                return $rating !== '' ? $rating : null;
            }
        }
        return null;
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

        // The `tt…` IMDb id may arrive either at the top level (plain
        // /movie/{id} response) or nested under `external_ids` when requested
        // via append_to_response=external_ids. Prefer the explicit external_ids
        // block, falling back to the top-level field.
        $externalIds = MetadataValue::asAssoc($data['external_ids'] ?? null);
        $imdbId = MetadataValue::asNullableString($externalIds['imdb_id'] ?? null)
            ?? MetadataValue::asNullableString($data['imdb_id'] ?? null);

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
            'imdb_id' => $imdbId,
            'tmdb_id' => MetadataValue::asNullableString($data['id'] ?? null),
            'poster_path' => MetadataValue::asNullableString($data['poster_path'] ?? null),
            'backdrop_path' => MetadataValue::asNullableString($data['backdrop_path'] ?? null),
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
