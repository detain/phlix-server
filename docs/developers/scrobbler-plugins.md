# Scrobbler Plugin Guide

## Overview

A scrobbler plugin integrates Phlex with external media tracking services (e.g., Last.fm, Trakt.tv) to submit playback data and synchronize watch history.

## Plugin Type

Set `"type": "scrobbler"` in `plugin.json`.

## Required Events

Scrobbler plugins **must** subscribe to:

| Event | Description | Typical Action |
|-------|-------------|----------------|
| `phlex.playback.started` | Playback began | Submit "start" scrobble |
| `phlex.playback.stopped` | Playback ended | Submit "stop" scrobble |
| `phlex.playback.progress` | Progress update (throttled ~30s) | Submit "pause" scrobble (Trakt) or ignore (Last.fm) |

## OAuth Flow

Most scrobbler services require OAuth authentication:

1. **Initiate**: Redirect user to provider's authorization URL
2. **Callback**: Handle the redirect, exchange code for tokens
3. **Store**: Persist tokens in plugin settings (JSON in `plugins.settings_json`)
4. **Refresh**: Handle token refresh when expired (typically 401 response)

### Trakt.tv OAuth2 PKCE

Trakt uses OAuth2 with PKCE (Proof Key for Code Exchange):

```php
// Generate state and code verifier
$state = bin2hex(random_bytes(16));
$codeVerifier = bin2hex(random_bytes(32));

// Build auth URL with code challenge
$codeChallenge = base64url_encode(hash('sha256', $codeVerifier, true));
$authUrl = "https://api.trakt.tv/oauth/authorize?" . http_build_query([
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'response_type' => 'code',
    'state' => $state,
    'code_challenge' => $codeChallenge,
    'code_challenge_method' => 'S256',
]);

// Exchange code for tokens
$tokens = $api->exchangeCode($code, $codeVerifier);
// Returns: ['access_token' => ..., 'refresh_token' => ..., 'expires_in' => ...]
```

## Settings Shape

Scrobbler plugins should store these settings:

```json
{
    "enabled": true,
    "username": "trakt-username",
    "access_token": "oauth-access-token",
    "refresh_token": "oauth-refresh-token",
    "expires_at": 1699999999,
    "sync_enabled": true,
    "sync_interval_minutes": 30,
    "scrobble_enabled": true
}
```

## Two-Way History Sync

### Trakt → Phlex (Pull)

Run on a schedule (e.g., every 30 minutes via cron):

1. Fetch watched history from Trakt API
2. For each item, check local `watch_history` table
3. If item is not ≥ 90% complete in Phlex, write an entry

```php
public function syncTraktToPhlex(string $profileId): int
{
    $history = $this->api->getWatchedHistory($username, 1, 100, $accessToken);
    $written = 0;

    foreach ($history as $item) {
        $mediaItemId = $this->findMediaItemId($item);
        $existing = $this->watchHistory->getForMediaItem($profileId, $mediaItemId);

        if ($existing !== null && $existing['progress_percent'] >= 90.0) {
            continue; // Already complete
        }

        $this->watchHistory->updateProgress(
            $profileId,
            $mediaItemId,
            $durationTicks,
            $durationTicks,
            WatchHistory::STATUS_COMPLETED
        );
        $written++;
    }

    return $written;
}
```

### Phlex → Trakt (Push)

After `PlaybackStopped` with ≥ 90% completion:

```php
public function syncPhlexToTrakt(string $profileId, string $mediaItemId, int $position, ?int $duration): bool
{
    $entry = $this->watchHistory->getForMediaItem($profileId, $mediaItemId);

    if ($entry === null || $entry['progress_percent'] < 90.0) {
        return false;
    }

    $item = $this->buildMediaItem($mediaItemId, $entry);
    $watchedAt = new \DateTimeImmutable($entry['last_watched_at']);

    $this->api->addToHistory($item, $watchedAt, $accessToken);
    return true;
}
```

## Scrobble Semantics

### Last.fm (2-State)

| Event | Last.fm Action |
|-------|-----------------|
| PlaybackStarted | `nowPlaying` update (optional) |
| PlaybackStopped (≥ threshold) | `scrobble` |

### Trakt.tv (3-State)

| Event | Trakt Action |
|-------|--------------|
| PlaybackStarted | `start` |
| PlaybackProgressUpdated (~30s) | `pause` |
| PlaybackStopped | `stop` |

## Entry Class Template

```php
namespace Phlex\Plugins\Scrobbler\YourService;

use Phlex\Media\Library\ItemRepository;
use Phlex\Plugins\Contract\LifecycleInterface;
use Phlex\Shared\Events\Playback\PlaybackStarted;
use Phlex\Shared\Events\Playback\PlaybackStopped;
use Phlex\Shared\Events\Playback\PlaybackProgressUpdated;
use Psr\Container\ContainerInterface;

final class YourPlugin implements LifecycleInterface
{
    public const PLUGIN_TYPE = 'scrobbler';

    private ?ItemRepository $itemRepository = null;
    private YourSettings $settings;
    private YourApiClient $api;

    public function configure(array $settings): void
    {
        $this->settings = YourSettings::fromArray($settings);
    }

    public function onEnable(ContainerInterface $container): void
    {
        $this->itemRepository = $container->get(ItemRepository::class);
        $this->api = new YourApiClient($this->settings);
    }

    public function onDisable(): void
    {
        $this->itemRepository = null;
    }

    public function subscribedEvents(): array
    {
        return [
            PlaybackStarted::class => 'onPlaybackStarted',
            PlaybackStopped::class => 'onPlaybackStopped',
            PlaybackProgressUpdated::class => 'onPlaybackProgressUpdated',
        ];
    }

    // ... event handlers ...
}
```

## Plugin Manifest

```json
{
    "name": "phlex-plugin-yourservice",
    "version": "1.0.0",
    "phlex_min_server_version": "0.14.0",
    "type": "scrobbler",
    "entry": "Phlex\\Plugins\\Scrobbler\\YourService\\YourPlugin",
    "events": [
        "phlex.playback.started",
        "phlex.playback.stopped",
        "phlex.playback.progress"
    ],
    "settings": {
        "enabled": {
            "type": "boolean",
            "default": false
        },
        "username": {
            "type": "string",
            "default": ""
        }
    }
}
```

## See Also

- [Last.fm Plugin Reference Implementation](/src/Plugins/Lastfm/Plugin.php)
- [Trakt.tv Plugin Reference Implementation](/src/Plugins/Scrobbler/Trakt/TraktPlugin.php)
- [Plugin Lifecycle Interface](/src/Plugins/Contract/LifecycleInterface.php)
- [WatchHistory Reference](/src/Auth/WatchHistory.php)
