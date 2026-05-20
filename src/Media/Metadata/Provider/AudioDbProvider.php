<?php

declare(strict_types=1);

namespace Phlix\Media\Metadata\Provider;

use Phlix\Media\Metadata\MetadataHttpClient;
use Phlix\Media\Metadata\MetadataProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * AudioDbProvider fetches music metadata from the AudioDB API.
 *
 * This provider supports searching for artists, albums, and tracks, as well
 * as retrieving detailed information about each. AudioDB requires an API key
 * and is more lenient on rate limiting than MusicBrainz.
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @since 0.13.0
 * @see https://www.theaudiodb.com/api.php
 */
class AudioDbProvider implements MetadataProviderInterface
{
    use MusicMetadataProviderTrait;

    /** @var MetadataHttpClient HTTP client for AudioDB API requests */
    private MetadataHttpClient $http;

    /** @var string AudioDB API key */
    private string $apiKey;

    /** @var string Base URL for AudioDB API */
    private const BASE_URL = 'https://theaudiodb.com/api/v1/json/2';

    /** @var int Default search limit */
    private const DEFAULT_LIMIT = 20;

    /**
     * Constructor for AudioDbProvider.
     *
     * @param MetadataHttpClient $http HTTP client for API requests
     * @param string $apiKey AudioDB API key
     * @param LoggerInterface|null $logger Optional logger instance
     */
    public function __construct(
        MetadataHttpClient $http,
        string $apiKey = '',
        ?LoggerInterface $logger = null
    ) {
        $this->http = $http;
        $this->apiKey = $apiKey;
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
     * @param array<string, mixed> $options Search options (limit)
     * @return array<int, array<string, mixed>> Search results
     */
    public function search(string $query, array $options = []): array
    {
        if (empty($this->apiKey)) {
            $this->getLogger()->warning('AudioDB search skipped - no API key configured');
            return [];
        }

        /** @var int $limit */
        $limit = is_int($options['limit'] ?? null) ? $options['limit'] : self::DEFAULT_LIMIT;

        $this->rateLimit(0.5);

        try {
            /** @var array<string, mixed>|null $response */
            $response = $this->http->get(self::BASE_URL . '/search.php', [
                's' => $query,
            ]);

            if (!is_array($response) || !isset($response['artists']) || !is_array($response['artists'])) {
                return [];
            }

            /** @var array<int, array<string, mixed>> $artists */
            $artists = $response['artists'];

            /** @var array<int, array<string, mixed>> $results */
            $results = [];

            foreach (array_slice($artists, 0, (int) $limit) as $artist) {
                if (!is_array($artist)) {
                    continue;
                }
                $results[] = [
                    'id' => is_string($artist['idArtist'] ?? null) ? $artist['idArtist'] : '',
                    'title' => is_string($artist['strArtist'] ?? null) ? $artist['strArtist'] : '',
                    'type' => 'artist',
                    'year' => null,
                    'thumb' => is_string($artist['strArtistThumb'] ?? null) ? $artist['strArtistThumb'] : null,
                ];
            }

            return $results;
        } catch (\Throwable $e) {
            $this->getLogger()->error('AudioDB search failed', [
                'query' => $query,
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
        $entity = $options['entity'] ?? 'artist';

        $details = match ($entity) {
            'artist' => $this->getArtist($externalId),
            'album' => $this->getAlbum($externalId),
            'track' => $this->getTrack($externalId),
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
        return ['audiodb'];
    }

    /**
     * {@inheritdoc}
     */
    public function getSourceName(): string
    {
        return 'audiodb';
    }

    /**
     * Get detailed artist information from AudioDB.
     *
     * @param string $audiodb_id AudioDB artist ID
     * @return array<string, mixed>|null Artist details or null on failure
     */
    public function getArtist(string $audiodb_id): ?array
    {
        if (empty($this->apiKey)) {
            $this->getLogger()->warning('AudioDB getArtist skipped - no API key configured');
            return null;
        }

        $this->rateLimit(0.5);

        try {
            /** @var array<string, mixed>|null $response */
            $response = $this->http->get(self::BASE_URL . '/artist.php', [
                'i' => $audiodb_id,
            ]);

            $artists = $response['artists'] ?? null;
            if (!is_array($artists) || count($artists) === 0) {
                return null;
            }
            $firstArtist = $artists[0];
            if (!is_array($firstArtist)) {
                return null;
            }

            /** @var array<string, mixed> $artist */
            $artist = $firstArtist;

            return [
                'id' => is_string($artist['idArtist'] ?? null) ? $artist['idArtist'] : $audiodb_id,
                'name' => is_string($artist['strArtist'] ?? null) ? $artist['strArtist'] : '',
                'country' => is_string($artist['strCountry'] ?? null) ? $artist['strCountry'] : null,
                'genre' => is_string($artist['strGenre'] ?? null) ? $artist['strGenre'] : null,
                'biography' => is_string($artist['strBiographyEN'] ?? null) ? $artist['strBiographyEN'] : null,
                'thumb' => is_string($artist['strArtistThumb'] ?? null) ? $artist['strArtistThumb'] : null,
                'fanart' => is_string($artist['strArtistFanart'] ?? null) ? $artist['strArtistFanart'] : null,
            ];
        } catch (\Throwable $e) {
            $this->getLogger()->error('AudioDB getArtist failed', [
                'audiodb_id' => $audiodb_id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get detailed album information from AudioDB.
     *
     * @param string $audiodb_id AudioDB album ID
     * @return array<string, mixed>|null Album details or null on failure
     */
    public function getAlbum(string $audiodb_id): ?array
    {
        if (empty($this->apiKey)) {
            $this->getLogger()->warning('AudioDB getAlbum skipped - no API key configured');
            return null;
        }

        $this->rateLimit(0.5);

        try {
            /** @var array<string, mixed>|null $response */
            $response = $this->http->get(self::BASE_URL . '/album.php', [
                'i' => $audiodb_id,
            ]);

            $albumData = $response['album'] ?? null;
            if (!is_array($albumData) || count($albumData) === 0) {
                return null;
            }
            $firstAlbum = $albumData[0];
            if (!is_array($firstAlbum)) {
                return null;
            }

            /** @var array<string, mixed> $album */
            $album = $firstAlbum;

            $albumId = is_string($album['idAlbum'] ?? null) ? $album['idAlbum'] : $audiodb_id;
            $tracks = $this->getAlbumTracks($albumId);

            return [
                'id' => $albumId,
                'title' => is_string($album['strAlbum'] ?? null) ? $album['strAlbum'] : '',
                'artist_id' => is_string($album['idArtist'] ?? null) ? $album['idArtist'] : null,
                'artist_name' => is_string($album['strArtist'] ?? null) ? $album['strArtist'] : null,
                'year' => isset($album['intYearReleased'])
                    && is_numeric($album['intYearReleased']) ? (int) $album['intYearReleased'] : null,
                'genre' => is_string($album['strGenre'] ?? null) ? $album['strGenre'] : null,
                'thumb' => is_string($album['strAlbumThumb'] ?? null) ? $album['strAlbumThumb'] : null,
                'tracks' => $tracks,
            ];
        } catch (\Throwable $e) {
            $this->getLogger()->error('AudioDB getAlbum failed', [
                'audiodb_id' => $audiodb_id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get track information for an album.
     *
     * @param string $albumId AudioDB album ID
     * @return array<int, array<string, mixed>> Tracks
     */
    private function getAlbumTracks(string $albumId): array
    {
        try {
            /** @var array<string, mixed>|null $response */
            $response = $this->http->get(self::BASE_URL . '/track.php', [
                'm' => $albumId,
            ]);

            if (!is_array($response) || !isset($response['track']) || !is_array($response['track'])) {
                return [];
            }

            /** @var array<int, array<string, mixed>> $tracksResult */
            $tracksResult = [];

            foreach ($response['track'] as $track) {
                if (!is_array($track)) {
                    continue;
                }
                $tracksResult[] = [
                    'id' => is_string($track['idTrack'] ?? null) ? $track['idTrack'] : '',
                    'title' => is_string($track['strTrack'] ?? null) ? $track['strTrack'] : '',
                    'duration' => is_int($track['intDuration'] ?? null) ? $track['intDuration'] : 0,
                    'position' => is_int($track['intTrackNumber'] ?? null) ? $track['intTrackNumber'] : 0,
                ];
            }

            return $tracksResult;
        } catch (\Throwable $e) {
            $this->getLogger()->error('AudioDB getAlbumTracks failed', [
                'album_id' => $albumId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get detailed track information from AudioDB.
     *
     * @param string $audiodb_id AudioDB track ID
     * @return array<string, mixed>|null Track details or null on failure
     */
    public function getTrack(string $audiodb_id): ?array
    {
        if (empty($this->apiKey)) {
            $this->getLogger()->warning('AudioDB getTrack skipped - no API key configured');
            return null;
        }

        $this->rateLimit(0.5);

        try {
            /** @var array<string, mixed>|null $response */
            $response = $this->http->get(self::BASE_URL . '/track.php', [
                'i' => $audiodb_id,
            ]);

            $trackData = $response['track'] ?? null;
            if (!is_array($trackData) || count($trackData) === 0) {
                return null;
            }
            $firstTrack = $trackData[0];
            if (!is_array($firstTrack)) {
                return null;
            }

            /** @var array<string, mixed> $track */
            $track = $firstTrack;

            return [
                'id' => is_string($track['idTrack'] ?? null) ? $track['idTrack'] : $audiodb_id,
                'title' => is_string($track['strTrack'] ?? null) ? $track['strTrack'] : '',
                'duration' => is_int($track['intDuration'] ?? null) ? $track['intDuration'] : 0,
                'artist_name' => is_string($track['strArtist'] ?? null) ? $track['strArtist'] : null,
                'album_name' => is_string($track['strAlbum'] ?? null) ? $track['strAlbum'] : null,
                'position' => is_int($track['intTrackNumber'] ?? null) ? $track['intTrackNumber'] : null,
            ];
        } catch (\Throwable $e) {
            $this->getLogger()->error('AudioDB getTrack failed', [
                'audiodb_id' => $audiodb_id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
