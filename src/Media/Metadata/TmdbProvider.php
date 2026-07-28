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
    /**
     * Crew jobs surfaced as "key crew" on the media-detail page. Anything
     * outside this allow-list (gaffers, grips, …) is dropped so the crew block
     * stays short and relevant.
     *
     * @var list<string>
     */
    private const KEY_CREW_JOBS = [
        'Director',
        'Writer',
        'Screenplay',
        'Story',
        'Creator',
        'Producer',
        'Executive Producer',
    ];

    /** Maximum number of cast objects emitted per item. */
    private const MAX_CAST = 20;

    /** Maximum number of crew objects emitted per item. */
    private const MAX_CREW = 12;

    /** @var MetadataHttpClient HTTP client for TMDB API requests */
    private MetadataHttpClient $http;

    /** @var string Base URL for TMDB image CDN */
    private string $imageBaseUrl;

    /** @var string TMDB API v3 authentication key (empty when unconfigured). */
    private string $apiKey;

    /** @var StructuredLogger Structured logger instance */
    private StructuredLogger $logger;

    /**
     * Constructor for TmdbProvider.
     *
     * @param string                  $apiKey TMDB API v3 authentication key.
     * @param MetadataHttpClient|null $http   Optional HTTP client (injected in
     *                                        tests); defaults to a real TMDB client.
     * @param StructuredLogger|null   $logger Optional logger; defaults to MEDIA channel.
     */
    public function __construct(string $apiKey, ?MetadataHttpClient $http = null, ?StructuredLogger $logger = null)
    {
        $this->apiKey = $apiKey;
        $this->http = $http ?? new MetadataHttpClient(
            'https://api.themoviedb.org/3',
            $apiKey
        );
        $this->imageBaseUrl = 'https://image.tmdb.org/t/p';
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::MEDIA);
    }

    /**
     * Whether a (non-empty) TMDB API key is configured.
     *
     * Used by interactive search/apply to surface a clear "configure TMDB"
     * error instead of letting an unauthenticated request silently return no
     * results / no match.
     *
     * @return bool True when an API key is present.
     */
    public function hasApiKey(): bool
    {
        return $this->apiKey !== '';
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
        $year = $options['year'] ?? null;

        $params = [
            'query' => $query,
            'language' => $language,
            'include_adult' => $includeAdult,
        ];

        $result = $this->http->getResult('/search/movie', $params);
        $response = $result->body();

        if ($response === null || !isset($response['results'])) {
            ProviderOutcomeLog::record($this->logger, 'TmdbProvider', 'search', $result, [
                'query' => $query,
                'year' => $year,
                'endpoint' => '/search/movie',
            ]);
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

        $this->logger->debug('TmdbProvider: search', [
            'query' => $query,
            'year' => $year,
            'result_count' => count($output),
            'endpoint' => '/search/movie',
        ]);

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

        $result = $this->http->getResult("/find/{$imdbId}", [
            'external_source' => 'imdb_id',
        ]);
        $response = $result->body();

        if ($response === null) {
            ProviderOutcomeLog::record($this->logger, 'TmdbProvider', 'findByImdbId', $result, [
                'imdb_id' => $imdbId,
                'endpoint' => "/find/{$imdbId}",
            ]);
            return null;
        }

        $movieResults = MetadataValue::asAssocList($response['movie_results'] ?? null);
        if ($movieResults === []) {
            $this->logger->debug('TmdbProvider: findByImdbId no results', [
                'imdb_id' => $imdbId,
                'endpoint' => "/find/{$imdbId}",
            ]);
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

        $tmdbId = MetadataValue::asString($first['id'] ?? null);
        $title = MetadataValue::asString($first['title'] ?? ($first['name'] ?? null));

        $this->logger->debug('TmdbProvider: findByImdbId matched', [
            'imdb_id' => $imdbId,
            'tmdb_id' => $tmdbId,
            'title' => $title,
            'endpoint' => "/find/{$imdbId}",
        ]);

        return [
            'id' => $tmdbId,
            'title' => $title,
            'overview' => MetadataValue::asString($first['overview'] ?? null),
            'year' => $year,
        ];
    }

    /**
     * Get detailed movie information from TMDB.
     *
     * @param string $externalId TMDB movie ID
     * @param array<string, mixed> $options Options (language, preferred_locale)
     * @return array<string, mixed> Movie details including name, overview, year, genres, actors, director
     */
    public function getDetails(string $externalId, array $options = []): array
    {
        $language = MetadataValue::asString($options['language'] ?? null, 'en-US');

        $append = 'credits,genres,production_companies,external_ids,keywords,release_dates,videos,images';
        $result = $this->http->getResult("/movie/{$externalId}", [
            'language' => $language,
            'append_to_response' => $append,
            // Title logos are language-tagged; ask for English plus the
            // language-neutral (`null`) set so `images.logos[]` comes back with
            // usable PNG candidates for the hero overlay (Phase C).
            'include_image_language' => 'en,null',
        ]);
        $response = $result->body();

        if ($response === null) {
            ProviderOutcomeLog::record($this->logger, 'TmdbProvider', 'getDetails', $result, [
                'tmdb_id' => $externalId,
                'endpoint' => "/movie/{$externalId}",
            ]);
            return [];
        }

        // P1-S4: Accept a configurable fallback chain instead of the hardcoded
        // [preferredLocale, 'en-US', originalLocale] chain. This allows the
        // server operator to define which locales TMDB should try for missing
        // titles/overviews/taglines via the metadata_language_preference config.
        $fallbackChain = MetadataValue::asList($options['language_fallback_chain'] ?? null);
        if ($fallbackChain !== []) {
            $this->applyLanguageFallbackChain($response, $externalId, $fallbackChain);
        } else {
            // Fall back to legacy single-locale behavior for backwards compat.
            $preferredLocale = MetadataValue::asNullableString($options['preferred_locale'] ?? null);
            if ($preferredLocale !== null) {
                $this->applyLocalizedFieldFallback($response, $externalId, $preferredLocale);
            }
        }

        $details = $this->formatMovieDetails($response);

        $this->logger->debug('TmdbProvider: getDetails', [
            'tmdb_id' => $externalId,
            'title' => $details['title'] ?? null,
            'endpoint' => "/movie/{$externalId}",
        ]);

        return $details;
    }

    /**
     * Normalize a 2-letter language code to a locale (e.g., en → en-US, de → de-DE).
     *
     * @param string $lang 2-letter language code
     * @return string Locale-formatted string
     */
    private function normalizeToLocale(string $lang): string
    {
        return strlen($lang) === 2 ? $lang . '-US' : $lang;
    }

    /**
     * Fill missing localizable fields (title, overview, tagline) by trying a
     * configurable fallback chain of locale codes.
     *
     * P1-S4: Replaces the legacy hardcoded chain with an operator-configured
     * list passed via the `language_fallback_chain` option. Each locale is tried
      * in order until all three fields are populated or the chain is exhausted.
      *
      * @param array<string, mixed> &$response   The response array to enrich (modified in place)
      * @param string              $externalId  TMDB movie ID for fallback requests
      * @param list<mixed>         $chain       Ordered list of locale codes to try
      */
    private function applyLanguageFallbackChain(array &$response, string $externalId, array $chain): void
    {
        $localizableFields = ['title', 'overview', 'tagline'];
        // Filter to only string locale codes at runtime (defense in depth)
        $chain = array_values(array_filter($chain, fn($v) => is_string($v)));
        $needsFallback = [];
        foreach ($localizableFields as $field) {
            if (empty($response[$field])) {
                $needsFallback[$field] = true;
            }
        }
        if ($needsFallback === []) {
            return;
        }
        foreach ($chain as $locale) {
            if ($locale === '' || $locale === 'en-US') {
                // Skip redundant or pointless locales in the chain
                continue;
            }
            $fallbackResponse = $this->http->get("/movie/{$externalId}", ['language' => $locale]);
            foreach (array_keys($needsFallback) as $field) {
                if (!empty($fallbackResponse[$field])) {
                    $response[$field] = $fallbackResponse[$field];
                    unset($needsFallback[$field]);
                }
            }
            if ($needsFallback === []) {
                break;
            }
        }
    }

    /**
     * Fill missing localizable fields (title, overview, tagline) by trying a fallback
     * chain: preferred_locale → en-US → original_language.
     *
     * @param array<string, mixed> &$response   The response array to enrich (modified in place)
     * @param string              $externalId  TMDB movie ID for fallback requests
     * @param string              $preferredLocale The user's preferred locale
     *
     * @deprecated P1-S4: Use applyLanguageFallbackChain() with a configurable chain instead.
     */
    private function applyLocalizedFieldFallback(array &$response, string $externalId, string $preferredLocale): void
    {
        $originalLanguage = MetadataValue::asString($response['original_language'] ?? null);
        $originalLocale = $originalLanguage !== '' ? $this->normalizeToLocale($originalLanguage) : null;

        $chain = array_unique([$preferredLocale, 'en-US', $originalLocale]);
        $localizableFields = ['title', 'overview', 'tagline'];
        $needsFallback = [];
        foreach ($localizableFields as $field) {
            if (empty($response[$field])) {
                $needsFallback[$field] = true;
            }
        }
        if ($needsFallback === []) {
            return;
        }
        foreach ($chain as $locale) {
            if ($locale === null || $locale === '') {
                continue;
            }
            $fallbackResponse = $this->http->get("/movie/{$externalId}", ['language' => $locale]);
            foreach (array_keys($needsFallback) as $field) {
                if (!empty($fallbackResponse[$field])) {
                    $response[$field] = $fallbackResponse[$field];
                    unset($needsFallback[$field]);
                }
            }
            if ($needsFallback === []) {
                break;
            }
        }
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

        $result = $this->http->getResult('/search/tv', $params);
        $response = $result->body();
        if ($response === null || !isset($response['results'])) {
            ProviderOutcomeLog::record($this->logger, 'TmdbProvider', 'searchTv', $result, [
                'query' => $query,
                'year' => $year,
                'endpoint' => '/search/tv',
            ]);
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

        $this->logger->debug('TmdbProvider: searchTv', [
            'query' => $query,
            'year' => $year,
            'result_count' => count($output),
            'endpoint' => '/search/tv',
        ]);

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
        $append = 'genres,external_ids,content_ratings,aggregate_credits,production_companies,keywords,videos,images';
        $result = $this->http->getResult("/tv/{$externalId}", [
            'language' => MetadataValue::asString($options['language'] ?? null, 'en-US'),
            'append_to_response' => $append,
            // See getDetails(): pull English + language-neutral logos so the
            // series carries a title logo for the hero overlay (Phase C).
            'include_image_language' => 'en,null',
        ]);
        $response = $result->body();
        if ($response === null) {
            ProviderOutcomeLog::record($this->logger, 'TmdbProvider', 'getTvDetails', $result, [
                'tmdb_id' => $externalId,
                'endpoint' => "/tv/{$externalId}",
            ]);
            return [];
        }

        $details = $this->formatTvDetails($response);

        $this->logger->debug('TmdbProvider: getTvDetails', [
            'tmdb_id' => $externalId,
            'name' => $details['name'] ?? null,
            'endpoint' => "/tv/{$externalId}",
        ]);

        return $details;
    }

    /**
     * Get a TV season's details + episode list from TMDB.
     *
     * `append_to_response=credits` pulls the season-level regular cast in the SAME
     * request (no extra HTTP round-trip). Each episode object in the season
     * response already carries its own `guest_stars` and `crew`, so per-episode
     * cast is (season regulars ∪ that episode's guest stars) and per-episode crew
     * is that episode's crew — richer than a per-episode `/episode/{e}` call and a
     * single request for the whole season.
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
     *         runtime: int,
     *         vote_average: float,
     *         cast: list<array{name: string, role: string, profile_url: string|null}>,
     *         crew: list<array{name: string, job: string, profile_url: string|null}>
     *     }>
     * } Season details (empty `episodes` when the season is unknown).
     */
    public function getTvSeason(string $externalId, int $seasonNumber, array $options = []): array
    {
        $result = $this->http->getResult("/tv/{$externalId}/season/{$seasonNumber}", [
            'language' => MetadataValue::asString($options['language'] ?? null, 'en-US'),
            'append_to_response' => 'credits',
        ]);
        $response = $result->body();
        if ($response === null) {
            ProviderOutcomeLog::record($this->logger, 'TmdbProvider', 'getTvSeason', $result, [
                'tmdb_id' => $externalId,
                'season' => $seasonNumber,
                'endpoint' => "/tv/{$externalId}/season/{$seasonNumber}",
            ]);
            return ['poster_path' => null, 'overview' => '', 'episodes' => []];
        }

        // Season-level regular cast (from the appended `credits.cast`), shared by
        // every episode as a base before each episode's own guest stars are folded in.
        $seasonCredits = MetadataValue::asAssoc($response['credits'] ?? null);
        $seasonCast = $this->buildEpisodeCast(MetadataValue::asAssocList($seasonCredits['cast'] ?? null));

        $episodes = [];
        foreach (MetadataValue::asAssocList($response['episodes'] ?? null) as $ep) {
            $guestStars = $this->buildEpisodeCast(MetadataValue::asAssocList($ep['guest_stars'] ?? null));
            $episodes[] = [
                'episode_number' => MetadataValue::asInt($ep['episode_number'] ?? null),
                'name' => MetadataValue::asString($ep['name'] ?? null),
                'overview' => MetadataValue::asString($ep['overview'] ?? null),
                'still_path' => MetadataValue::asNullableString($ep['still_path'] ?? null),
                'air_date' => MetadataValue::asString($ep['air_date'] ?? null),
                'runtime' => MetadataValue::asInt($ep['runtime'] ?? null),
                'vote_average' => MetadataValue::asFloat($ep['vote_average'] ?? null),
                'cast' => $this->mergeCast($seasonCast, $guestStars),
                'crew' => $this->buildEpisodeCrew(MetadataValue::asAssocList($ep['crew'] ?? null)),
            ];
        }

        $this->logger->debug('TmdbProvider: getTvSeason', [
            'tmdb_id' => $externalId,
            'season' => $seasonNumber,
            'episode_count' => count($episodes),
            'endpoint' => "/tv/{$externalId}/season/{$seasonNumber}",
        ]);

        return [
            'poster_path' => MetadataValue::asNullableString($response['poster_path'] ?? null),
            'overview' => MetadataValue::asString($response['overview'] ?? null),
            'episodes' => $episodes,
        ];
    }

    /**
     * Build rich cast objects from a TMDB season/episode `cast`/`guest_stars`
     * list. These entries carry `character` + `profile_path` DIRECTLY (movie-style),
     * unlike the series `aggregate_credits` shape ({@see self::buildTvCast()}).
     *
     * @param list<array<string, mixed>> $cast Raw TMDB cast/guest-star entries.
     * @return list<array{name: string, role: string, profile_url: string|null}>
     */
    private function buildEpisodeCast(array $cast): array
    {
        $out = [];
        foreach (array_slice($cast, 0, self::MAX_CAST) as $member) {
            $name = MetadataValue::asString($member['name'] ?? null);
            if ($name === '') {
                continue;
            }
            $out[] = [
                'name' => $name,
                'role' => MetadataValue::asString($member['character'] ?? null),
                'profile_url' => $this->profileUrl(
                    MetadataValue::asNullableString($member['profile_path'] ?? null),
                ),
            ];
        }
        return $out;
    }

    /**
     * Merge season regulars with an episode's guest stars, de-duplicating by
     * name (a guest star already listed as a regular is not repeated) and capping
     * at {@see self::MAX_CAST}.
     *
     * @param list<array{name: string, role: string, profile_url: string|null}> $base  Season regulars.
     * @param list<array{name: string, role: string, profile_url: string|null}> $guest Episode guest stars.
     * @return list<array{name: string, role: string, profile_url: string|null}>
     */
    private function mergeCast(array $base, array $guest): array
    {
        $out = [];
        $seen = [];
        foreach (array_merge($base, $guest) as $member) {
            // De-duplicate case- and whitespace-insensitively so a guest star already
            // listed as a season regular (e.g. "John Smith" vs " john smith ") is not
            // repeated. The first occurrence's original name/role is preserved.
            $key = mb_strtolower(trim($member['name']));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $member;
            if (count($out) >= self::MAX_CAST) {
                break;
            }
        }
        return $out;
    }

    /**
     * Build key-crew objects from a TMDB episode `crew` list. Episode crew entries
     * carry a single `job` field directly (movie-style); filtered to
     * {@see self::KEY_CREW_JOBS}, de-duplicated and capped by {@see self::filterKeyCrew()}.
     *
     * @param list<array<string, mixed>> $crew Raw TMDB episode crew entries.
     * @return list<array{name: string, job: string, profile_url: string|null}>
     */
    private function buildEpisodeCrew(array $crew): array
    {
        $rows = [];
        foreach ($crew as $member) {
            $rows[] = [
                'name' => MetadataValue::asString($member['name'] ?? null),
                'job' => MetadataValue::asString($member['job'] ?? null),
                'profile_path' => MetadataValue::asNullableString($member['profile_path'] ?? null),
            ];
        }
        return $this->filterKeyCrew($rows);
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
        // TheTVDB series id (from `append_to_response=external_ids`). TMDB stores
        // it as an integer under `tvdb_id`; kept as a string in the record so the
        // resolver can thread it into `metadata_json.external_ids.tvdb` for the
        // theme-music (M3) Plex-archive lookup. Non-numeric/absent → null.
        $tvdbId = MetadataValue::asNullableString($externalIds['tvdb_id'] ?? null);

        // Recurring series cast (TMDB `aggregate_credits`, order-sorted), reduced
        // to the top-billed names. `aggregate_credits` entries carry `name` +
        // `roles[]`; actorNames() only needs `name`.
        $aggregateCredits = MetadataValue::asAssoc($data['aggregate_credits'] ?? null);
        $cast = MetadataValue::asAssocList($aggregateCredits['cast'] ?? null);
        $actors = MetadataValue::actorNames(array_slice($cast, 0, self::MAX_CAST));

        // Rich cast objects with profile photos for the media-detail page. The
        // flat `actors` list above is UNCHANGED (cards + the `$.actors[*]`
        // filter depend on it); these are additive.
        $castObjects = $this->buildTvCast($cast);

        // Key crew. `aggregate_credits.crew` entries carry `jobs[]`
        // ([{job, …}, …]) rather than a single `job`; the series-level
        // `created_by[]` is folded in as job "Creator".
        $crew = MetadataValue::asAssocList($aggregateCredits['crew'] ?? null);
        $crewObjects = $this->buildTvCrew($crew, MetadataValue::asAssocList($data['created_by'] ?? null));

        // Studios/networks. For TV, `networks` (the broadcaster) is just as
        // studio-like as `production_companies`, so both feed the list and the
        // single `studio` string.
        $companies = $this->buildProductionCompanies(array_merge(
            MetadataValue::asAssocList($data['production_companies'] ?? null),
            MetadataValue::asAssocList($data['networks'] ?? null),
        ));
        $studio = $companies[0]['name'] ?? null;

        // Series-level tags. TMDB TV `keywords` are nested under `keywords.results[]`
        // (movies use `keywords.keywords[]`); each is `{id, name}`. These flow to
        // the series record and are inherited by its episodes.
        $tags = $this->extractKeywords($data['keywords'] ?? null);

        // Primary trailer (append_to_response=videos). Null when the series has no
        // usable YouTube trailer/teaser; the trailer_* keys are then omitted so the
        // resolver/shaper leave the field absent rather than a broken URL.
        $trailer = $this->extractPrimaryTrailer($data['videos'] ?? null);

        // Title logo (append_to_response=images). Null when the series has no
        // usable logo; the logo_* keys are then omitted so the resolver/shaper
        // leave the field absent rather than a broken URL.
        $logo = $this->selectLogo($data['images'] ?? null);

        return array_merge($trailer ?? [], $logo ?? [], [
            'name' => MetadataValue::asString($data['name'] ?? ($data['original_name'] ?? null)),
            'original_name' => MetadataValue::asString($data['original_name'] ?? null),
            'overview' => MetadataValue::asString($data['overview'] ?? null),
            'official_rating' => $this->extractUsContentRating($data['content_ratings'] ?? null),
            'vote_average' => MetadataValue::asFloat($data['vote_average'] ?? null),
            'year' => $year,
            'genres' => $genreNames,
            'tags' => $tags,
            'actors' => $actors,
            'cast' => $castObjects,
            'crew' => $crewObjects,
            'production_companies' => $companies,
            'studio' => $studio,
            'tmdb_id' => MetadataValue::asNullableString($data['id'] ?? null),
            'imdb_id' => $imdbId,
            'tvdb_id' => $tvdbId,
            'poster_path' => MetadataValue::asNullableString($data['poster_path'] ?? null),
            'backdrop_path' => MetadataValue::asNullableString($data['backdrop_path'] ?? null),
            'number_of_seasons' => MetadataValue::asInt($data['number_of_seasons'] ?? null),
        ]);
    }

    /**
     * Build rich cast objects from a TMDB `aggregate_credits.cast` list.
     *
     * `aggregate_credits` entries carry `name`, `profile_path`, an `order`, and
     * a `roles[]` array whose first entry's `character` is the displayed role
     * (a recurring actor may play several characters across seasons).
     *
     * @param list<array<string, mixed>> $cast Raw TMDB aggregate cast entries.
     * @return list<array{name: string, role: string, profile_url: string|null}>
     */
    private function buildTvCast(array $cast): array
    {
        $out = [];
        foreach (array_slice($cast, 0, self::MAX_CAST) as $member) {
            $name = MetadataValue::asString($member['name'] ?? null);
            if ($name === '') {
                continue;
            }
            $roles = MetadataValue::asAssocList($member['roles'] ?? null);
            $role = MetadataValue::asString($roles[0]['character'] ?? null);
            $out[] = [
                'name' => $name,
                'role' => $role,
                'profile_url' => $this->profileUrl(
                    MetadataValue::asNullableString($member['profile_path'] ?? null),
                ),
            ];
        }
        return $out;
    }

    /**
     * Build key-crew objects from a TMDB `aggregate_credits.crew` list plus the
     * series-level `created_by[]` (mapped to job "Creator").
     *
     * `aggregate_credits.crew` entries carry `jobs[]` ([{job, …}, …]); the
     * single `job` field is used as a fallback. Filtered to {@see self::KEY_CREW_JOBS},
     * de-duplicated by name+job and capped at {@see self::MAX_CREW}.
     *
     * @param list<array<string, mixed>> $crew      Raw TMDB aggregate crew entries.
     * @param list<array<string, mixed>> $createdBy Raw TMDB `created_by` entries.
     * @return list<array{name: string, job: string, profile_url: string|null}>
     */
    private function buildTvCrew(array $crew, array $createdBy): array
    {
        $normalized = [];
        foreach ($createdBy as $creator) {
            $normalized[] = [
                'name' => MetadataValue::asString($creator['name'] ?? null),
                'job' => 'Creator',
                'profile_path' => MetadataValue::asNullableString($creator['profile_path'] ?? null),
            ];
        }
        foreach ($crew as $member) {
            $jobs = MetadataValue::asAssocList($member['jobs'] ?? null);
            $job = MetadataValue::asString($jobs[0]['job'] ?? ($member['job'] ?? null));
            $normalized[] = [
                'name' => MetadataValue::asString($member['name'] ?? null),
                'job' => $job,
                'profile_path' => MetadataValue::asNullableString($member['profile_path'] ?? null),
            ];
        }
        return $this->filterKeyCrew($normalized);
    }

    /**
     * Filter/normalize a flat list of `{name, job, profile_path}` crew rows to
     * the key-crew allow-list, de-duplicating by name+job and capping the count.
     *
     * @param list<array{name: string, job: string, profile_path: string|null}> $rows
     * @return list<array{name: string, job: string, profile_url: string|null}>
     */
    private function filterKeyCrew(array $rows): array
    {
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $name = $row['name'];
            $job = $row['job'];
            if ($name === '' || !in_array($job, self::KEY_CREW_JOBS, true)) {
                continue;
            }
            $key = $name . '|' . $job;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'name' => $name,
                'job' => $job,
                'profile_url' => $this->profileUrl($row['profile_path']),
            ];
            if (count($out) >= self::MAX_CREW) {
                break;
            }
        }
        return $out;
    }

    /**
     * Build production-company objects from a TMDB `production_companies` (or
     * TV `networks`) list. Entries without a name are skipped.
     *
     * @param list<array<string, mixed>> $companies Raw TMDB company entries.
     * @return list<array{name: string, logo_url: string|null, origin_country: string|null}>
     */
    private function buildProductionCompanies(array $companies): array
    {
        $out = [];
        $seen = [];
        foreach ($companies as $company) {
            $name = MetadataValue::asString($company['name'] ?? null);
            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $out[] = [
                'name' => $name,
                'logo_url' => $this->logoUrl(
                    MetadataValue::asNullableString($company['logo_path'] ?? null),
                ),
                'origin_country' => MetadataValue::asNullableString($company['origin_country'] ?? null),
            ];
        }
        return $out;
    }

    /**
     * Build a full TMDB profile-photo URL (w185) from a `/path.jpg` fragment.
     *
     * @param string|null $path Raw TMDB `profile_path`.
     * @return string|null Full URL, or null when no path is present.
     */
    private function profileUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        return $this->imageBaseUrl . '/w185' . $path;
    }

    /**
     * Build a full TMDB logo URL (w185) from a `/path.png` fragment.
     *
     * @param string|null $path Raw TMDB `logo_path`.
     * @return string|null Full URL, or null when no path is present.
     */
    private function logoUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        return $this->imageBaseUrl . '/w185' . $path;
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
     * Pull the US MPAA certification (e.g. `PG-13`) from a movie
     * `release_dates` block (TMDB `append_to_response=release_dates`).
     *
     * The block is `{results: [{iso_3166_1, release_dates: [{certification,
     * type, …}]}]}`. Within the US entry a movie can carry several dated
     * releases (premiere/theatrical/digital/…), only some of which name a
     * certification; the theatrical release (`type === 3`) is preferred, then
     * any dated release with a non-empty certification. Returns the raw
     * certification string (normalization to the canonical enum happens at the
     * storage/display boundary) or null when none is present.
     *
     * @param mixed $releaseDates Raw `release_dates` payload.
     * @return string|null The US certification, or null when absent.
     */
    private function extractUsMovieCertification(mixed $releaseDates): ?string
    {
        $block = MetadataValue::asAssoc($releaseDates);
        // Scan EVERY US `results[]` entry (TMDB usually emits one, but a movie
        // can carry several): a theatrical (type 3) cert wins outright, else the
        // first non-empty cert seen across all US entries is the fallback. We do
        // NOT return early on a US entry whose certs are all empty — a later US
        // entry may still name one.
        $fallback = null;
        foreach (MetadataValue::asAssocList($block['results'] ?? null) as $row) {
            if (MetadataValue::asString($row['iso_3166_1'] ?? null) !== 'US') {
                continue;
            }

            $entries = MetadataValue::asAssocList($row['release_dates'] ?? null);
            foreach ($entries as $entry) {
                $cert = MetadataValue::asString($entry['certification'] ?? null);
                if ($cert === '') {
                    continue;
                }
                if (MetadataValue::asInt($entry['type'] ?? null) === 3) {
                    return $cert;
                }
                $fallback ??= $cert;
            }
        }
        return $fallback;
    }

    /**
     * Pull tag names from a TMDB `keywords` block.
     *
     * TMDB nests TV keywords under `keywords.results[]` and movie keywords under
     * `keywords.keywords[]`; both entry shapes are `{id, name}`. Names are
     * de-duplicated and blanks dropped so only usable tags survive.
     *
     * @param mixed $keywords Raw `keywords` payload.
     * @return list<string> De-duplicated tag names (possibly empty).
     */
    private function extractKeywords(mixed $keywords): array
    {
        $block = MetadataValue::asAssoc($keywords);
        $entries = MetadataValue::asAssocList($block['results'] ?? null);
        if ($entries === []) {
            $entries = MetadataValue::asAssocList($block['keywords'] ?? null);
        }

        $out = [];
        foreach ($entries as $entry) {
            $name = MetadataValue::asString($entry['name'] ?? null);
            if ($name !== '' && !in_array($name, $out, true)) {
                $out[] = $name;
            }
        }
        return $out;
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

        // Rich cast objects with profile photos for the media-detail page.
        // `actors` (above) is left UNCHANGED — the resolver flattens it to names
        // and the `$.actors[*]` filter / SPA chips depend on that shape; `cast`
        // is additive. Movie `/credits.cast` carries `character` directly (vs
        // TV's `roles[].character`).
        $castObjects = [];
        foreach (array_slice($cast, 0, self::MAX_CAST) as $member) {
            $name = MetadataValue::asString($member['name'] ?? null);
            if ($name === '') {
                continue;
            }
            $castObjects[] = [
                'name' => $name,
                'role' => MetadataValue::asString($member['character'] ?? null),
                'profile_url' => $this->profileUrl(
                    MetadataValue::asNullableString($member['profile_path'] ?? null),
                ),
            ];
        }

        // Key crew. Movie `/credits.crew` entries carry a single `job` field.
        $crewRows = [];
        foreach ($crew as $member) {
            $crewRows[] = [
                'name' => MetadataValue::asString($member['name'] ?? null),
                'job' => MetadataValue::asString($member['job'] ?? null),
                'profile_path' => MetadataValue::asNullableString($member['profile_path'] ?? null),
            ];
        }
        $crewObjects = $this->filterKeyCrew($crewRows);

        $companies = $this->buildProductionCompanies($studios);

        // Movie-level tags. TMDB movie `keywords` are nested under
        // `keywords.keywords[]` (TV uses `keywords.results[]`); each is
        // `{id, name}`. extractKeywords() handles both shapes, mirroring TV.
        $tags = $this->extractKeywords($data['keywords'] ?? null);

        // Primary trailer (append_to_response=videos). Null when the movie has no
        // usable YouTube trailer/teaser; the trailer_* keys are then omitted so the
        // canonical resolver/shaper leave the field absent rather than a broken URL.
        $trailer = $this->extractPrimaryTrailer($data['videos'] ?? null);

        // Title logo (append_to_response=images). Null when the movie has no usable
        // logo; the logo_* keys are then omitted so the canonical resolver/shaper
        // leave the field absent rather than a broken URL.
        $logo = $this->selectLogo($data['images'] ?? null);

        return array_merge($trailer ?? [], $logo ?? [], [
            'name' => MetadataValue::asString(
                $data['title'] ?? ($data['name'] ?? null)
            ),
            'original_name' => MetadataValue::asString(
                $data['original_title'] ?? ($data['original_name'] ?? null)
            ),
            'overview' => MetadataValue::asString($data['overview'] ?? null),
            'official_rating' => $this->extractUsMovieCertification($data['release_dates'] ?? null),
            'vote_average' => MetadataValue::asFloat($data['vote_average'] ?? null),
            'vote_count' => MetadataValue::asInt($data['vote_count'] ?? null),
            'year' => $year,
            'runtime_ticks' => $runtime * 600000000, // Convert minutes to ticks
            'genres' => $genreNames,
            'tags' => $tags,
            'studio' => $studio,
            'tagline' => MetadataValue::asString($data['tagline'] ?? null),
            'budget' => MetadataValue::asInt($data['budget'] ?? null),
            'revenue' => MetadataValue::asInt($data['revenue'] ?? null),
            'imdb_id' => $imdbId,
            'tmdb_id' => MetadataValue::asNullableString($data['id'] ?? null),
            'poster_path' => MetadataValue::asNullableString($data['poster_path'] ?? null),
            'backdrop_path' => MetadataValue::asNullableString($data['backdrop_path'] ?? null),
            'actors' => $actors,
            'cast' => $castObjects,
            'crew' => $crewObjects,
            'production_companies' => $companies,
            'director' => $this->findDirector($crew),
        ]);
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
     * Get collection (box-set) details from TMDB.
     *
     * Fetches the /collection/{id} endpoint to retrieve collection metadata
     * including name, overview, and the ordered list of parts.
     *
     * @param int $collectionId TMDB collection ID
     * @return array{name: string, overview: string|null, poster_path: string|null,
     *     backdrop_path: string|null, parts: array<int, array{id: int, title: string,
     *     overview: string|null, poster_path: string|null, backdrop_path: string|null,
     *     release_date: string, vote_average: float}>}|null Collection details or null on failure
     *
     * @since 0.36.0
     */
    public function getCollection(int $collectionId): ?array
    {
        $response = $this->http->get("/collection/{$collectionId}");

        if ($response === null || !isset($response['id'])) {
            return null;
        }

        $parts = [];
        foreach (MetadataValue::asAssocList($response['parts'] ?? null) as $part) {
            $parts[] = [
                'id' => MetadataValue::asInt($part['id'] ?? null),
                'title' => MetadataValue::asString($part['title'] ?? null),
                'overview' => MetadataValue::asNullableString($part['overview'] ?? null),
                'poster_path' => MetadataValue::asNullableString($part['poster_path'] ?? null),
                'backdrop_path' => MetadataValue::asNullableString($part['backdrop_path'] ?? null),
                'release_date' => MetadataValue::asString($part['release_date'] ?? null),
                'vote_average' => MetadataValue::asFloat($part['vote_average'] ?? null),
            ];
        }

        return [
            'name' => MetadataValue::asString($response['name'] ?? null),
            'overview' => MetadataValue::asNullableString($response['overview'] ?? null),
            'poster_path' => MetadataValue::asNullableString($response['poster_path'] ?? null),
            'backdrop_path' => MetadataValue::asNullableString($response['backdrop_path'] ?? null),
            'parts' => $parts,
        ];
    }

    /**
     * Get the TMDB collection ID for a movie.
     *
     * Fetches /movie/{id} and returns the belongs_to_collection.id field
     * if the movie is part of a box-set/collection.
     *
     * @param int $tmdbId TMDB movie ID
     * @return int|null The collection ID if the movie belongs to a collection, null otherwise
     *
     * @since 0.36.0
     */
    public function getCollectionIdForMovie(int $tmdbId): ?int
    {
        $response = $this->http->get("/movie/{$tmdbId}");

        if ($response === null) {
            return null;
        }

        $belongsToCollection = MetadataValue::asAssoc($response['belongs_to_collection'] ?? null);
        if ($belongsToCollection === []) {
            return null;
        }

        $id = MetadataValue::asInt($belongsToCollection['id'] ?? null);
        return $id > 0 ? $id : null;
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
            $entry = $this->normalizeVideoEntry($video);
            if ($entry === null) {
                continue; // Skip non-YouTube / non-trailer-or-teaser videos
            }

            $trailers[] = [
                'title' => ucfirst($entry['type']) . ' (' . $entry['name'] . ')',
                'url' => 'https://www.youtube.com/watch?v=' . $entry['key'],
                'duration' => 0, // TMDB doesn't provide duration
                'quality' => 0, // Unknown until played
            ];
        }

        return $trailers;
    }

    /**
     * Normalize a single raw TMDB `videos.results[]` entry to a compact shape,
     * or null when it is not a usable YouTube trailer/teaser.
     *
     * Shared by {@see self::getTrailers()} (the on-demand extras list) and
     * {@see self::extractPrimaryTrailer()} (the single scan-time trailer captured
     * onto movie/series metadata) so the YouTube/type parsing stays identical.
     *
     * @param mixed $video Raw video entry (any type; non-arrays are rejected).
     * @return array{type: string, site_label: string, key: string, name: string, official: bool}|null
     */
    private function normalizeVideoEntry(mixed $video): ?array
    {
        if (!is_array($video)) {
            return null;
        }

        // Only trailers (type=Trailer) and teasers (type=Teaser) are usable.
        $typeRaw = $video['type'] ?? '';
        $type = is_string($typeRaw) ? strtolower($typeRaw) : '';
        if ($type !== 'trailer' && $type !== 'teaser') {
            return null;
        }

        // YouTube only (the URL scheme the player understands).
        $siteRaw = $video['site'] ?? '';
        if (!is_string($siteRaw) || strtolower($siteRaw) !== 'youtube') {
            return null;
        }

        $keyRaw = $video['key'] ?? '';
        $key = is_string($keyRaw) ? $keyRaw : '';
        if ($key === '') {
            return null; // No key → no playable URL.
        }
        // Validate the YouTube id charset before it is interpolated into a
        // `youtube.com/watch?v={key}` URL. Real ids are 11 chars of
        // [A-Za-z0-9_-]; a 20-char ceiling stays safe while rejecting keys that
        // could smuggle a quote/query/markup into the URL a client renders
        // (open-redirect / javascript: / XSS defence).
        if (preg_match('/^[A-Za-z0-9_-]{1,20}$/', $key) !== 1) {
            return null;
        }

        $nameRaw = $video['name'] ?? $type;
        $name = is_string($nameRaw) ? $nameRaw : $type;

        return [
            'type' => $type,
            'site_label' => 'YouTube',
            'key' => $key,
            'name' => $name,
            'official' => ($video['official'] ?? false) === true,
        ];
    }

    /**
     * Pick the single PRIMARY trailer from a TMDB `videos` block (as delivered by
     * `append_to_response=videos` on movie/TV details).
     *
     * Preference order: an official YouTube Trailer → any YouTube Trailer → any
     * YouTube Teaser → none. Returns the fields captured onto item metadata so a
     * client can render a "Play Trailer" button, or null when the item has no
     * usable video (the caller then omits the keys — never a broken/empty URL).
     *
     * @param mixed $videos Raw `videos` payload.
     * @return array{trailer_key: string, trailer_site: string, trailer_url: string}|null
     */
    private function extractPrimaryTrailer(mixed $videos): ?array
    {
        $block = MetadataValue::asAssoc($videos);
        $results = MetadataValue::asAssocList($block['results'] ?? null);

        $officialTrailer = null;
        $anyTrailer = null;
        $anyTeaser = null;
        foreach ($results as $video) {
            $entry = $this->normalizeVideoEntry($video);
            if ($entry === null) {
                continue;
            }
            if ($entry['type'] === 'trailer') {
                if ($entry['official'] && $officialTrailer === null) {
                    $officialTrailer = $entry;
                }
                $anyTrailer ??= $entry;
            } else { // teaser
                $anyTeaser ??= $entry;
            }
        }

        $chosen = $officialTrailer ?? $anyTrailer ?? $anyTeaser;
        if ($chosen === null) {
            return null;
        }

        return [
            'trailer_key' => $chosen['key'],
            'trailer_site' => $chosen['site_label'],
            'trailer_url' => 'https://www.youtube.com/watch?v=' . $chosen['key'],
        ];
    }

    /**
     * Pick the single PRIMARY title logo from a TMDB `images` block (as delivered
     * by `append_to_response=images&include_image_language=en,null` on movie/TV
     * details).
     *
     * A title logo is transparent art (the styled title treatment) a client can
     * overlay on the hero backdrop. For a raster, transparency-safe LOCAL cache we
     * PREFER a PNG `file_path` (TMDB logos may be `.svg` or `.png`): when any PNG
     * candidate exists the pool is restricted to PNGs; otherwise the SVG pool is
     * used and its URL is still surfaced (the caller exposes the TMDB SVG URL but
     * does NOT rasterize it locally). Within the pool, an `en` logo wins over a
     * language-neutral (`null`) one, which wins over any other language; ties break
     * on the highest `vote_average`.
     *
     * Returns the fields captured onto item metadata (`logo_path` = the raw TMDB
     * path, `logo_url` = the full `/original` URL), or null when the item has no
     * usable logo (the caller then omits the keys — never a broken/empty URL).
     *
     * @param mixed $images Raw `images` payload.
     * @return array{logo_path: string, logo_url: string}|null
     */
    private function selectLogo(mixed $images): ?array
    {
        $block = MetadataValue::asAssoc($images);
        $logos = MetadataValue::asAssocList($block['logos'] ?? null);
        if ($logos === []) {
            return null;
        }

        $candidates = [];
        foreach ($logos as $logo) {
            $path = MetadataValue::asString($logo['file_path'] ?? null);
            if ($path === '') {
                continue;
            }
            $candidates[] = [
                'path' => $path,
                'is_png' => preg_match('/\.png$/i', $path) === 1,
                'lang' => MetadataValue::asNullableString($logo['iso_639_1'] ?? null),
                'vote' => MetadataValue::asFloat($logo['vote_average'] ?? null),
            ];
        }
        if ($candidates === []) {
            return null;
        }

        // Prefer PNG (raster, transparency-safe local cache). Restrict the pool to
        // PNGs when any exist; fall back to the full (SVG) pool otherwise.
        $pngs = array_values(array_filter($candidates, static fn(array $c): bool => $c['is_png']));
        $pool = $pngs !== [] ? $pngs : $candidates;

        usort($pool, function (array $a, array $b): int {
            $rank = $this->logoLanguageRank($a['lang']) <=> $this->logoLanguageRank($b['lang']);
            if ($rank !== 0) {
                return $rank;
            }
            return $b['vote'] <=> $a['vote'];
        });

        $chosen = $pool[0];

        return [
            'logo_path' => $chosen['path'],
            'logo_url' => $this->imageBaseUrl . '/original' . $chosen['path'],
        ];
    }

    /**
     * Language-preference rank for logo selection: `en` (0) beats a
     * language-neutral (`null`/empty) logo (1), which beats any other language (2).
     * Lower is preferred.
     *
     * @param string|null $lang TMDB `iso_639_1` of the logo (null = language-neutral).
     */
    private function logoLanguageRank(?string $lang): int
    {
        if ($lang === 'en') {
            return 0;
        }
        if ($lang === null || $lang === '') {
            return 1;
        }
        return 2;
    }
}
