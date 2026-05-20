<?php

declare(strict_types=1);

namespace Phlix\Media\Metadata;

interface MetadataProviderInterface
{
    public const MEDIA_TYPE_ALBUM = 'album';
    public const MEDIA_TYPE_ARTIST = 'artist';
    public const MEDIA_TYPE_TRACK = 'track';

    /**
     * Search for media by query string.
     *
     * @param string $query Search query (e.g., movie title, series name)
     * @param array<string, mixed> $options Search options (e.g., year, language)
     * @return array<int, array{id: string, title: string, overview?: string, poster_path?: string}> Search results
     */
    public function search(string $query, array $options = []): array;

    /**
     * Get detailed metadata for a specific external ID.
     *
     * @param string $externalId The external provider's unique identifier
     * @param array<string, mixed> $options Additional options (e.g., language)
     * @return array<string, mixed> Detailed metadata (name, year, overview, genres, actors, etc.)
     */
    public function getDetails(string $externalId, array $options = []): array;

    /**
     * Get images (posters, backdrops, banners) for an external ID.
     *
     * @param string $externalId The external provider's unique identifier
     * @return array<string, array<int, array{url: string, width?: int, height?: int}>> Images by type
     */
    public function getImages(string $externalId): array;

    /**
     * Get the provider names this implementation handles.
     *
     * @return array<string> Provider name aliases (e.g., ['tmdb'] or ['tvdb', 'thetvdb'])
     */
    public function getProviders(): array;

    /**
     * Get the source name of this provider.
     *
     * @return string Provider source name (e.g., 'musicbrainz', 'audiodb', 'tmdb')
     */
    public function getSourceName(): string;
}
