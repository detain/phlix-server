# Music Metadata Providers

This document describes the music metadata provider architecture in Phlex, covering provider implementation, configuration, and extension.

## Overview

Phlex ships two in-core music metadata providers:

- **MusicBrainz** — Primary provider, public API with rate limiting (1 req/sec)
- **AudioDB** — Fallback provider, requires user-supplied API key

Both providers implement `MetadataProviderInterface` and use the shared `MusicMetadataProviderTrait` for rate limiting and header management.

## Architecture

### Provider Interface

Music providers must implement `MetadataProviderInterface`:

```php
interface MetadataProviderInterface
{
    public const MEDIA_TYPE_ALBUM = 'album';
    public const MEDIA_TYPE_ARTIST = 'artist';
    public const MEDIA_TYPE_TRACK = 'track';

    public function search(string $query, array $options = []): array;
    public function getDetails(string $externalId, array $options = []): array;
    public function getImages(string $externalId): array;
    public function getProviders(): array;
    public function getSourceName(): string;
}
```

### Shared Trait

`MusicMetadataProviderTrait` provides:

- `rateLimit(float $seconds)` — Enforces minimum time between requests
- `mbHeaders(string $userAgent)` — Returns MusicBrainz-required headers

## Configuration

Music providers are configured via `config/music_providers.php`:

```php
return [
    'musicbrainz' => [
        'enabled'    => true,
        'rate_limit' => 1.0,        // seconds between requests
        'user_agent' => 'Phlex/1.0 (https://phlex.media)',
        'use_fallback' => true,    // fall back to AudioDB if MusicBrainz fails
    ],
    'audiodb' => [
        'enabled'  => true,
        'api_key' => '',           // user supplies their own key
        'rate_limit' => 0.5,
    ],
];
```

### Environment Variables

| Variable | Description |
|---------|-------------|
| `PHLEX_MUSICBRAINZ_ENABLED` | Enable/disable MusicBrainz provider |
| `PHLEX_MUSICBRAINZ_USER_AGENT` | Custom User-Agent for MusicBrainz |
| `PHLEX_AUDIODB_ENABLED` | Enable/disable AudioDB provider |
| `PHLEX_AUDIODB_API_KEY` | AudioDB API key |

## MusicBrainz Requirements

MusicBrainz requires:

1. **User-Agent header** with contact information (email or website)
2. **Rate limiting** — minimum 1 second between requests
3. **No commercial use** — see MusicBrainz terms of use

Example User-Agent:
```
Phlex/1.0 (https://phlex.media; contact@phlex.media)
```

## Adding a Third Provider

To add a new music metadata provider:

1. Create `src/Media/Metadata/Provider/YourProvider.php` implementing `MetadataProviderInterface`
2. Add provider registration in `MetadataManager::registerProvider()`
3. Add configuration keys to `config/music_providers.php`
4. Add unit tests in `tests/unit/Media/Metadata/Provider/`
5. Update this document

## Provider Priority

Music providers are registered with the following priority order:

```
artist  → musicbrainz → audiodb → local
album   → musicbrainz → audiodb → local
track   → musicbrainz → audiodb → local
```

The `use_fallback` config key controls whether to try the next provider if the primary returns no results.

## API Reference

### MusicBrainzProvider

```php
// Search for artists/albums/tracks
$provider->search('query', ['entity' => 'artist', 'limit' => 20]);

// Get detailed artist info
$provider->getArtist('mbid'); // Returns {mbid, name, sort_name, country, disambiguation, tags, biography}

// Get detailed album info
$provider->getAlbum('mbid'); // Returns {mbid, title, artist_mbid, artist_name, year, genre, tracks}

// Get detailed track info
$provider->getTrack('mbid'); // Returns {mbid, title, duration, artist_mbid, artist_name, album_mbid, position}
```

### AudioDbProvider

```php
// Search (requires API key)
$provider->search('query', ['limit' => 20]);

// Get detailed artist info
$provider->getArtist('audiodb_id'); // Returns {id, name, country, genre, biography, thumb, fanart}

// Get detailed album info
$provider->getAlbum('audiodb_id'); // Returns {id, title, artist_id, artist_name, year, genre, thumb, tracks}

// Get detailed track info
$provider->getTrack('audiodb_id'); // Returns {id, title, duration, artist_name, album_name, position}
```

## Error Handling

Both providers degrade gracefully on error:

- **MusicBrainz** — Returns empty array on HTTP error, logs warning
- **AudioDB** — Returns empty results if no API key, returns null on missing data

## Rate Limiting

| Provider | Limit |
|---------|-------|
| MusicBrainz | 1 request/second |
| AudioDB | ~2 requests/second (0.5s delay) |

Rate limiting is enforced via `rateLimit()` in `MusicMetadataProviderTrait`. The delay is applied before each request to ensure compliance.
