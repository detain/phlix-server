<?php

declare(strict_types=1);

namespace Phlex\Media\Metadata\Provider;

use Phlex\Media\Metadata\MetadataHttpClient;
use Phlex\Media\Metadata\MetadataProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * MusicBrainzProvider fetches music metadata from the MusicBrainz API.
 *
 * This provider supports searching for artists, albums, and tracks, as well
 * as retrieving detailed information about each. MusicBrainz is a public API
 * that requires a proper User-Agent header and enforces rate limiting (1 req/sec).
 *
 * @author Phlex Development Team
 * @version 1.0.0
 * @since 0.13.0
 * @see https://musicbrainz.org/doc/Development/XML_Web_Service/Version_2
 */
class MusicBrainzProvider implements MetadataProviderInterface
{
    use MusicMetadataProviderTrait;

    /** @var MetadataHttpClient HTTP client for MusicBrainz API requests */
    private MetadataHttpClient $http;

    /** @var string User-agent string for MusicBrainz API requests */
    private string $userAgent;

    /** @var string Base URL for MusicBrainz API */
    private const BASE_URL = 'https://musicbrainz.org/ws/2';

    /** @var int Default search limit */
    private const DEFAULT_LIMIT = 20;

    /**
     * Constructor for MusicBrainzProvider.
     *
     * @param MetadataHttpClient $http HTTP client for API requests
     * @param string $userAgent User-agent string (e.g., 'Phlex/1.0 (https://phlex.media)')
     * @param LoggerInterface|null $logger Optional logger instance
     */
    public function __construct(
        MetadataHttpClient $http,
        string $userAgent = 'Phlex/1.0 (https://phlex.media)',
        ?LoggerInterface $logger = null
    ) {
        $this->http = $http;
        $this->userAgent = $userAgent;
        if ($logger) {
            $this->setLogger($logger);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function supports(string $media_type): bool
    {
        return in_array($media_type, [
            self::MEDIA_TYPE_ALBUM,
            self::MEDIA_TYPE_ARTIST,
            self::MEDIA_TYPE_TRACK,
        ], true);
    }

    /**
     * Search for music entities by query.
     *
     * @param string $query Search query
     * @param array<string, mixed> $options Search options (limit, type, year, entity)
     * @return array<int, array<string, mixed>> Search results
     */
    public function search(string $query, array $options = []): array
    {
        $limit = $options['limit'] ?? self::DEFAULT_LIMIT;
        $type = $options['type'] ?? null;
        $entity = is_string($options['entity'] ?? null) ? $options['entity'] : 'artist';

        $this->rateLimit(1.0);

        $params = [
            'query' => $query,
            'limit' => min($limit, 100),
            'fmt' => 'json',
        ];

        if ($type) {
            $params['type'] = $type;
        }

        try {
            $response = $this->http->get(self::BASE_URL . '/' . $entity, $params, $this->mbHeaders($this->userAgent));

            if (!is_array($response) || !isset($response['entities']) || !is_array($response['entities'])) {
                return [];
            }

            /** @var array<int, array<string, mixed>> $entities */
            $entities = $response['entities'];

            /** @var array<int, array<string, mixed>> $results */
            $results = [];

            foreach ($entities as $result) {
                if (!is_array($result)) {
                    continue;
                }
                $lifeSpan = is_array($result['life-span'] ?? null) ? $result['life-span'] : [];
                $beginYear = $lifeSpan['begin'] ?? null;
                $results[] = [
                    'id' => is_string($result['id'] ?? null) ? $result['id'] : '',
                    'title' => is_string($result['name'] ?? null) ? $result['name'] : '',
                    'type' => is_string($result['type'] ?? null) ? $result['type'] : null,
                    'year' => is_string($beginYear) ? (int) substr($beginYear, 0, 4) : null,
                    'score' => is_int($result['score'] ?? null) ? $result['score'] : 0,
                ];
            }

            return $results;
        } catch (\Throwable $e) {
            $this->getLogger()->error('MusicBrainz search failed', [
                'query' => $query,
                'entity' => $entity,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getDetails(string $externalId, array $options = []): array
    {
        $entity = is_string($options['entity'] ?? null) ? $options['entity'] : 'artist';

        $details = match ($entity) {
            'artist' => $this->getArtist($externalId),
            'album', 'release' => $this->getAlbum($externalId),
            'track', 'recording' => $this->getTrack($externalId),
            default => $this->getArtist($externalId),
        };

        return $details ?? [];
    }

    /**
     * {@inheritdoc}
     */
    public function getImages(string $externalId): array
    {
        return [
            'posters' => [],
            'backdrops' => [],
            'logos' => [],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getProviders(): array
    {
        return ['musicbrainz'];
    }

    /**
     * {@inheritdoc}
     */
    public function getSourceName(): string
    {
        return 'musicbrainz';
    }

    /**
     * Get detailed artist information from MusicBrainz.
     *
     * @param string $mbid MusicBrainz artist ID
     * @return array<string, mixed>|null Artist details or null on failure
     */
    public function getArtist(string $mbid): ?array
    {
        $this->rateLimit(1.0);

        try {
            /** @var array<string, mixed>|null $response */
            $response = $this->http->get(
                self::BASE_URL . '/artist/' . $mbid,
                ['fmt' => 'json'],
                $this->mbHeaders($this->userAgent)
            );

            if (!is_array($response)) {
                return null;
            }

            $tags = [];
            if (isset($response['tags']) && is_array($response['tags'])) {
                foreach ($response['tags'] as $tag) {
                    if (is_array($tag) && isset($tag['name'])) {
                        $tags[] = $tag['name'];
                    }
                }
            }

            return [
                'mbid' => is_string($response['id'] ?? null) ? $response['id'] : $mbid,
                'name' => is_string($response['name'] ?? null) ? $response['name'] : '',
                'sort_name' => is_string($response['sort-name'] ?? null) ? $response['sort-name'] : null,
                'country' => is_string($response['country'] ?? null) ? $response['country'] : null,
                'disambiguation' => is_string($response['disambiguation'] ?? null) ? $response['disambiguation'] : null,
                'tags' => $tags,
                'biography' => is_string($response['disambiguation'] ?? null) ? $response['disambiguation'] : null,
                'life_span' => is_array($response['life-span'] ?? null) ? $response['life-span'] : null,
            ];
        } catch (\Throwable $e) {
            $this->getLogger()->error('MusicBrainz getArtist failed', [
                'mbid' => $mbid,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get detailed album/release information from MusicBrainz.
     *
     * @param string $mbid MusicBrainz release ID
     * @return array<string, mixed>|null Album details or null on failure
     */
    public function getAlbum(string $mbid): ?array
    {
        $this->rateLimit(1.0);

        try {
            /** @var array<string, mixed>|null $response */
            $response = $this->http->get(
                self::BASE_URL . '/release/' . $mbid,
                ['fmt' => 'json', 'inc' => 'recordings+artists'],
                $this->mbHeaders($this->userAgent)
            );

            if (!is_array($response)) {
                return null;
            }

            $tracks = [];
            $media = $response['media'] ?? null;
            $mediaFirst = is_array($media) ? ($media[0] ?? null) : null;
            $mediaTracks = is_array($mediaFirst) ? ($mediaFirst['tracks'] ?? null) : null;
            if (is_array($mediaTracks)) {
                foreach ($mediaTracks as $track) {
                    if (!is_array($track)) {
                        continue;
                    }
                    $tracks[] = [
                        'mbid' => is_string($track['id'] ?? null) ? $track['id'] : '',
                        'title' => is_string($track['title'] ?? null) ? $track['title'] : '',
                        'duration' => is_int($track['length'] ?? null) ? $track['length'] : 0,
                        'position' => is_int($track['position'] ?? null) ? $track['position'] : 0,
                    ];
                }
            }

            $artistCredit = $response['artist-credit'] ?? null;
            $artistMbid = null;
            $artistName = null;
            $artistCreditFirst = is_array($artistCredit) ? ($artistCredit[0] ?? null) : null;
            $artistData = is_array($artistCreditFirst) ? ($artistCreditFirst['artist'] ?? null) : null;
            if (is_array($artistData)) {
                $artistMbid = is_string($artistData['id'] ?? null) ? $artistData['id'] : null;
                $artistName = is_string($artistData['name'] ?? null) ? $artistData['name'] : null;
            }

            return [
                'mbid' => is_string($response['id'] ?? null) ? $response['id'] : $mbid,
                'title' => is_string($response['title'] ?? null) ? $response['title'] : '',
                'artist_mbid' => $artistMbid,
                'artist_name' => $artistName,
                'year' => isset($response['date']) && is_string($response['date'])
                    ? (int) substr($response['date'], 0, 4) : null,
                'genre' => null,
                'tracks' => $tracks,
            ];
        } catch (\Throwable $e) {
            $this->getLogger()->error('MusicBrainz getAlbum failed', [
                'mbid' => $mbid,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get detailed track/recording information from MusicBrainz.
     *
     * @param string $mbid MusicBrainz recording ID
     * @return array<string, mixed>|null Track details or null on failure
     */
    public function getTrack(string $mbid): ?array
    {
        $this->rateLimit(1.0);

        try {
            /** @var array<string, mixed>|null $response */
            $response = $this->http->get(
                self::BASE_URL . '/recording/' . $mbid,
                ['fmt' => 'json', 'inc' => 'artists+releases'],
                $this->mbHeaders($this->userAgent)
            );

            if (!is_array($response)) {
                return null;
            }

            $albumMbid = null;
            $albumName = null;
            $releases = $response['releases'] ?? null;
            $firstRelease = is_array($releases) ? ($releases[0] ?? null) : null;
            if (is_array($firstRelease)) {
                $albumMbid = is_string($firstRelease['id'] ?? null) ? $firstRelease['id'] : null;
                $albumName = is_string($firstRelease['title'] ?? null) ? $firstRelease['title'] : null;
            }

            $artistMbid = null;
            $artistName = null;
            $artistCredit = $response['artist-credit'] ?? null;
            $artistCreditFirst = is_array($artistCredit) ? ($artistCredit[0] ?? null) : null;
            $artistData = is_array($artistCreditFirst) ? ($artistCreditFirst['artist'] ?? null) : null;
            if (is_array($artistData)) {
                $artistMbid = is_string($artistData['id'] ?? null) ? $artistData['id'] : null;
                $artistName = is_string($artistData['name'] ?? null) ? $artistData['name'] : null;
            }

            return [
                'mbid' => is_string($response['id'] ?? null) ? $response['id'] : $mbid,
                'title' => is_string($response['title'] ?? null) ? $response['title'] : '',
                'duration' => is_int($response['length'] ?? null) ? $response['length'] : 0,
                'artist_mbid' => $artistMbid,
                'artist_name' => $artistName,
                'album_mbid' => $albumMbid,
                'album_name' => $albumName,
                'position' => null,
            ];
        } catch (\Throwable $e) {
            $this->getLogger()->error('MusicBrainz getTrack failed', [
                'mbid' => $mbid,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
